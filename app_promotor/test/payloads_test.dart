/// Os payloads precisam bater com os contratos de `docs/FASE-3.md` e com o
/// que os testes PHP (`CheckInTest`, `OperacaoTest`) enviam.
library;

import 'dart:convert';

import 'package:app_promotor/modelos/execucao.dart';
import 'package:app_promotor/modelos/payloads.dart';
import 'package:app_promotor/util/datas.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  final hora = DateTime(2026, 10, 31, 9, 0, 0);
  final horaIso = formatarIso8601ComFuso(hora);

  group('CheckInPayload', () {
    test('tem lat, lng, gps_em, aparelho_em (ISO com fuso) e gps_simulado=false', () {
      final json = CheckInPayload.deLocal(checkInPadrao(gpsEm: hora)).toJson();

      expect(json.keys.toSet(), {'lat', 'lng', 'gps_em', 'aparelho_em', 'gps_simulado'});
      expect(json['lat'], -29.168);
      expect(json['lng'], -51.179);
      expect(json['gps_em'], horaIso);
      expect(json['aparelho_em'], horaIso);
      expect(json['gps_simulado'], isFalse);
      expect(json['lat'], isA<double>());
      // Serializável sem perdas (vai para a fila como texto).
      expect(jsonDecode(jsonEncode(json)), json);
    });
  });

  group('FotoPayload', () {
    test('campos multipart: uuid, tipo, lat, lng, gps_em, aparelho_em e gps_simulado="0"', () {
      final foto = fotoPadrao(TipoFoto.antes, uuid: '1d0b0d4e-4bd9-4d3c-9b1a-8a3a8f6f3e21', gpsEm: hora);
      final payload = FotoPayload.deLocal(foto);
      final campos = payload.campos();

      expect(campos.keys.toSet(), {'uuid', 'tipo', 'lat', 'lng', 'gps_em', 'aparelho_em', 'gps_simulado'});
      expect(campos['uuid'], '1d0b0d4e-4bd9-4d3c-9b1a-8a3a8f6f3e21');
      expect(campos['tipo'], 'antes');
      expect(campos['lat'], '-29.168');
      expect(campos['lng'], '-51.179');
      expect(campos['gps_em'], horaIso);
      expect(campos['gps_simulado'], '0');
      expect(FotoPayload.campoArquivo, 'foto');
      expect(payload.nomeArquivo, '1d0b0d4e-4bd9-4d3c-9b1a-8a3a8f6f3e21.jpg');
    });

    test('tipos aceitos pela API: antes, depois e ruptura', () {
      expect(TipoFoto.values.map((t) => t.valor), ['antes', 'depois', 'ruptura']);
    });

    test('vai e volta da fila preservando o caminho do arquivo', () {
      final payload = FotoPayload.deLocal(fotoPadrao(TipoFoto.depois, gpsEm: hora));
      final json = jsonDecode(jsonEncode(payload.toJson())) as Map<String, dynamic>;
      final restaurado = FotoPayload.fromJson(json);

      expect(restaurado.campos(), payload.campos());
      expect(restaurado.caminho, payload.caminho);
    });
  });

  group('ConclusaoPayload', () {
    test('normal: uuid, tipo, finalizada_em, observacao e itens com sku_id e estoque', () {
      final json = ConclusaoPayload(
        uuid: 'c1',
        tipo: TipoConclusao.normal,
        finalizadaEm: hora,
        observacao: '',
        itens: const [
          ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.temEstoque),
          ItemTrabalhado(skuId: 11, estoque: SituacaoEstoque.somenteGondola),
        ],
      ).toJson();

      expect(json.keys.toSet(), {'uuid', 'tipo', 'finalizada_em', 'observacao', 'itens'});
      expect(json['tipo'], 'normal');
      expect(json['finalizada_em'], horaIso);
      expect(json['observacao'], '');
      expect(json['itens'], [
        {'sku_id': 10, 'estoque': 'tem_estoque'},
        {'sku_id': 11, 'estoque': 'somente_gondola'},
      ]);
    });

    test('ruptura: itens só com sku_id (o servidor grava estoque=ruptura)', () {
      final json = ConclusaoPayload(
        uuid: 'c2',
        tipo: TipoConclusao.ruptura,
        finalizadaEm: hora,
        observacao: 'Gôndola vazia',
        itens: const [ItemTrabalhado(skuId: 10)],
      ).toJson();

      expect(json['tipo'], 'ruptura');
      expect(json['itens'], [
        {'sku_id': 10},
      ]);
    });

    test('impedimento: observacao obrigatória e itens vazio mesmo se houver seleção', () {
      final json = ConclusaoPayload(
        uuid: 'c3',
        tipo: TipoConclusao.impedimento,
        finalizadaEm: hora,
        observacao: 'Acesso ao estoque impedido pelo responsável.',
        itens: const [ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.temEstoque)],
      ).toJson();

      expect(json['tipo'], 'impedimento');
      expect(json['observacao'], 'Acesso ao estoque impedido pelo responsável.');
      expect(json['itens'], isEmpty);
    });

    test('valores de estoque aceitos pela API', () {
      expect(SituacaoEstoque.values.map((s) => s.valor), ['tem_estoque', 'somente_gondola']);
      expect(TipoConclusao.values.map((t) => t.valor), ['normal', 'ruptura', 'impedimento']);
    });
  });

  group('ExecucaoVisita', () {
    test('vai e volta em JSON preservando horários, fotos e conclusão', () {
      final execucao = ExecucaoVisita(
        visitaId: 1,
        checkIn: checkInPadrao(gpsEm: hora, enviado: true),
        fotos: [fotoPadrao(TipoFoto.antes, gpsEm: hora), fotoPadrao(TipoFoto.depois, gpsEm: hora, enviada: true)],
        itens: const [ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.temEstoque)],
        conclusao: ConclusaoLocal(
          uuid: 'c1',
          tipo: TipoConclusao.normal,
          finalizadaEm: hora,
          observacao: '',
          itens: const [ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.temEstoque)],
        ),
        statusServidor: 'em_andamento',
      );
      final restaurada = ExecucaoVisita.fromJson(jsonDecode(jsonEncode(execucao.toJson())) as Map<String, dynamic>);

      expect(restaurada.checkIn!.enviado, isTrue);
      expect(restaurada.checkIn!.gpsEm.isAtSameMomentAs(hora), isTrue);
      expect(restaurada.fotos.map((f) => f.uuid), ['uuid-antes', 'uuid-depois']);
      expect(restaurada.fotos[1].enviada, isTrue);
      expect(restaurada.itens.single.estoque, SituacaoEstoque.temEstoque);
      expect(restaurada.conclusao!.tipo, TipoConclusao.normal);
      expect(restaurada.statusServidor, 'em_andamento');
      expect(restaurada.temPendencias, isTrue);
    });
  });
}
