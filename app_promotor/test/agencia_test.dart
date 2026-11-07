/// Promotor que entra pela agência: o roteiro junta visitas de várias indústrias, cada uma
/// com a própria numeração, e a execução vai para a rota da indústria certa.
library;

import 'dart:io';

import 'package:app_promotor/api/api_promotor.dart';
import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/modelos/roteiro.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:app_promotor/util/datas.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  test('chave da visita e caminho na API', () {
    expect(chaveDe('', 7), '7');
    expect(chaveDe('gaboardi', 7), 'gaboardi:7');
    expect(ApiPromotor.caminhoDaVisita('7'), 'visitas/7');
    expect(ApiPromotor.caminhoDaVisita('gaboardi:7'), 'industrias/gaboardi/visitas/7');
  });

  test('roteiro pela agência: visitas de duas indústrias com o mesmo id não se misturam', () {
    final roteiro = Roteiro.fromJson({
      'data': '2026-10-31',
      'roteiro_id': null,
      'supervisor': {'id': 3, 'nome': 'Ana'},
      'visitas': [
        visitaJson(id: 1)..['industria'] = 'acme',
        (visitaJson(id: 1)..['industria'] = 'gaboardi')..['ordem'] = 2,
      ],
      'industrias': [
        {'id': 3, 'industria': 'acme', 'nome': 'Acme', 'skus': []},
        {'id': 3, 'industria': 'gaboardi', 'nome': 'Gaboardi', 'skus': []},
      ],
    });

    expect(roteiro.visitas.map((v) => v.chave), ['acme:1', 'gaboardi:1']);
    expect(roteiro.visita('gaboardi:1')!.ordem, 2);
    expect(roteiro.industria(roteiro.visitas.first)!.nome, 'Acme');
    expect(roteiro.industria(roteiro.visitas.last)!.nome, 'Gaboardi');
    final copia = Roteiro.fromJson(roteiro.toJson());
    expect(copia.visitas.last.chave, 'gaboardi:1', reason: 'a empresa sobrevive ao cache local');
  });

  test('foto de antes (check-in) de uma visita da agência vai para a rota da indústria', () async {
    final servidor = ServidorFalso();
    final hoje = formatarDataIso(DateTime.now());
    final pasta = await Directory.systemTemp.createTemp('app_promotor_agencia');
    final estado = EstadoApp(
      httpClient: servidor.cliente,
      armazenamento: ArmazenamentoMemoria(),
      repositorio: await repositorioEmMemoria(),
      localizacao: LocalizacaoFalsa(posicaoNoPdv()),
      fotos: ServicoFotos(pastaBase: () async => pasta),
      urlCentral: 'http://central.test',
    );
    servidor.responder('GET /api/v1/empresas/sul', 200, {'codigo': 'sul', 'nome': 'Agência Sul', 'url': 'http://sul.test', 'api': 'http://sul.test/api/v1', 'api_versao': '1'});
    servidor.responder('POST /api/v1/login', 200, {
      'token': '1|sul',
      'usuario': {'id': 2, 'nome': 'João', 'email': 'joao@sul.test', 'perfil': 'promotor', 'tipo_vinculo': 'clt', 'agencia': {'id': 1, 'nome': 'Sul'}, 'empresa': {'codigo': 'sul', 'nome': 'Agência Sul'}},
    });
    servidor.responder('GET /api/v1/roteiro', 200, roteiroJson(data: hoje, visitas: [visitaJson(id: 1)..['industria'] = 'gaboardi']));
    servidor.responder('POST /api/v1/industrias/gaboardi/visitas/1/fotos', 201, {'foto': {'id': 1}, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179}});

    await estado.carregar();
    await estado.descobrirEmpresa('sul');
    await estado.entrar(email: 'joao@sul.test', senha: 'senha1234');
    await estado.atualizarRoteiro();
    final visita = estado.roteiro!.visitas.single;
    expect(visita.chave, 'gaboardi:1');

    expect(await abrirVisita(estado, visita), isNull);
    for (var i = 0; i < 100 && estado.pendentes > 0; i++) {
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
    expect(servidor.das('fotos').single.caminho, '/api/v1/industrias/gaboardi/visitas/1/fotos');
    expect(estado.execucao('gaboardi:1').checkIn!.enviado, isTrue);
    await estado.repositorio.fechar();
    await pasta.delete(recursive: true);
  });
}
