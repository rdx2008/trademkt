/// Cliente HTTP fino sobre `package:http`, com token Bearer e erros tipados.
library;

import 'dart:async';
import 'dart:convert';
import 'dart:io';
import 'dart:typed_data';

import 'package:http/http.dart' as http;

/// Resposta recusada pelo servidor (4xx/5xx).
class ErroApi implements Exception {
  ErroApi({required this.status, required this.mensagem, Map<String, List<String>>? erros}) : erros = erros ?? const {};

  final int status;
  final String mensagem;

  /// Erros de validação do Laravel (`errors`), por campo.
  final Map<String, List<String>> erros;

  bool get naoAutenticado => status == 401;
  bool get conflito => status == 409;
  bool get validacao => status == 422;
  bool get definitivo => status >= 400 && status < 500 && status != 408 && status != 429;

  /// Primeira mensagem de validação, ou a mensagem geral.
  String get descricao {
    for (final lista in erros.values) {
      if (lista.isNotEmpty) {
        return lista.first;
      }
    }

    return mensagem;
  }

  @override
  String toString() => 'ErroApi($status): $descricao';
}

/// Sem rede, tempo esgotado ou servidor inacessível.
class SemConexao implements Exception {
  const SemConexao([this.mensagem = 'Sem conexão com o servidor.']);

  final String mensagem;

  @override
  String toString() => mensagem;
}

class RespostaJson {
  const RespostaJson(this.status, this.corpo);

  final int status;
  final Map<String, dynamic> corpo;

  bool get criado => status == 201;
}

class ClienteApi {
  ClienteApi({
    required http.Client http,
    required String baseUrl,
    this.token,
    this.tempoLimite = const Duration(seconds: 30),
    this.tempoLimiteUpload = const Duration(minutes: 2),
  })  : _http = http, // ignore: prefer_initializing_formals
        baseUrl = baseUrl.replaceAll(RegExp(r'/+$'), '');

  final http.Client _http;

  /// Ex.: `https://xyz.trade.mastervps.online/api/v1`.
  final String baseUrl;
  String? token;
  final Duration tempoLimite;
  final Duration tempoLimiteUpload;

  Uri _uri(String caminho, [Map<String, String>? query]) =>
      Uri.parse('$baseUrl/${caminho.replaceAll(RegExp(r'^/+'), '')}').replace(queryParameters: query);

  Map<String, String> _cabecalhos({bool json = true}) => {
        'Accept': 'application/json',
        if (json) 'Content-Type': 'application/json',
        if (token != null) 'Authorization': 'Bearer $token',
      };

  Future<RespostaJson> get(String caminho, {Map<String, String>? query}) =>
      _executar(() => _http.get(_uri(caminho, query), headers: _cabecalhos(json: false)).timeout(tempoLimite));

  Future<RespostaJson> post(String caminho, [Map<String, dynamic>? corpo]) => _executar(
        () => _http
            .post(_uri(caminho), headers: _cabecalhos(), body: jsonEncode(corpo ?? const <String, dynamic>{}))
            .timeout(tempoLimite),
      );

  Future<RespostaJson> postMultipart(
    String caminho,
    Map<String, String> campos, {
    required String campoArquivo,
    required Uint8List bytes,
    required String nomeArquivo,
  }) =>
      _executar(() async {
        final pedido = http.MultipartRequest('POST', _uri(caminho))
          ..headers.addAll(_cabecalhos(json: false))
          ..fields.addAll(campos)
          ..files.add(http.MultipartFile.fromBytes(campoArquivo, bytes, filename: nomeArquivo));
        final enviado = await _http.send(pedido).timeout(tempoLimiteUpload);

        return http.Response.fromStream(enviado);
      });

  Future<RespostaJson> _executar(Future<http.Response> Function() chamada) async {
    http.Response resposta;
    try {
      resposta = await chamada();
    } on SocketException catch (e) {
      throw SemConexao('Sem conexão: ${e.message}');
    } on TimeoutException {
      throw const SemConexao('O servidor demorou a responder.');
    } on http.ClientException catch (e) {
      throw SemConexao('Falha de rede: ${e.message}');
    } on HandshakeException {
      throw const SemConexao('Falha no certificado HTTPS do servidor.');
    }

    final corpo = _decodificar(resposta);
    if (resposta.statusCode >= 400) {
      throw ErroApi(
        status: resposta.statusCode,
        mensagem: corpo['message'] as String? ?? 'Erro ${resposta.statusCode} no servidor.',
        erros: _erros(corpo['errors']),
      );
    }

    return RespostaJson(resposta.statusCode, corpo);
  }

  static Map<String, dynamic> _decodificar(http.Response resposta) {
    if (resposta.body.isEmpty) {
      return const {};
    }
    try {
      final decodificado = jsonDecode(utf8.decode(resposta.bodyBytes));

      return decodificado is Map<String, dynamic> ? decodificado : {'dados': decodificado};
    } on FormatException {
      return {'message': 'Resposta inesperada do servidor (${resposta.statusCode}).'};
    }
  }

  static Map<String, List<String>> _erros(dynamic erros) {
    if (erros is! Map) {
      return const {};
    }

    return {
      for (final entrada in erros.entries)
        entrada.key.toString(): entrada.value is List
            ? (entrada.value as List).map((e) => e.toString()).toList()
            : [entrada.value.toString()],
    };
  }
}
