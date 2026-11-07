import 'dart:ui' show Color;

/// Marca própria da empresa (white label), vinda da descoberta pela API central.
class Marca {
  const Marca({required this.nome, this.corHex, this.logoUrl, this.logoLocal});

  /// Nome exibido (o que o admin definiu em Aparência, ou o nome do cliente).
  final String nome;

  /// Cor principal `#rrggbb`; `null` mantém o visual padrão do app.
  final String? corHex;
  final String? logoUrl;

  /// Cópia do logo no aparelho, para aparecer sem internet.
  final String? logoLocal;

  Color? get cor {
    final hex = corHex;
    if (hex == null || !RegExp(r'^#[0-9a-fA-F]{6}$').hasMatch(hex)) {
      return null;
    }

    return Color(0xFF000000 | int.parse(hex.substring(1), radix: 16));
  }

  factory Marca.fromJson(Map<String, dynamic> json) => Marca(
        nome: json['nome'] as String,
        corHex: json['cor'] as String?,
        logoUrl: json['logo_url'] as String?,
        logoLocal: json['logo_local'] as String?,
      );

  Map<String, dynamic> toJson() => {'nome': nome, 'cor': corHex, 'logo_url': logoUrl, 'logo_local': logoLocal};

  Marca copiarCom({String? logoLocal, bool semLogoLocal = false}) => Marca(
        nome: nome,
        corHex: corHex,
        logoUrl: logoUrl,
        logoLocal: semLogoLocal ? null : (logoLocal ?? this.logoLocal),
      );

  /// Mesma aparência (ignora o caminho local do logo).
  bool mesmaAparencia(Marca? outra) =>
      outra != null && outra.nome == nome && outra.corHex == corHex && outra.logoUrl == logoUrl;
}

/// Empresa (instalação) descoberta pelo código, via API central.
class Empresa {
  const Empresa({required this.codigo, required this.nome, required this.url, required this.api, this.marca});

  final String codigo;
  final String nome;
  final String url;
  final String api;
  final Marca? marca;

  /// Nome a mostrar ao promotor: o da marca própria, se houver.
  String get nomeExibido => marca?.nome ?? nome;

  factory Empresa.fromJson(Map<String, dynamic> json) => Empresa(
        codigo: json['codigo'] as String,
        nome: json['nome'] as String,
        url: json['url'] as String,
        api: json['api'] as String,
        marca: json['marca'] is Map<String, dynamic> ? Marca.fromJson(json['marca'] as Map<String, dynamic>) : null,
      );

  Map<String, dynamic> toJson() => {
        'codigo': codigo,
        'nome': nome,
        'url': url,
        'api': api,
        if (marca != null) 'marca': marca!.toJson(),
      };

  Empresa copiarCom({Marca? marca}) => Empresa(codigo: codigo, nome: nome, url: url, api: api, marca: marca ?? this.marca);
}

/// Usuário autenticado (sempre promotor; a API recusa os demais perfis).
class Usuario {
  const Usuario({
    required this.id,
    required this.nome,
    required this.email,
    required this.perfil,
    this.tipoVinculo,
    this.agenciaNome,
    this.empresaCodigo,
    this.empresaNome,
    this.crachaUrl,
  });

  final int id;
  final String nome;
  final String email;
  final String perfil;
  final String? tipoVinculo;
  final String? agenciaNome;
  final String? empresaCodigo;
  final String? empresaNome;

  /// Endereço que o QR do crachá aponta (página pública de conferência).
  final String? crachaUrl;

  bool get freelancer => tipoVinculo == 'freelancer';

  factory Usuario.fromJson(Map<String, dynamic> json) {
    final agencia = json['agencia'] as Map<String, dynamic>?;
    final empresa = json['empresa'] as Map<String, dynamic>?;

    return Usuario(
      id: json['id'] as int,
      nome: json['nome'] as String,
      email: json['email'] as String,
      perfil: json['perfil'] as String,
      tipoVinculo: json['tipo_vinculo'] as String?,
      agenciaNome: agencia?['nome'] as String?,
      empresaCodigo: empresa?['codigo'] as String?,
      empresaNome: empresa?['nome'] as String?,
      crachaUrl: json['cracha_url'] as String?,
    );
  }

  Map<String, dynamic> toJson() => {
        'id': id,
        'nome': nome,
        'email': email,
        'perfil': perfil,
        'tipo_vinculo': tipoVinculo,
        'agencia': agenciaNome == null ? null : {'nome': agenciaNome},
        'empresa': {'codigo': empresaCodigo, 'nome': empresaNome},
        'cracha_url': crachaUrl,
      };
}

/// Sessão persistida no aparelho: para onde falar e com qual token.
class Sessao {
  const Sessao({required this.empresa, required this.token, required this.usuario});

  final Empresa empresa;
  final String token;
  final Usuario usuario;

  /// Identifica a conta (empresa + usuário): cada uma tem seu banco local no aparelho.
  String get chave => '${empresa.api}#${usuario.id}';

  Sessao copiarCom({Empresa? empresa}) => Sessao(empresa: empresa ?? this.empresa, token: token, usuario: usuario);

  factory Sessao.fromJson(Map<String, dynamic> json) => Sessao(
        empresa: Empresa.fromJson(json['empresa'] as Map<String, dynamic>),
        token: json['token'] as String,
        usuario: Usuario.fromJson(json['usuario'] as Map<String, dynamic>),
      );

  Map<String, dynamic> toJson() => {'empresa': empresa.toJson(), 'token': token, 'usuario': usuario.toJson()};
}
