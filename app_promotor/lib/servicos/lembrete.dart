/// Lembrete diário das visitas: uma notificação local do próprio aparelho (não é push, não usa servidor
/// e funciona sem internet). Desligado por padrão: só a pessoa liga, e só então o Android pede permissão.
library;

import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart' show TimeOfDay;
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:timezone/data/latest_all.dart' as tzdata;
import 'package:timezone/timezone.dart' as tz;

/// Escolha da pessoa: ligado ou não e a que horas.
class ConfigLembrete {
  const ConfigLembrete({this.ativo = false, this.hora = 7, this.minuto = 0});

  final bool ativo;
  final int hora;
  final int minuto;

  TimeOfDay get horario => TimeOfDay(hour: hora, minute: minuto);

  ConfigLembrete copiarCom({bool? ativo, int? hora, int? minuto}) =>
      ConfigLembrete(ativo: ativo ?? this.ativo, hora: hora ?? this.hora, minuto: minuto ?? this.minuto);

  String get texto => '$ativo|$hora|$minuto';

  static ConfigLembrete doTexto(String? texto) {
    final p = (texto ?? '').split('|');
    if (p.length != 3) {
      return const ConfigLembrete();
    }
    final h = int.tryParse(p[1]);
    final m = int.tryParse(p[2]);
    if (h == null || m == null || h < 0 || h > 23 || m < 0 || m > 59) {
      return const ConfigLembrete();
    }

    return ConfigLembrete(ativo: p[0] == 'true', hora: h, minuto: m);
  }
}

/// Instante (em UTC) da próxima vez que o relógio do aparelho marca [hora]:[minuto]. Usar UTC com
/// repetição diária mantém a hora local certa sem precisar saber o nome do fuso do aparelho.
tz.TZDateTime proximoHorarioEmUtc(DateTime agora, int hora, int minuto) {
  var alvo = DateTime(agora.year, agora.month, agora.day, hora, minuto);
  if (!alvo.isAfter(agora)) {
    alvo = alvo.add(const Duration(days: 1));
  }

  return tz.TZDateTime.from(alvo.toUtc(), tz.UTC);
}

abstract class ServicoLembrete {
  Future<ConfigLembrete> carregar();

  /// Liga ou desliga e agenda/cancela a notificação. Devolve `false` se o Android não deixou (sem permissão).
  Future<bool> aplicar(ConfigLembrete config);
}

class ServicoLembreteLocal implements ServicoLembrete {
  ServicoLembreteLocal({FlutterLocalNotificationsPlugin? plugin, FlutterSecureStorage? armazenamento})
      : _plugin = plugin ?? FlutterLocalNotificationsPlugin(),
        _armazenamento = armazenamento ?? const FlutterSecureStorage();

  final FlutterLocalNotificationsPlugin _plugin;
  final FlutterSecureStorage _armazenamento;

  static const _chave = 'lembrete';
  static const _idNotificacao = 1001;
  bool _iniciado = false;

  Future<void> _iniciar() async {
    if (_iniciado) {
      return;
    }
    tzdata.initializeTimeZones();
    await _plugin.initialize(const InitializationSettings(android: AndroidInitializationSettings('@mipmap/ic_launcher')));
    _iniciado = true;
  }

  @override
  Future<ConfigLembrete> carregar() async {
    try {
      final config = ConfigLembrete.doTexto(await _armazenamento.read(key: _chave));
      if (config.ativo) {
        // Reagenda ao abrir o app: cobre mudança de fuso, de horário de verão e reinício do aparelho.
        await _agendar(config);
      }

      return config;
    } on Object {
      return const ConfigLembrete();
    }
  }

  @override
  Future<bool> aplicar(ConfigLembrete config) async {
    try {
      await _iniciar();
      if (!config.ativo) {
        await _plugin.cancel(_idNotificacao);
        await _armazenamento.write(key: _chave, value: config.texto);

        return true;
      }
      final liberado = await _plugin.resolvePlatformSpecificImplementation<AndroidFlutterLocalNotificationsPlugin>()?.requestNotificationsPermission();
      if (liberado == false) {
        return false;
      }
      await _agendar(config);
      await _armazenamento.write(key: _chave, value: config.texto);

      return true;
    } on Object catch (e) {
      debugPrint('Lembrete: $e');

      return false;
    }
  }

  Future<void> _agendar(ConfigLembrete config) async {
    await _iniciar();
    await _plugin.cancel(_idNotificacao);
    await _plugin.zonedSchedule(
      _idNotificacao,
      'Hora de ver o seu roteiro',
      'Confira as visitas de hoje antes de sair.',
      proximoHorarioEmUtc(DateTime.now(), config.hora, config.minuto),
      const NotificationDetails(
        android: AndroidNotificationDetails(
          'lembrete_diario',
          'Lembrete diário',
          channelDescription: 'Lembra de conferir o roteiro do dia.',
          importance: Importance.defaultImportance,
        ),
      ),
      androidScheduleMode: AndroidScheduleMode.inexactAllowWhileIdle,
      matchDateTimeComponents: DateTimeComponents.time,
    );
  }
}

/// Só para testes.
class ServicoLembreteMemoria implements ServicoLembrete {
  ConfigLembrete config = const ConfigLembrete();
  bool permitir = true;

  @override
  Future<ConfigLembrete> carregar() async => config;

  @override
  Future<bool> aplicar(ConfigLembrete nova) async {
    if (nova.ativo && !permitir) {
      return false;
    }
    config = nova;

    return true;
  }
}
