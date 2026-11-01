/// Empresa (instalação) descoberta pelo código, via API central.
class Empresa {
  const Empresa({required this.codigo, required this.nome, required this.url, required this.api});

  final String codigo;
  final String nome;
  final String url;
  final String api;

  factory Empresa.fromJson(Map<String, dynamic> json) => Empresa(
        codigo: json['codigo'] as String,
        nome: json['nome'] as String,
        url: json['url'] as String,
        api: json['api'] as String,
      );

  Map<String, dynamic> toJson() => {'codigo': codigo, 'nome': nome, 'url': url, 'api': api};
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
  });

  final int id;
  final String nome;
  final String email;
  final String perfil;
  final String? tipoVinculo;
  final String? agenciaNome;
  final String? empresaCodigo;
  final String? empresaNome;

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
      };
}

/// Sessão persistida no aparelho: para onde falar e com qual token.
class Sessao {
  const Sessao({required this.empresa, required this.token, required this.usuario});

  final Empresa empresa;
  final String token;
  final Usuario usuario;

  factory Sessao.fromJson(Map<String, dynamic> json) => Sessao(
        empresa: Empresa.fromJson(json['empresa'] as Map<String, dynamic>),
        token: json['token'] as String,
        usuario: Usuario.fromJson(json['usuario'] as Map<String, dynamic>),
      );

  Map<String, dynamic> toJson() => {'empresa': empresa.toJson(), 'token': token, 'usuario': usuario.toJson()};
}
