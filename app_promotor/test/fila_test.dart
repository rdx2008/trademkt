import 'package:app_promotor/dados/repositorio_local.dart';
import 'package:app_promotor/modelos/execucao.dart';
import 'package:app_promotor/modelos/roteiro.dart';
import 'package:app_promotor/sincronizacao/item_fila.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

ItemFila item({
  int visita = 1,
  required TipoItemFila tipo,
  TipoFoto? foto,
  required String chave,
  required DateTime criadoEm,
  Map<String, dynamic>? payload,
}) =>
    ItemFila(
      visitaId: visita,
      tipo: tipo,
      chave: chave,
      payload: payload ?? {'chave': chave},
      prioridade: prioridadeFila(tipo, tipoFoto: foto),
      criadoEm: criadoEm,
      arquivo: tipo == TipoItemFila.foto ? '/fotos/$chave.jpg' : null,
    );

void main() {
  final base = DateTime(2026, 10, 31, 9, 0);
  DateTime em(int minutos) => base.add(Duration(minutes: minutos));

  group('prioridade e ordenação', () {
    test('check-in → fotos antes → ruptura → depois → conclusão', () {
      expect(prioridadeFila(TipoItemFila.checkIn), 0);
      expect(prioridadeFila(TipoItemFila.foto, tipoFoto: TipoFoto.antes), 1);
      expect(prioridadeFila(TipoItemFila.foto, tipoFoto: TipoFoto.ruptura), 2);
      expect(prioridadeFila(TipoItemFila.foto, tipoFoto: TipoFoto.depois), 3);
      expect(prioridadeFila(TipoItemFila.conclusao), 4);
    });

    test('ordena pela prioridade dentro da visita mesmo que a criação esteja fora de ordem', () {
      final fila = [
        item(tipo: TipoItemFila.conclusao, chave: 'c', criadoEm: em(30)),
        item(tipo: TipoItemFila.foto, foto: TipoFoto.depois, chave: 'd', criadoEm: em(20)),
        item(tipo: TipoItemFila.foto, foto: TipoFoto.antes, chave: 'a2', criadoEm: em(6)),
        item(tipo: TipoItemFila.foto, foto: TipoFoto.antes, chave: 'a1', criadoEm: em(5)),
        item(tipo: TipoItemFila.checkIn, chave: 'check-in:1', criadoEm: em(0)),
      ];

      expect(ordenarFila(fila).map((i) => i.chave), ['check-in:1', 'a1', 'a2', 'd', 'c']);
    });

    test('visitas são enviadas na ordem em que começaram, sem intercalar', () {
      final fila = [
        item(visita: 2, tipo: TipoItemFila.checkIn, chave: 'check-in:2', criadoEm: em(40)),
        item(visita: 1, tipo: TipoItemFila.conclusao, chave: 'c1', criadoEm: em(50)),
        item(visita: 2, tipo: TipoItemFila.foto, foto: TipoFoto.antes, chave: 'a2', criadoEm: em(45)),
        item(visita: 1, tipo: TipoItemFila.checkIn, chave: 'check-in:1', criadoEm: em(0)),
      ];

      expect(ordenarFila(fila).map((i) => i.chave), ['check-in:1', 'c1', 'check-in:2', 'a2']);
    });
  });

  group('RepositorioLocal (SQLite em memória)', () {
    late RepositorioLocal repo;

    setUp(() async => repo = await repositorioEmMemoria());
    tearDown(() => repo.fechar());

    test('guarda e devolve o roteiro por data', () async {
      expect(await repo.obterRoteiro('2026-10-31'), isNull);
      await repo.salvarRoteiro(Roteiro.fromJson(roteiroJson()));
      final salvo = await repo.obterRoteiro('2026-10-31');

      expect(salvo!.visitas.single.pdv.nome, 'Mercado Centro');
      expect(salvo.industria(3)!.skus.length, 2);
      expect(salvo.industria(3)!.skus.first.embalagens.last.gtin, '17891000315504');
      expect(await repo.baixadoEm('2026-10-31'), isNotNull);
    });

    test('guarda a execução da visita e a lista', () async {
      await repo.salvarExecucao(ExecucaoVisita(visitaId: 1, checkIn: checkInPadrao()));
      await repo.salvarExecucao(ExecucaoVisita(visitaId: 1, checkIn: checkInPadrao(enviado: true)));
      await repo.salvarExecucao(const ExecucaoVisita(visitaId: 2));

      final todas = await repo.listarExecucoes();
      expect(todas.keys, [1, 2]);
      expect(todas[1]!.checkIn!.enviado, isTrue);
      expect((await repo.obterExecucao(1))!.checkIn!.lat, -29.168);
    });

    test('enfileirar é idempotente por chave e preserva o primeiro payload', () async {
      final primeiro = await repo.enfileirar(item(tipo: TipoItemFila.checkIn, chave: 'check-in:1', criadoEm: em(0), payload: {'lat': -29.168}));
      final repetido = await repo.enfileirar(item(tipo: TipoItemFila.checkIn, chave: 'check-in:1', criadoEm: em(1), payload: {'lat': -29.1681}));

      expect(primeiro.id, isNotNull);
      expect(repetido.id, primeiro.id);
      expect(repetido.payload, {'lat': -29.168});
      expect(await repo.contarPendentes(), 1);
    });

    test('estados: pendente → enviado/erro/conflito, contagem e reabertura', () async {
      final a = await repo.enfileirar(item(tipo: TipoItemFila.checkIn, chave: 'check-in:1', criadoEm: em(0)));
      final b = await repo.enfileirar(item(tipo: TipoItemFila.foto, foto: TipoFoto.antes, chave: 'f1', criadoEm: em(1)));
      final c = await repo.enfileirar(item(tipo: TipoItemFila.conclusao, chave: 'c1', criadoEm: em(2)));

      await repo.atualizarItem(a.copiarCom(estado: EstadoItemFila.enviado));
      await repo.atualizarItem(b.copiarCom(estado: EstadoItemFila.erro, ultimoErro: 'Fora do raio', tentativas: 1));
      await repo.atualizarItem(c.copiarCom(estado: EstadoItemFila.conflito, ultimoErro: 'Outra versão'));

      expect(await repo.contarPendentes(), 0);
      expect(await repo.contarComProblema(), 2);
      expect((await repo.listarFila(estado: EstadoItemFila.erro)).single.ultimoErro, 'Fora do raio');
      expect((await repo.listarFila(visitaId: 1)).length, 3);

      await repo.reabrirItens(1);
      expect(await repo.contarPendentes(), 2);
      expect((await repo.obterItemPorChave('f1'))!.ultimoErro, isNull);
      expect((await repo.obterItemPorChave('f1'))!.tentativas, 1);
      expect((await repo.obterItemPorChave('f1'))!.arquivo, '/fotos/f1.jpg');
    });

    test('limpa enviados antigos e tudo', () async {
      final antigo = await repo.enfileirar(item(tipo: TipoItemFila.checkIn, chave: 'check-in:1', criadoEm: DateTime.now().subtract(const Duration(days: 10))));
      final novo = await repo.enfileirar(item(tipo: TipoItemFila.checkIn, chave: 'check-in:2', visita: 2, criadoEm: DateTime.now()));
      await repo.atualizarItem(antigo.copiarCom(estado: EstadoItemFila.enviado));
      await repo.atualizarItem(novo.copiarCom(estado: EstadoItemFila.enviado));

      expect(await repo.limparEnviados(), 1);
      expect((await repo.listarFila()).single.chave, 'check-in:2');

      await repo.salvarRoteiro(Roteiro.fromJson(roteiroJson()));
      await repo.limparTudo();
      expect(await repo.listarFila(), isEmpty);
      expect(await repo.obterRoteiro('2026-10-31'), isNull);
    });
  });
}
