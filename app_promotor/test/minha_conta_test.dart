import 'dart:io';

import 'package:app_promotor/api/cliente_api.dart';
import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/modelos/producao.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:app_promotor/telas/tela_producao.dart';
import 'package:app_promotor/telas/tela_senha.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

Map<String, dynamic> _conta(String codigo, int id) => {
      'empresa': {'codigo': codigo, 'nome': 'Agência $codigo', 'url': 'http://$codigo.test', 'api': 'http://$codigo.test/api/v1', 'api_versao': '1'},
      'token': '1|$codigo',
      'usuario': {'id': id, 'nome': 'Joana', 'email': 'joana@exemplo.com', 'perfil': 'promotor', 'tipo_vinculo': 'freelancer', 'agencia': {'id': 1, 'nome': codigo}, 'empresa': {'codigo': codigo, 'nome': codigo}},
    };

void main() {
  late ServidorFalso servidor;
  late EstadoApp estado;
  late Directory pasta;

  setUp(() async {
    servidor = ServidorFalso();
    pasta = await Directory.systemTemp.createTemp('app_promotor_conta');
    estado = EstadoApp(
      httpClient: servidor.cliente,
      armazenamento: ArmazenamentoMemoria(),
      repositorio: await repositorioEmMemoria(),
      localizacao: LocalizacaoFalsa(posicaoNoPdv()),
      fotos: ServicoFotos(pastaBase: () async => pasta),
      urlCentral: 'http://central.test',
    );
    servidor.responder('GET /api/v1/roteiro', 200, roteiroJson());
    servidor.responder('POST /api/v1/entrar', 200, {
      'contas': [_conta('sul', 4), _conta('norte', 9)],
    });
    servidor.responder('POST /api/v1/senha', 200, {'message': 'Senha alterada.'});
    servidor.responder('GET /api/v1/producao', 200, {
      'mes': '2026-10',
      'total': 3,
      'visitas': 2,
      'dias': [
        {
          'data': '2026-10-14',
          'total': 2,
          'itens': [
            {'pdv': 'Super Centro', 'industria': 'Gaboardi', 'codigo': 'A1', 'descricao': 'Detergente', 'situacao': 'tem_estoque'},
            {'pdv': 'Super Centro', 'industria': 'Gaboardi', 'codigo': 'B2', 'descricao': 'Esponja', 'situacao': 'somente_gondola'},
          ],
        },
        {
          'data': '2026-10-15',
          'total': 1,
          'itens': [
            {'pdv': 'Mercado Sul', 'industria': 'Gaboardi', 'codigo': 'C3', 'descricao': 'Sabão', 'situacao': 'tem_estoque'},
          ],
        },
      ],
    });
    await estado.carregar();
    await estado.entrarUnico(email: 'joana@exemplo.com', senha: 'senha1234');
    await estado.atualizarRoteiro();
  });

  tearDown(() async {
    for (var i = 0; i < 100 && (estado.atualizandoRoteiro || estado.sincronizando); i++) {
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
    await estado.repositorio.fechar();
    await pasta.delete(recursive: true);
  });

  test('trocar a senha chama só a agência ativa quando não pede as outras', () async {
    final outras = await estado.alterarSenha(atual: 'senha1234', nova: 'NovaSenha99');

    expect(outras, 0);
    final chamadas = servidor.das('/senha');
    expect(chamadas, hasLength(1));
    expect(chamadas.single.json, {'senha_atual': 'senha1234', 'password': 'NovaSenha99', 'password_confirmation': 'NovaSenha99'});
    expect(chamadas.single.cabecalhos['Authorization'], 'Bearer 1|sul');
  });

  test('com "nas outras" tenta cada agência com o token dela e conta as que aceitaram', () async {
    final outras = await estado.alterarSenha(atual: 'senha1234', nova: 'NovaSenha99', nasOutras: true);

    expect(outras, 1);
    expect(servidor.das('/senha').map((c) => c.cabecalhos['Authorization']), ['Bearer 1|sul', 'Bearer 1|norte']);
  });

  test('senha atual errada vira erro e nada muda nas outras', () async {
    servidor.responder('POST /api/v1/senha', 422, {
      'message': 'A senha atual não confere.',
      'errors': {
        'senha_atual': ['A senha atual não confere.'],
      },
    });

    await expectLater(
      estado.alterarSenha(atual: 'errada', nova: 'NovaSenha99', nasOutras: true),
      throwsA(isA<ErroApi>().having((e) => e.descricao, 'descricao', 'A senha atual não confere.')),
    );
    expect(servidor.das('/senha'), hasLength(1), reason: 'parou na primeira, sem tentar as outras');
  });

  test('a produção do mês vem do servidor, por dia', () async {
    final producao = await estado.carregarProducao('2026-10');

    expect(servidor.das('producao').single.caminho, '/api/v1/producao');
    expect(producao.total, 3);
    expect(producao.visitas, 2);
    expect(producao.dias.map((d) => d.data), ['2026-10-14', '2026-10-15']);
    expect(producao.dias.first.itens.last.situacaoRotulo, 'Somente na gôndola');
    expect(Producao.fromJson({'mes': '2026-01'}).dias, isEmpty);
  });

  testWidgets('a tela da senha valida antes de enviar', (tester) async {
    await tester.pumpWidget(MaterialApp(home: TelaSenha(estado: estado)));

    await tester.tap(find.byKey(const Key('salvar-senha')));
    await tester.pump();
    expect(find.text('Preencha a senha atual e a nova senha.'), findsOneWidget);

    await tester.enterText(find.byKey(const Key('senha-atual')), 'senha1234');
    await tester.enterText(find.byKey(const Key('senha-nova')), 'curta');
    await tester.tap(find.byKey(const Key('salvar-senha')));
    await tester.pump();
    expect(find.textContaining('8 caracteres ou mais'), findsWidgets);

    await tester.enterText(find.byKey(const Key('senha-nova')), 'NovaSenha99');
    await tester.enterText(find.byKey(const Key('senha-repita')), 'Outra12345');
    await tester.tap(find.byKey(const Key('salvar-senha')));
    await tester.pump();
    expect(find.text('A confirmação não é igual à nova senha.'), findsOneWidget);
    expect(servidor.das('/senha'), isEmpty, reason: 'nada foi enviado');
    expect(find.byKey(const Key('nas-outras')), findsOneWidget, reason: 'há mais de uma agência no aparelho');
  });

  testWidgets('a tela de produção mostra os totais e os dias', (tester) async {
    await tester.runAsync(() async {
      await tester.pumpWidget(MaterialApp(home: TelaProducao(estado: estado, inicio: DateTime(2026, 10, 20))));
      for (var i = 0; i < 100 && find.byKey(const Key('total-skus')).evaluate().isEmpty; i++) {
        await Future<void>.delayed(const Duration(milliseconds: 20));
        await tester.pump();
      }

      expect(find.text('outubro de 2026'), findsOneWidget);
      expect(find.byKey(const Key('total-skus')), findsOneWidget);
      expect(find.text('3'), findsOneWidget);
      expect(find.text('14/10/2026'), findsOneWidget);
      expect(find.text('2 SKU(s) trabalhado(s)'), findsOneWidget);
    });
  });
}
