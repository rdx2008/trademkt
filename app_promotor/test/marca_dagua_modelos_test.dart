import 'package:app_promotor/modelos/roteiro.dart';
import 'package:app_promotor/modelos/usuario.dart';
import 'package:app_promotor/util/marca_dagua.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:image/image.dart' as img;

import 'apoio.dart';

void main() {
  group('marca d\'água', () {
    test('linhas trazem PDV, tipo, data/hora e coordenadas', () {
      final linhas = linhasMarcaDagua(
        pdvNome: 'Mercado São João',
        pdvId: 7,
        tipo: 'antes',
        lat: -29.168,
        lng: -51.179,
        gpsEm: DateTime(2026, 10, 31, 9, 5, 7),
      );

      expect(linhas, ['PDV 7 - Mercado Sao Joao', 'ANTES 31/10/2026 09:05:07', 'LAT -29.168000 LNG -51.179000']);
    });

    test('escurece a faixa inferior da foto e mantém o tamanho quando já é pequeno', () {
      final original = jpegDeTeste(largura: 640, altura: 480);
      final marcado = aplicarMarcaDagua(PedidoMarcaDagua(jpeg: original, linhas: const ['PDV 7 - Teste', 'ANTES 31/10/2026 09:05:07', 'LAT -29.168000 LNG -51.179000']));
      final imagem = img.decodeImage(marcado)!;

      expect(imagem.width, 640);
      expect(imagem.height, 480);
      final topo = imagem.getPixel(10, 10);
      final rodape = imagem.getPixel(10, 470);
      expect(topo.r, greaterThan(240), reason: 'o topo continua branco');
      expect(rodape.r, lessThan(120), reason: 'a faixa da marca é escura');
    });

    test('reduz fotos grandes para no máximo 1600 px no maior lado', () {
      final grande = jpegDeTeste(largura: 3000, altura: 2000);
      final imagem = img.decodeImage(aplicarMarcaDagua(PedidoMarcaDagua(jpeg: grande, linhas: const ['x'])))!;

      expect(imagem.width, 1600);
      expect(imagem.height, lessThanOrEqualTo(1067));
    });

    test('rejeita bytes que não são imagem', () {
      expect(() => aplicarMarcaDagua(PedidoMarcaDagua(jpeg: jpegDeTeste().sublist(0, 10), linhas: const [])), throwsArgumentError);
    });

    test('remove acentos para as fontes bitmap', () {
      expect(semAcentos('Gôndola Açúcar Não'), 'Gondola Acucar Nao');
    });
  });

  group('modelos da API', () {
    test('roteiro, visitas, PDV e SKUs são lidos do JSON do servidor', () {
      final roteiro = Roteiro.fromJson(roteiroJson(visitas: [visitaJson(), visitaJson(id: 2, status: 'em_andamento', checkIn: {'lat': -29.168})]));

      expect(roteiro.data, '2026-10-31');
      expect(roteiro.roteiroId, 55);
      expect(roteiro.supervisorNome, 'Supervisora');
      expect(roteiro.visitas.length, 2);
      expect(roteiro.visita('2')!.checkIn, {'lat': -29.168});
      expect(roteiro.visita('2')!.status, 'em_andamento');
      final pdv = roteiro.visitas.first.pdv;
      expect(pdv.lat, -29.168);
      expect(pdv.raioM, 200);
      expect(pdv.temCoordenadas, isTrue);
      final sku = roteiro.industria(roteiro.visitas.first)!.skus.first;
      expect(sku.codigo, 'ALG-25');
      expect(sku.embalagens.map((e) => e.tipo), ['unidade', 'caixa_master']);
      expect(roteiro.industria(roteiro.visitas.first.copiarCom(status: 'x')), isNotNull);
      expect(roteiro.visita('99'), isNull);
    });

    test('coordenadas podem vir como texto ou nulas', () {
      final pdv = Pdv.fromJson({'id': 1, 'nome': 'X', 'lat': '-29.168', 'lng': null});

      expect(pdv.lat, -29.168);
      expect(pdv.lng, isNull);
      expect(pdv.temCoordenadas, isFalse);
    });

    test('roteiro vai e volta em JSON (cache local)', () {
      final roteiro = Roteiro.fromJson(roteiroJson());
      final copia = Roteiro.fromJson(roteiro.toJson());

      expect(copia.toJson(), roteiro.toJson());
      expect(roteiro.comVisita(roteiro.visitas.first.copiarCom(status: 'realizada')).visitas.first.status, 'realizada');
    });

    test('rótulos e transições de status', () {
      expect(StatusVisita.rotulo('nao_executada'), 'Não executada');
      expect(StatusVisita.aceitaCheckIn('previsto'), isTrue);
      expect(StatusVisita.aceitaCheckIn('nao_executada'), isTrue);
      expect(StatusVisita.aceitaCheckIn('em_andamento'), isFalse);
      expect(StatusVisita.encerrada('em_atencao'), isTrue);
    });

    test('sessão com empresa e usuário vai e volta em JSON', () {
      final sessao = Sessao(
        empresa: Empresa.fromJson({'codigo': 'xyz', 'nome': 'XYZ', 'url': 'http://xyz.test', 'api': 'http://xyz.test/api/v1'}),
        token: '1|abc',
        usuario: Usuario.fromJson({
          'id': 5,
          'nome': 'João',
          'email': 'promotor@xyz.test',
          'perfil': 'promotor',
          'tipo_vinculo': 'freelancer',
          'agencia': {'id': 1, 'nome': 'Agência'},
          'empresa': {'codigo': 'xyz', 'nome': 'XYZ'},
        }),
      );
      final copia = Sessao.fromJson(sessao.toJson());

      expect(copia.token, '1|abc');
      expect(copia.usuario.freelancer, isTrue);
      expect(copia.usuario.agenciaNome, 'Agência');
      expect(copia.empresa.api, 'http://xyz.test/api/v1');
    });
  });
}
