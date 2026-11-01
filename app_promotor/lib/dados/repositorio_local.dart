/// Banco local (SQLite): roteiro em cache, execuções e fila de sincronização.
library;

import 'dart:convert';

import 'package:path/path.dart' as p;
import 'package:sqflite/sqflite.dart';

import '../modelos/execucao.dart';
import '../modelos/roteiro.dart';
import '../sincronizacao/item_fila.dart';

class RepositorioLocal {
  RepositorioLocal._(this._db);

  final Database _db;

  static const _versao = 1;

  /// Abre (ou cria) o banco. Em testes, passe a `databaseFactoryFfi` e `inMemoryDatabasePath`.
  static Future<RepositorioLocal> abrir({DatabaseFactory? fabrica, String? caminho}) async {
    final f = fabrica ?? databaseFactory;
    final destino = caminho ?? p.join(await f.getDatabasesPath(), 'app_promotor.db');
    final db = await f.openDatabase(
      destino,
      options: OpenDatabaseOptions(version: _versao, onCreate: _criar),
    );

    return RepositorioLocal._(db);
  }

  static Future<void> _criar(Database db, int versao) async {
    await db.execute('''
      CREATE TABLE roteiros (
        data TEXT PRIMARY KEY,
        json TEXT NOT NULL,
        baixado_em TEXT NOT NULL
      )''');
    await db.execute('''
      CREATE TABLE execucoes (
        visita_id INTEGER PRIMARY KEY,
        json TEXT NOT NULL,
        atualizado_em TEXT NOT NULL
      )''');
    await db.execute('''
      CREATE TABLE fila (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        visita_id INTEGER NOT NULL,
        tipo TEXT NOT NULL,
        chave TEXT NOT NULL UNIQUE,
        payload TEXT NOT NULL,
        prioridade INTEGER NOT NULL,
        criado_em TEXT NOT NULL,
        arquivo TEXT,
        tentativas INTEGER NOT NULL DEFAULT 0,
        ultimo_erro TEXT,
        estado TEXT NOT NULL DEFAULT 'pendente'
      )''');
    await db.execute('CREATE INDEX fila_estado ON fila (estado, visita_id)');
  }

  Future<void> fechar() => _db.close();

  // ---------------------------------------------------------------- roteiros

