import 'package:flutter/material.dart';

import '../api/cliente_api.dart';
import '../estado_app.dart';

class TelaLogin extends StatefulWidget {
  const TelaLogin({super.key, required this.estado});

  final EstadoApp estado;

  @override
  State<TelaLogin> createState() => _TelaLoginState();
}

class _TelaLoginState extends State<TelaLogin> {
  final _email = TextEditingController();
  final _senha = TextEditingController();
  bool _ocupado = false;
  bool _mostrarSenha = false;
  String? _erro;

  @override
  void initState() {
    super.initState();
    _email.text = widget.estado.sessao?.usuario.email ?? '';
  }

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
      await widget.estado.entrar(email: _email.text, senha: _senha.text);
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
    final estado = widget.estado;
    final empresa = estado.empresaPendente ?? estado.sessao?.empresa;

    return Scaffold(
      appBar: AppBar(
        title: Text(empresa?.nome ?? 'Entrar'),
        leading: estado.sessaoExpirada
            ? null
            : IconButton(icon: const Icon(Icons.arrow_back), tooltip: 'Trocar empresa', onPressed: estado.trocarEmpresa),
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
                    if (estado.sessaoExpirada) ...[
                      const Card(
                        child: Padding(
                          padding: EdgeInsets.all(12),
                          child: Text('Sua sessão expirou. Entre novamente para continuar enviando as visitas.'),
                        ),
                      ),
                      const SizedBox(height: 16),
                    ],
                    TextField(
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
                      controller: _senha,
                      obscureText: !_mostrarSenha,
                      autofillHints: const [AutofillHints.password],
                      textInputAction: TextInputAction.done,
                      decoration: InputDecoration(
                        labelText: 'Senha',
                        border: const OutlineInputBorder(),
                        prefixIcon: const Icon(Icons.lock_outline),
                        suffixIcon: IconButton(
                          icon: Icon(_mostrarSenha ? Icons.visibility_off : Icons.visibility),
                          onPressed: () => setState(() => _mostrarSenha = !_mostrarSenha),
                        ),
                      ),
                      onSubmitted: (_) => _entrar(),
                    ),
                    if (_erro != null) ...[
                      const SizedBox(height: 12),
                      Text(_erro!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                    ],
                    const SizedBox(height: 16),
                    FilledButton(
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
