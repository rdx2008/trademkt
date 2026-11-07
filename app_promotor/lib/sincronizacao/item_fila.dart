/// Itens da fila persistente de sincronização.
library;

import '../modelos/execucao.dart';

enum TipoItemFila {
  checkIn('check_in'),
  foto('foto'),
  conclusao('conclusao');

  const TipoItemFila(this.valor);

  final String valor;

  static TipoItemFila deValor(String valor) => values.firstWhere((t) => t.valor == valor);
}

enum EstadoItemFila {
  /// Aguardando envio (ou reenvio após falha de rede).
  pendente('pendente'),

  /// Confirmado pelo servidor.
  enviado('enviado'),

  /// Recusado pelo servidor (422/404/403); exige ação do promotor ou do supervisor.
  erro('erro'),

  /// O servidor já tem outra versão (409) e a reconciliação não resolveu.
  conflito('conflito');

  const EstadoItemFila(this.valor);

  final String valor;

  static EstadoItemFila deValor(String valor) => values.firstWhere((t) => t.valor == valor);
}

/// Prioridade de envio dentro de uma mesma visita:
/// check-in → fotos antes → fotos de ruptura → fotos depois → conclusão.
int prioridadeFila(TipoItemFila tipo, {TipoFoto? tipoFoto}) => switch (tipo) {
      TipoItemFila.checkIn => 0,
      TipoItemFila.foto => switch (tipoFoto) {
          TipoFoto.antes => 1,
          TipoFoto.impedimento => 1,
          TipoFoto.ruptura => 2,
          TipoFoto.depois => 3,
          null => 3,
        },
      TipoItemFila.conclusao => 4,
    };

class ItemFila {
  const ItemFila({
    this.id,
    required this.visitaId,
    required this.tipo,
    required this.chave,
    required this.payload,
    required this.prioridade,
    required this.criadoEm,
    this.arquivo,
    this.tentativas = 0,
    this.ultimoErro,
    this.estado = EstadoItemFila.pendente,
  });

  final int? id;

  /// Chave da visita no aparelho (ver `Visita.chave`).
  final String visitaId;
  final TipoItemFila tipo;

  /// UUID da foto/conclusão ou `check-in:<visita>`; identifica o item para reenvio idempotente.
  final String chave;

  /// Corpo serializado na hora em que o item entrou na fila; nunca muda depois.
  final Map<String, dynamic> payload;
  final int prioridade;
  final DateTime criadoEm;

  /// Caminho do arquivo da foto, quando houver.
  final String? arquivo;
  final int tentativas;
  final String? ultimoErro;
  final EstadoItemFila estado;

  ItemFila copiarCom({int? id, int? tentativas, String? ultimoErro, EstadoItemFila? estado, bool limparErro = false}) =>
      ItemFila(
        id: id ?? this.id,
        visitaId: visitaId,
        tipo: tipo,
        chave: chave,
        payload: payload,
        prioridade: prioridade,
        criadoEm: criadoEm,
        arquivo: arquivo,
        tentativas: tentativas ?? this.tentativas,
        ultimoErro: limparErro ? null : (ultimoErro ?? this.ultimoErro),
        estado: estado ?? this.estado,
      );

  static String chaveCheckIn(String visitaId) => 'check-in:$visitaId';
}

/// Ordena a fila para envio: as visitas na ordem em que começaram a ser
/// executadas e, dentro de cada visita, por prioridade e hora de criação.
List<ItemFila> ordenarFila(Iterable<ItemFila> itens) {
  final lista = itens.toList();
  final inicioPorVisita = <String, DateTime>{};
  for (final item in lista) {
    final atual = inicioPorVisita[item.visitaId];
    if (atual == null || item.criadoEm.isBefore(atual)) {
      inicioPorVisita[item.visitaId] = item.criadoEm;
    }
  }
  lista.sort((a, b) {
    if (a.visitaId != b.visitaId) {
      final porInicio = inicioPorVisita[a.visitaId]!.compareTo(inicioPorVisita[b.visitaId]!);

      return porInicio != 0 ? porInicio : a.visitaId.compareTo(b.visitaId);
    }
    if (a.prioridade != b.prioridade) {
      return a.prioridade.compareTo(b.prioridade);
    }
    final porCriacao = a.criadoEm.compareTo(b.criadoEm);

    return porCriacao != 0 ? porCriacao : (a.id ?? 0).compareTo(b.id ?? 0);
  });

  return lista;
}
