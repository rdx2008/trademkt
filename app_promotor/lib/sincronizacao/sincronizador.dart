/// Processa a fila persistente: envia na ordem check-in → fotos antes →
/// fotos de ruptura → fotos depois → conclusão, reenvia de forma idempotente
/// e reconcilia com `GET /visitas/{visita}` quando o servidor responde 409.
library;

import 'dart:typed_data';

import '../api/api_promotor.dart';
import '../api/cliente_api.dart';
import '../dados/repositorio_local.dart';
import '../modelos/execucao.dart';
import '../modelos/roteiro.dart';
import 'item_fila.dart';

typedef LeitorArquivo = Future<Uint8List?> Function(String caminho);

class ResultadoSincronizacao {
  int enviados = 0;
  int erros = 0;
  int conflitos = 0;
  int pendentes = 0;
  bool semConexao = false;
  bool sessaoExpirada = false;
  final List<String> mensagens = [];

  bool get tudoCerto => erros == 0 && conflitos == 0 && !semConexao && !sessaoExpirada;

  String get resumo {
    if (sessaoExpirada) {
      return 'Sessão expirada. Entre novamente.';
    }
    if (semConexao) {
      return 'Sem conexão. $pendentes item(ns) aguardando envio.';
    }
    final partes = <String>[];
    if (enviados > 0) {
      partes.add('$enviados enviado(s)');
    }
    if (erros > 0) {
      partes.add('$erros com erro');
    }
    if (conflitos > 0) {
      partes.add('$conflitos em conflito');
    }
    if (pendentes > 0) {
      partes.add('$pendentes pendente(s)');
    }

    return partes.isEmpty ? 'Tudo sincronizado.' : partes.join(', ');
  }
}

class Sincronizador {
  Sincronizador({required this.api, required this.repositorio, required this.lerArquivo});

  final ApiPromotor api;
  final RepositorioLocal repositorio;
  final LeitorArquivo lerArquivo;

  bool _emAndamento = false;

  bool get emAndamento => _emAndamento;

  Future<ResultadoSincronizacao> sincronizar() async {
    final resultado = ResultadoSincronizacao();
    if (_emAndamento) {
      resultado.pendentes = await repositorio.contarPendentes();

      return resultado;
    }
    _emAndamento = true;
    try {
      final fila = ordenarFila(await repositorio.listarFila(estado: EstadoItemFila.pendente));
      final visitasBloqueadas = <String>{};

      for (final itemDaLista in fila) {
        if (visitasBloqueadas.contains(itemDaLista.visitaId)) {
          continue;
        }
        // Uma reconciliação anterior pode já ter confirmado este item no banco.
        final item = await repositorio.obterItemPorChave(itemDaLista.chave) ?? itemDaLista;
        if (item.estado == EstadoItemFila.enviado) {
          resultado.enviados++;
          continue;
        }
        if (item.estado != EstadoItemFila.pendente) {
          continue;
        }
        try {
          await _enviar(item);
          resultado.enviados++;
        } on SemConexao {
          await repositorio.atualizarItem(item.copiarCom(tentativas: item.tentativas + 1, ultimoErro: 'Sem conexão'));
          resultado.semConexao = true;
          break;
        } on ErroApi catch (erro) {
          if (erro.naoAutenticado) {
            resultado.sessaoExpirada = true;
            break;
          }
          visitasBloqueadas.add(item.visitaId);
          if (erro.conflito) {
            final resolvido = await _resolverConflito(item);
            if (resolvido) {
              visitasBloqueadas.remove(item.visitaId);
              resultado.enviados++;
            } else {
              resultado.conflitos++;
              resultado.mensagens.add('Visita ${item.visitaId}: ${erro.descricao}');
            }
          } else if (erro.definitivo) {
            await repositorio.atualizarItem(item.copiarCom(
              estado: EstadoItemFila.erro,
              tentativas: item.tentativas + 1,
              ultimoErro: erro.descricao,
            ));
            resultado.erros++;
            resultado.mensagens.add('Visita ${item.visitaId}: ${erro.descricao}');
          } else {
            // 5xx, 408, 429: fica pendente para a próxima rodada.
            await repositorio.atualizarItem(item.copiarCom(tentativas: item.tentativas + 1, ultimoErro: erro.descricao));
            resultado.mensagens.add('Visita ${item.visitaId}: ${erro.descricao}');
          }
        }
      }
    } finally {
      _emAndamento = false;
    }
    resultado.pendentes = await repositorio.contarPendentes();

    return resultado;
  }

