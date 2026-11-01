/// Chamadas da API usadas pelo app (contratos em `docs/FASE-3.md`).
library;

import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../modelos/roteiro.dart';
import '../modelos/usuario.dart';
import 'cliente_api.dart';

/// API central: descoberta da empresa pelo código da instalação.
class ApiCentral {
  ApiCentral({required http.Client http, required String urlCentral})
      : _cliente = ClienteApi(http: http, baseUrl: '$urlCentral/api/v1');

  final ClienteApi _cliente;

  /// `GET /api/v1/empresas/{codigo}` no domínio central.
  Future<Empresa> descobrir(String codigo) async {
    final limpo = codigo.trim().toLowerCase();
    if (limpo.isEmpty) {
      throw ErroApi(status: 422, mensagem: 'Informe o código da empresa.');
    }
    final resposta = await _cliente.get('empresas/${Uri.encodeComponent(limpo)}');

    return Empresa.fromJson(resposta.corpo);
  }
}

/// API da instalação do cliente (`{url}/api/v1`), com o token do promotor.
class ApiPromotor {
  ApiPromotor(this.cliente);

  final ClienteApi cliente;

  /// `POST /login`. Devolve o token e o usuário.
  Future<({String token, Usuario usuario})> login({
    required String email,
    required String senha,
    required String dispositivo,
  }) async {
    final resposta = await cliente.post('login', {
      'email': email.trim(),
      'password': senha,
      'dispositivo': dispositivo.length > 100 ? dispositivo.substring(0, 100) : dispositivo,
    });

    return (
      token: resposta.corpo['token'] as String,
      usuario: Usuario.fromJson(resposta.corpo['usuario'] as Map<String, dynamic>),
    );
  }

  Future<Usuario> me() async {
    final resposta = await cliente.get('me');

    return Usuario.fromJson(resposta.corpo['usuario'] as Map<String, dynamic>);
  }

  Future<void> logout() => cliente.post('logout');

  /// `GET /roteiro?data=AAAA-MM-DD`.
  Future<Roteiro> roteiro(String data) async {
    final resposta = await cliente.get('roteiro', query: {'data': data});

    return Roteiro.fromJson(resposta.corpo);
  }

  /// `POST /visitas/{visita}/check-in`.
  Future<RespostaJson> checkIn(int visitaId, Map<String, dynamic> payload) =>
      cliente.post('visitas/$visitaId/check-in', payload);

  /// `POST /visitas/{visita}/fotos` (multipart).
  Future<RespostaJson> enviarFoto(
    int visitaId,
    Map<String, String> campos, {
    required Uint8List bytes,
    required String nomeArquivo,
  }) =>
      cliente.postMultipart(
        'visitas/$visitaId/fotos',
        campos,
        campoArquivo: 'foto',
        bytes: bytes,
        nomeArquivo: nomeArquivo,
      );

  /// `POST /visitas/{visita}/concluir`.
  Future<RespostaJson> concluir(int visitaId, Map<String, dynamic> payload) =>
      cliente.post('visitas/$visitaId/concluir', payload);

  /// `GET /visitas/{visita}`: estado atual para reconciliação.
  Future<Map<String, dynamic>> visita(int visitaId) async {
    final resposta = await cliente.get('visitas/$visitaId');

    return resposta.corpo['visita'] as Map<String, dynamic>;
  }
}
