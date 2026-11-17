import 'package:flutter/material.dart';

import '../api/cliente_api.dart';
import '../estado_app.dart';

/// Primeira tela do app: só e-mail e senha. O sistema descobre sozinho as agências do promotor.
/// Não há cadastro aqui: o cadastro é feito pela agência.
class TelaLoginUnico extends StatefulWidget {
  const TelaLoginUnico({super.key, required this.estado});

  final EstadoApp estado;

  @override
  State<TelaLoginUnico> createState() => _TelaLoginUnicoState();
}

class _TelaLoginUnicoState extends State<TelaLoginUnico> {
  final _email = TextEditingController();
  final _senha = TextEditingController();
  bool _ocupado = false;
  bool _mostrarSenha = false;
  String? _erro;

  @override
  void dispose() {
    _email.dispose();
    _senha.dispose();
    super.dispose();
  }

  Future<void> _entrar() async {
    if (_email.text.trim().isEmpty || _senha.text.isEmpty) {
      setState(() => _erro = 'Informe e-mail e senha.');

      return;
    }
    setState(() {
      _ocupado = true;
      _erro = null;
    });
    try {
      await widget.estado.entrarUnico(email: _email.text, senha: _senha.text);
    } on ErroApi catch (erro) {
      setState(() => _erro = erro.descricao);
    } on SemConexao catch (erro) {
      setState(() => _erro = erro.mensagem);
    } finally {
      if (mounted) {
        setState(() => _ocupado = false);
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Scaffold(
      // O acesso por código da agência fica escondido neste menu: quase ninguém precisa dele.
      appBar: AppBar(
        backgroundColor: Colors.transparent,
        foregroundColor: tema.colorScheme.onSurface,
        elevation: 0,
        scrolledUnderElevation: 0,
        actions: [
          PopupMenuButton<String>(
            key: const Key('opcoes-acesso'),
            tooltip: 'Mais opções',
            icon: const Icon(Icons.more_vert),
            onSelected: (_) => widget.estado.usarCodigoDaAgencia(),
            itemBuilder: (_) => const [
              PopupMenuItem<String>(key: Key('usar-codigo'), value: 'codigo', child: Text('Entrar com o código da agência')),
            ],
          ),
        ],
      ),
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: AutofillGroup(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    Icon(Icons.storefront, size: 72, color: tema.colorScheme.primary),
                    const SizedBox(height: 16),
                    Text('Trade Promo', textAlign: TextAlign.center, style: tema.textTheme.headlineMedium),
                    const SizedBox(height: 8),
                    const Text('Entre com o seu e-mail e a sua senha.', textAlign: TextAlign.center),
                    const SizedBox(height: 24),
                    TextField(
                      key: const Key('email'),
                      controller: _email,
                      keyboardType: TextInputType.emailAddress,
                      autofillHints: const [AutofillHints.username, AutofillHints.email],
                      autocorrect: false,
                      textInputAction: TextInputAction.next,
                      decoration: const InputDecoration(
                        labelText: 'E-mail',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.mail_outline),
                      ),
                    ),
                    const SizedBox(height: 12),
                    TextField(
                      key: const Key('senha'),
                      controller: _senha,
                      obscureText: !_mostrarSenha,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: TextInputAction.done,
                      decoration: InputDecoration(
                        labelText: 'Senha',
                        border: const OutlineInputBorder(),
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          tooltip: _mostrarSenha ? 'Ocultar senha' : 'Mostrar senha',
                          icon: Icon(_mostrarSenha ? Icons.visibility_off : Icons.visibility),
                          onPressed: () => setState(() => _mostrarSenha = !_mostrarSenha),
                        ),
                      ),
                      onSubmitted: (_) => _entrar(),
                    ),
                    if (_erro != null) ...[
                      const SizedBox(height: 12),
                      Text(_erro!, style: TextStyle(color: tema.colorScheme.error)),
                    ],
                    const SizedBox(height: 16),
                    FilledButton(
                      key: const Key('entrar'),
                      onPressed: _ocupado ? null : _entrar,
                      child: _ocupado
                          ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                          : const Text('Entrar'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
