import 'dart:io';
import 'dart:typed_data';

import 'package:flutter/material.dart';

import '../dominio/regras_visita.dart';
import '../estado_app.dart';
import '../modelos/execucao.dart';
import '../modelos/roteiro.dart';
import '../sincronizacao/item_fila.dart';
import '../util/datas.dart';
import 'tela_camera.dart';
import 'tela_roteiro.dart' show corStatus;
import 'tela_skus.dart';

/// Execução da visita: check-in → foto antes → SKUs → foto depois → enviar;
/// ou ruptura (foto + produtos) ou impedimento (observação).
class TelaVisita extends StatefulWidget {
  const TelaVisita({super.key, required this.estado, required this.visitaId});

  final EstadoApp estado;
  final String visitaId;

  @override
  State<TelaVisita> createState() => _TelaVisitaState();
}

class _TelaVisitaState extends State<TelaVisita> {
  bool _ocupado = false;
  List<ItemTrabalhado> _itensRuptura = const [];

  EstadoApp get estado => widget.estado;

  void _avisar(String mensagem) {
    if (!mounted) {
      return;
    }
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(SnackBar(content: Text(mensagem)));
  }

  Future<void> _executar(Future<String?> Function() acao) async {
    if (_ocupado) {
      return;
    }
    setState(() => _ocupado = true);
    try {
      final erro = await acao();
      if (erro != null) {
        _avisar(erro);
      }
    } finally {
      if (mounted) {
        setState(() => _ocupado = false);
      }
    }
  }

  Future<void> _tirarFoto(Visita visita, TipoFoto tipo) => _executar(() async {
        final preparo = await estado.prepararFoto(visita, tipo);
        if (preparo.erro != null) {
          return preparo.erro;
        }
        if (!mounted) {
          return null;
        }
        final bytes = await Navigator.of(context).push<Uint8List>(
          MaterialPageRoute(builder: (_) => TelaCamera(titulo: 'Foto ${tipo.rotulo.toLowerCase()}')),
        );
        if (bytes == null) {
          return null;
        }
        final erro = await estado.registrarFoto(visita, tipo, bytes, preparo.posicao!);
        if (erro == null) {
          _avisar('Foto ${tipo.rotulo.toLowerCase()} gravada.');
        }

        return erro;
      });

  Future<void> _selecionarSkus(Visita visita, {required bool ruptura}) async {
    final industria = estado.industriaDa(visita);
    if (industria == null || industria.skus.isEmpty) {
      _avisar('Nenhum SKU ativo para esta indústria. Atualize o roteiro.');

      return;
    }
    final atuais = ruptura ? _itensRuptura : estado.execucao(visita.chave).itens;
    final escolhidos = await Navigator.of(context).push<List<ItemTrabalhado>>(
      MaterialPageRoute(builder: (_) => TelaSkus(industria: industria, iniciais: atuais, ruptura: ruptura)),
    );
    if (escolhidos == null) {
      return;
    }
    if (ruptura) {
      setState(() => _itensRuptura = escolhidos);
    } else {
      await estado.salvarItens(visita.chave, escolhidos);
    }
  }

  Future<void> _concluir(Visita visita) async {
    final confirmado = await _confirmar('Concluir visita', 'Enviar a visita como concluída?');
    if (!confirmado) {
      return;
    }
    await _executar(() => estado.concluir(visita, TipoConclusao.normal));
  }

  Future<void> _registrarRuptura(Visita visita) async {
    final confirmado = await _confirmar(
      'Registrar ruptura',
      'A visita será encerrada como ruptura de estoque com ${_itensRuptura.length} produto(s). Confirmar?',
    );
    if (!confirmado) {
      return;
    }
    await _executar(() => estado.concluir(visita, TipoConclusao.ruptura, itens: _itensRuptura));
  }

