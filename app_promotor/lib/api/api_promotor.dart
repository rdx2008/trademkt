/// Chamadas da API usadas pelo app (contratos em `docs/FASE-3.md`).
library;

import 'dart:typed_data';

import 'package:http/http.dart' as http;

import '../modelos/producao.dart';
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

  /// `POST /api/v1/entrar`: login único, só com e-mail e senha. O servidor acha as agências onde a
  /// pessoa está cadastrada e devolve uma sessão por agência em que a senha confere.
  Future<List<Sessao>> entrar({required String email, required String senha, required String dispositivo}) async {
    final resposta = await _cliente.post('entrar', {
      'email': email.trim(),
      'password': senha,
      'dispositivo': dispositivo.length > 100 ? dispositivo.substring(0, 100) : dispositivo,
    });

    return (resposta.corpo['contas'] as List<dynamic>? ?? const [])
        .map((c) => c as Map<String, dynamic>)
        .map(
          (c) => Sessao(
            empresa: Empresa.fromJson(c['empresa'] as Map<String, dynamic>),
            token: c['token'] as String,
            usuario: Usuario.fromJson(c['usuario'] as Map<String, dynamic>),
          ),
        )
        .toList();
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

  /// `POST /senha`: troca a senha desta agência (os outros aparelhos são desconectados pelo servidor).
  Future<void> alterarSenha({required String atual, required String nova}) async {
    await cliente.post('senha', {'senha_atual': atual, 'password': nova, 'password_confirmation': nova});
  }

  /// `GET /producao?mes=AAAA-MM`: SKUs trabalhados no mês, por dia.
  Future<Producao> producao(String mes) async {
    final resposta = await cliente.get('producao', query: {'mes': mes});

    return Producao.fromJson(resposta.corpo);
  }

  /// `GET /roteiro?data=AAAA-MM-DD`.
  Future<Roteiro> roteiro(String data) async {
    final resposta = await cliente.get('roteiro', query: {'data': data});

    return Roteiro.fromJson(resposta.corpo);
  }

  /// Caminho da visita pela chave: `visitas/{id}` na própria indústria ou
  /// `industrias/{empresa}/visitas/{id}` quando o promotor entrou pela agência.
  static String caminhoDaVisita(String chave) {
    final partes = chave.split(':');

    return partes.length == 2 ? 'industrias/${partes[0]}/visitas/${partes[1]}' : 'visitas/$chave';
  }

  /// `POST .../visitas/{visita}/check-in`.
  Future<RespostaJson> checkIn(String visitaId, Map<String, dynamic> payload) =>
      cliente.post('${caminhoDaVisita(visitaId)}/check-in', payload);

  /// `POST .../visitas/{visita}/fotos` (multipart).
  Future<RespostaJson> enviarFoto(
    String visitaId,
    Map<String, String> campos, {
    required Uint8List bytes,
    required String nomeArquivo,
  }) =>
      cliente.postMultipart(
        '${caminhoDaVisita(visitaId)}/fotos',
        campos,
        campoArquivo: 'foto',
        bytes: bytes,
        nomeArquivo: nomeArquivo,
      );

  /// `POST .../visitas/{visita}/coletas`.
  Future<RespostaJson> enviarColeta(String visitaId, Map<String, dynamic> payload) =>
      cliente.post('${caminhoDaVisita(visitaId)}/coletas', payload);

  /// `POST .../visitas/{visita}/concluir`.
  Future<RespostaJson> concluir(String visitaId, Map<String, dynamic> payload) =>
      cliente.post('${caminhoDaVisita(visitaId)}/concluir', payload);

  /// `GET .../visitas/{visita}`: estado atual para reconciliação.
  Future<Map<String, dynamic>> visita(String visitaId) async {
    final resposta = await cliente.get(caminhoDaVisita(visitaId));

    return resposta.corpo['visita'] as Map<String, dynamic>;
  }
}
