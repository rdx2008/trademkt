import 'package:app_promotor/util/datas.dart';
import 'package:app_promotor/util/geo.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('formatarIso8601ComFuso', () {
    test('usa o formato Y-m-d\\TH:i:sP exigido pela API, sem fração de segundo', () {
      final data = DateTime(2026, 10, 31, 9, 5, 7, 123, 456);
      final texto = formatarIso8601ComFuso(data);

      expect(texto, matches(RegExp(r'^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+-]\d{2}:\d{2}$')));
      expect(texto, startsWith('2026-10-31T09:05:07'));
      expect(texto, isNot(contains('Z')));
      expect(texto, isNot(contains('.')));
    });

    test('o deslocamento é o do fuso do aparelho e o instante é preservado', () {
      final data = DateTime(2026, 10, 31, 22, 30, 0);
      final texto = formatarIso8601ComFuso(data);
      final deslocamento = data.timeZoneOffset;
      final sinal = deslocamento.isNegative ? '-' : '+';
      final horas = deslocamento.abs().inHours.toString().padLeft(2, '0');
      final minutos = (deslocamento.abs().inMinutes % 60).toString().padLeft(2, '0');

      expect(texto, endsWith('$sinal$horas:$minutos'));
      expect(DateTime.parse(texto).isAtSameMomentAs(data), isTrue);
    });

    test('data em UTC é convertida para o horário local antes de formatar', () {
      final utc = DateTime.utc(2026, 11, 1, 1, 30, 0);
      final texto = formatarIso8601ComFuso(utc);

      expect(DateTime.parse(texto).isAtSameMomentAs(utc), isTrue);
      expect(texto, startsWith(formatarDataIso(utc.toLocal())));
    });
  });

  test('formatos de exibição em português', () {
    final data = DateTime(2026, 10, 31, 9, 5, 7);

    expect(formatarDataIso(data), '2026-10-31');
    expect(formatarDataBr(data), '31/10/2026');
    expect(formatarDataHoraBr(data), '31/10/2026 09:05');
    expect(formatarDataHoraSegundosBr(data), '31/10/2026 09:05:07');
    expect(somenteData(data), DateTime(2026, 10, 31));
    expect(interpretarIso('2026-10-31T09:05:07-03:00'), isNotNull);
    expect(interpretarIso('31/10/2026'), isNull);
  });

  group('distanciaMetros (Haversine, mesmos casos do CheckInTest em PHP)', () {
    const latPdv = -29.168;
    const lngPdv = -51.179;

    test('no mesmo ponto a distância é zero', () {
      expect(distanciaMetros(latPdv, lngPdv, latPdv, lngPdv), 0);
    });

    test('-29.169 fica dentro de 200 m (aceito pelo servidor)', () {
      final d = distanciaMetros(-29.169, lngPdv, latPdv, lngPdv);

      expect(d, greaterThan(100));
      expect(d, lessThan(200));
    });

    test('-29.178 fica fora de 200 m (recusado pelo servidor)', () {
      expect(distanciaMetros(-29.178, lngPdv, latPdv, lngPdv), greaterThan(1000));
    });

    test('-29.1696 fica fora de 150 m mas dentro de 200 m (raio específico do PDV)', () {
      final d = distanciaMetros(-29.1696, lngPdv, latPdv, lngPdv);

      expect(d, greaterThan(150));
      expect(d, lessThan(200));
    });

    test('coordenadas válidas', () {
      expect(coordenadasValidas(-29.168, -51.179), isTrue);
      expect(coordenadasValidas(91, 0), isFalse);
      expect(coordenadasValidas(0, -181), isFalse);
    });
  });
}
