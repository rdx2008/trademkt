/// Versão do app e a pergunta ao servidor: "ainda posso usar esta versão?". O APK é distribuído por
/// link, sem loja, então é o próprio app que avisa quando precisa ser atualizado.
library;

import 'package:package_info_plus/package_info_plus.dart';

/// Compara versões `1.2.3` (partes que faltam valem 0, sufixos como `+4` são ignorados).
/// Devolve negativo se [a] é mais velha que [b], zero se iguais e positivo se mais nova.
int compararVersoes(String a, String b) {
  List<int> partes(String v) => v
      .split('+')
      .first
      .split('.')
      .map((p) => int.tryParse(p.replaceAll(RegExp(r'[^0-9]'), '')) ?? 0)
      .toList();
  final x = partes(a);
  final y = partes(b);
  for (var i = 0; i < 3; i++) {
    final dif = (i < x.length ? x[i] : 0) - (i < y.length ? y[i] : 0);
    if (dif != 0) {
      return dif;
    }
  }

  return 0;
}

/// O que o servidor respondeu sobre versões.
class InfoVersao {
  const InfoVersao({required this.minima, required this.atual, this.downloadUrl});

  final String minima;
  final String atual;
  final String? downloadUrl;

  factory InfoVersao.fromJson(Map<String, dynamic> json) => InfoVersao(
        minima: json['versao_minima'] as String? ?? '0.0.0',
        atual: json['versao_atual'] as String? ?? '0.0.0',
        downloadUrl: json['download_url'] as String?,
      );
}

abstract class ServicoVersao {
  /// Versão instalada, como `0.2.0`.
  Future<String> instalada();
}

class ServicoVersaoPacote implements ServicoVersao {
  const ServicoVersaoPacote();

  @override
  Future<String> instalada() async {
    try {
      return (await PackageInfo.fromPlatform()).version;
    } on Object {
      return '0.0.0';
    }
  }
}

/// Só para testes.
class ServicoVersaoFixa implements ServicoVersao {
  const ServicoVersaoFixa(this.versao);

  final String versao;

  @override
  Future<String> instalada() async => versao;
}
