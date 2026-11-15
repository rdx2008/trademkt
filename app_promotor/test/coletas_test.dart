/// Modelos de coleta (fase 10): leitura do roteiro, validação, fila offline e a tela do formulário.
library;

import 'dart:io';

import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/modelos/coleta.dart';
import 'package:app_promotor/modelos/roteiro.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:app_promotor/sincronizacao/item_fila.dart';
import 'package:app_promotor/telas/tela_coleta.dart';
import 'package:app_promotor/util/datas.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

Map<String, dynamic> modeloJson({int id = 4, List<int> redes = const [], int versao = 1}) => {
      'id': id,
      'nome': 'Validade',
      'tipo': 'validade',
      'versao': versao,
      'redes': redes,
      'campos': [
        {'chave': 'validade', 'rotulo': 'Validade', 'tipo': 'data', 'obrigatorio': true, 'por_sku': true, 'opcoes': []},
        {'chave': 'quantidade', 'rotulo': 'Quantidade', 'tipo': 'quantidade', 'obrigatorio': false, 'por_sku': true, 'opcoes': []},
        {'chave': 'observacao', 'rotulo': 'Observação', 'tipo': 'texto', 'obrigatorio': false, 'por_sku': false, 'opcoes': []},
        {'chave': 'foto_etiqueta', 'rotulo': 'Foto', 'tipo': 'foto', 'obrigatorio': true, 'por_sku': false, 'opcoes': []},
      ],
    };

Map<String, dynamic> roteiroComModelos(String data, List<Map<String, dynamic>> modelos) {
  final roteiro = roteiroJson(data: data);
  (roteiro['industrias'] as List).first['modelos'] = modelos;
  ((roteiro['visitas'] as List).first['pdv'] as Map)['rede_id'] = 5;

  return roteiro;
}