  Future<void> salvarRoteiro(Roteiro roteiro) => _db.insert(
        'roteiros',
        {'data': roteiro.data, 'json': jsonEncode(roteiro.toJson()), 'baixado_em': DateTime.now().toIso8601String()},
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

  Future<Roteiro?> obterRoteiro(String data) async {
    final linhas = await _db.query('roteiros', where: 'data = ?', whereArgs: [data], limit: 1);
    if (linhas.isEmpty) {
      return null;
    }

    return Roteiro.fromJson(jsonDecode(linhas.first['json'] as String) as Map<String, dynamic>);
  }

  Future<DateTime?> baixadoEm(String data) async {
    final linhas = await _db.query('roteiros', columns: ['baixado_em'], where: 'data = ?', whereArgs: [data], limit: 1);

    return linhas.isEmpty ? null : DateTime.tryParse(linhas.first['baixado_em'] as String);
  }

  // --------------------------------------------------------------- execuções

  Future<void> salvarExecucao(ExecucaoVisita execucao) => _db.insert(
        'execucoes',
        {
          'visita_id': execucao.visitaId,
          'json': jsonEncode(execucao.toJson()),
          'atualizado_em': DateTime.now().toIso8601String(),
        },
        conflictAlgorithm: ConflictAlgorithm.replace,
      );

  /// Encadeia as alterações de execução para que a tela e o sincronizador
  /// nunca gravem uma por cima da outra (ler → alterar → gravar sem intercalar).
  Future<void> _ultimaAlteracao = Future.value();

  Future<ExecucaoVisita> modificarExecucao(int visitaId, ExecucaoVisita Function(ExecucaoVisita atual) alterar) {
    final resultado = _ultimaAlteracao.then((_) async {
      final atual = await obterExecucao(visitaId) ?? ExecucaoVisita(visitaId: visitaId);
      final nova = alterar(atual);
      await salvarExecucao(nova);

      return nova;
    });
    _ultimaAlteracao = resultado.then((_) {}, onError: (_) {});

    return resultado;
  }

  Future<ExecucaoVisita?> obterExecucao(int visitaId) async {
    final linhas = await _db.query('execucoes', where: 'visita_id = ?', whereArgs: [visitaId], limit: 1);
    if (linhas.isEmpty) {
      return null;
    }

    return ExecucaoVisita.fromJson(jsonDecode(linhas.first['json'] as String) as Map<String, dynamic>);
  }

  Future<Map<int, ExecucaoVisita>> listarExecucoes() async {
    final linhas = await _db.query('execucoes');

    return {
      for (final linha in linhas)
        linha['visita_id'] as int:
            ExecucaoVisita.fromJson(jsonDecode(linha['json'] as String) as Map<String, dynamic>),
    };
  }

  // -------------------------------------------------------------------- fila

  /// Coloca o item na fila. Se a chave já existir, devolve o item existente
  /// sem alterar o payload: o que entrou primeiro é o que será reenviado.
  Future<ItemFila> enfileirar(ItemFila item) async {
    final existente = await obterItemPorChave(item.chave);
    if (existente != null) {
      return existente;
    }
    final id = await _db.insert('fila', _paraLinha(item), conflictAlgorithm: ConflictAlgorithm.ignore);
    if (id == 0) {
      return (await obterItemPorChave(item.chave))!;
    }

    return item.copiarCom(id: id);
  }

  Future<ItemFila?> obterItemPorChave(String chave) async {
    final linhas = await _db.query('fila', where: 'chave = ?', whereArgs: [chave], limit: 1);

    return linhas.isEmpty ? null : _deLinha(linhas.first);
  }

  Future<List<ItemFila>> listarFila({EstadoItemFila? estado, int? visitaId}) async {
    final condicoes = <String>[];
    final args = <Object>[];
    if (estado != null) {
      condicoes.add('estado = ?');
      args.add(estado.valor);
    }
    if (visitaId != null) {
      condicoes.add('visita_id = ?');
      args.add(visitaId);
    }
    final linhas = await _db.query(
      'fila',
      where: condicoes.isEmpty ? null : condicoes.join(' AND '),
      whereArgs: args.isEmpty ? null : args,
      orderBy: 'id',
    );

    return linhas.map(_deLinha).toList();
  }

  Future<void> atualizarItem(ItemFila item) =>
      _db.update('fila', _paraLinha(item), where: 'id = ?', whereArgs: [item.id]);

  Future<int> contarPendentes() async {
    final resultado = await _db.rawQuery("SELECT COUNT(*) AS n FROM fila WHERE estado = 'pendente'");

    return Sqflite.firstIntValue(resultado) ?? 0;
  }

  Future<int> contarComProblema() async {
    final resultado = await _db.rawQuery("SELECT COUNT(*) AS n FROM fila WHERE estado IN ('erro', 'conflito')");

    return Sqflite.firstIntValue(resultado) ?? 0;
  }

  /// Volta itens com erro/conflito da visita para a fila (nova tentativa manual).
  Future<void> reabrirItens(int visitaId) => _db.update(
        'fila',
        {'estado': EstadoItemFila.pendente.valor, 'ultimo_erro': null},
        where: "visita_id = ? AND estado IN ('erro', 'conflito')",
        whereArgs: [visitaId],
      );

  /// Remove itens já confirmados pelo servidor há mais tempo que [idade].
  Future<int> limparEnviados({Duration idade = const Duration(days: 7)}) => _db.delete(
        'fila',
        where: 'estado = ? AND criado_em < ?',
        whereArgs: [EstadoItemFila.enviado.valor, DateTime.now().subtract(idade).toIso8601String()],
      );

  /// Apaga tudo (troca de usuário). Só deve ser chamado sem pendências.
  Future<void> limparTudo() async {
    await _db.delete('fila');
    await _db.delete('execucoes');
    await _db.delete('roteiros');
  }

  Map<String, Object?> _paraLinha(ItemFila item) => {
        if (item.id != null) 'id': item.id,
        'visita_id': item.visitaId,
        'tipo': item.tipo.valor,
        'chave': item.chave,
        'payload': jsonEncode(item.payload),
        'prioridade': item.prioridade,
        'criado_em': item.criadoEm.toIso8601String(),
        'arquivo': item.arquivo,
        'tentativas': item.tentativas,
        'ultimo_erro': item.ultimoErro,
        'estado': item.estado.valor,
      };

  ItemFila _deLinha(Map<String, Object?> linha) => ItemFila(
        id: linha['id'] as int,
        visitaId: linha['visita_id'] as int,
        tipo: TipoItemFila.deValor(linha['tipo'] as String),
        chave: linha['chave'] as String,
        payload: jsonDecode(linha['payload'] as String) as Map<String, dynamic>,
        prioridade: linha['prioridade'] as int,
        criadoEm: DateTime.parse(linha['criado_em'] as String),
        arquivo: linha['arquivo'] as String?,
        tentativas: linha['tentativas'] as int,
        ultimoErro: linha['ultimo_erro'] as String?,
        estado: EstadoItemFila.deValor(linha['estado'] as String),
      );
}
