/// Várias contas no mesmo aparelho (promotor que atende mais de uma agência),
/// menu com troca de conta e sair, e a marca própria de cada empresa (white label).
library;

import 'dart:convert';
import 'dart:io';

import 'package:app_promotor/dados/repositorio_local.dart';
import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/main.dart';
import 'package:app_promotor/modelos/usuario.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/marca.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:app_promotor/util/datas.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:sqflite_common_ffi/sqflite_ffi.dart';

import 'apoio.dart';

/// PNG mínimo (1x1) para o logo.
final _png = base64Decode(
  'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==',
);

Map<String, dynamic> _empresa(String codigo, String nome, {Map<String, dynamic>? marca}) => {
      'codigo': codigo,
      'nome': nome,
      'url': 'http://$codigo.test',
      'api': 'http://$codigo.test/api/v1',
      'api_versao': '1',
      'marca': marca,
    };

Map<String, dynamic> _login(String token, int id, String nome, String codigo, String empresa) => {
      'token': token,
      'usuario': {
        'id': id,
        'nome': nome,
        'email': '$codigo@promotor.test',
        'perfil': 'promotor',
        'tipo_vinculo': 'clt',
        'agencia': {'id': 1, 'nome': empresa},
        'empresa': {'codigo': codigo, 'nome': empresa},
      },
    };

