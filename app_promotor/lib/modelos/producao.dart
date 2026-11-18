/// Produção do mês do próprio promotor: SKUs trabalhados por dia (ruptura não conta).
library;

class ItemProducao {
  const ItemProducao({required this.pdv, required this.codigo, required this.descricao, required this.situacao});

  final String pdv;
  final String codigo;
  final String descricao;

  /// `tem_estoque` ou `somente_gondola`.
  final String situacao;

  String get situacaoRotulo => situacao == 'tem_estoque' ? 'Tem estoque' : 'Somente na gôndola';

  factory ItemProducao.fromJson(Map<String, dynamic> json) => ItemProducao(
        pdv: json['pdv'] as String? ?? '',
        codigo: json['codigo'] as String? ?? '',
        descricao: json['descricao'] as String? ?? '',
        situacao: json['situacao'] as String? ?? '',
      );
}

class DiaProducao {
  const DiaProducao({required this.data, required this.total, required this.itens});

  /// `AAAA-MM-DD`.
  final String data;
  final int total;
  final List<ItemProducao> itens;

  factory DiaProducao.fromJson(Map<String, dynamic> json) => DiaProducao(
        data: json['data'] as String,
        total: json['total'] as int? ?? 0,
        itens: (json['itens'] as List<dynamic>? ?? const []).map((i) => ItemProducao.fromJson(i as Map<String, dynamic>)).toList(),
      );
}

class Producao {
  const Producao({required this.mes, required this.total, required this.visitas, required this.dias});

  /// `AAAA-MM`.
  final String mes;
  final int total;
  final int visitas;
  final List<DiaProducao> dias;

  factory Producao.fromJson(Map<String, dynamic> json) => Producao(
        mes: json['mes'] as String,
        total: json['total'] as int? ?? 0,
        visitas: json['visitas'] as int? ?? 0,
        dias: (json['dias'] as List<dynamic>? ?? const []).map((d) => DiaProducao.fromJson(d as Map<String, dynamic>)).toList(),
      );
}