  Future<void> _impedimento(Visita visita) async {
    // Sem foto de antes não há visita aberta: a foto da entrada da loja faz esse papel.
    if (!estado.execucao(visita.chave).temCheckIn) {
      await _tirarFoto(visita, TipoFoto.impedimento);
      if (!mounted || !estado.execucao(visita.chave).temCheckIn) {
        return;
      }
    }
    final controle = TextEditingController();
    final observacao = await showDialog<String>(
      context: context,
      builder: (context) => AlertDialog(
        title: const Text('Impedimento de acesso'),
        content: TextField(
          controller: controle,
          maxLines: 4,
          maxLength: 2000,
          autofocus: true,
          decoration: const InputDecoration(
            labelText: 'Descreva o que impediu a visita',
            border: OutlineInputBorder(),
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancelar')),
          FilledButton(onPressed: () => Navigator.pop(context, controle.text), child: const Text('Registrar')),
        ],
      ),
    );
    controle.dispose();
    if (observacao == null) {
      return;
    }
    await _executar(() => estado.concluir(visita, TipoConclusao.impedimento, observacao: observacao));
  }

  Future<bool> _confirmar(String titulo, String texto) async =>
      await showDialog<bool>(
        context: context,
        builder: (context) => AlertDialog(
          title: Text(titulo),
          content: Text(texto),
          actions: [
            TextButton(onPressed: () => Navigator.pop(context, false), child: const Text('Cancelar')),
            FilledButton(onPressed: () => Navigator.pop(context, true), child: const Text('Confirmar')),
          ],
        ),
      ) ??
      false;

  @override
  Widget build(BuildContext context) {
    return ListenableBuilder(
      listenable: estado,
      builder: (context, _) {
        final visita = estado.roteiro?.visita(widget.visitaId);
        if (visita == null) {
          return Scaffold(
            appBar: AppBar(title: const Text('Visita')),
            body: const Center(child: Text('Visita não encontrada no roteiro salvo.')),
          );
        }
        final e = estado.execucao(visita.chave);
        final etapa = RegrasVisita.etapa(e);
        final industria = estado.industriaDa(visita);
        final status = estado.statusExibido(visita);
        final bloqueado = e.concluida || _ocupado;

        return Scaffold(
          appBar: AppBar(title: Text(visita.pdv.nome)),
          body: ListView(
            padding: const EdgeInsets.all(12),
            children: [
              _Cabecalho(visita: visita, industria: industria, status: status, e: e),
              const SizedBox(height: 12),
              _Passo(
                numero: 1,
                titulo: 'Foto antes da gôndola',
                feito: e.temFotoAntes,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (e.temCheckIn)
                      _Linha(
                        'Foto registrada às ${formatarDataHoraBr(e.checkIn!.gpsEm)} · ${e.checkIn!.distanciaM.round()} m do PDV',
                        enviado: e.checkIn!.enviado,
                      )
                    else
                      Text(
                        'Tire a foto dentro do raio de ${visita.pdv.raioM} m do PDV. '
                        'A localização e a hora da foto ficam salvas. O GPS é lido só ao tirar as fotos.',
                      ),
                    const SizedBox(height: 8),
                    _Fotos(
                      fotos: e.fotosDoTipo(TipoFoto.antes),
                      rotulo: 'Tirar foto antes',
                      onTirar: bloqueado ||
                              e.temFotoDepois ||
                              (!e.temCheckIn && !StatusVisita.aceitaCheckIn(e.statusServidor ?? visita.status))
                          ? null
                          : () => _tirarFoto(visita, TipoFoto.antes),
                    ),
                  ],
                ),
              ),
              _Passo(
                numero: 2,
                titulo: 'SKUs trabalhados e estoque',
                feito: e.itens.isNotEmpty && e.itens.every((i) => i.estoque != null),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    if (e.itens.isEmpty)
                      const Text('Nenhum SKU selecionado.')
                    else
                      ...e.itens.map((item) {
                        final sku = industria?.skus.where((s) => s.id == item.skuId).firstOrNull;

                        return ListTile(
                          dense: true,
                          contentPadding: EdgeInsets.zero,
                          leading: const Icon(Icons.inventory_2_outlined),
                          title: Text(sku == null ? 'SKU ${item.skuId}' : '${sku.codigo} · ${sku.descricao}'),
                          subtitle: Text(item.estoque?.rotulo ?? 'Situação de estoque não informada'),
                        );
                      }),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: bloqueado || etapa == EtapaVisita.fotoAntes ? null : () => _selecionarSkus(visita, ruptura: false),
                      icon: const Icon(Icons.checklist),
                      label: Text(e.itens.isEmpty ? 'Selecionar SKUs' : 'Alterar SKUs (${e.itens.length})'),
                    ),
                  ],
                ),
              ),
              _Passo(
                numero: 3,
                titulo: 'Foto depois',
                feito: e.temFotoDepois,
                child: _Fotos(
                  fotos: e.fotosDoTipo(TipoFoto.depois),
                  rotulo: 'Tirar foto depois',
                  onTirar: bloqueado || !e.temFotoAntes ? null : () => _tirarFoto(visita, TipoFoto.depois),
                ),
              ),
              const SizedBox(height: 8),
              if (!e.concluida) ...[
                FilledButton.icon(
                  onPressed: bloqueado ||
                          RegrasVisita.validarConclusao(
                                e,
                                TipoConclusao.normal,
                                itens: e.itens,
                                observacao: '',
                                finalizadaEm: DateTime.now(),
                              ) !=
                              null
                      ? null
                      : () => _concluir(visita),
                  icon: const Icon(Icons.send),
                  label: const Text('Concluir e enviar visita'),
                ),
                const SizedBox(height: 16),
                const Divider(),
                Text('Outras situações', style: Theme.of(context).textTheme.titleMedium),
                ExpansionTile(
                  leading: const Icon(Icons.remove_shopping_cart_outlined),
                  title: const Text('Ruptura de estoque'),
                  subtitle: const Text('Produto em falta no PDV. Exige a foto antes, a foto da ruptura e a lista de produtos.'),
                  childrenPadding: const EdgeInsets.fromLTRB(16, 0, 16, 12),
                  children: [
                    _Fotos(
                      fotos: e.fotosDoTipo(TipoFoto.ruptura),
                      rotulo: 'Foto da ruptura',
                      onTirar: bloqueado || !e.temCheckIn ? null : () => _tirarFoto(visita, TipoFoto.ruptura),
                    ),
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: bloqueado || !e.temCheckIn ? null : () => _selecionarSkus(visita, ruptura: true),
                      icon: const Icon(Icons.checklist),
                      label: Text(
                        _itensRuptura.isEmpty ? 'Produtos em ruptura' : 'Produtos em ruptura (${_itensRuptura.length})',
                      ),
                    ),
                    const SizedBox(height: 8),
                    FilledButton.tonalIcon(
                      onPressed: bloqueado || !e.temFotoRuptura || _itensRuptura.isEmpty
                          ? null
                          : () => _registrarRuptura(visita),
                      icon: const Icon(Icons.report_problem_outlined),
                      label: const Text('Registrar ruptura e encerrar'),
                    ),
                  ],
                ),
                ListTile(
                  leading: const Icon(Icons.block),
                  title: const Text('Impedimento de acesso'),
                  subtitle: Text(
                    e.temCheckIn
                        ? 'Não foi possível executar a visita. Exige observação.'
                        : 'Sem acesso à gôndola: fotografe a entrada da loja e descreva o motivo.',
                  ),
                  trailing: const Icon(Icons.chevron_right),
                  enabled: !bloqueado && (e.temCheckIn || StatusVisita.aceitaCheckIn(e.statusServidor ?? visita.status)),
                  onTap: () => _impedimento(visita),
                ),
              ] else
                _Envios(estado: estado, visitaId: visita.chave, e: e),
            ],
          ),
        );
      },
    );
  }
}

