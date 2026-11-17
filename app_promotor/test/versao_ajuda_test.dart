import 'dart:io';

import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/main.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:app_promotor/servicos/versao.dart';
import 'package:app_promotor/telas/tela_ajuda.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  test('compara versões por número, não por texto', () {
    expect(compararVersoes('0.2.0', '0.10.0'), lessThan(0));
    expect(compararVersoes('1.0.0', '0.9.9'), greaterThan(0));
    expect(compararVersoes('1.2', '1.2.0'), 0);
    expect(compararVersoes('0.2.0+2', '0.2.0'), 0, reason: 'o número da compilação não conta');
    expect(compararVersoes('lixo', '0.0.0'), 0);
  });

  group('verificação no servidor', () {
    late ServidorFalso servidor;
    late Directory pasta;

    Future<EstadoApp> estadoCom(String instalada) async {
      pasta = await Directory.systemTemp.createTemp('app_promotor_versao');

      return EstadoApp(
        httpClient: servidor.cliente,
        armazenamento: ArmazenamentoMemoria(),
        repositorio: await repositorioEmMemoria(),
        localizacao: LocalizacaoFalsa(posicaoNoPdv()),
        fotos: ServicoFotos(pastaBase: () async => pasta),
        urlCentral: 'http://central.test',
        servicoVersao: ServicoVersaoFixa(instalada),
      );
    }

    setUp(() => servidor = ServidorFalso());

    tearDown(() async {
      await pasta.delete(recursive: true);
    });

    test('abaixo da mínima bloqueia e guarda o link; igual ou acima libera', () async {
      servidor.responder('GET /api/v1/app', 200, {'versao_minima': '0.3.0', 'versao_atual': '0.4.0', 'download_url': 'https://arquivos.exemplo.com.br/app.apk'});
      final velho = await estadoCom('0.2.0');
      await velho.carregar();
      await velho.verificarVersao();
      expect(velho.atualizacaoObrigatoria, isTrue);
      expect(velho.atualizacaoDisponivel, isTrue);
      expect(velho.urlAtualizacao, 'https://arquivos.exemplo.com.br/app.apk');
      await velho.repositorio.fechar();

      final emDia = await estadoCom('0.3.0');
      await emDia.carregar();
      await emDia.verificarVersao();
      expect(emDia.atualizacaoObrigatoria, isFalse);
      expect(emDia.atualizacaoDisponivel, isTrue, reason: 'há uma versão mais nova, mas não é obrigatória');
      await emDia.repositorio.fechar();

      final novo = await estadoCom('0.4.0');
      await novo.carregar();
      await novo.verificarVersao();
      expect(novo.atualizacaoObrigatoria, isFalse);
      expect(novo.atualizacaoDisponivel, isFalse);
      await novo.repositorio.fechar();
    });

    test('sem internet ou com erro do servidor nunca bloqueia o promotor', () async {
      servidor.offline = true;
      final estado = await estadoCom('0.1.0');
      await estado.carregar();
      await estado.verificarVersao();
      expect(estado.atualizacaoObrigatoria, isFalse);

      servidor.offline = false;
      servidor.responder('GET /api/v1/app', 500, {'message': 'erro'});
      await estado.verificarVersao();
      expect(estado.atualizacaoObrigatoria, isFalse);
      await estado.repositorio.fechar();
    });

    testWidgets('a tela de atualização aparece no lugar do app quando a versão é recusada', (tester) async {
      servidor.responder('GET /api/v1/app', 200, {'versao_minima': '9.0.0', 'versao_atual': '9.0.0', 'download_url': 'https://arquivos.exemplo.com.br/app.apk'});
      final estado = await tester.runAsync(() => estadoCom('0.2.0'));
      await tester.runAsync(() async {
        await estado!.carregar();
        await estado.verificarVersao();
        await tester.pumpWidget(AppPromotor(estado: estado));
        await tester.pump();
        expect(find.text('Atualize o aplicativo'), findsOneWidget);
        expect(find.byKey(const Key('baixar-atualizacao')), findsOneWidget);
        expect(find.text('E-mail'), findsNothing, reason: 'não dá para entrar com a versão recusada');
        await estado.repositorio.fechar();
      });
    });
  });

  testWidgets('a ajuda explica a visita sem falar de check-in nem de indústria', (tester) async {
    await tester.pumpWidget(const MaterialApp(home: TelaAjuda()));

    expect(find.text('2. Foto antes da gôndola'), findsOneWidget);
    final todo = TelaAjuda.passos.map((p) => '${p.titulo} ${p.texto}').join(' ').toLowerCase();
    expect(todo, isNot(contains('check-in')));
    expect(todo, isNot(contains('indústria')));
    expect(todo, contains('sem internet'));
  });
}
