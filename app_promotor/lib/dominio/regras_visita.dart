/// Regras de sequência da visita, espelhando as validações do servidor para
/// que o promotor só grave no aparelho o que a API vai aceitar depois.
library;

import '../modelos/execucao.dart';
import '../modelos/roteiro.dart';
import '../util/geo.dart';

/// Etapa em que a visita está, para a tela saber o que oferecer.
enum EtapaVisita { checkIn, fotoAntes, trabalho, concluida }

/// Posição lida do GPS do aparelho.
class Posicao {
  const Posicao({
    required this.lat,
    required this.lng,
    required this.gpsEm,
    this.precisaoM,
    this.simulada = false,
  });

  final double lat;
  final double lng;

  /// Hora informada pelo GPS (não pelo relógio do aparelho).
  final DateTime gpsEm;
  final double? precisaoM;

  /// Android informa quando a posição vem de um provedor de localização simulada.
  final bool simulada;
}

class RegrasVisita {
  RegrasVisita._();

  /// Tolerância do servidor para hora de GPS no futuro.
  static const toleranciaFuturo = Duration(minutes: 5);

  static EtapaVisita etapa(ExecucaoVisita e) {
    if (e.concluida) {
      return EtapaVisita.concluida;
    }
    if (!e.temCheckIn) {
      return EtapaVisita.checkIn;
    }
    if (!e.temFotoAntes && !e.temFotoRuptura) {
      return EtapaVisita.fotoAntes;
    }

    return EtapaVisita.trabalho;
  }

  /// Mensagem de bloqueio comum a qualquer uso do GPS, ou `null` se a posição serve.
  static String? validarPosicao(Posicao p) {
    if (p.simulada) {
      return 'Localização simulada detectada. Desative apps de GPS falso para continuar.';
    }
    if (!coordenadasValidas(p.lat, p.lng)) {
      return 'Coordenadas inválidas recebidas do GPS.';
    }

    return null;
  }

  /// Motivo pelo qual o check-in não pode ser feito agora, ou `null` se pode.
  static String? validarCheckIn(Visita visita, ExecucaoVisita e, Posicao p, {DateTime? agora}) {
    final bloqueio = validarPosicao(p);
    if (bloqueio != null) {
      return bloqueio;
    }
    if (e.temCheckIn) {
      return 'O check-in desta visita já foi feito.';
    }
    final status = e.statusServidor ?? visita.status;
    if (!StatusVisita.aceitaCheckIn(status)) {
      return 'A visita não está prevista (${StatusVisita.rotulo(status)}).';
    }
    final pdv = visita.pdv;
    if (!pdv.temCoordenadas) {
      return 'O PDV não tem coordenadas cadastradas. Avise o supervisor.';
    }
    final distancia = distanciaMetros(p.lat, p.lng, pdv.lat!, pdv.lng!);
    if (distancia > pdv.raioM) {
      return 'Você está a ${distancia.round()} m do PDV. O check-in só vale até ${pdv.raioM} m.';
    }
    final hoje = agora ?? DateTime.now();
    if (p.gpsEm.isAfter(hoje.add(toleranciaFuturo))) {
      return 'A hora do GPS está no futuro. Confira o relógio do aparelho.';
    }

    return null;
  }

  static double distanciaAoPdv(Pdv pdv, Posicao p) =>
      pdv.temCoordenadas ? distanciaMetros(p.lat, p.lng, pdv.lat!, pdv.lng!) : double.infinity;

  /// Motivo pelo qual a foto não pode ser tirada agora, ou `null` se pode.
  static String? validarFoto(Visita visita, ExecucaoVisita e, TipoFoto tipo, Posicao p) {
    final bloqueio = validarPosicao(p);
    if (bloqueio != null) {
      return bloqueio;
    }
    if (e.concluida) {
      return 'A visita já foi concluída.';
    }
    if (!e.temCheckIn) {
      return 'Faça o check-in antes de fotografar.';
    }
    final pdv = visita.pdv;
    if (pdv.temCoordenadas && distanciaMetros(p.lat, p.lng, pdv.lat!, pdv.lng!) > pdv.raioM) {
      return 'A foto precisa ser tirada dentro do raio de ${pdv.raioM} m do PDV.';
    }
    if (p.gpsEm.isBefore(e.checkIn!.gpsEm)) {
      return 'A hora do GPS da foto é anterior ao check-in. Aguarde o GPS atualizar.';
    }
    switch (tipo) {
      case TipoFoto.antes:
        if (e.temFotoDepois) {
          return 'As fotos de antes devem vir antes das fotos de depois.';
        }
      case TipoFoto.depois:
        if (!e.temFotoAntes) {
          return 'Tire a foto de antes primeiro.';
        }
        final ultimaAntes = e.fotosDoTipo(TipoFoto.antes).map((f) => f.gpsEm).reduce((a, b) => a.isAfter(b) ? a : b);
        if (p.gpsEm.isBefore(ultimaAntes)) {
          return 'A foto de depois precisa ter horário posterior à foto de antes.';
        }
      case TipoFoto.ruptura:
        break;
    }

    return null;
  }

  /// Motivo pelo qual a conclusão não pode ser gravada, ou `null` se pode.
  static String? validarConclusao(
    ExecucaoVisita e,
    TipoConclusao tipo, {
    required List<ItemTrabalhado> itens,
    required String observacao,
    required DateTime finalizadaEm,
  }) {
    if (e.concluida) {
      return 'A visita já foi concluída.';
    }
    if (!e.temCheckIn) {
      return 'Faça o check-in antes de concluir.';
    }
    switch (tipo) {
      case TipoConclusao.normal:
        if (!e.temFotoAntes || !e.temFotoDepois) {
          return 'São obrigatórias fotos de antes e de depois.';
        }
        if (itens.isEmpty) {
          return 'Informe ao menos um SKU trabalhado.';
        }
        if (itens.any((i) => i.estoque == null)) {
          return 'Informe a situação de estoque de cada SKU.';
        }
      case TipoConclusao.ruptura:
        if (!e.temFotoRuptura) {
          return 'Tire uma foto da ruptura.';
        }
        if (itens.isEmpty) {
          return 'Selecione os produtos em ruptura.';
        }
      case TipoConclusao.impedimento:
        if (observacao.trim().isEmpty) {
          return 'Descreva o impedimento de acesso.';
        }
    }
    if (itens.map((i) => i.skuId).toSet().length != itens.length) {
      return 'Há SKU repetido na lista.';
    }
    final ultimo = e.ultimoHorario;
    if (ultimo != null && finalizadaEm.isBefore(ultimo)) {
      return 'O horário de conclusão é anterior ao check-in ou às fotos.';
    }

    return null;
  }
}
