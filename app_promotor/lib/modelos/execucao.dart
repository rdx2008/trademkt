/// Estado local da execução de uma visita: o que o promotor já fez no aparelho,
/// enviado ou não. É gravado no banco local e sobrevive ao fechamento do app.
library;

import '../util/datas.dart';

enum TipoFoto {
  antes('antes'),
  depois('depois'),
  ruptura('ruptura');

  const TipoFoto(this.valor);

  final String valor;

  static TipoFoto deValor(String valor) => values.firstWhere((t) => t.valor == valor);

  String get rotulo => switch (this) {
        TipoFoto.antes => 'Antes',
        TipoFoto.depois => 'Depois',
        TipoFoto.ruptura => 'Ruptura',
      };
}

enum SituacaoEstoque {
  temEstoque('tem_estoque'),
  somenteGondola('somente_gondola');

  const SituacaoEstoque(this.valor);

  final String valor;

  static SituacaoEstoque? deValor(String? valor) {
    for (final s in values) {
      if (s.valor == valor) {
        return s;
      }
    }

    return null;
  }

  String get rotulo => switch (this) {
        SituacaoEstoque.temEstoque => 'Tem estoque',
        SituacaoEstoque.somenteGondola => 'Estoque somente na gôndola',
      };
}

enum TipoConclusao {
  normal('normal'),
  ruptura('ruptura'),
  impedimento('impedimento');

  const TipoConclusao(this.valor);

  final String valor;

  static TipoConclusao deValor(String valor) => values.firstWhere((t) => t.valor == valor);

  String get rotulo => switch (this) {
        TipoConclusao.normal => 'Visita concluída',
        TipoConclusao.ruptura => 'Ruptura registrada',
        TipoConclusao.impedimento => 'Impedimento de acesso',
      };
}

class CheckInLocal {
  const CheckInLocal({
    required this.lat,
    required this.lng,
    required this.gpsEm,
    required this.aparelhoEm,
    required this.distanciaM,
    this.enviado = false,
  });

  final double lat;
  final double lng;
  final DateTime gpsEm;
  final DateTime aparelhoEm;
  final double distanciaM;
  final bool enviado;

  CheckInLocal copiarCom({bool? enviado}) => CheckInLocal(
        lat: lat,
        lng: lng,
        gpsEm: gpsEm,
        aparelhoEm: aparelhoEm,
        distanciaM: distanciaM,
        enviado: enviado ?? this.enviado,
      );

  factory CheckInLocal.fromJson(Map<String, dynamic> json) => CheckInLocal(
        lat: (json['lat'] as num).toDouble(),
        lng: (json['lng'] as num).toDouble(),
        gpsEm: DateTime.parse(json['gps_em'] as String),
        aparelhoEm: DateTime.parse(json['aparelho_em'] as String),
        distanciaM: (json['distancia_m'] as num?)?.toDouble() ?? 0,
        enviado: json['enviado'] as bool? ?? false,
      );

  Map<String, dynamic> toJson() => {
        'lat': lat,
        'lng': lng,
        'gps_em': formatarIso8601ComFuso(gpsEm),
        'aparelho_em': formatarIso8601ComFuso(aparelhoEm),
        'distancia_m': distanciaM,
        'enviado': enviado,
      };
}

class FotoLocal {
  const FotoLocal({
    required this.uuid,
    required this.tipo,
    required this.caminho,
    required this.lat,
    required this.lng,
    required this.gpsEm,
    required this.aparelhoEm,
    this.enviada = false,
  });

  final String uuid;
  final TipoFoto tipo;

  /// Caminho do JPEG com marca d'água no armazenamento privado do app.
  final String caminho;
  final double lat;
  final double lng;
  final DateTime gpsEm;
  final DateTime aparelhoEm;
  final bool enviada;

  FotoLocal copiarCom({bool? enviada}) => FotoLocal(
        uuid: uuid,
        tipo: tipo,
        caminho: caminho,
        lat: lat,
        lng: lng,
        gpsEm: gpsEm,
        aparelhoEm: aparelhoEm,
        enviada: enviada ?? this.enviada,
      );

  factory FotoLocal.fromJson(Map<String, dynamic> json) => FotoLocal(
        uuid: json['uuid'] as String,
        tipo: TipoFoto.deValor(json['tipo'] as String),
        caminho: json['caminho'] as String,
        lat: (json['lat'] as num).toDouble(),
        lng: (json['lng'] as num).toDouble(),
        gpsEm: DateTime.parse(json['gps_em'] as String),
        aparelhoEm: DateTime.parse(json['aparelho_em'] as String),
        enviada: json['enviada'] as bool? ?? false,
      );

  Map<String, dynamic> toJson() => {
        'uuid': uuid,
        'tipo': tipo.valor,
        'caminho': caminho,
        'lat': lat,
        'lng': lng,
        'gps_em': formatarIso8601ComFuso(gpsEm),
        'aparelho_em': formatarIso8601ComFuso(aparelhoEm),
        'enviada': enviada,
      };
}

class ItemTrabalhado {
  const ItemTrabalhado({required this.skuId, this.estoque});

  final int skuId;
  final SituacaoEstoque? estoque;

  ItemTrabalhado copiarCom({SituacaoEstoque? estoque}) => ItemTrabalhado(skuId: skuId, estoque: estoque ?? this.estoque);

