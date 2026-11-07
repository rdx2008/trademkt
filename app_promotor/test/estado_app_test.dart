/// Fluxo do app de ponta a ponta sem aparelho: descoberta da empresa, login,
/// roteiro, check-in com bloqueio de GPS simulado, fotos, conclusão e fila.
library;

import 'dart:io';

import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/main.dart';
import 'package:app_promotor/modelos/execucao.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:app_promotor/sincronizacao/item_fila.dart';
import 'package:app_promotor/util/datas.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  late ServidorFalso servidor;
  late LocalizacaoFalsa gps;
  late EstadoApp estado;
  late Directory pasta;
  final hoje = formatarDataIso(DateTime.now());

  setUp(() async {
    servidor = ServidorFalso();
    gps = LocalizacaoFalsa(posicaoNoPdv());
    pasta = await Directory.systemTemp.createTemp('app_promotor_teste');
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
      'usuario': {'id': 5, 'nome': 'João Promotor', 'email': 'promotor@xyz.test', 'perfil': 'promotor', 'tipo_vinculo': 'clt', 'agencia': {'id': 1, 'nome': 'Agência'}, 'empresa': {'codigo': 'xyz', 'nome': 'Empresa XYZ'}},
    });
    servidor.responder('GET /api/v1/roteiro', 200, roteiroJson(data: hoje));
    servidor.responder('POST /api/v1/visitas/1/check-in', 201, {'id': 1, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179}});
    servidor.responder('POST /api/v1/visitas/1/fotos', 201, {'foto': {'id': 1}});
    servidor.responder('POST /api/v1/visitas/1/concluir', 201, {'visita': {'id': 1, 'status': 'realizada'}});
    servidor.responder('POST /api/v1/logout', 200, {'message': 'Sessão encerrada.'});
  });

  tearDown(() async {
    await _aguardarFila(estado);
    await estado.repositorio.fechar();
    await pasta.delete(recursive: true);
  });

  Future<void> entrar() async {
    await estado.carregar();
    await estado.descobrirEmpresa('XYZ ');
    await estado.entrar(email: 'promotor@xyz.test', senha: 'senha1234');
    await estado.atualizarRoteiro();
  }

  test('descobre a empresa pelo código, faz login com o nome do aparelho e baixa o roteiro', () async {
    await entrar();

    expect(servidor.das('empresas').single.caminho, '/api/v1/empresas/xyz');
    expect(servidor.das('login').single.json, {'email': 'promotor@xyz.test', 'password': 'senha1234', 'dispositivo': 'Moto G - teste'});
    expect(servidor.das('roteiro').first.cabecalhos['Authorization'], 'Bearer 1|token');
    expect(estado.autenticado, isTrue);
    expect(estado.sessao!.usuario.nome, 'João Promotor');
    expect(estado.roteiro!.visitas.single.pdv.nome, 'Mercado Centro');
    expect(await estado.repositorio.obterRoteiro(hoje), isNotNull, reason: 'roteiro fica em cache para uso offline');
  });

  test('sem conexão o roteiro salvo continua disponível', () async {
    await entrar();
    servidor.offline = true;
    estado.roteiro = null;

    await estado.atualizarRoteiro();

    expect(estado.roteiro!.visitas, hasLength(1));
    expect(estado.aviso, contains('Sem conexão'));
  });

  test('localização simulada bloqueia o check-in e nada entra na fila', () async {
    await entrar();
    gps.posicao = posicaoNoPdv(simulada: true);

    final erro = await estado.fazerCheckIn(estado.roteiro!.visitas.single);

    expect(erro, contains('simulada'));
    expect(estado.execucao('1').temCheckIn, isFalse);
    expect(await estado.repositorio.contarPendentes(), 0);
    expect(servidor.das('check-in'), isEmpty);
  });

  test('check-in fora do raio é recusado no aparelho antes de chegar ao servidor', () async {
    await entrar();
    gps.posicao = posicaoNoPdv(lat: -29.178);

    expect(await estado.fazerCheckIn(estado.roteiro!.visitas.single), contains('m do PDV'));
    expect(servidor.das('check-in'), isEmpty);
  });

  test('visita completa: check-in, fotos com marca d\'água, SKUs e conclusão sincronizados na ordem', () async {
    await entrar();
    final visita = estado.roteiro!.visitas.single;
    // Horários no passado recente: a conclusão usa a hora atual e precisa ser posterior às fotos.
    final inicio = DateTime.now().subtract(const Duration(minutes: 10));
    gps.posicao = posicaoNoPdv(gpsEm: inicio);

    expect(await estado.fazerCheckIn(visita), isNull);
    await _aguardarFila(estado);
    expect(estado.execucao('1').checkIn!.enviado, isTrue);

    gps.posicao = posicaoNoPdv(gpsEm: inicio.add(const Duration(minutes: 1)));
    final preparoAntes = await estado.prepararFoto(visita, TipoFoto.antes);
    expect(preparoAntes.erro, isNull);
    expect(await estado.registrarFoto(visita, TipoFoto.antes, jpegDeTeste(), preparoAntes.posicao!), isNull);

    // Foto depois antes de existir foto antes não acontece; aqui a sequência é válida.
    await estado.salvarItens('1', const [ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.somenteGondola)]);
    gps.posicao = posicaoNoPdv(gpsEm: inicio.add(const Duration(minutes: 2)));
    final preparoDepois = await estado.prepararFoto(visita, TipoFoto.depois);
    expect(await estado.registrarFoto(visita, TipoFoto.depois, jpegDeTeste(), preparoDepois.posicao!), isNull);

    expect(await estado.concluir(visita, TipoConclusao.normal), isNull);
    await _aguardarFila(estado);

    final execucao = estado.execucao('1');
    expect(execucao.fotos.map((f) => f.tipo), [TipoFoto.antes, TipoFoto.depois]);
    expect(File(execucao.fotos.first.caminho).existsSync(), isTrue, reason: 'foto com marca d\'água gravada no aparelho');
    expect(execucao.temPendencias, isFalse);
    expect(estado.statusExibido(visita), 'realizada');
    expect(estado.pendentes, 0);

    final envios = servidor.chamadas.where((c) => c.caminho.contains('visitas/1')).map((c) => c.caminho.split('/').last).toList();
    expect(envios, ['check-in', 'fotos', 'fotos', 'concluir']);
    expect(servidor.das('concluir').single.json['itens'], [
      {'sku_id': 10, 'estoque': 'somente_gondola'},
    ]);
    final fila = await estado.repositorio.listarFila(visitaId: '1');
    expect(fila.map((i) => i.estado).toSet(), {EstadoItemFila.enviado});
  });

  test('impedimento exige observação e deixa a visita em atenção', () async {
    await entrar();
    servidor.responder('POST /api/v1/visitas/1/concluir', 201, {'visita': {'id': 1, 'status': 'em_atencao'}});
    final visita = estado.roteiro!.visitas.single;
    await estado.fazerCheckIn(visita);

    expect(await estado.concluir(visita, TipoConclusao.impedimento, observacao: ''), contains('Descreva'));
    expect(await estado.concluir(visita, TipoConclusao.impedimento, observacao: 'Loja fechada para inventário'), isNull);
    await _aguardarFila(estado);

    expect(estado.statusExibido(visita), 'em_atencao');
    final corpo = servidor.das('concluir').single.json;
    expect(corpo['tipo'], 'impedimento');
    expect(corpo['itens'], isEmpty);
    expect(corpo['observacao'], 'Loja fechada para inventário');
  });

  test('offline tudo fica na fila e é enviado ao sincronizar depois', () async {
    await entrar();
    final visita = estado.roteiro!.visitas.single;
    servidor.offline = true;

    expect(await estado.fazerCheckIn(visita), isNull);
    final preparo = await estado.prepararFoto(visita, TipoFoto.ruptura);
    expect(await estado.registrarFoto(visita, TipoFoto.ruptura, jpegDeTeste(), preparo.posicao!), isNull);
    expect(await estado.concluir(visita, TipoConclusao.ruptura, itens: const [ItemTrabalhado(skuId: 11)]), isNull);
    await _aguardarFila(estado);

    expect(estado.pendentes, 3);
    expect(estado.execucao('1').temPendencias, isTrue);
    expect(await estado.sair(), isFalse, reason: 'não deixa sair com pendências');

    servidor.offline = false;
    final resultado = await estado.sincronizar();

    expect(resultado!.tudoCerto, isTrue);
    expect(estado.pendentes, 0);
    expect(servidor.das('concluir').single.json['itens'], [
      {'sku_id': 11},
    ]);
    expect(await estado.sair(), isTrue);
    expect(estado.sessao, isNull);
    expect(await estado.repositorio.listarFila(), isEmpty);
  });

  test('401 na sincronização pede login de novo sem perder a fila', () async {
    await entrar();
    final visita = estado.roteiro!.visitas.single;
    servidor.responder('POST /api/v1/visitas/1/check-in', 401, {'message': 'Unauthenticated.'});

    await estado.fazerCheckIn(visita);
    await _aguardarFila(estado);

    expect(estado.sessaoExpirada, isTrue);
    expect(estado.autenticado, isFalse);
    expect(estado.pendentes, 1);

    servidor.responder('POST /api/v1/visitas/1/check-in', 201, {'id': 1, 'status': 'em_andamento', 'check_in': {}});
    await estado.entrar(email: 'promotor@xyz.test', senha: 'senha1234');
    await _aguardarFila(estado);

    expect(estado.autenticado, isTrue);
    expect(estado.pendentes, 0);
  });

  testWidgets('telas: empresa → login → roteiro', (tester) async {
    // runAsync: o banco SQLite e o HTTP falso são assíncronos de verdade.
    await tester.runAsync(() async {
      await estado.carregar();
      await tester.pumpWidget(AppPromotor(estado: estado));
      await tester.pump();

      expect(find.text('Código da empresa'), findsOneWidget);
      await tester.enterText(find.byType(TextField), 'xyz');
      await tester.tap(find.text('Continuar'));
      await _ate(tester, find.text('Empresa XYZ'));

      await tester.enterText(find.byType(TextField).at(0), 'promotor@xyz.test');
      await tester.enterText(find.byType(TextField).at(1), 'senha1234');
      await tester.tap(find.text('Entrar'));
      await _ate(tester, find.text('Mercado Centro'));

      expect(find.text('João Promotor'), findsOneWidget);
      expect(find.text('Prevista'), findsOneWidget);
    });
  });
}

/// Repinta até o [finder] aparecer (ou desiste depois de alguns segundos).
Future<void> _ate(WidgetTester tester, Finder finder) async {
  for (var i = 0; i < 200; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 25));
    await tester.pump();
    if (finder.evaluate().isNotEmpty) {
      return;
    }
  }
  fail('Não apareceu: $finder');
}

/// A gravação dispara a sincronização em segundo plano; espera ela terminar.
Future<void> _aguardarFila(EstadoApp estado) async {
  for (var i = 0; i < 50; i++) {
    await Future<void>.delayed(const Duration(milliseconds: 20));
    if (!estado.sincronizando) {
      return;
    }
  }
}