  Future<void> _enviar(ItemFila item) async {
    switch (item.tipo) {
      case TipoItemFila.checkIn:
        final resposta = await api.checkIn(item.visitaId, item.payload);
        await _confirmar(item);
        await _atualizarExecucao(item.visitaId, (e) {
          final servidor = resposta.corpo['check_in'] as Map<String, dynamic>?;
          final checkIn = e.checkIn?.copiarCom(enviado: true) ?? (servidor == null ? null : _checkInDoServidor(servidor));

          return e.copiarCom(checkIn: checkIn, statusServidor: resposta.corpo['status'] as String?);
        });
      case TipoItemFila.foto:
        final caminho = item.arquivo ?? item.payload['caminho'] as String?;
        final bytes = caminho == null ? null : await lerArquivo(caminho);
        if (bytes == null) {
          throw ErroApi(status: 404, mensagem: 'Arquivo da foto não encontrado no aparelho.');
        }
        final campos = {
          for (final entrada in item.payload.entries)
            if (entrada.key != 'caminho') entrada.key: entrada.value.toString(),
        };
        await api.enviarFoto(item.visitaId, campos, bytes: bytes, nomeArquivo: '${item.chave}.jpg');
        await _confirmar(item);
        await _atualizarExecucao(
          item.visitaId,
          (e) => e.copiarCom(fotos: e.fotos.map((f) => f.uuid == item.chave ? f.copiarCom(enviada: true) : f).toList()),
        );
      case TipoItemFila.conclusao:
        final resposta = await api.concluir(item.visitaId, item.payload);
        await _confirmar(item);
        final visita = resposta.corpo['visita'] as Map<String, dynamic>?;
        await _atualizarExecucao(
          item.visitaId,
          (e) => e.copiarCom(conclusao: e.conclusao?.copiarCom(enviada: true), statusServidor: visita?['status'] as String?),
        );
    }
  }

  Future<void> _confirmar(ItemFila item) =>
      repositorio.atualizarItem(item.copiarCom(estado: EstadoItemFila.enviado, limparErro: true));

  Future<void> _atualizarExecucao(String visitaId, ExecucaoVisita Function(ExecucaoVisita) alterar) =>
      repositorio.modificarExecucao(visitaId, alterar);

  /// O servidor respondeu 409: consulta a visita e confere se o item já está lá.
  /// Se estiver, o item é dado como enviado; senão fica em conflito.
  Future<bool> _resolverConflito(ItemFila item) async {
    Map<String, dynamic> visita;
    try {
      visita = await api.visita(item.visitaId);
    } on ErroApi catch (erro) {
      await repositorio.atualizarItem(item.copiarCom(estado: EstadoItemFila.conflito, ultimoErro: erro.descricao));

      return false;
    }
    await aplicarEstadoDoServidor(item.visitaId, visita);

    final presente = switch (item.tipo) {
      TipoItemFila.checkIn => visita['check_in'] != null,
      TipoItemFila.foto => _fotosDoServidor(visita).contains(item.chave),
      TipoItemFila.conclusao => visita['tipo_conclusao'] == item.payload['tipo'] &&
          StatusVisita.encerrada(visita['status'] as String? ?? ''),
    };
    if (presente) {
      await _confirmar(item);

      return true;
    }
    await repositorio.atualizarItem(item.copiarCom(
      estado: EstadoItemFila.conflito,
      tentativas: item.tentativas + 1,
      ultimoErro: 'O servidor já tem outra versão deste registro.',
    ));

    return false;
  }

  /// Reconciliação explícita: baixa o estado da visita e alinha o que há no aparelho.
  Future<ExecucaoVisita> reconciliar(String visitaId) async {
    final visita = await api.visita(visitaId);

    return aplicarEstadoDoServidor(visitaId, visita);
  }

  /// Marca como enviado o que o servidor já tem e registra o status confirmado.
  Future<ExecucaoVisita> aplicarEstadoDoServidor(String visitaId, Map<String, dynamic> visita) async {
    final fotosServidor = _fotosDoServidor(visita);
    final checkInServidor = visita['check_in'] as Map<String, dynamic>?;
    final concluidaNoServidor = visita['tipo_conclusao'] != null;

    final nova = await repositorio.modificarExecucao(
      visitaId,
      (atual) => atual.copiarCom(
        checkIn: checkInServidor == null
            ? atual.checkIn
            : (atual.checkIn?.copiarCom(enviado: true) ?? _checkInDoServidor(checkInServidor)),
        fotos: atual.fotos.map((f) => fotosServidor.contains(f.uuid) ? f.copiarCom(enviada: true) : f).toList(),
        conclusao: concluidaNoServidor ? atual.conclusao?.copiarCom(enviada: true) : atual.conclusao,
        statusServidor: visita['status'] as String?,
      ),
    );

    // Itens da fila que o servidor já confirmou também saem de pendente.
    for (final item in await repositorio.listarFila(visitaId: visitaId)) {
      if (item.estado == EstadoItemFila.enviado) {
        continue;
      }
      final presente = switch (item.tipo) {
        TipoItemFila.checkIn => checkInServidor != null,
        TipoItemFila.foto => fotosServidor.contains(item.chave),
        TipoItemFila.conclusao => concluidaNoServidor && visita['tipo_conclusao'] == item.payload['tipo'],
      };
      if (presente) {
        await _confirmar(item);
      }
    }

    return nova;
  }

  static Set<String> _fotosDoServidor(Map<String, dynamic> visita) => {
        for (final foto in (visita['fotos'] as List?) ?? const []) (foto as Map)['uuid'] as String,
      };

  static CheckInLocal _checkInDoServidor(Map<String, dynamic> c) => CheckInLocal(
        lat: (c['lat'] as num).toDouble(),
        lng: (c['lng'] as num).toDouble(),
        gpsEm: DateTime.parse(c['gps_em'] as String),
        aparelhoEm: DateTime.parse(c['aparelho_em'] as String? ?? c['gps_em'] as String),
        distanciaM: (c['distancia_m'] as num?)?.toDouble() ?? 0,
        enviado: true,
      );
}