class _Cabecalho extends StatelessWidget {
  const _Cabecalho({required this.visita, required this.industria, required this.status, required this.e});

  final Visita visita;
  final Industria? industria;
  final String status;
  final ExecucaoVisita e;

  @override
  Widget build(BuildContext context) {
    final cor = corStatus(context, status);

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(child: Text(industria?.nome ?? 'Indústria', style: Theme.of(context).textTheme.titleMedium)),
                Chip(
                  label: Text(StatusVisita.rotulo(status)),
                  backgroundColor: cor.withValues(alpha: 0.15),
                  side: BorderSide(color: cor),
                  visualDensity: VisualDensity.compact,
                ),
              ],
            ),
            if (visita.pdv.endereco != null) Text(visita.pdv.endereco!),
            if (visita.pdv.cnpj != null) Text('CNPJ ${visita.pdv.cnpj}', style: Theme.of(context).textTheme.bodySmall),
            if (e.temPendencias)
              Padding(
                padding: const EdgeInsets.only(top: 8),
                child: Row(
                  children: [
                    const Icon(Icons.cloud_upload_outlined, size: 16),
                    const SizedBox(width: 6),
                    Text('Há dados desta visita aguardando envio.', style: Theme.of(context).textTheme.bodySmall),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}

class _Passo extends StatelessWidget {
  const _Passo({required this.numero, required this.titulo, required this.feito, required this.child});

  final int numero;
  final String titulo;
  final bool feito;
  final Widget child;

  @override
  Widget build(BuildContext context) {
    final cor = feito ? Colors.green.shade700 : Theme.of(context).colorScheme.outline;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Row(
              children: [
                CircleAvatar(
                  radius: 14,
                  backgroundColor: cor,
                  foregroundColor: Colors.white,
                  child: feito ? const Icon(Icons.check, size: 16) : Text('$numero'),
                ),
                const SizedBox(width: 10),
                Expanded(child: Text(titulo, style: Theme.of(context).textTheme.titleMedium)),
              ],
            ),
            const SizedBox(height: 10),
            child,
          ],
        ),
      ),
    );
  }
}

class _Linha extends StatelessWidget {
  const _Linha(this.texto, {required this.enviado});

  final String texto;
  final bool enviado;

