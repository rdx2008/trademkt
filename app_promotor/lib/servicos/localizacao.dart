/// Leitura pontual do GPS. Só é chamada no check-in e nas fotos: não há
/// rastreio contínuo. Posição vinda de provedor simulado é bloqueada aqui.
library;

import 'package:geolocator/geolocator.dart';

import '../dominio/regras_visita.dart';

class ErroLocalizacao implements Exception {
  const ErroLocalizacao(this.mensagem, {this.simulada = false});

  final String mensagem;

  /// `true` quando o bloqueio foi por localização simulada (mock location).
  final bool simulada;

  @override
  String toString() => mensagem;
}

abstract class ServicoLocalizacao {
  /// Devolve a posição atual ou lança [ErroLocalizacao].
  Future<Posicao> obterPosicao();
}

class ServicoLocalizacaoGeolocator implements ServicoLocalizacao {
  const ServicoLocalizacaoGeolocator({this.tempoLimite = const Duration(seconds: 40)});

  final Duration tempoLimite;

  @override
  Future<Posicao> obterPosicao() async {
    if (!await Geolocator.isLocationServiceEnabled()) {
      throw const ErroLocalizacao('Ative a localização (GPS) do aparelho para continuar.');
    }

    var permissao = await Geolocator.checkPermission();
    if (permissao == LocationPermission.denied) {
      permissao = await Geolocator.requestPermission();
    }
    if (permissao == LocationPermission.denied || permissao == LocationPermission.deniedForever) {
      throw const ErroLocalizacao('Permita o acesso à localização nas configurações do app.');
    }

    Position posicao;
    try {
      posicao = await Geolocator.getCurrentPosition(
        locationSettings: AndroidSettings(accuracy: LocationAccuracy.high, timeLimit: tempoLimite),
      );
    } on Object catch (erro) {
      throw ErroLocalizacao('Não foi possível obter a posição do GPS ($erro). Vá para um local aberto e tente de novo.');
    }

    // Android marca posições vindas de apps de "GPS falso" (mock location).
    if (posicao.isMocked) {
      throw const ErroLocalizacao(
        'Localização simulada detectada. Desative apps de GPS falso e as opções de desenvolvedor para continuar.',
        simulada: true,
      );
    }

    return Posicao(
      lat: posicao.latitude,
      lng: posicao.longitude,
      gpsEm: posicao.timestamp.toLocal(),
      precisaoM: posicao.accuracy,
      simulada: posicao.isMocked,
    );
  }
}
