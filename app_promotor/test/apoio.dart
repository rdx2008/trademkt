/// Fixtures compartilhadas pelos testes, no formato devolvido pela API Laravel.
library;

import 'dart:convert';
import 'dart:typed_data';

import 'package:app_promotor/dados/repositorio_local.dart';
import 'package:app_promotor/dominio/regras_visita.dart';
import 'package:app_promotor/modelos/execucao.dart';
import 'package:app_promotor/modelos/roteiro.dart';
import 'package:app_promotor/servicos/localizacao.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image/image.dart' as img;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

/// Mesmo PDV dos testes PHP (`CheckInTest`): -29.168, -51.179, raio 200 m.
Map<String, dynamic> pdvJson({double? lat = -29.168, double? lng = -51.179, int raio = 200}) => {
      'id': 7,
      'nome': 'Mercado Centro',
      'cnpj': '12.345.678/0001-90',
      'endereco': 'Rua Sinimbu, 100 - Caxias do Sul/RS',
      'lat': lat,
      'lng': lng,
      'raio_m': raio,
    };

Map<String, dynamic> visitaJson({int id = 1, String status = 'previsto', Map<String, dynamic>? checkIn, Map<String, dynamic>? pdv}) =>
    {
      'id': id,
      'ordem': id,
      'origem': 'contrato',
      'status': status,
      'check_in': checkIn,
      'industria_id': 3,
      'pdv': pdv ?? pdvJson(),
    };

Map<String, dynamic> roteiroJson({List<Map<String, dynamic>>? visitas, String data = '2026-10-31'}) => {
      'data': data,
      'roteiro_id': 55,
      'supervisor': {'id': 9, 'nome': 'Supervisora'},
      'visitas': visitas ?? [visitaJson()],
      'industrias': [
        {
          'id': 3,
          'nome': 'Marca Aurora',
          'skus': [
            {
              'id': 10,
              'codigo': 'ALG-25',
              'descricao': 'Alvejante 2 L',
              'categoria': 'Limpeza',
              'foto_url': null,
              'embalagens': [
                {'tipo': 'unidade', 'quantidade': 1, 'gtin': '7891000315507'},
                {'tipo': 'caixa_master', 'quantidade': 12, 'gtin': '17891000315504'},
              ],
            },
            {'id': 11, 'codigo': 'DET-500', 'descricao': 'Detergente 500 ml', 'categoria': 'Limpeza', 'foto_url': null, 'embalagens': []},
          ],
        },
      ],
    };

Visita visitaPadrao({String status = 'previsto', int raio = 200}) =>
    Visita.fromJson(visitaJson(status: status, pdv: pdvJson(raio: raio)));

/// Posição em cima do PDV, com hora de GPS controlada.
Posicao posicaoNoPdv({DateTime? gpsEm, double lat = -29.168, double lng = -51.179, bool simulada = false}) =>
    Posicao(lat: lat, lng: lng, gpsEm: gpsEm ?? DateTime.now(), simulada: simulada);

CheckInLocal checkInPadrao({DateTime? gpsEm, bool enviado = false}) => CheckInLocal(
      lat: -29.168,
      lng: -51.179,
      gpsEm: gpsEm ?? DateTime(2026, 10, 31, 9, 0, 0),
      aparelhoEm: gpsEm ?? DateTime(2026, 10, 31, 9, 0, 0),
      distanciaM: 0,
      enviado: enviado,
    );

FotoLocal fotoPadrao(TipoFoto tipo, {String? uuid, DateTime? gpsEm, bool enviada = false}) => FotoLocal(
      uuid: uuid ?? 'uuid-${tipo.valor}',
      tipo: tipo,
      caminho: '/fotos/${uuid ?? tipo.valor}.jpg',
      lat: -29.168,
      lng: -51.179,
      gpsEm: gpsEm ?? DateTime(2026, 10, 31, 9, 5, 0),
      aparelhoEm: gpsEm ?? DateTime(2026, 10, 31, 9, 5, 0),
      enviada: enviada,
    );

/// Banco SQLite em memória (sqflite_common_ffi).
Future<RepositorioLocal> repositorioEmMemoria() {
  sqfliteFfiInit();

  return RepositorioLocal.abrir(fabrica: databaseFactoryFfi, caminho: inMemoryDatabasePath);
}

/// JPEG pequeno gerado em memória, para fotos nos testes.
Uint8List jpegDeTeste({int largura = 400, int altura = 300}) {
  final imagem = img.Image(width: largura, height: altura);
  img.fill(imagem, color: img.ColorRgb8(255, 255, 255));

  return Uint8List.fromList(img.encodeJpg(imagem));
}

/// Requisição capturada pelo [ServidorFalso].
class Chamada {
  Chamada(this.metodo, this.caminho, this.corpo, this.cabecalhos);

  final String metodo;
  final String caminho;
  final String corpo;
  final Map<String, String> cabecalhos;

  Map<String, dynamic> get json => jsonDecode(corpo) as Map<String, dynamic>;
}

/// Servidor HTTP falso programável, por método+caminho.
class ServidorFalso {
  final List<Chamada> chamadas = [];
  final Map<String, http.Response Function(Chamada chamada)> respostas = {};
  bool offline = false;

  http.Client get cliente => MockClient((pedido) async {
        if (offline) {
          throw http.ClientException('Falha de rede simulada', pedido.url);
        }
        final chamada = Chamada(pedido.method, pedido.url.path, utf8.decode(pedido.bodyBytes, allowMalformed: true), pedido.headers);
        chamadas.add(chamada);
        final resposta = respostas['${pedido.method} ${pedido.url.path}'] ?? respostas['${pedido.method} *'];
        if (resposta == null) {
          return http.Response(jsonEncode({'message': 'Rota não programada: ${pedido.method} ${pedido.url.path}'}), 500);
        }

        return resposta(chamada);
      });

  void responder(String chave, int status, Map<String, dynamic> corpo) =>
      respostas[chave] = (_) => http.Response(jsonEncode(corpo), status, headers: {'content-type': 'application/json'});

  List<Chamada> das(String caminhoParcial) => chamadas.where((c) => c.caminho.contains(caminhoParcial)).toList();
}

/// GPS falso para o estado do app.
class LocalizacaoFalsa implements ServicoLocalizacao {
  LocalizacaoFalsa(this.posicao);

  Posicao posicao;
  ErroLocalizacao? erro;

  @override
  Future<Posicao> obterPosicao() async {
    if (erro != null) {
      throw erro!;
    }

    return posicao;
  }
}