void main() {
  group('regras do formulário', () {
    const preco = CampoColeta(chave: 'preco', rotulo: 'Preço', tipo: 'preco', obrigatorio: true);
    const quantidade = CampoColeta(chave: 'qtd', rotulo: 'Qtd', tipo: 'quantidade');
    const escolha = CampoColeta(chave: 'acao', rotulo: 'Ação', tipo: 'escolha', opcoes: ['Trocar', 'Retirar']);

    test('valida e normaliza por tipo', () {
      expect(validarCampo(preco, '4,5').valor, 4.5);
      expect(validarCampo(preco, '').erro, contains('Responda'));
      expect(validarCampo(preco, '-1').erro, contains('inválido'));
      expect(validarCampo(quantidade, '3').valor, 3);
      expect(validarCampo(quantidade, '1.5').erro, contains('inválido'));
      expect(validarCampo(quantidade, '').erro, isNull, reason: 'opcional vazio é aceito');
      expect(validarCampo(escolha, 'Trocar').valor, 'Trocar');
      expect(validarCampo(escolha, 'Outra').erro, contains('inválido'));
      expect(validarCampo(const CampoColeta(chave: 'd', rotulo: 'D', tipo: 'data'), '2026-12-31').valor, '2026-12-31');
      expect(validarCampo(const CampoColeta(chave: 'd', rotulo: 'D', tipo: 'data'), '31/12/2026').erro, contains('inválido'));
      expect(validarCampo(const CampoColeta(chave: 's', rotulo: 'S', tipo: 'sim_nao'), 'sim').valor, true);
    });

    test('valida um bloco e junta os erros', () {
      final r = validarBloco(const [preco, quantidade], {'qtd': 'x'});
      expect(r.erros, hasLength(2));
      final ok = validarBloco(const [preco, quantidade], {'preco': '10', 'qtd': '2'});
      expect(ok.erros, isEmpty);
      expect(ok.respostas, {'preco': 10.0, 'qtd': 2});
    });
  });

  test('modelos e rede do PDV vêm do roteiro e voltam do cache', () {
    final roteiro = Roteiro.fromJson(roteiroComModelos('2026-10-31', [modeloJson(redes: [5])]));
    final industria = roteiro.industrias.single;
    expect(industria.modelos.single.nome, 'Validade');
    expect(industria.modelos.single.vale(5), isTrue);
    expect(industria.modelos.single.vale(9), isFalse);
    expect(industria.modelos.single.camposPorSku.map((c) => c.chave), ['validade', 'quantidade']);
    expect(industria.modelos.single.camposGerais.map((c) => c.chave), ['observacao'], reason: 'campo de foto ainda não é respondido no app');
    expect(roteiro.visitas.single.pdv.redeId, 5);

    final volta = Roteiro.fromJson(roteiro.toJson());
    expect(volta.industrias.single.modelos.single.versao, 1);
    expect(volta.visitas.single.pdv.redeId, 5);
    expect(ModeloColeta.fromJson(modeloJson()).vale(null), isTrue, reason: 'sem redes = todas as lojas');
  });

  group('no aparelho', () {
    late ServidorFalso servidor;
    late LocalizacaoFalsa gps;
    late EstadoApp estado;
    late Directory pasta;
    final hoje = formatarDataIso(DateTime.now());

    setUp(() async {
      servidor = ServidorFalso();
      gps = LocalizacaoFalsa(posicaoNoPdv());
      pasta = await Directory.systemTemp.createTemp('app_promotor_coleta');
      estado = EstadoApp(
        httpClient: servidor.cliente,
        armazenamento: ArmazenamentoMemoria(),
        repositorio: await repositorioEmMemoria(),
        localizacao: gps,
        fotos: ServicoFotos(pastaBase: () async => pasta),
        urlCentral: 'http://central.test',
        dispositivo: 'Moto G - teste',
      );
      servidor.responder('GET /api/v1/empresas/xyz', 200, {'codigo': 'xyz', 'nome': 'Empresa XYZ', 'url': 'http://xyz.test', 'api': 'http://xyz.test/api/v1', 'api_versao': '1'});
      servidor.responder('POST /api/v1/login', 200, {
        'token': '1|token',
        'usuario': {'id': 5, 'nome': 'João Promotor', 'email': 'p@xyz.test', 'perfil': 'promotor', 'tipo_vinculo': 'clt', 'agencia': {'id': 1, 'nome': 'Agência'}, 'empresa': {'codigo': 'xyz', 'nome': 'Empresa XYZ'}},
      });
      servidor.responder('GET /api/v1/roteiro', 200, roteiroComModelos(hoje, [modeloJson(redes: [5]), modeloJson(id: 8, redes: [99])]));
      servidor.responder('POST /api/v1/visitas/1/fotos', 201, {'foto': {'id': 1}, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179}});
      servidor.responder('POST /api/v1/visitas/1/coletas', 201, {'id': 1, 'repetida': false});
      await estado.carregar();
      await estado.descobrirEmpresa('xyz');
      await estado.entrar(email: 'p@xyz.test', senha: 'senha1234');
      await estado.atualizarRoteiro();
    });

    tearDown(() async {
      for (var i = 0; i < 50 && estado.sincronizando; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
      await estado.repositorio.fechar();
      await pasta.delete(recursive: true);
    });

    Future<void> aguardar() async {
      for (var i = 0; i < 50; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
        if (!estado.sincronizando) {
          return;
        }
      }
    }

    test('só os modelos da rede da loja aparecem', () {
      final visita = estado.roteiro!.visitas.single;
      expect(estado.modelosDaVisita(visita).map((m) => m.id), [4]);
    });

    test('só depois da foto antes; guarda geral e por SKU e envia na fila', () async {
      final visita = estado.roteiro!.visitas.single;
      final modelo = estado.modelosDaVisita(visita).single;
      expect(await estado.salvarColeta(visita, modelo, gerais: {'observacao': 'ok'}), contains('foto antes'));

      expect(await abrirVisita(estado, visita), isNull);
      await aguardar();

      // Obrigatório do SKU respondido faltando.
      expect(
        await estado.salvarColeta(visita, modelo, porSku: {10: {'quantidade': '3'}}),
        contains('Responda "Validade"'),
      );
      expect(await estado.salvarColeta(visita, modelo), 'Nada foi respondido.');

      expect(
        await estado.salvarColeta(
          visita,
          modelo,
          gerais: {'observacao': 'Tudo certo'},
          porSku: {
            10: {'validade': '2026-12-31', 'quantidade': '4'},
            11: {'validade': '', 'quantidade': ''}, // produto não conferido: ignorado
          },
        ),
        isNull,
      );
      await aguardar();

      final enviadas = servidor.das('coletas');
      expect(enviadas, hasLength(2));
      final porSku = enviadas.map((c) => c.json).firstWhere((j) => j['sku_id'] != null);
      expect(porSku['modelo_id'], 4);
      expect(porSku['versao'], 1);
      expect(porSku['respostas'], {'validade': '2026-12-31', 'quantidade': 4});
      final geral = enviadas.map((c) => c.json).firstWhere((j) => j['sku_id'] == null);
      expect(geral['respostas'], {'observacao': 'Tudo certo'});

      final e = estado.execucao('1');
      expect(e.coletas.every((c) => c.enviada), isTrue);
      expect(e.temPendencias, isFalse);
      // Já enviadas não são refeitas.
      expect(await estado.salvarColeta(visita, modelo, gerais: {'observacao': 'Outro'}), isNull);
      await aguardar();
      expect(servidor.das('coletas'), hasLength(2));
    });

    test('offline: refazer antes de enviar troca o conteúdo da mesma resposta', () async {
      final visita = estado.roteiro!.visitas.single;
      final modelo = estado.modelosDaVisita(visita).single;
      expect(await abrirVisita(estado, visita), isNull);
      await aguardar();

      servidor.offline = true;
      expect(await estado.salvarColeta(visita, modelo, porSku: {10: {'validade': '2026-12-31', 'quantidade': '1'}}), isNull);
      await aguardar();
      expect(await estado.salvarColeta(visita, modelo, porSku: {10: {'validade': '2026-11-30', 'quantidade': '2'}}), isNull);
      await aguardar();
      final fila = (await estado.itensDaFila('1')).where((i) => i.tipo == TipoItemFila.coleta).toList();
      expect(fila, hasLength(1), reason: 'a mesma resposta, não duas');
      expect(fila.single.payload['respostas'], {'validade': '2026-11-30', 'quantidade': 2});
      expect(estado.execucao('1').temPendencias, isTrue);

      servidor.offline = false;
      await estado.sincronizar();
      await aguardar();
      expect(servidor.das('coletas').single.json['respostas'], {'validade': '2026-11-30', 'quantidade': 2});
      expect(estado.execucao('1').temPendencias, isFalse);
    });
  });

  testWidgets('tela do formulário: preenche, valida e salva', (tester) async {
    late ServidorFalso servidor;
    late EstadoApp estado;
    late Directory pasta;
    final hoje = formatarDataIso(DateTime.now());

    await tester.runAsync(() async {
      servidor = ServidorFalso();
      pasta = await Directory.systemTemp.createTemp('app_promotor_tela_coleta');
      estado = EstadoApp(
        httpClient: servidor.cliente,
        armazenamento: ArmazenamentoMemoria(),
        repositorio: await repositorioEmMemoria(),
        localizacao: LocalizacaoFalsa(posicaoNoPdv()),
        fotos: ServicoFotos(pastaBase: () async => pasta),
        urlCentral: 'http://central.test',
        dispositivo: 'Moto G - teste',
      );
      servidor.responder('GET /api/v1/empresas/xyz', 200, {'codigo': 'xyz', 'nome': 'Empresa XYZ', 'url': 'http://xyz.test', 'api': 'http://xyz.test/api/v1', 'api_versao': '1'});
      servidor.responder('POST /api/v1/login', 200, {
        'token': '1|token',
        'usuario': {'id': 5, 'nome': 'João', 'email': 'p@xyz.test', 'perfil': 'promotor', 'tipo_vinculo': 'clt', 'agencia': {'id': 1, 'nome': 'Agência'}, 'empresa': {'codigo': 'xyz', 'nome': 'Empresa XYZ'}},
      });
      servidor.responder('GET /api/v1/roteiro', 200, roteiroComModelos(hoje, [modeloJson(redes: [5])]));
      servidor.responder('POST /api/v1/visitas/1/fotos', 201, {'foto': {'id': 1}, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179}});
      servidor.responder('POST /api/v1/visitas/1/coletas', 201, {'id': 1, 'repetida': false});
      await estado.carregar();
      await estado.descobrirEmpresa('xyz');
      await estado.entrar(email: 'p@xyz.test', senha: 'senha1234');
      await estado.atualizarRoteiro();
      await abrirVisita(estado, estado.roteiro!.visitas.single);
    });
    final visita = estado.roteiro!.visitas.single;
    tester.view.physicalSize = const Size(800, 3000);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    await tester.pumpWidget(MaterialApp(
      home: TelaColeta(estado: estado, visita: visita, modelo: estado.modelosDaVisita(visita).single, industria: estado.industriaDa(visita)),
    ));
    await tester.pump();

    expect(find.text('Observação'), findsOneWidget);
    expect(find.text('Foto'), findsNothing, reason: 'campo de foto não aparece');
    await tester.enterText(find.byKey(const ValueKey('g-observacao')), 'Loja organizada');
    await tester.tap(find.text('ALG-25 · Alvejante 2 L'));
    await tester.pumpAndSettle();
    await tester.enterText(find.byKey(const ValueKey('s10-quantidade')), '6');

    // Falta a validade obrigatória do produto respondido.
    await tester.tap(find.text('Salvar respostas'));
    await tester.runAsync(() => Future<void>.delayed(const Duration(milliseconds: 100)));
    await tester.pump();
    expect(find.textContaining('Responda "Validade"'), findsOneWidget);

    await tester.runAsync(() async {
      for (var i = 0; i < 50 && estado.sincronizando; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
      await estado.repositorio.fechar();
      await pasta.delete(recursive: true);
    });
  });
}
