/// Persistência da sessão (empresa, token e usuário) no armazenamento seguro.
library;

import 'dart:convert';

import 'package:flutter_secure_storage/flutter_secure_storage.dart';

import '../modelos/usuario.dart';

abstract class ArmazenamentoSessao {
  Future<Sessao?> carregar();

  Future<void> salvar(Sessao sessao);

  Future<void> limpar();
}

/// Usa o Keystore do Android por baixo (`flutter_secure_storage`).
class ArmazenamentoSeguro implements ArmazenamentoSessao {
  ArmazenamentoSeguro([FlutterSecureStorage? armazenamento])
      : _armazenamento = armazenamento ?? const FlutterSecureStorage();

  final FlutterSecureStorage _armazenamento;

  static const _chave = 'sessao';

  @override
  Future<Sessao?> carregar() async {
    final texto = await _armazenamento.read(key: _chave);
    if (texto == null || texto.isEmpty) {
      return null;
    }
    try {
      return Sessao.fromJson(jsonDecode(texto) as Map<String, dynamic>);
    } on Object {
      await limpar();

      return null;
    }
  }

  @override
  Future<void> salvar(Sessao sessao) => _armazenamento.write(key: _chave, value: jsonEncode(sessao.toJson()));

  @override
  Future<void> limpar() => _armazenamento.delete(key: _chave);
}

/// Só para testes.
class ArmazenamentoMemoria implements ArmazenamentoSessao {
  Sessao? sessao;

  @override
  Future<Sessao?> carregar() async => sessao;

  @override
  Future<void> salvar(Sessao sessao) async => this.sessao = sessao;

  @override
  Future<void> limpar() async => sessao = null;
}
