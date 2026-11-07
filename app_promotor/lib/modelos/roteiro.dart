/// Modelos do roteiro do dia (`GET /api/v1/roteiro`).
library;

double? _numero(dynamic valor) {
  if (valor == null) {
    return null;
  }
  if (valor is num) {
    return valor.toDouble();
  }

  return double.tryParse(valor.toString());
}

class Embalagem {
  const Embalagem({required this.tipo, required this.quantidade, this.gtin});

  final String tipo;
  final int quantidade;
  final String? gtin;

  factory Embalagem.fromJson(Map<String, dynamic> json) => Embalagem(
        tipo: json['tipo'] as String,
        quantidade: (json['quantidade'] as num?)?.toInt() ?? 1,
        gtin: json['gtin'] as String?,
      );

  Map<String, dynamic> toJson() => {'tipo': tipo, 'quantidade': quantidade, 'gtin': gtin};
}

class Sku {
  const Sku({
    required this.id,
    required this.codigo,
    required this.descricao,
    this.categoria,
    this.fotoUrl,
    this.embalagens = const [],
  });

  final int id;
  final String codigo;
  final String descricao;
  final String? categoria;
  final String? fotoUrl;
  final List<Embalagem> embalagens;

  factory Sku.fromJson(Map<String, dynamic> json) => Sku(
        id: json['id'] as int,
        codigo: json['codigo'] as String,
        descricao: json['descricao'] as String,
        categoria: json['categoria'] as String?,
        fotoUrl: json['foto_url'] as String?,
        embalagens: ((json['embalagens'] as List?) ?? const [])
            .map((e) => Embalagem.fromJson(e as Map<String, dynamic>))
            .toList(),
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'codigo': codigo,
        'descricao': descricao,
        'categoria': categoria,
        'foto_url': fotoUrl,
        'embalagens': embalagens.map((e) => e.toJson()).toList(),
      };
}

/// Chave de uma visita ou indústria no aparelho: `<empresa>:<id>` quando o promotor entra pela
/// agência (cada indústria tem a própria numeração) ou só `<id>` quando entra pela indústria.
String chaveDe(String industria, int id) => industria.isEmpty ? '$id' : '$industria:$id';

class Industria {
  const Industria({required this.id, required this.nome, this.industria = '', this.skus = const []});

  final int id;

  /// Código da empresa (indústria) de onde vem, vazio quando o login é na própria indústria.
  final String industria;
  final String nome;
  final List<Sku> skus;

  String get chave => chaveDe(industria, id);

  factory Industria.fromJson(Map<String, dynamic> json) => Industria(
        id: json['id'] as int,
        industria: json['industria'] as String? ?? '',
        nome: json['nome'] as String,
        skus: ((json['skus'] as List?) ?? const []).map((e) => Sku.fromJson(e as Map<String, dynamic>)).toList(),
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'industria': industria, 'nome': nome, 'skus': skus.map((e) => e.toJson()).toList()};
}

class Pdv {
  const Pdv({
    required this.id,
    required this.nome,
    this.cnpj,
    this.endereco,
    this.lat,
    this.lng,
    this.raioM = 200,
  });

  final int id;
  final String nome;
  final String? cnpj;
  final String? endereco;
  final double? lat;
  final double? lng;
  final int raioM;

  bool get temCoordenadas => lat != null && lng != null;

  factory Pdv.fromJson(Map<String, dynamic> json) => Pdv(
        id: json['id'] as int,
        nome: json['nome'] as String,
        cnpj: json['cnpj'] as String?,
        endereco: json['endereco'] as String?,
        lat: _numero(json['lat']),
        lng: _numero(json['lng']),
        raioM: (json['raio_m'] as num?)?.toInt() ?? 200,
      );

  Map<String, dynamic> toJson() =>
      {'id': id, 'nome': nome, 'cnpj': cnpj, 'endereco': endereco, 'lat': lat, 'lng': lng, 'raio_m': raioM};
}

/// Situações da visita devolvidas pela API (`App\Enums\StatusVisita`).
class StatusVisita {
  static const previsto = 'previsto';
  static const emAndamento = 'em_andamento';
  static const realizada = 'realizada';
  static const emAtencao = 'em_atencao';
  static const naoExecutada = 'nao_executada';

