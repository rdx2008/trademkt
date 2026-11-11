/// Grava a foto capturada pela câmera do app, já com marca d'água, na pasta
/// privada do aplicativo. A foto fica no aparelho até o servidor confirmar.
library;

import 'dart:io';

import 'package:flutter/foundation.dart';
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

import '../util/marca_dagua.dart';

Uint8List _marcar(PedidoMarcaDagua pedido) => aplicarMarcaDagua(pedido);

class ServicoFotos {
  ServicoFotos({Future<Directory> Function()? pastaBase}) : _pastaBase = pastaBase ?? getApplicationSupportDirectory;

  final Future<Directory> Function() _pastaBase;

  Future<Directory> _pastaVisita(String visitaId) async {
    final pasta = Directory(p.join((await _pastaBase()).path, 'fotos', 'visita_${visitaId.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '_')}'));
    if (!await pasta.exists()) {
      await pasta.create(recursive: true);
    }

    return pasta;
  }

  /// Aplica a marca d'água em outro isolate e grava `<uuid>.jpg`. Devolve o caminho.
  Future<String> gravar({
    required String visitaId,
    required String uuid,
    required Uint8List original,
    required List<String> linhasMarca,
  }) async {
    final marcado = await compute(_marcar, PedidoMarcaDagua(jpeg: original, linhas: linhasMarca));
    final arquivo = File(p.join((await _pastaVisita(visitaId)).path, '$uuid.jpg'));
    await arquivo.writeAsBytes(marcado, flush: true);

    return arquivo.path;
  }

  Future<Uint8List?> ler(String caminho) async {
    final arquivo = File(caminho);

    return await arquivo.exists() ? arquivo.readAsBytes() : null;
  }

  /// Remove as fotos de visitas já confirmadas há mais de [idade].
  Future<void> limparAntigas({Duration idade = const Duration(days: 30)}) async {
    final raiz = Directory(p.join((await _pastaBase()).path, 'fotos'));
    if (!await raiz.exists()) {
      return;
    }
    final limite = DateTime.now().subtract(idade);
    await for (final entrada in raiz.list(recursive: true)) {
      if (entrada is File && (await entrada.lastModified()).isBefore(limite)) {
        await entrada.delete();
      }
    }
  }
}
