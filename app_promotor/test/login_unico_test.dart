import 'dart:io';

import 'package:app_promotor/api/cliente_api.dart';
import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

Map<String, dynamic> _conta(String codigo, String nome, int usuarioId) => {
      'empresa': {'codigo': codigo, 'nome': nome, 'url': 'http://$codigo.test', 'api': 'http://$codigo.test/api/v1', 'api_versao': '1'},
      'token': '1|$codigo',
      'usuario': {
        'id': usuarioId,
        'nome': 'Joana',
        'email': 'joana@exemplo.com',
        'perfil': 'promotor',
        'tipo_vinculo': 'freelancer',
        'agencia': {'id': 1, 'nome': nome},
        'empresa': {'codigo': codigo, 'nome': nome},
      },
    };

void main() {
  late ServidorFalso servidor;
  late EstadoApp estado;
  late ArmazenamentoMemoria armazenamento;
  late Directory pasta;

  setUp(() async {
    servidor = ServidorFalso();
    armazenamento = ArmazenamentoMemoria();
    pasta = await Directory.systemTemp.createTemp('app_promotor_unico');
    estado = EstadoApp(
      httpClient: servidor.cliente,
      armazenamento: armazenamento,
      repositorio: await repositorioEmMemoria(),
      localizacao: LocalizacaoFalsa(posicaoNoPdv()),
      fotos: ServicoFotos(pastaBase: () async => pasta),
      urlCentral: 'http://central.test',
      dispositivo: 'Moto G',
    );
    servidor.responder('GET /api/v1/roteiro', 200, roteiroJson());
    servidor.responder('GET /api/v1/empresas/sul', 200, _conta('sul', 'Agência Sul', 1)['empresa'] as Map<String, dynamic>);
  });

  tearDown(() async {
    for (var i = 0; i < 100 && (estado.atualizandoRoteiro || estado.sincronizando); i++) {
      await Future<void>.delayed(const Duration(milliseconds: 20));
    }
    await estado.repositorio.fechar();
    await pasta.delete(recursive: true);
  });

  test('entra só com e-mail e senha, pela API central, e guarda a conta da agência', () async {
    servidor.responder('POST /api/v1/entrar', 200, {
      'contas': [_conta('sul', 'Agência Sul', 4)],
    });
    await estado.carregar();
    await estado.entrarUnico(email: ' Joana@Exemplo.com ', senha: 'senha1234');

    expect(servidor.das('entrar').single.json, {'email': 'Joana@Exemplo.com', 'password': 'senha1234', 'dispositivo': 'Moto G'});
    expect(estado.autenticado, isTrue);
    expect(estado.sessao!.empresa.codigo, 'sul');
    expect(estado.sessao!.token, '1|sul');
    expect(estado.contas, hasLength(1));
    expect(armazenamento.sessao!.usuario.freelancer, isTrue);
    await estado.atualizarRoteiro();
    expect(servidor.das('roteiro').first.cabecalhos['Authorization'], 'Bearer 1|sul', reason: 'o roteiro já usa o token da agência');
  });

  test('com mais de uma agência guarda todas e abre a primeira', () async {
    servidor.responder('POST /api/v1/entrar', 200, {
      'contas': [_conta('sul', 'Agência Sul', 4), _conta('norte', 'Agência Norte', 9)],
    });
    await estado.carregar();
    await estado.entrarUnico(email: 'joana@exemplo.com', senha: 'senha1234');

    expect(estado.contas.map((c) => c.empresa.codigo), ['sul', 'norte']);
    expect(estado.sessao!.empresa.codigo, 'sul');
  });

  test('e-mail ou senha errados mostram a mensagem do servidor e não guardam nada', () async {
    servidor.responder('POST /api/v1/entrar', 422, {
      'message': 'E-mail ou senha inválidos.',
      'errors': {'email': ['E-mail ou senha inválidos.']},
    });
    await estado.carregar();

    await expectLater(
      estado.entrarUnico(email: 'joana@exemplo.com', senha: 'errada'),
      throwsA(isA<ErroApi>().having((e) => e.descricao, 'descricao', 'E-mail ou senha inválidos.')),
    );
    expect(estado.autenticado, isFalse);
    expect(estado.contas, isEmpty);
  });

  test('sem internet avisa e continua na tela de login', () async {
    servidor.offline = true;
    await estado.carregar();

    await expectLater(estado.entrarUnico(email: 'joana@exemplo.com', senha: 'senha1234'), throwsA(isA<SemConexao>()));
    expect(estado.autenticado, isFalse);
  });

  test('o código da agência continua valendo como caminho alternativo', () async {
    await estado.carregar();
    expect(estado.entrandoPorCodigo, isFalse);

    estado.usarCodigoDaAgencia();
    expect(estado.entrandoPorCodigo, isTrue);
    await estado.descobrirEmpresa('sul');
    expect(estado.empresaPendente!.codigo, 'sul');

    estado.voltarAoLoginUnico();
    expect(estado.entrandoPorCodigo, isFalse);
    expect(estado.empresaPendente, isNull);
  });
}
