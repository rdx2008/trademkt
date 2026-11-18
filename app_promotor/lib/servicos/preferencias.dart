/// Preferências do aparelho (não são segredo): hoje, só a aparência (claro, escuro ou automático).
library;

import 'package:flutter/material.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';

abstract class PreferenciasApp {
  Future<ThemeMode> carregarTema();

  Future<void> salvarTema(ThemeMode modo);
}

ThemeMode modoDoTexto(String? texto) => switch (texto) {
      'claro' => ThemeMode.light,
      'escuro' => ThemeMode.dark,
      _ => ThemeMode.system,
    };

String textoDoModo(ThemeMode modo) => switch (modo) {
      ThemeMode.light => 'claro',
      ThemeMode.dark => 'escuro',
      ThemeMode.system => 'automatico',
    };

class PreferenciasSeguras implements PreferenciasApp {
  PreferenciasSeguras([FlutterSecureStorage? armazenamento]) : _armazenamento = armazenamento ?? const FlutterSecureStorage();

  final FlutterSecureStorage _armazenamento;

  static const _chave = 'aparencia';

  @override
  Future<ThemeMode> carregarTema() async {
    try {
      return modoDoTexto(await _armazenamento.read(key: _chave));
    } on Object {
      return ThemeMode.system;
    }
  }

  @override
  Future<void> salvarTema(ThemeMode modo) async {
    try {
      await _armazenamento.write(key: _chave, value: textoDoModo(modo));
    } on Object {
      // Preferência é conveniência: se não gravar, vale só até fechar o app.
    }
  }
}

/// Só para testes.
class PreferenciasMemoria implements PreferenciasApp {
  ThemeMode tema = ThemeMode.system;

  @override
  Future<ThemeMode> carregarTema() async => tema;

  @override
  Future<void> salvarTema(ThemeMode modo) async => tema = modo;
}
