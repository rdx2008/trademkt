import 'dart:io';

import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/main.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/preferencias.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  test('o texto guardado vira o modo e volta, e valor desconhecido cai no automático', () {
    for (final modo in ThemeMode.values) {
      expect(modoDoTexto(textoDoModo(modo)), modo);
    }
    expect(modoDoTexto(null), ThemeMode.system);
    expect(modoDoTexto('qualquer coisa'), ThemeMode.system);
  });

  test('o tema escuro usa tons claros da cor da empresa e o claro mantém a cor exata', () {
    const cor = Color(0xFF0F766E);
    final claro = temaDaMarca(cor);
    final escuro = temaDaMarca(cor, brilho: Brightness.dark);

    expect(claro.colorScheme.brightness, Brightness.light);
    expect(claro.colorScheme.primary, cor);
    expect(escuro.colorScheme.brightness, Brightness.dark);
    expect(escuro.colorScheme.primary, isNot(cor), reason: 'no escuro o Material clareia a cor para dar contraste');
    expect(escuro.scaffoldBackgroundColor.computeLuminance(), lessThan(0.1));
  });

  test('a escolha de aparência é guardada e volta na próxima abertura', () async {
    final preferencias = PreferenciasMemoria();
    final pasta = await Directory.systemTemp.createTemp('app_promotor_tema');
    final repo = await repositorioEmMemoria();
    EstadoApp novo() => EstadoApp(
          httpClient: ServidorFalso().cliente,
          armazenamento: ArmazenamentoMemoria(),
          repositorio: repo,
          localizacao: LocalizacaoFalsa(posicaoNoPdv()),
          fotos: ServicoFotos(pastaBase: () async => pasta),
          urlCentral: 'http://central.test',
          preferencias: preferencias,
        );

    final primeiro = novo();
    await primeiro.carregar();
    expect(primeiro.modoTema, ThemeMode.system, reason: 'o padrão segue o celular');
    var avisos = 0;
    primeiro.addListener(() => avisos++);
    await primeiro.mudarModoTema(ThemeMode.dark);
    expect(primeiro.modoTema, ThemeMode.dark);
    expect(avisos, 1);
    await primeiro.mudarModoTema(ThemeMode.dark);
    expect(avisos, 1, reason: 'repetir a mesma escolha não refaz a tela');

    final segundo = novo();
    await segundo.carregar();
    expect(segundo.modoTema, ThemeMode.dark);

    await repo.fechar();
    await pasta.delete(recursive: true);
  });
}
