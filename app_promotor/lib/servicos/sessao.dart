/// Persistência das contas (empresa, token e usuário) no armazenamento seguro.
///
/// O promotor pode trabalhar para mais de uma agência: o aparelho guarda uma sessão por
/// conta (empresa + usuário) e uma delas fica ativa.
library;

import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../modelos/usuario.dart';

abstract class ArmazenamentoSessao {
  /// Sessão da conta ativa, ou `null` se não há conta.
  Future<Sessao?> carregar();

  /// Todas as contas guardadas, na ordem em que entraram.
  Future<List<Sessao>> listar();

  /// Grava (ou atualiza) a conta e a torna a ativa.
  Future<void> salvar(Sessao sessao);

  /// Torna ativa uma conta já guardada.
  Future<void> ativar(String chave);

  /// Esquece uma conta. Se era a ativa, nenhuma fica ativa.
  Future<void> remover(String chave);

  Future<void> limpar();
}

/// Estado das contas, comum às implementações.
class _Contas {
  final List<Sessao> lista = [];
  String? ativa;

  Sessao? get sessaoAtiva {
    for (final s in lista) {
      if (s.chave == ativa) {
        return s;
      }
    }

    return null;
  }

  void salvar(Sessao sessao) {
    final i = lista.indexWhere((s) => s.chave == sessao.chave);
    if (i >= 0) {
      lista[i] = sessao;
    } else {
      lista.add(sessao);
    }
    ativa = sessao.chave;
  }

  void ativar(String chave) {
    if (lista.any((s) => s.chave == chave)) {
      ativa = chave;
    }
  }

  void remover(String chave) {
    lista.removeWhere((s) => s.chave == chave);
    if (ativa == chave) {
      ativa = null;
    }
  }

  Map<String, dynamic> toJson() => {'ativa': ativa, 'contas': lista.map((s) => s.toJson()).toList()};

  static _Contas fromJson(Map<String, dynamic> json) {
    final contas = _Contas();
    for (final item in (json['contas'] as List<dynamic>? ?? const [])) {
      contas.lista.add(Sessao.fromJson(item as Map<String, dynamic>));
    }
    contas.ativa = json['ativa'] as String?;

    return contas;
  }
}

/// Usa o Keystore do Android por baixo (`flutter_secure_storage`).
class ArmazenamentoSeguro implements ArmazenamentoSessao {
  ArmazenamentoSeguro([FlutterSecureStorage? armazenamento])
      : _armazenamento = armazenamento ?? const FlutterSecureStorage();

  final FlutterSecureStorage _armazenamento;

  static const _chave = 'contas';

  /// Versão anterior: uma única sessão.
  static const _chaveLegada = 'sessao';

  Future<_Contas> _ler() async {
    try {
      final texto = await _armazenamento.read(key: _chave);
      if (texto != null && texto.isNotEmpty) {
        return _Contas.fromJson(jsonDecode(texto) as Map<String, dynamic>);
      }
      final legada = await _armazenamento.read(key: _chaveLegada);
      if (legada != null && legada.isNotEmpty) {
        final contas = _Contas()..salvar(Sessao.fromJson(jsonDecode(legada) as Map<String, dynamic>));
        await _gravar(contas);
        await _armazenamento.delete(key: _chaveLegada);

        return contas;
      }
    } on Object {
      await limpar();
    }

    return _Contas();
  }

  Future<void> _gravar(_Contas contas) => _armazenamento.write(key: _chave, value: jsonEncode(contas.toJson()));

  @override
  Future<Sessao?> carregar() async => (await _ler()).sessaoAtiva;

  @override
  Future<List<Sessao>> listar() async => List.of((await _ler()).lista);

  @override
  Future<void> salvar(Sessao sessao) async => _gravar((await _ler())..salvar(sessao));

  @override
  Future<void> ativar(String chave) async => _gravar((await _ler())..ativar(chave));

  @override
  Future<void> remover(String chave) async => _gravar((await _ler())..remover(chave));

  @override
  Future<void> limpar() async {
    await _armazenamento.delete(key: _chave);
    await _armazenamento.delete(key: _chaveLegada);
  }
}

/// Só para testes.
class ArmazenamentoMemoria implements ArmazenamentoSessao {
  final _Contas _contas = _Contas();

  /// Conta ativa (atalho para os testes).
  Sessao? get sessao => _contas.sessaoAtiva;

  @override
  Future<Sessao?> carregar() async => _contas.sessaoAtiva;

  @override
  Future<List<Sessao>> listar() async => List.of(_contas.lista);

  @override
  Future<void> salvar(Sessao sessao) async => _contas.salvar(sessao);

  @override
  Future<void> ativar(String chave) async => _contas.ativar(chave);

  @override
  Future<void> remover(String chave) async => _contas.remover(chave);

  @override
  Future<void> limpar() async {
    _contas.lista.clear();
    _contas.ativa = null;
  }
}
