/// Utilitários de data e hora.
///
/// A API exige datas no formato ISO 8601 com fuso e sem fração de segundo
/// (`Y-m-d\TH:i:sP` do PHP), por exemplo `2026-10-31T12:00:00-03:00`.
/// O `toIso8601String` do Dart não serve: gera fração de segundo e usa `Z` em UTC.
library;

String _dois(int n) => n.toString().padLeft(2, '0');

/// Formata a data no horário local do aparelho com o deslocamento do fuso.
String formatarIso8601ComFuso(DateTime data) {
  final local = data.isUtc ? data.toLocal() : data;
  final deslocamento = local.timeZoneOffset;
  final sinal = deslocamento.isNegative ? '-' : '+';
  final absoluto = deslocamento.abs();
  final horas = absoluto.inHours;
  final minutos = absoluto.inMinutes % 60;

  return '${local.year.toString().padLeft(4, '0')}-${_dois(local.month)}-${_dois(local.day)}'
      'T${_dois(local.hour)}:${_dois(local.minute)}:${_dois(local.second)}'
      '$sinal${_dois(horas)}:${_dois(minutos)}';
}

/// `AAAA-MM-DD` no horário local (parâmetro `data` do roteiro).
String formatarDataIso(DateTime data) {
  final local = data.isUtc ? data.toLocal() : data;

  return '${local.year.toString().padLeft(4, '0')}-${_dois(local.month)}-${_dois(local.day)}';
}

/// `DD/MM/AAAA` para exibição.
String formatarDataBr(DateTime data) {
  final local = data.isUtc ? data.toLocal() : data;

  return '${_dois(local.day)}/${_dois(local.month)}/${local.year}';
}

/// `DD/MM/AAAA HH:MM` para exibição.
String formatarDataHoraBr(DateTime data) {
  final local = data.isUtc ? data.toLocal() : data;

  return '${formatarDataBr(local)} ${_dois(local.hour)}:${_dois(local.minute)}';
}

/// `DD/MM/AAAA HH:MM:SS` para a marca d'água.
String formatarDataHoraSegundosBr(DateTime data) {
  final local = data.isUtc ? data.toLocal() : data;

  return '${formatarDataHoraBr(local)}:${_dois(local.second)}';
}

/// Interpreta uma data ISO 8601 (com ou sem fuso). Devolve `null` se inválida.
DateTime? interpretarIso(String? texto) {
  if (texto == null || texto.isEmpty) {
    return null;
  }

  return DateTime.tryParse(texto);
}

/// Remove a hora, mantendo o dia local.
DateTime somenteData(DateTime data) {
  final local = data.isUtc ? data.toLocal() : data;

  return DateTime(local.year, local.month, local.day);
}
