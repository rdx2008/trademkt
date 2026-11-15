/// Modelos de coleta (formulários da indústria respondidos dentro da visita) e as respostas guardadas
/// no aparelho. As regras de validação espelham as do servidor (`App\Services\Coletas\Formulario`).
library;

class CampoColeta {
  const CampoColeta({
    required this.chave,
    required this.rotulo,
    required this.tipo,
    this.obrigatorio = false,
    this.porSku = false,
    this.opcoes = const [],
  });

  final String chave;
  final String rotulo;

  /// sim_nao, numero, quantidade, preco, texto, escolha, data
  final String tipo;
  final bool obrigatorio;
  final bool porSku;
  final List<String> opcoes;

  /// Campos de foto ainda não são respondidos no app.
  bool get suportado => tipo != 'foto';

  factory CampoColeta.fromJson(Map<String, dynamic> json) => CampoColeta(
        chave: json['chave'] as String,
        rotulo: json['rotulo'] as String,
        tipo: json['tipo'] as String,
        obrigatorio: json['obrigatorio'] == true,
        porSku: json['por_sku'] == true,
        opcoes: ((json['opcoes'] as List?) ?? const []).map((e) => e.toString()).toList(),
      );

  Map<String, dynamic> toJson() =>
      {'chave': chave, 'rotulo': rotulo, 'tipo': tipo, 'obrigatorio': obrigatorio, 'por_sku': porSku, 'opcoes': opcoes};
}

class ModeloColeta {
  const ModeloColeta({
    required this.id,
    required this.nome,
    required this.tipo,
    required this.versao,
    this.redes = const [],
    this.campos = const [],
  });

  final int id;
  final String nome;
  final String tipo;
  final int versao;

  /// Redes de supermercados em que vale; vazio = todas as lojas.
  final List<int> redes;
  final List<CampoColeta> campos;

  List<CampoColeta> get camposGerais => campos.where((c) => !c.porSku && c.suportado).toList();
  List<CampoColeta> get camposPorSku => campos.where((c) => c.porSku && c.suportado).toList();
  bool get temPorSku => camposPorSku.isNotEmpty;

  bool vale(int? redeId) => redes.isEmpty || (redeId != null && redes.contains(redeId));

  factory ModeloColeta.fromJson(Map<String, dynamic> json) => ModeloColeta(
        id: json['id'] as int,
        nome: json['nome'] as String,
        tipo: json['tipo'] as String? ?? 'livre',
        versao: json['versao'] as int,
        redes: ((json['redes'] as List?) ?? const []).map((e) => (e as num).toInt()).toList(),
        campos: ((json['campos'] as List?) ?? const []).map((e) => CampoColeta.fromJson(e as Map<String, dynamic>)).toList(),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'nome': nome,
        'tipo': tipo,
        'versao': versao,
        'redes': redes,
        'campos': campos.map((c) => c.toJson()).toList(),
      };
}

/// Resposta de um modelo numa visita: uma geral (`skuId` nulo) e uma por SKU respondido.
class RespostaColeta {
  const RespostaColeta({
    required this.uuid,
    required this.modeloId,
    required this.versao,
    this.skuId,
    required this.respostas,
    required this.respondidaEm,
    this.enviada = false,
  });

  final String uuid;
  final int modeloId;
  final int versao;
  final int? skuId;
  final Map<String, dynamic> respostas;
  final DateTime respondidaEm;
  final bool enviada;

  RespostaColeta copiarCom({Map<String, dynamic>? respostas, DateTime? respondidaEm, bool? enviada}) => RespostaColeta(
        uuid: uuid,
        modeloId: modeloId,
        versao: versao,
        skuId: skuId,
        respostas: respostas ?? this.respostas,
        respondidaEm: respondidaEm ?? this.respondidaEm,
        enviada: enviada ?? this.enviada,
      );

  /// Corpo de `POST .../visitas/{visita}/coletas`.
  Map<String, dynamic> toPayload() => {
        'uuid': uuid,
        'modelo_id': modeloId,
        'versao': versao,
        if (skuId != null) 'sku_id': skuId,
        'respostas': respostas,
        'respondida_em': respondidaEm.toUtc().toIso8601String(),
      };

  factory RespostaColeta.fromJson(Map<String, dynamic> json) => RespostaColeta(
        uuid: json['uuid'] as String,
        modeloId: json['modelo_id'] as int,
        versao: json['versao'] as int,
        skuId: json['sku_id'] as int?,
        respostas: Map<String, dynamic>.from(json['respostas'] as Map),
        respondidaEm: DateTime.parse(json['respondida_em'] as String),
        enviada: json['enviada'] == true,
      );

  Map<String, dynamic> toJson() => {
        'uuid': uuid,
        'modelo_id': modeloId,
        'versao': versao,
        'sku_id': skuId,
        'respostas': respostas,
        'respondida_em': respondidaEm.toIso8601String(),
        'enviada': enviada,
      };
}

/// Valida e normaliza um valor digitado para o campo. Devolve `(valor, erro)`: valor `null` e erro
/// `null` quando o campo está vazio e não é obrigatório.
({Object? valor, String? erro}) validarCampo(CampoColeta campo, Object? bruto) {
  final vazio = bruto == null || (bruto is String && bruto.trim().isEmpty);
  if (vazio) {
    return campo.obrigatorio ? (valor: null, erro: 'Responda "${campo.rotulo}".') : (valor: null, erro: null);
  }
  final texto = bruto.toString().trim();
  String invalido() => 'Valor inválido em "${campo.rotulo}".';
  switch (campo.tipo) {
    case 'sim_nao':
      if (bruto is bool) {
        return (valor: bruto, erro: null);
      }

      return texto == 'sim' || texto == 'nao' ? (valor: texto == 'sim', erro: null) : (valor: null, erro: invalido());
    case 'numero':
    case 'preco':
      final n = double.tryParse(texto.replaceAll(',', '.'));
      if (n == null || n < 0 && campo.tipo == 'preco' || n >= 1000000 && campo.tipo == 'preco') {
        return (valor: null, erro: invalido());
      }

      return (valor: (n * 100).round() / 100, erro: null);
    case 'quantidade':
      final n = int.tryParse(texto);

      return n == null || n < 0 ? (valor: null, erro: invalido()) : (valor: n, erro: null);
    case 'texto':
      return texto.length > 1000 ? (valor: null, erro: invalido()) : (valor: texto, erro: null);
    case 'escolha':
      return campo.opcoes.contains(texto) ? (valor: texto, erro: null) : (valor: null, erro: invalido());
    case 'data':
      return RegExp(r'^\d{4}-\d{2}-\d{2}$').hasMatch(texto) && DateTime.tryParse(texto) != null
          ? (valor: texto, erro: null)
          : (valor: null, erro: invalido());
  }

  return (valor: null, erro: invalido());
}

/// Valida o conjunto de respostas de um bloco de campos. Devolve as respostas limpas e a lista de erros.
({Map<String, dynamic> respostas, List<String> erros}) validarBloco(List<CampoColeta> campos, Map<String, Object?> brutas) {
  final limpas = <String, dynamic>{};
  final erros = <String>[];
  for (final campo in campos) {
    final r = validarCampo(campo, brutas[campo.chave]);
    if (r.erro != null) {
      erros.add(r.erro!);
    } else if (r.valor != null) {
      limpas[campo.chave] = r.valor;
    }
  }

  return (respostas: limpas, erros: erros);
}
