/// Guarda no aparelho o logo da empresa, para a marca aparecer também sem internet.
library;

import 'dart:io';

import 'package:http/http.dart' as http;
import 'package:path/path.dart' as p;
import 'package:path_provider/path_provider.dart';

class ServicoMarca {
  ServicoMarca({Future<Directory> Function()? pastaBase}) : _pastaBase = pastaBase ?? getApplicationSupportDirectory;

  final Future<Directory> Function() _pastaBase;

  /// Logos maiores que isto são ignorados (o servidor já reduz para poucos KB).
  static const tamanhoMaximo = 1024 * 1024;

  /// Baixa o logo e devolve o caminho local, ou `null` se não foi possível.
  /// O nome do arquivo leva a versão (`?v=` da URL): logo novo, arquivo novo.
  Future<String?> baixarLogo(http.Client cliente, {required String codigo, required String url}) async {
    try {
      final uri = Uri.parse(url);
      final resposta = await cliente.get(uri);
      if (resposta.statusCode != 200 || resposta.bodyBytes.isEmpty || resposta.bodyBytes.length > tamanhoMaximo) {
        return null;
      }
      final pasta = Directory(p.join((await _pastaBase()).path, 'marca'));
      await pasta.create(recursive: true);
      final prefixo = 'logo_${_seguro(codigo)}_';
      final arquivo = File(p.join(pasta.path, '$prefixo${_seguro(uri.queryParameters['v'] ?? 'x')}.png'));
      await arquivo.writeAsBytes(resposta.bodyBytes, flush: true);
      await for (final outro in pasta.list()) {
        if (outro is File && outro.path != arquivo.path && p.basename(outro.path).startsWith(prefixo)) {
          await outro.delete();
        }
      }

      return arquivo.path;
    } on Object {
      return null;
    }
  }

  Future<bool> existe(String? caminho) async => caminho != null && await File(caminho).exists();

  static String _seguro(String texto) => texto.replaceAll(RegExp(r'[^A-Za-z0-9_-]'), '_');
}
