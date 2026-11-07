/// Fila de sincronização contra um servidor HTTP falso: ordem, idempotência,
/// comportamento offline, erros definitivos e reconciliação em 409.
library;

import 'dart:typed_data';

import 'package:app_promotor/api/api_promotor.dart';
import 'package:app_promotor/api/cliente_api.dart';
import 'package:app_promotor/dados/repositorio_local.dart';
import 'package:app_promotor/modelos/execucao.dart';
import 'package:app_promotor/modelos/payloads.dart';
import 'package:app_promotor/sincronizacao/item_fila.dart';
import 'package:app_promotor/sincronizacao/sincronizador.dart';
import 'package:app_promotor/util/datas.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  late RepositorioLocal repo;
  late ServidorFalso servidor;
  late Sincronizador sinc;

  final t0 = DateTime(2026, 10, 31, 9, 0, 0);
  final t1 = DateTime(2026, 10, 31, 9, 5, 0);
  final t2 = DateTime(2026, 10, 31, 9, 20, 0);
  final t3 = DateTime(2026, 10, 31, 9, 30, 0);

  const uuidAntes = '11111111-1111-4111-8111-111111111111';
  const uuidDepois = '22222222-2222-4222-8222-222222222222';
  const uuidConclusao = '33333333-3333-4333-8333-333333333333';

  Future<Uint8List?> lerArquivo(String caminho) async => caminho.contains('inexistente') ? null : jpegDeTeste();

  setUp(() async {
    repo = await repositorioEmMemoria();
    servidor = ServidorFalso();
    final api = ApiPromotor(ClienteApi(http: servidor.cliente, baseUrl: 'http://xyz.test/api/v1', token: 'token-teste'));
    sinc = Sincronizador(api: api, repositorio: repo, lerArquivo: lerArquivo);

    servidor.responder('POST /api/v1/visitas/1/check-in', 201, {
      'id': 1,
      'status': 'em_andamento',
      'check_in': {'lat': -29.168, 'lng': -51.179, 'distancia_m': 0, 'raio_m': 200, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0), 'recebido_em': formatarIso8601ComFuso(t0)},
    });
    servidor.responder('POST /api/v1/visitas/1/fotos', 201, {'foto': {'id': 1}});
    servidor.responder('POST /api/v1/visitas/1/concluir', 201, {'visita': {'id': 1, 'status': 'realizada', 'duracao_segundos': 1800}});
  });

  tearDown(() => repo.fechar());

  /// Visita completa gravada no aparelho e enfileirada fora de ordem de propósito.
  Future<void> prepararVisitaCompleta() async {
    final checkIn = checkInPadrao(gpsEm: t0);
    final antes = fotoPadrao(TipoFoto.antes, uuid: uuidAntes, gpsEm: t1);
    final depois = fotoPadrao(TipoFoto.depois, uuid: uuidDepois, gpsEm: t2);
    final conclusao = ConclusaoLocal(
      uuid: uuidConclusao,
      tipo: TipoConclusao.normal,
      finalizadaEm: t3,
      observacao: '',
      itens: const [ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.temEstoque)],
    );
    await repo.salvarExecucao(ExecucaoVisita(visitaId: '1', checkIn: checkIn, fotos: [antes, depois], conclusao: conclusao));

    await repo.enfileirar(ItemFila(visitaId: '1', tipo: TipoItemFila.conclusao, chave: uuidConclusao, payload: ConclusaoPayload.deLocal(conclusao).toJson(), prioridade: 4, criadoEm: t3));
    await repo.enfileirar(ItemFila(visitaId: '1', tipo: TipoItemFila.foto, chave: uuidDepois, payload: FotoPayload.deLocal(depois).toJson(), arquivo: depois.caminho, prioridade: 3, criadoEm: t2));
    await repo.enfileirar(ItemFila(visitaId: '1', tipo: TipoItemFila.foto, chave: uuidAntes, payload: FotoPayload.deLocal(antes).toJson(), arquivo: antes.caminho, prioridade: 1, criadoEm: t1));
    await repo.enfileirar(ItemFila(visitaId: '1', tipo: TipoItemFila.checkIn, chave: ItemFila.chaveCheckIn('1'), payload: CheckInPayload.deLocal(checkIn).toJson(), prioridade: 0, criadoEm: t0));
  }

  test('envia na ordem check-in → foto antes → foto depois → conclusão com os payloads do contrato', () async {
    await prepararVisitaCompleta();

    final resultado = await sinc.sincronizar();

    expect(resultado.enviados, 4);
    expect(resultado.tudoCerto, isTrue);
    expect(resultado.pendentes, 0);
    expect(servidor.chamadas.map((c) => c.caminho), [
      '/api/v1/visitas/1/check-in',
      '/api/v1/visitas/1/fotos',
      '/api/v1/visitas/1/fotos',
      '/api/v1/visitas/1/concluir',
    ]);
    expect(servidor.chamadas.every((c) => c.cabecalhos['Authorization'] == 'Bearer token-teste'), isTrue);
    expect(servidor.chamadas.every((c) => c.cabecalhos['Accept'] == 'application/json'), isTrue);

    final checkIn = servidor.chamadas[0].json;
    expect(checkIn, {
      'lat': -29.168,
      'lng': -51.179,
      'gps_em': formatarIso8601ComFuso(t0),
      'aparelho_em': formatarIso8601ComFuso(t0),
      'gps_simulado': false,
    });

    final fotoAntes = servidor.chamadas[1];
    expect(fotoAntes.cabecalhos['content-type'], startsWith('multipart/form-data; boundary='));
    expect(fotoAntes.corpo, contains('name="uuid"\r\n\r\n$uuidAntes'));
    expect(fotoAntes.corpo, contains('name="tipo"\r\n\r\nantes'));
    expect(fotoAntes.corpo, contains('name="lat"\r\n\r\n-29.168'));
    expect(fotoAntes.corpo, contains('name="lng"\r\n\r\n-51.179'));
    expect(fotoAntes.corpo, contains('name="gps_em"\r\n\r\n${formatarIso8601ComFuso(t1)}'));
    expect(fotoAntes.corpo, contains('name="aparelho_em"\r\n\r\n${formatarIso8601ComFuso(t1)}'));
    expect(fotoAntes.corpo, contains('name="gps_simulado"\r\n\r\n0'));
    expect(fotoAntes.corpo, contains('name="foto"; filename="$uuidAntes.jpg"'));
    expect(fotoAntes.corpo, isNot(contains('caminho')));
    expect(servidor.chamadas[2].corpo, contains('name="tipo"\r\n\r\ndepois'));

    final conclusao = servidor.chamadas[3].json;
    expect(conclusao, {
      'uuid': uuidConclusao,
      'tipo': 'normal',
      'finalizada_em': formatarIso8601ComFuso(t3),
      'observacao': '',
      'itens': [
        {'sku_id': 10, 'estoque': 'tem_estoque'},
      ],
    });

    final execucao = (await repo.obterExecucao('1'))!;
    expect(execucao.checkIn!.enviado, isTrue);
    expect(execucao.fotos.every((f) => f.enviada), isTrue);
    expect(execucao.conclusao!.enviada, isTrue);
    expect(execucao.statusServidor, 'realizada');
    expect(execucao.temPendencias, isFalse);
    expect((await repo.listarFila()).every((i) => i.estado == EstadoItemFila.enviado), isTrue);
  });

  test('sem conexão nada é perdido e o reenvio manda exatamente o mesmo corpo', () async {
    await prepararVisitaCompleta();
    servidor.offline = true;

    final offline = await sinc.sincronizar();
    expect(offline.semConexao, isTrue);
    expect(offline.enviados, 0);
    expect(offline.pendentes, 4);
    expect((await repo.obterItemPorChave('check-in:1'))!.tentativas, 1);

    // Primeira chamada passa e a rede cai no meio.
    servidor.offline = false;
    var chamadas = 0;
    final respostaCheckIn = servidor.respostas['POST /api/v1/visitas/1/check-in']!;
    servidor.respostas['POST /api/v1/visitas/1/fotos'] = (_) => throw const SemConexao();
    servidor.respostas['POST /api/v1/visitas/1/check-in'] = (c) {
      chamadas++;

      return respostaCheckIn(c);
    };
    final parcial = await sinc.sincronizar();
    expect(parcial.enviados, 1);
    expect(parcial.semConexao, isTrue);
    expect(parcial.pendentes, 3);
    final primeiroCorpoFoto = servidor.das('fotos').last.corpo;

    // Volta a rede: check-in não é reenviado (já confirmado), a foto vai igual.
    servidor.responder('POST /api/v1/visitas/1/fotos', 201, {'foto': {'id': 1}});
    final completo = await sinc.sincronizar();
    expect(completo.tudoCerto, isTrue);
    expect(chamadas, 1);
    final segundoCorpoFoto = servidor.das('fotos').where((c) => c.corpo.contains(uuidAntes)).last.corpo;
    expect(_semBoundary(segundoCorpoFoto), _semBoundary(primeiroCorpoFoto));
  });

  test('reenvio aceito com 200 (idempotente no servidor) conta como enviado', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/check-in', 200, {'id': 1, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0)}});
    servidor.responder('POST /api/v1/visitas/1/fotos', 200, {'foto': {'id': 1}});
    servidor.responder('POST /api/v1/visitas/1/concluir', 200, {'visita': {'id': 1, 'status': 'realizada'}});

    final resultado = await sinc.sincronizar();

    expect(resultado.enviados, 4);
    expect(resultado.tudoCerto, isTrue);
  });

  test('422 no check-in bloqueia o resto da visita e marca o item com a mensagem do servidor', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/check-in', 422, {
      'message': 'Check-in fora do raio permitido do PDV.',
      'errors': {'lat': ['Check-in fora do raio permitido do PDV.']},
    });

    final resultado = await sinc.sincronizar();

    expect(resultado.erros, 1);
    expect(resultado.enviados, 0);
    expect(resultado.pendentes, 3);
    expect(resultado.mensagens.single, contains('fora do raio'));
    expect(servidor.chamadas.length, 1);
    final item = (await repo.obterItemPorChave('check-in:1'))!;
    expect(item.estado, EstadoItemFila.erro);
    expect(item.ultimoErro, 'Check-in fora do raio permitido do PDV.');
    expect(await repo.contarComProblema(), 1);
  });

  test('erro de uma visita não impede o envio de outra', () async {
    await prepararVisitaCompleta();
    final checkIn2 = checkInPadrao(gpsEm: t0);
    await repo.salvarExecucao(ExecucaoVisita(visitaId: '2', checkIn: checkIn2));
    await repo.enfileirar(ItemFila(visitaId: '2', tipo: TipoItemFila.checkIn, chave: 'check-in:2', payload: CheckInPayload.deLocal(checkIn2).toJson(), prioridade: 0, criadoEm: t3));
    servidor.responder('POST /api/v1/visitas/1/check-in', 404, {'message': 'No query results.'});
    servidor.responder('POST /api/v1/visitas/2/check-in', 201, {'id': 2, 'status': 'em_andamento', 'check_in': {'lat': -29.168, 'lng': -51.179, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0)}});

    final resultado = await sinc.sincronizar();

    expect(resultado.erros, 1);
    expect(resultado.enviados, 1);
    expect(servidor.chamadas.map((c) => c.caminho), ['/api/v1/visitas/1/check-in', '/api/v1/visitas/2/check-in']);
    expect((await repo.obterExecucao('2'))!.checkIn!.enviado, isTrue);
  });

  test('5xx mantém o item pendente para a próxima rodada', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/check-in', 503, {'message': 'Manutenção'});

    final resultado = await sinc.sincronizar();

    expect(resultado.erros, 0);
    expect(resultado.pendentes, 4);
    expect((await repo.obterItemPorChave('check-in:1'))!.estado, EstadoItemFila.pendente);
    expect((await repo.obterItemPorChave('check-in:1'))!.tentativas, 1);
  });

  test('401 interrompe e sinaliza sessão expirada, sem apagar a fila', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/check-in', 401, {'message': 'Unauthenticated.'});

    final resultado = await sinc.sincronizar();

    expect(resultado.sessaoExpirada, isTrue);
    expect(resultado.pendentes, 4);
    expect(servidor.chamadas.length, 1);
  });

  test('409 numa foto já gravada é resolvido pela reconciliação com GET /visitas/{id}', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/fotos', 409, {'message': 'UUID já usado com outra foto ou metadados.'});
    servidor.responder('GET /api/v1/visitas/1', 200, {
      'visita': {
        'id': 1,
        'status': 'em_andamento',
        'tipo_conclusao': null,
        'check_in': {'lat': -29.168, 'lng': -51.179, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0)},
        'itens': [],
        'fotos': [
          {'id': 1, 'uuid': uuidAntes, 'tipo': 'antes', 'metadados': {}},
          {'id': 2, 'uuid': uuidDepois, 'tipo': 'depois', 'metadados': {}},
        ],
      },
    });

    final resultado = await sinc.sincronizar();

    expect(resultado.conflitos, 0);
    expect(resultado.enviados, 4);
    expect(resultado.tudoCerto, isTrue);
    expect(servidor.das('visitas/1').where((c) => c.metodo == 'GET').length, 1, reason: 'a segunda foto já foi alinhada pela primeira reconciliação');
    final execucao = (await repo.obterExecucao('1'))!;
    expect(execucao.fotos.every((f) => f.enviada), isTrue);
    expect(execucao.conclusao!.enviada, isTrue);
  });

  test('409 na conclusão com outra versão no servidor fica em conflito', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/concluir', 409, {'message': 'A visita já possui outra finalização.'});
    servidor.responder('GET /api/v1/visitas/1', 200, {
      'visita': {'id': 1, 'status': 'em_atencao', 'tipo_conclusao': 'impedimento', 'check_in': {'lat': -29.168, 'lng': -51.179, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0)}, 'itens': [], 'fotos': [{'uuid': uuidAntes, 'tipo': 'antes'}, {'uuid': uuidDepois, 'tipo': 'depois'}]},
    });

    final resultado = await sinc.sincronizar();

    expect(resultado.enviados, 3);
    expect(resultado.conflitos, 1);
    expect(resultado.mensagens.single, contains('outra finalização'));
    final item = (await repo.obterItemPorChave(uuidConclusao))!;
    expect(item.estado, EstadoItemFila.conflito);
    expect((await repo.obterExecucao('1'))!.statusServidor, 'em_atencao');
  });

  test('409 no check-in de visita já encerrada é aceito se o servidor tem o check-in', () async {
    await prepararVisitaCompleta();
    servidor.responder('POST /api/v1/visitas/1/check-in', 409, {'message': 'A visita não está prevista.'});
    servidor.responder('GET /api/v1/visitas/1', 200, {
      'visita': {'id': 1, 'status': 'realizada', 'tipo_conclusao': 'normal', 'check_in': {'lat': -29.168, 'lng': -51.179, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0), 'distancia_m': 0}, 'itens': [], 'fotos': [{'uuid': uuidAntes, 'tipo': 'antes'}, {'uuid': uuidDepois, 'tipo': 'depois'}]},
    });

    final resultado = await sinc.sincronizar();

    // Tudo já estava no servidor: nada é reenviado e nada fica pendente.
    expect(resultado.tudoCerto, isTrue);
    expect(resultado.pendentes, 0);
    expect(servidor.chamadas.where((c) => c.metodo == 'POST').length, 1);
    expect((await repo.listarFila()).every((i) => i.estado == EstadoItemFila.enviado), isTrue);
  });

  test('foto sem arquivo no aparelho vira erro definitivo em vez de travar a fila', () async {
    final checkIn = checkInPadrao(gpsEm: t0, enviado: true);
    final foto = FotoLocal(uuid: uuidAntes, tipo: TipoFoto.antes, caminho: '/fotos/inexistente.jpg', lat: -29.168, lng: -51.179, gpsEm: t1, aparelhoEm: t1);
    await repo.salvarExecucao(ExecucaoVisita(visitaId: '1', checkIn: checkIn, fotos: [foto]));
    await repo.enfileirar(ItemFila(visitaId: '1', tipo: TipoItemFila.foto, chave: uuidAntes, payload: FotoPayload.deLocal(foto).toJson(), arquivo: foto.caminho, prioridade: 1, criadoEm: t1));

    final resultado = await sinc.sincronizar();

    expect(resultado.erros, 1);
    expect((await repo.obterItemPorChave(uuidAntes))!.ultimoErro, contains('não encontrado'));
    expect(servidor.chamadas, isEmpty);
  });

  test('reconciliar cria o check-in local a partir do servidor quando o aparelho não o tem', () async {
    servidor.responder('GET /api/v1/visitas/1', 200, {
      'visita': {'id': 1, 'status': 'em_andamento', 'tipo_conclusao': null, 'check_in': {'lat': -29.168, 'lng': -51.179, 'gps_em': formatarIso8601ComFuso(t0), 'aparelho_em': formatarIso8601ComFuso(t0), 'distancia_m': 12.5}, 'itens': [], 'fotos': []},
    });

    final execucao = await sinc.reconciliar('1');

    expect(execucao.checkIn!.enviado, isTrue);
    expect(execucao.checkIn!.distanciaM, 12.5);
    expect(execucao.statusServidor, 'em_andamento');
  });
}

/// Compara corpos multipart ignorando o boundary aleatório.
String _semBoundary(String corpo) => corpo.replaceAll(RegExp(r'--dart-http-boundary-[A-Za-z0-9+/=._-]+'), '--boundary');