  static String rotulo(String status) => switch (status) {
        previsto => 'Prevista',
        emAndamento => 'Em andamento',
        realizada => 'Realizada',
        emAtencao => 'Em atenção',
        naoExecutada => 'Não executada',
        _ => status,
      };

  /// Situações em que o servidor aceita um novo check-in.
  static bool aceitaCheckIn(String status) => status == previsto || status == naoExecutada;

  static bool encerrada(String status) => status == realizada || status == emAtencao;
}

class Visita {
  const Visita({
    required this.id,
    required this.ordem,
    required this.origem,
    required this.status,
    required this.industriaId,
    required this.pdv,
    this.industria = '',
    this.checkIn,
  });

  final int id;

  /// Código da empresa (indústria) dona da visita; vazio no login pela própria indústria.
  final String industria;
  final int ordem;
  final String origem;
  final String status;
  final int industriaId;
  final Pdv pdv;

  /// Check-in já registrado no servidor, quando houver.
  final Map<String, dynamic>? checkIn;

  /// Identifica a visita no aparelho e nas rotas da API.
  String get chave => chaveDe(industria, id);

  factory Visita.fromJson(Map<String, dynamic> json) => Visita(
        id: json['id'] as int,
        industria: json['industria'] as String? ?? '',
        ordem: (json['ordem'] as num?)?.toInt() ?? 0,
        origem: json['origem'] as String? ?? 'contrato',
        status: json['status'] as String? ?? StatusVisita.previsto,
        industriaId: json['industria_id'] as int,
        pdv: Pdv.fromJson(json['pdv'] as Map<String, dynamic>),
        checkIn: json['check_in'] as Map<String, dynamic>?,
      );

  Map<String, dynamic> toJson() => {
        'id': id,
        'industria': industria,
        'ordem': ordem,
        'origem': origem,
        'status': status,
        'check_in': checkIn,
        'industria_id': industriaId,
        'pdv': pdv.toJson(),
      };

  Visita copiarCom({String? status, Map<String, dynamic>? checkIn}) => Visita(
        id: id,
        industria: industria,
        ordem: ordem,
        origem: origem,
        status: status ?? this.status,
        industriaId: industriaId,
        pdv: pdv,
        checkIn: checkIn ?? this.checkIn,
      );
}

class Roteiro {
  const Roteiro({
    required this.data,
    this.roteiroId,
    this.supervisorNome,
    this.visitas = const [],
    this.industrias = const [],
  });

  /// `AAAA-MM-DD`.
  final String data;
  final int? roteiroId;
  final String? supervisorNome;
  final List<Visita> visitas;
  final List<Industria> industrias;

  factory Roteiro.fromJson(Map<String, dynamic> json) {
    final supervisor = json['supervisor'] as Map<String, dynamic>?;

    return Roteiro(
      data: json['data'] as String,
      roteiroId: json['roteiro_id'] as int?,
      supervisorNome: supervisor?['nome'] as String?,
      visitas: ((json['visitas'] as List?) ?? const []).map((e) => Visita.fromJson(e as Map<String, dynamic>)).toList(),
      industrias:
          ((json['industrias'] as List?) ?? const []).map((e) => Industria.fromJson(e as Map<String, dynamic>)).toList(),
    );
  }

  Map<String, dynamic> toJson() => {
        'data': data,
        'roteiro_id': roteiroId,
        'supervisor': supervisorNome == null ? null : {'nome': supervisorNome},
        'visitas': visitas.map((e) => e.toJson()).toList(),
        'industrias': industrias.map((e) => e.toJson()).toList(),
      };

  /// Indústria da visita (mesma empresa e mesmo id).
  Industria? industria(Visita visita) {
    for (final industria in industrias) {
      if (industria.industria == visita.industria && industria.id == visita.industriaId) {
        return industria;
      }
    }

    return null;
  }

  Visita? visita(String chave) {
    for (final visita in visitas) {
      if (visita.chave == chave) {
        return visita;
      }
    }

    return null;
  }

  /// Devolve uma cópia com a visita substituída (status/check-in atualizados pelo servidor).
  Roteiro comVisita(Visita atualizada) => Roteiro(
        data: data,
        roteiroId: roteiroId,
        supervisorNome: supervisorNome,
        visitas: visitas.map((v) => v.chave == atualizada.chave ? atualizada : v).toList(),
        industrias: industrias,
      );
}