  factory ItemTrabalhado.fromJson(Map<String, dynamic> json) =>
      ItemTrabalhado(skuId: json['sku_id'] as int, estoque: SituacaoEstoque.deValor(json['estoque'] as String?));

  Map<String, dynamic> toJson() => {'sku_id': skuId, 'estoque': estoque?.valor};
}

class ConclusaoLocal {
  const ConclusaoLocal({
    required this.uuid,
    required this.tipo,
    required this.finalizadaEm,
    required this.observacao,
    required this.itens,
    this.enviada = false,
  });

  final String uuid;
  final TipoConclusao tipo;
  final DateTime finalizadaEm;
  final String observacao;
  final List<ItemTrabalhado> itens;
  final bool enviada;

  ConclusaoLocal copiarCom({bool? enviada}) => ConclusaoLocal(
        uuid: uuid,
        tipo: tipo,
        finalizadaEm: finalizadaEm,
        observacao: observacao,
        itens: itens,
        enviada: enviada ?? this.enviada,
      );

  factory ConclusaoLocal.fromJson(Map<String, dynamic> json) => ConclusaoLocal(
        uuid: json['uuid'] as String,
        tipo: TipoConclusao.deValor(json['tipo'] as String),
        finalizadaEm: DateTime.parse(json['finalizada_em'] as String),
        observacao: json['observacao'] as String? ?? '',
        itens: ((json['itens'] as List?) ?? const [])
            .map((e) => ItemTrabalhado.fromJson(e as Map<String, dynamic>))
            .toList(),
        enviada: json['enviada'] as bool? ?? false,
      );

  Map<String, dynamic> toJson() => {
        'uuid': uuid,
        'tipo': tipo.valor,
        'finalizada_em': formatarIso8601ComFuso(finalizadaEm),
        'observacao': observacao,
        'itens': itens.map((e) => e.toJson()).toList(),
        'enviada': enviada,
      };
}

/// Tudo que o promotor fez numa visita neste aparelho.
class ExecucaoVisita {
  const ExecucaoVisita({
    required this.visitaId,
    this.checkIn,
    this.fotos = const [],
    this.itens = const [],
    this.conclusao,
    this.statusServidor,
  });

  final int visitaId;
  final CheckInLocal? checkIn;
  final List<FotoLocal> fotos;

  /// SKUs selecionados durante o trabalho (antes da conclusão).
  final List<ItemTrabalhado> itens;
  final ConclusaoLocal? conclusao;

  /// Último status confirmado pelo servidor (reconciliação).
  final String? statusServidor;

  List<FotoLocal> fotosDoTipo(TipoFoto tipo) => fotos.where((f) => f.tipo == tipo).toList();

  bool get temCheckIn => checkIn != null;
  bool get temFotoAntes => fotos.any((f) => f.tipo == TipoFoto.antes);
  bool get temFotoDepois => fotos.any((f) => f.tipo == TipoFoto.depois);
  bool get temFotoRuptura => fotos.any((f) => f.tipo == TipoFoto.ruptura);
  bool get concluida => conclusao != null;

  /// Há algo gravado no aparelho que ainda não chegou ao servidor?
  bool get temPendencias =>
      (checkIn != null && !checkIn!.enviado) ||
      fotos.any((f) => !f.enviada) ||
      (conclusao != null && !conclusao!.enviada);

  /// Última hora de GPS registrada (check-in ou foto), para validar a sequência.
  DateTime? get ultimoHorario {
    DateTime? ultimo = checkIn?.gpsEm;
    for (final foto in fotos) {
      if (ultimo == null || foto.gpsEm.isAfter(ultimo)) {
        ultimo = foto.gpsEm;
      }
    }

    return ultimo;
  }

  ExecucaoVisita copiarCom({
    CheckInLocal? checkIn,
    List<FotoLocal>? fotos,
    List<ItemTrabalhado>? itens,
    ConclusaoLocal? conclusao,
    String? statusServidor,
  }) =>
      ExecucaoVisita(
        visitaId: visitaId,
        checkIn: checkIn ?? this.checkIn,
        fotos: fotos ?? this.fotos,
        itens: itens ?? this.itens,
        conclusao: conclusao ?? this.conclusao,
        statusServidor: statusServidor ?? this.statusServidor,
      );

  factory ExecucaoVisita.fromJson(Map<String, dynamic> json) => ExecucaoVisita(
        visitaId: json['visita_id'] as int,
        checkIn: json['check_in'] == null ? null : CheckInLocal.fromJson(json['check_in'] as Map<String, dynamic>),
        fotos: ((json['fotos'] as List?) ?? const []).map((e) => FotoLocal.fromJson(e as Map<String, dynamic>)).toList(),
        itens: ((json['itens'] as List?) ?? const [])
            .map((e) => ItemTrabalhado.fromJson(e as Map<String, dynamic>))
            .toList(),
        conclusao:
            json['conclusao'] == null ? null : ConclusaoLocal.fromJson(json['conclusao'] as Map<String, dynamic>),
        statusServidor: json['status_servidor'] as String?,
      );

  Map<String, dynamic> toJson() => {
        'visita_id': visitaId,
        'check_in': checkIn?.toJson(),
        'fotos': fotos.map((f) => f.toJson()).toList(),
        'itens': itens.map((i) => i.toJson()).toList(),
        'conclusao': conclusao?.toJson(),
        'status_servidor': statusServidor,
      };
}
