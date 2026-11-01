/// Corpos enviados à API, exatamente como os contratos de `docs/FASE-3.md`.
///
/// Os payloads são serializados uma única vez, ao entrar na fila, e reenviados
/// byte a byte iguais: é isso que garante a idempotência por UUID no servidor.
library;

import '../util/datas.dart';
import 'execucao.dart';

/// `POST /api/v1/visitas/{visita}/check-in` (JSON).
class CheckInPayload {
  const CheckInPayload({required this.lat, required this.lng, required this.gpsEm, required this.aparelhoEm});

  final double lat;
  final double lng;
  final DateTime gpsEm;
  final DateTime aparelhoEm;

  factory CheckInPayload.deLocal(CheckInLocal c) =>
      CheckInPayload(lat: c.lat, lng: c.lng, gpsEm: c.gpsEm, aparelhoEm: c.aparelhoEm);

  /// `gps_simulado` é sempre `false`: posição simulada é bloqueada no aparelho
  /// e nunca chega a virar payload.
  Map<String, dynamic> toJson() => {
        'lat': lat,
        'lng': lng,
        'gps_em': formatarIso8601ComFuso(gpsEm),
        'aparelho_em': formatarIso8601ComFuso(aparelhoEm),
        'gps_simulado': false,
      };
}

/// `POST /api/v1/visitas/{visita}/fotos` (multipart).
class FotoPayload {
  const FotoPayload({
    required this.uuid,
    required this.tipo,
    required this.caminho,
    required this.lat,
    required this.lng,
    required this.gpsEm,
    required this.aparelhoEm,
  });

  final String uuid;
  final TipoFoto tipo;
  final String caminho;
  final double lat;
  final double lng;
  final DateTime gpsEm;
  final DateTime aparelhoEm;

  factory FotoPayload.deLocal(FotoLocal f) => FotoPayload(
        uuid: f.uuid,
        tipo: f.tipo,
        caminho: f.caminho,
        lat: f.lat,
        lng: f.lng,
        gpsEm: f.gpsEm,
        aparelhoEm: f.aparelhoEm,
      );

  /// Nome do campo de arquivo no multipart.
  static const campoArquivo = 'foto';

  /// Campos de texto do multipart. Em formulário, `false` vai como `"0"`
  /// (aceito pelas regras `boolean` e `declined` do Laravel).
  Map<String, String> campos() => {
        'uuid': uuid,
        'tipo': tipo.valor,
        'lat': lat.toString(),
        'lng': lng.toString(),
        'gps_em': formatarIso8601ComFuso(gpsEm),
        'aparelho_em': formatarIso8601ComFuso(aparelhoEm),
        'gps_simulado': '0',
      };

  String get nomeArquivo => '$uuid.jpg';

  /// Forma guardada na fila (os campos mais o caminho do arquivo).
  Map<String, dynamic> toJson() => {...campos(), 'caminho': caminho};

  factory FotoPayload.fromJson(Map<String, dynamic> json) => FotoPayload(
        uuid: json['uuid'] as String,
        tipo: TipoFoto.deValor(json['tipo'] as String),
        caminho: json['caminho'] as String,
        lat: double.parse(json['lat'].toString()),
        lng: double.parse(json['lng'].toString()),
        gpsEm: DateTime.parse(json['gps_em'] as String),
        aparelhoEm: DateTime.parse(json['aparelho_em'] as String),
      );
}

/// `POST /api/v1/visitas/{visita}/concluir` (JSON).
class ConclusaoPayload {
  const ConclusaoPayload({
    required this.uuid,
    required this.tipo,
    required this.finalizadaEm,
    required this.observacao,
    required this.itens,
  });

  final String uuid;
  final TipoConclusao tipo;
  final DateTime finalizadaEm;
  final String observacao;
  final List<ItemTrabalhado> itens;

  factory ConclusaoPayload.deLocal(ConclusaoLocal c) => ConclusaoPayload(
        uuid: c.uuid,
        tipo: c.tipo,
        finalizadaEm: c.finalizadaEm,
        observacao: c.observacao,
        itens: c.itens,
      );

  /// - normal: cada item leva `sku_id` e `estoque`;
  /// - ruptura: só `sku_id` (o servidor grava `ruptura`);
  /// - impedimento: `itens: []` e `observacao` obrigatória.
  Map<String, dynamic> toJson() => {
        'uuid': uuid,
        'tipo': tipo.valor,
        'finalizada_em': formatarIso8601ComFuso(finalizadaEm),
        'observacao': observacao,
        'itens': switch (tipo) {
          TipoConclusao.impedimento => const <Map<String, dynamic>>[],
          TipoConclusao.ruptura => itens.map((i) => {'sku_id': i.skuId}).toList(),
          TipoConclusao.normal => itens.map((i) => {'sku_id': i.skuId, 'estoque': i.estoque?.valor}).toList(),
        },
      };
}
