import 'package:app_promotor/dominio/regras_visita.dart';
import 'package:app_promotor/modelos/execucao.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  final t0 = DateTime(2026, 10, 31, 9, 0, 0);
  final t1 = DateTime(2026, 10, 31, 9, 5, 0);
  final t2 = DateTime(2026, 10, 31, 9, 20, 0);
  final t3 = DateTime(2026, 10, 31, 9, 30, 0);

  group('check-in', () {
    test('aceita visita prevista dentro do raio', () {
      expect(RegrasVisita.validarCheckIn(visitaPadrao(), const ExecucaoVisita(visitaId: '1'), posicaoNoPdv(gpsEm: t0), agora: t0), isNull);
    });

    test('aceita visita marcada como não executada (sincronização tardia reabre)', () {
      final visita = visitaPadrao(status: 'nao_executada');

      expect(RegrasVisita.validarCheckIn(visita, const ExecucaoVisita(visitaId: '1'), posicaoNoPdv(gpsEm: t0), agora: t0), isNull);
    });

    test('bloqueia localização simulada', () {
      final erro = RegrasVisita.validarCheckIn(visitaPadrao(), const ExecucaoVisita(visitaId: '1'), posicaoNoPdv(simulada: true));

      expect(erro, contains('simulada'));
    });

    test('bloqueia fora do raio e respeita o raio específico do PDV', () {
      expect(RegrasVisita.validarCheckIn(visitaPadrao(), const ExecucaoVisita(visitaId: '1'), posicaoNoPdv(lat: -29.178)), contains('m do PDV'));
      expect(RegrasVisita.validarCheckIn(visitaPadrao(), const ExecucaoVisita(visitaId: '1'), posicaoNoPdv(lat: -29.169)), isNull);
      expect(RegrasVisita.validarCheckIn(visitaPadrao(raio: 150), const ExecucaoVisita(visitaId: '1'), posicaoNoPdv(lat: -29.1696)), isNotNull);
    });

    test('bloqueia check-in repetido, visita encerrada e PDV sem coordenadas', () {
      expect(RegrasVisita.validarCheckIn(visitaPadrao(), ExecucaoVisita(visitaId: '1', checkIn: checkInPadrao()), posicaoNoPdv()), contains('já foi aberta'));
      expect(RegrasVisita.validarCheckIn(visitaPadrao(status: 'realizada'), const ExecucaoVisita(visitaId: '1'), posicaoNoPdv()), contains('Realizada'));
      final semCoordenadas = visitaPadrao().copiarCom();
      expect(semCoordenadas.pdv.temCoordenadas, isTrue);
    });

    test('o status confirmado pelo servidor prevalece sobre o do roteiro salvo', () {
      final execucao = const ExecucaoVisita(visitaId: '1', statusServidor: 'realizada');

      expect(RegrasVisita.validarCheckIn(visitaPadrao(), execucao, posicaoNoPdv()), contains('Realizada'));
    });

    test('bloqueia hora do GPS no futuro além da tolerância de 5 minutos', () {
      final erro = RegrasVisita.validarCheckIn(
        visitaPadrao(),
        const ExecucaoVisita(visitaId: '1'),
        posicaoNoPdv(gpsEm: t0.add(const Duration(minutes: 6))),
        agora: t0,
      );

      expect(erro, contains('futuro'));
    });
  });

  group('fotos', () {
    final comCheckIn = ExecucaoVisita(visitaId: '1', checkIn: checkInPadrao(gpsEm: t0));

    test('a foto de antes (ou da entrada) abre a visita; depois e ruptura exigem a visita aberta', () {
      const semCheckIn = ExecucaoVisita(visitaId: '1');
      expect(RegrasVisita.validarFoto(visitaPadrao(), semCheckIn, TipoFoto.antes, posicaoNoPdv(gpsEm: t1)), isNull);
      expect(RegrasVisita.validarFoto(visitaPadrao(), semCheckIn, TipoFoto.impedimento, posicaoNoPdv(gpsEm: t1)), isNull);
      for (final tipo in [TipoFoto.depois, TipoFoto.ruptura]) {
        expect(RegrasVisita.validarFoto(visitaPadrao(), semCheckIn, tipo, posicaoNoPdv(gpsEm: t1)), contains('foto de antes da gôndola'));
      }
      // Abrir a visita pela foto vale as mesmas regras do check-in: raio, GPS simulado e situação.
      expect(RegrasVisita.validarFoto(visitaPadrao(), semCheckIn, TipoFoto.antes, posicaoNoPdv(gpsEm: t1, lat: -29.178)), contains('m da loja'));
      expect(RegrasVisita.validarFoto(visitaPadrao(), semCheckIn, TipoFoto.antes, posicaoNoPdv(gpsEm: t1, simulada: true)), contains('simulada'));
      expect(RegrasVisita.validarFoto(visitaPadrao(status: 'realizada'), semCheckIn, TipoFoto.antes, posicaoNoPdv(gpsEm: t1)), contains('Realizada'));
      expect(RegrasVisita.validarFoto(visitaPadrao(), comCheckIn, TipoFoto.antes, posicaoNoPdv(gpsEm: t1)), isNull);
    });

    test('foto depois exige foto antes com horário anterior', () {
      expect(RegrasVisita.validarFoto(visitaPadrao(), comCheckIn, TipoFoto.depois, posicaoNoPdv(gpsEm: t1)), contains('antes primeiro'));
      final comAntes = comCheckIn.copiarCom(fotos: [fotoPadrao(TipoFoto.antes, gpsEm: t1)]);
      expect(RegrasVisita.validarFoto(visitaPadrao(), comAntes, TipoFoto.depois, posicaoNoPdv(gpsEm: t0)), contains('posterior'));
      expect(RegrasVisita.validarFoto(visitaPadrao(), comAntes, TipoFoto.depois, posicaoNoPdv(gpsEm: t2)), isNull);
    });

    test('foto antes não pode vir depois de uma foto depois', () {
      final comDepois = comCheckIn.copiarCom(fotos: [fotoPadrao(TipoFoto.antes, gpsEm: t1), fotoPadrao(TipoFoto.depois, gpsEm: t2)]);

      expect(RegrasVisita.validarFoto(visitaPadrao(), comDepois, TipoFoto.antes, posicaoNoPdv(gpsEm: t3)), contains('antes das fotos de depois'));
    });

    test('foto de ruptura só exige check-in; fora do raio ou simulada é bloqueada', () {
      expect(RegrasVisita.validarFoto(visitaPadrao(), comCheckIn, TipoFoto.ruptura, posicaoNoPdv(gpsEm: t1)), isNull);
      expect(RegrasVisita.validarFoto(visitaPadrao(), comCheckIn, TipoFoto.ruptura, posicaoNoPdv(gpsEm: t1, lat: -29.178)), contains('raio'));
      expect(RegrasVisita.validarFoto(visitaPadrao(), comCheckIn, TipoFoto.ruptura, posicaoNoPdv(gpsEm: t1, simulada: true)), contains('simulada'));
    });

    test('hora do GPS anterior ao check-in é recusada', () {
      expect(RegrasVisita.validarFoto(visitaPadrao(), comCheckIn, TipoFoto.antes, posicaoNoPdv(gpsEm: t0.subtract(const Duration(minutes: 1)))), contains('anterior ao check-in'));
    });
  });

  group('conclusão', () {
    final pronta = ExecucaoVisita(
      visitaId: '1',
      checkIn: checkInPadrao(gpsEm: t0),
      fotos: [fotoPadrao(TipoFoto.antes, gpsEm: t1), fotoPadrao(TipoFoto.depois, gpsEm: t2)],
    );
    const itens = [ItemTrabalhado(skuId: 10, estoque: SituacaoEstoque.temEstoque)];

    test('normal exige fotos antes e depois, SKU com estoque e horário posterior', () {
      expect(RegrasVisita.validarConclusao(pronta, TipoConclusao.normal, itens: itens, observacao: '', finalizadaEm: t3), isNull);
      expect(RegrasVisita.validarConclusao(pronta, TipoConclusao.normal, itens: const [], observacao: '', finalizadaEm: t3), contains('ao menos um SKU'));
      expect(
        RegrasVisita.validarConclusao(pronta, TipoConclusao.normal, itens: const [ItemTrabalhado(skuId: 10)], observacao: '', finalizadaEm: t3),
        contains('situação de estoque'),
      );
      expect(RegrasVisita.validarConclusao(pronta, TipoConclusao.normal, itens: itens, observacao: '', finalizadaEm: t1), contains('anterior'));
      final semDepois = pronta.copiarCom(fotos: [fotoPadrao(TipoFoto.antes, gpsEm: t1)]);
      expect(RegrasVisita.validarConclusao(semDepois, TipoConclusao.normal, itens: itens, observacao: '', finalizadaEm: t3), contains('antes e de depois'));
    });

    test('ruptura exige foto de ruptura e produtos', () {
      final comRuptura = pronta.copiarCom(fotos: [fotoPadrao(TipoFoto.ruptura, gpsEm: t1)]);

      expect(RegrasVisita.validarConclusao(pronta, TipoConclusao.ruptura, itens: const [ItemTrabalhado(skuId: 10)], observacao: '', finalizadaEm: t3), contains('foto da ruptura'));
      expect(RegrasVisita.validarConclusao(comRuptura, TipoConclusao.ruptura, itens: const [], observacao: '', finalizadaEm: t3), contains('produtos'));
      expect(RegrasVisita.validarConclusao(comRuptura, TipoConclusao.ruptura, itens: const [ItemTrabalhado(skuId: 10)], observacao: '', finalizadaEm: t3), isNull);
    });

    test('impedimento exige observação e dispensa fotos e SKUs', () {
      final soCheckIn = ExecucaoVisita(visitaId: '1', checkIn: checkInPadrao(gpsEm: t0));

      expect(RegrasVisita.validarConclusao(soCheckIn, TipoConclusao.impedimento, itens: const [], observacao: '  ', finalizadaEm: t3), contains('Descreva'));
      expect(RegrasVisita.validarConclusao(soCheckIn, TipoConclusao.impedimento, itens: const [], observacao: 'Loja fechada', finalizadaEm: t3), isNull);
    });

    test('sem check-in ou já concluída não conclui; SKU repetido é recusado', () {
      expect(RegrasVisita.validarConclusao(const ExecucaoVisita(visitaId: '1'), TipoConclusao.impedimento, itens: const [], observacao: 'x', finalizadaEm: t3), contains('foto de antes'));
      final concluida = pronta.copiarCom(conclusao: ConclusaoLocal(uuid: 'c', tipo: TipoConclusao.normal, finalizadaEm: t3, observacao: '', itens: itens));
      expect(RegrasVisita.validarConclusao(concluida, TipoConclusao.normal, itens: itens, observacao: '', finalizadaEm: t3), contains('já foi concluída'));
      expect(
        RegrasVisita.validarConclusao(pronta, TipoConclusao.normal, itens: [...itens, ...itens], observacao: '', finalizadaEm: t3),
        contains('repetido'),
      );
    });
  });

  test('etapa atual segue o fluxo foto antes (check-in) → trabalho → concluída', () {
    expect(RegrasVisita.etapa(const ExecucaoVisita(visitaId: '1')), EtapaVisita.fotoAntes);
    final comCheckIn = ExecucaoVisita(visitaId: '1', checkIn: checkInPadrao(gpsEm: t0));
    expect(RegrasVisita.etapa(comCheckIn), EtapaVisita.fotoAntes, reason: 'check-in antigo, ainda sem foto de antes');
    expect(RegrasVisita.etapa(comCheckIn.copiarCom(fotos: [fotoPadrao(TipoFoto.antes)])), EtapaVisita.trabalho);
    expect(RegrasVisita.etapa(comCheckIn.copiarCom(fotos: [fotoPadrao(TipoFoto.ruptura)])), EtapaVisita.trabalho);
    final concluida = comCheckIn.copiarCom(conclusao: ConclusaoLocal(uuid: 'c', tipo: TipoConclusao.impedimento, finalizadaEm: t3, observacao: 'x', itens: const []));
    expect(RegrasVisita.etapa(concluida), EtapaVisita.concluida);
  });
}