  @override
  Widget build(BuildContext context) => Row(
        children: [
          Icon(enviado ? Icons.cloud_done_outlined : Icons.cloud_upload_outlined,
              size: 18, color: enviado ? Colors.green.shade700 : null),
          const SizedBox(width: 6),
          Expanded(child: Text(texto)),
        ],
      );
}

class _Fotos extends StatelessWidget {
  const _Fotos({required this.fotos, required this.rotulo, required this.onTirar});

  final List<FotoLocal> fotos;
  final String rotulo;
  final VoidCallback? onTirar;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        if (fotos.isNotEmpty)
          SizedBox(
            height: 96,
            child: ListView.separated(
              scrollDirection: Axis.horizontal,
              itemCount: fotos.length,
              separatorBuilder: (_, _) => const SizedBox(width: 8),
              itemBuilder: (context, i) {
                final foto = fotos[i];

                return Stack(
                  children: [
                    ClipRRect(
                      borderRadius: BorderRadius.circular(8),
                      child: Image.file(
                        File(foto.caminho),
                        width: 128,
                        height: 96,
                        fit: BoxFit.cover,
                        errorBuilder: (_, _, _) => const SizedBox(
                          width: 128,
                          height: 96,
                          child: Icon(Icons.broken_image_outlined),
                        ),
                      ),
                    ),
                    Positioned(
                      right: 4,
                      top: 4,
                      child: Icon(
                        foto.enviada ? Icons.cloud_done : Icons.cloud_upload,
                        color: foto.enviada ? Colors.greenAccent : Colors.white,
                        shadows: const [Shadow(blurRadius: 4)],
                      ),
                    ),
                  ],
                );
              },
            ),
          ),
        if (fotos.isNotEmpty) const SizedBox(height: 8),
        OutlinedButton.icon(onPressed: onTirar, icon: const Icon(Icons.photo_camera), label: Text(rotulo)),
      ],
    );
  }
}

/// Após a conclusão: estado de cada envio desta visita e ações de reenvio.
class _Envios extends StatelessWidget {
  const _Envios({required this.estado, required this.visitaId, required this.e});

  final EstadoApp estado;
  final String visitaId;
  final ExecucaoVisita e;

  @override
  Widget build(BuildContext context) {
    final conclusao = e.conclusao!;

    return Card(
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Text(conclusao.tipo.rotulo, style: Theme.of(context).textTheme.titleMedium),
            Text('Finalizada às ${formatarDataHoraBr(conclusao.finalizadaEm)}'),
            if (conclusao.observacao.isNotEmpty) Text('Observação: ${conclusao.observacao}'),
            const SizedBox(height: 8),
            FutureBuilder<List<ItemFila>>(
              future: estado.itensDaFila(visitaId),
              builder: (context, snapshot) {
                final itens = snapshot.data ?? const <ItemFila>[];
                final problemas = itens.where((i) => i.estado == EstadoItemFila.erro || i.estado == EstadoItemFila.conflito);

                return Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    for (final item in itens)
                      ListTile(
                        dense: true,
                        contentPadding: EdgeInsets.zero,
                        leading: Icon(switch (item.estado) {
                          EstadoItemFila.enviado => Icons.cloud_done_outlined,
                          EstadoItemFila.pendente => Icons.cloud_upload_outlined,
                          EstadoItemFila.erro => Icons.error_outline,
                          EstadoItemFila.conflito => Icons.sync_problem,
                        }),
                        title: Text(_rotuloItem(item)),
                        subtitle: item.ultimoErro == null ? null : Text(item.ultimoErro!),
                      ),
                    if (problemas.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      FilledButton.tonalIcon(
                        onPressed: () => estado.tentarNovamente(visitaId),
                        icon: const Icon(Icons.refresh),
                        label: const Text('Tentar enviar novamente'),
                      ),
                    ],
                    const SizedBox(height: 8),
                    OutlinedButton.icon(
                      onPressed: () async {
                        final erro = await estado.reconciliar(visitaId);
                        if (context.mounted) {
                          ScaffoldMessenger.of(context).showSnackBar(
                            SnackBar(content: Text(erro ?? 'Situação conferida com o servidor.')),
                          );
                        }
                      },
                      icon: const Icon(Icons.cloud_sync_outlined),
                      label: const Text('Conferir com o servidor'),
                    ),
                  ],
                );
              },
            ),
          ],
        ),
      ),
    );
  }

  String _rotuloItem(ItemFila item) => switch (item.tipo) {
        TipoItemFila.checkIn => 'Local e hora da visita',
        TipoItemFila.foto => 'Foto ${item.payload['tipo']}',
        TipoItemFila.conclusao => 'Conclusão (${item.payload['tipo']})',
      };
}