void main() {
  late ServidorFalso servidor;
  late EstadoApp estado;
  late ArmazenamentoMemoria armazenamento;
  late Directory pasta;
  late Map<String, RepositorioLocal> bancos;
  final hoje = formatarDataIso(DateTime.now());

  Map<String, dynamic>? marcaAlfa;

  setUp(() async {
    servidor = ServidorFalso();
    pasta = await Directory.systemTemp.createTemp('app_promotor_contas');
    armazenamento = ArmazenamentoMemoria();
    bancos = {};
    marcaAlfa = {'nome': 'Alfa Trade', 'cor': '#0f766e', 'logo_url': 'http://alfa.test/marca/claro?v=abc1', 'icone_url': null};

    servidor.respostas['GET /api/v1/empresas/alfa'] = (_) => http.Response(
          jsonEncode(_empresa('alfa', 'Alfa', marca: marcaAlfa)),
          200,
          headers: {'content-type': 'application/json'},
        );
    servidor.responder('GET /api/v1/empresas/beta', 200, _empresa('beta', 'Beta', marca: {'nome': 'Beta', 'cor': null, 'logo_url': null, 'icone_url': null}));
    servidor.respostas['GET /marca/claro'] = (_) => http.Response.bytes(_png, 200);
    servidor.respostas['POST /api/v1/login'] = (c) {
      final alfa = c.json['email'].toString().startsWith('alfa');

      return http.Response(
        jsonEncode(alfa ? _login('1|alfa', 5, 'João Promotor', 'alfa', 'Alfa') : _login('2|beta', 8, 'João Promotor', 'beta', 'Beta')),
        200,
        headers: {'content-type': 'application/json'},
      );
    };
    servidor.respostas['GET /api/v1/roteiro'] = (c) {
      final alfa = c.cabecalhos['Authorization'] == 'Bearer 1|alfa';
      final pdv = pdvJson()..['nome'] = alfa ? 'Mercado da Alfa' : 'Mercado da Beta';
      final corpo = roteiroJson(data: hoje, visitas: [visitaJson(pdv: pdv)]);

      return http.Response(jsonEncode(corpo), 200, headers: {'content-type': 'application/json'});
    };
    servidor.responder('POST /api/v1/logout', 200, {'message': 'Sessão encerrada.'});

    estado = EstadoApp(
      httpClient: servidor.cliente,
      armazenamento: armazenamento,
      repositorio: await repositorioEmMemoria(),
      fabricaRepositorio: (chave) async => bancos[chave] ??= await _bancoEmArquivo(pasta, bancos.length),
      localizacao: LocalizacaoFalsa(posicaoNoPdv()),
      fotos: ServicoFotos(pastaBase: () async => pasta),
      servicoMarca: ServicoMarca(pastaBase: () async => pasta),
      urlCentral: 'http://central.test',
    );
  });

  tearDown(() async {
    // Atualizações em segundo plano (roteiro, marca, fila) precisam terminar antes de fechar os bancos.
    for (var i = 0; i < 100 && (estado.atualizandoRoteiro || estado.sincronizando); i++) {
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
    for (final banco in bancos.values) {
      await banco.fechar();
    }
    await estado.repositorio.fechar();
    await pasta.delete(recursive: true);
  });

  Future<void> entrarEm(String codigo) async {
    await estado.descobrirEmpresa(codigo);
    await estado.entrar(email: '$codigo@promotor.test', senha: 'senha1234');
    await estado.atualizarRoteiro();
  }

  group('marca própria', () {
    test('a descoberta traz nome, cor e logo; o logo é guardado no aparelho', () async {
      await estado.carregar();
      await estado.descobrirEmpresa('alfa');

      final marca = estado.empresaPendente!.marca!;
      expect(marca.nome, 'Alfa Trade');
      expect(marca.cor, const Color(0xFF0F766E));
      expect(estado.marcaAtual, same(marca), reason: 'o login já aparece vestido com a marca');
      expect(File(marca.logoLocal!).readAsBytesSync(), _png);
      expect(marca.logoLocal, endsWith('logo_alfa_abc1.png'));
    });

    test('a marca fica salva com a sessão e sobrevive a reabrir o app, mesmo offline', () async {
      await estado.carregar();
      await entrarEm('alfa');
      expect(estado.sessao!.empresa.nomeExibido, 'Alfa Trade');

      servidor.offline = true;
      final reaberto = EstadoApp(
        httpClient: servidor.cliente,
        armazenamento: armazenamento,
        repositorio: await repositorioEmMemoria(),
        fabricaRepositorio: (chave) async => bancos[chave]!,
        localizacao: LocalizacaoFalsa(posicaoNoPdv()),
        fotos: ServicoFotos(pastaBase: () async => pasta),
        servicoMarca: ServicoMarca(pastaBase: () async => pasta),
        urlCentral: 'http://central.test',
      );
      await reaberto.carregar();

      expect(reaberto.marcaAtual!.nome, 'Alfa Trade');
      expect(reaberto.marcaAtual!.cor, const Color(0xFF0F766E));
      expect(File(reaberto.marcaAtual!.logoLocal!).existsSync(), isTrue);
      await reaberto.repositorio.fechar();
    });

    test('mudança de cor e logo no painel chega ao app na próxima atualização', () async {
      await estado.carregar();
      await entrarEm('alfa');
      final logoAntigo = estado.sessao!.empresa.marca!.logoLocal!;

      marcaAlfa = {'nome': 'Alfa 2', 'cor': '#b91c1c', 'logo_url': 'http://alfa.test/marca/claro?v=zzz9', 'icone_url': null};
      await estado.atualizarMarca();

      final marca = estado.sessao!.empresa.marca!;
      expect(marca.nome, 'Alfa 2');
      expect(marca.cor, const Color(0xFFB91C1C));
      expect(marca.logoLocal, endsWith('logo_alfa_zzz9.png'));
      expect(File(logoAntigo).existsSync(), isFalse, reason: 'logo antigo é apagado');
      expect((await armazenamento.carregar())!.empresa.marca!.nome, 'Alfa 2');
    });

    test('quando o fornecedor desliga a marca própria, o app volta ao padrão', () async {
      await estado.carregar();
      await entrarEm('alfa');

      marcaAlfa = {'nome': 'Alfa', 'cor': null, 'logo_url': null, 'icone_url': null};
      await estado.atualizarMarca();

      expect(estado.marcaAtual!.cor, isNull);
      expect(estado.marcaAtual!.logoUrl, isNull);
      expect(temaDaMarca(estado.marcaAtual!.cor).colorScheme.primary, corPadrao);
    });

    test('falha ao atualizar a marca (sem internet) não derruba nada', () async {
      await estado.carregar();
      await entrarEm('alfa');

      servidor.offline = true;
      await estado.atualizarMarca();

      expect(estado.sessao!.empresa.marca!.nome, 'Alfa Trade');
    });

    test('o tema usa exatamente a cor da empresa', () {
      final tema = temaDaMarca(const Color(0xFF0F766E));

      expect(tema.colorScheme.primary, const Color(0xFF0F766E));
      expect(tema.appBarTheme.backgroundColor, const Color(0xFF0F766E));
    });
  });

  group('várias contas', () {
    test('cada empresa guarda seu roteiro, e trocar de conta não mistura nada', () async {
      await estado.carregar();
      await entrarEm('alfa');
      estado.iniciarNovaConta();
      expect(estado.adicionandoConta, isTrue);
      await entrarEm('beta');

      expect(estado.adicionandoConta, isFalse);
      expect(estado.contas.map((c) => c.empresa.codigo), ['alfa', 'beta']);
      expect(estado.sessao!.empresa.codigo, 'beta');
      expect(estado.roteiro!.visitas.single.pdv.nome, 'Mercado da Beta');

      expect(await estado.trocarConta(estado.contas.first), isNull);
      expect(estado.sessao!.empresa.codigo, 'alfa');
      expect(estado.roteiro!.visitas.single.pdv.nome, 'Mercado da Alfa', reason: 'vem do banco da Alfa, sem rede');
      expect((await armazenamento.carregar())!.empresa.codigo, 'alfa', reason: 'a conta ativa é lembrada');
    });

    test('envios pendentes ficam guardados na conta e voltam a ser enviados ao retornar a ela', () async {
      await estado.carregar();
      await entrarEm('alfa');
      final visita = estado.roteiro!.visitas.single;
      servidor.offline = true;
      expect(await estado.fazerCheckIn(visita), isNull);
      await estado.sincronizar();
      for (var i = 0; i < 100 && estado.sincronizando; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
      expect(estado.pendentes, greaterThan(0));

      estado.iniciarNovaConta();
      servidor.offline = false;
      await entrarEm('beta');
      expect(estado.pendentes, 0, reason: 'a fila da Alfa não aparece na Beta');
      expect(servidor.das('check-in'), isEmpty);

      servidor.responder('POST /api/v1/visitas/1/check-in', 201, {'id': 1, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179}});
      await estado.trocarConta(estado.contas.first);
      expect(estado.pendentes, greaterThan(0));
      for (var i = 0; i < 100 && estado.pendentes > 0; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
      }
      expect(estado.pendentes, 0);
      expect(servidor.das('check-in').single.cabecalhos['Authorization'], 'Bearer 1|alfa');
    });

    test('sair de uma conta mantém as outras e ativa a próxima; sair da última volta ao início', () async {
      await estado.carregar();
      await entrarEm('alfa');
      estado.iniciarNovaConta();
      await entrarEm('beta');

      expect(await estado.sair(), isTrue);
      expect(servidor.das('logout').single.cabecalhos['Authorization'], 'Bearer 2|beta');
      expect(estado.contas.map((c) => c.empresa.codigo), ['alfa']);
      expect(estado.sessao!.empresa.codigo, 'alfa');
      expect(estado.autenticado, isTrue);
      expect(estado.roteiro!.visitas.single.pdv.nome, 'Mercado da Alfa');

      expect(await estado.sair(), isTrue);
      expect(estado.contas, isEmpty);
      expect(estado.sessao, isNull);
      expect(armazenamento.sessao, isNull);
      expect(estado.autenticado, isFalse);
    });

    test('não troca de conta com um envio em andamento', () async {
      await estado.carregar();
      await entrarEm('alfa');
      estado.iniciarNovaConta();
      await entrarEm('beta');

      estado.sincronizando = true;
      expect(await estado.trocarConta(estado.contas.first), contains('Aguarde'));
      expect(estado.sessao!.empresa.codigo, 'beta');
      estado.sincronizando = false;
    });

    test('cancelar "adicionar empresa" volta para a conta atual', () async {
      await estado.carregar();
      await entrarEm('alfa');

      estado.iniciarNovaConta();
      await estado.descobrirEmpresa('beta');
      estado.cancelarNovaConta();

      expect(estado.adicionandoConta, isFalse);
      expect(estado.empresaPendente, isNull);
      expect(estado.sessao!.empresa.codigo, 'alfa');
    });

    test('contas guardadas na versão anterior (uma só sessão) continuam valendo', () async {
      final legado = Sessao(
        empresa: const Empresa(codigo: 'alfa', nome: 'Alfa', url: 'http://alfa.test', api: 'http://alfa.test/api/v1'),
        token: '1|alfa',
        usuario: const Usuario(id: 5, nome: 'João', email: 'a@a.test', perfil: 'promotor'),
      );
      final antigo = Sessao.fromJson(legado.toJson());

      expect(antigo.empresa.marca, isNull);
      expect(antigo.empresa.nomeExibido, 'Alfa');
      expect(antigo.chave, 'http://alfa.test/api/v1#5');
    });
  });

  group('telas', () {
    testWidgets('menu lateral mostra as contas, troca de conta e sai', (tester) async {
      // Tela alta: o menu é uma lista e só constrói o que cabe.
      tester.view.physicalSize = const Size(800, 1800);
      tester.view.devicePixelRatio = 1;
      addTearDown(tester.view.reset);
      await tester.runAsync(() async {
        await estado.carregar();
        await entrarEm('alfa');
        estado.iniciarNovaConta();
        await entrarEm('beta');
        await tester.pumpWidget(AppPromotor(estado: estado));
        await tester.pump();

        expect(find.text('Mercado da Beta'), findsOneWidget);
        await _abrirMenu(tester);

        expect(find.text('Contas neste aparelho'), findsOneWidget);
        expect(find.byKey(const Key('conta-ativa')), findsOneWidget);
        expect(find.byKey(Key('conta-${estado.contas.first.chave}')), findsOneWidget);
        expect(find.byKey(const Key('adicionar-conta')), findsOneWidget);
        expect(find.byKey(const Key('sair')), findsOneWidget);

        await tester.tap(find.byKey(Key('conta-${estado.contas.first.chave}')));
        await _ate(tester, find.text('Mercado da Alfa'));
        expect(estado.sessao!.empresa.codigo, 'alfa');

        await _abrirMenu(tester);
        await tester.tap(find.byKey(const Key('sair')));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 500));
        expect(find.text('Sair de Alfa Trade?'), findsOneWidget);
        await tester.tap(find.text('Sair'));
        await _ate(tester, find.text('Mercado da Beta'));
        expect(estado.sessao!.empresa.codigo, 'beta');
      });
    });
  });
}

/// Cada conta com seu próprio arquivo (o caminho `:memory:` seria um banco só, compartilhado).
Future<RepositorioLocal> _bancoEmArquivo(Directory pasta, int n) =>
    RepositorioLocal.abrir(fabrica: databaseFactoryFfi, caminho: '${pasta.path}${Platform.pathSeparator}conta_$n.db');

/// Abre o menu e espera a animação terminar (o primeiro quadro só liga o relógio).
Future<void> _abrirMenu(WidgetTester tester) async {
  await tester.tap(find.byIcon(Icons.menu));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));
}

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
