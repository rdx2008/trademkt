import 'dart:io';

import 'package:app_promotor/estado_app.dart';
import 'package:app_promotor/servicos/fotos.dart';
import 'package:app_promotor/servicos/lembrete.dart';
import 'package:app_promotor/servicos/sessao.dart';
import 'package:flutter_test/flutter_test.dart';

import 'apoio.dart';

void main() {
  group('horário do lembrete', () {
    test('antes da hora marcada agenda para hoje, depois dela para amanhã', () {
      final manha = DateTime(2026, 11, 18, 6, 30);
      final tarde = DateTime(2026, 11, 18, 9, 0);

      // Relógio do aparelho para o instante agendado (o TZDateTime.toLocal usaria o fuso do pacote, não o do aparelho).
      DateTime noAparelho(DateTime agora) => DateTime.fromMillisecondsSinceEpoch(proximoHorarioEmUtc(agora, 7, 0).millisecondsSinceEpoch);
      final hoje = noAparelho(manha);
      final amanha = noAparelho(tarde);

      // Convertido de volta para o relógio do aparelho, é sempre 07:00.
      expect(hoje.hour, 7);
      expect(hoje.minute, 0);
      expect(hoje.day, 18);
      expect(amanha.hour, 7);
      expect(amanha.day, 19);
      expect(proximoHorarioEmUtc(manha, 7, 0).location.name, 'UTC', reason: 'repetição diária em UTC dispensa saber o fuso do aparelho');
    });

    test('exatamente na hora marcada vai para o dia seguinte', () {
      final agora = DateTime(2026, 11, 18, 7, 0);

      expect(DateTime.fromMillisecondsSinceEpoch(proximoHorarioEmUtc(agora, 7, 0).millisecondsSinceEpoch).day, 19);
    });
  });

  group('configuração guardada', () {
    test('vai e volta como texto e valor estragado cai no padrão desligado', () {
      const config = ConfigLembrete(ativo: true, hora: 6, minuto: 45);

      final lida = ConfigLembrete.doTexto(config.texto);
      expect(lida.ativo, isTrue);
      expect(lida.hora, 6);
      expect(lida.minuto, 45);

      for (final ruim in [null, '', 'x', 'true|25|00', 'true|7|99', 'true|a|b']) {
        final padrao = ConfigLembrete.doTexto(ruim);
        expect(padrao.ativo, isFalse, reason: '$ruim');
        expect(padrao.hora, 7);
      }
    });
  });

  group('no estado do app', () {
    late ServicoLembreteMemoria servico;
    late EstadoApp estado;
    late Directory pasta;

    setUp(() async {
      servico = ServicoLembreteMemoria();
      pasta = await Directory.systemTemp.createTemp('app_promotor_lembrete');
      estado = EstadoApp(
        httpClient: ServidorFalso().cliente,
        armazenamento: ArmazenamentoMemoria(),
        repositorio: await repositorioEmMemoria(),
        localizacao: LocalizacaoFalsa(posicaoNoPdv()),
        fotos: ServicoFotos(pastaBase: () async => pasta),
        urlCentral: 'http://central.test',
        servicoLembrete: servico,
      );
    });

    tearDown(() async {
      await estado.repositorio.fechar();
      await pasta.delete(recursive: true);
    });

    test('começa desligado: ninguém recebe notificação sem ter pedido', () async {
      await estado.carregar();

      expect(estado.lembrete.ativo, isFalse);
    });

    test('ligar guarda a hora, desligar mantém a hora para a próxima vez', () async {
      await estado.carregar();
      var avisos = 0;
      estado.addListener(() => avisos++);

      expect(await estado.configurarLembrete(estado.lembrete.copiarCom(ativo: true, hora: 6, minuto: 30)), isTrue);
      expect(estado.lembrete.ativo, isTrue);
      expect(servico.config.hora, 6);
      expect(avisos, 1);

      expect(await estado.configurarLembrete(estado.lembrete.copiarCom(ativo: false)), isTrue);
      expect(estado.lembrete.ativo, isFalse);
      expect(estado.lembrete.hora, 6);
      expect(estado.lembrete.minuto, 30);
    });

    test('sem permissão do Android o lembrete continua desligado', () async {
      servico.permitir = false;
      await estado.carregar();

      expect(await estado.configurarLembrete(const ConfigLembrete(ativo: true)), isFalse);
      expect(estado.lembrete.ativo, isFalse);
    });

    test('ao abrir o app lê o que estava guardado', () async {
      servico.config = const ConfigLembrete(ativo: true, hora: 8, minuto: 15);
      await estado.carregar();

      expect(estado.lembrete.ativo, isTrue);
      expect(estado.lembrete.hora, 8);
    });
  });
}
