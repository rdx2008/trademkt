import 'package:flutter/material.dart';

import '../api/cliente_api.dart';
import '../estado_app.dart';

/// "Alterar minha senha": o promotor troca a própria senha sem pedir à agência.
class TelaSenha extends StatefulWidget {
  const TelaSenha({super.key, required this.estado});

  final EstadoApp estado;

  @override
  State<TelaSenha> createState() => _TelaSenhaState();
}

class _TelaSenhaState extends State<TelaSenha> {
  final _atual = TextEditingController();
  final _nova = TextEditingController();
  final _repita = TextEditingController();
  bool _mostrar = false;
  bool _ocupado = false;
  bool _nasOutras = true;
  String? _erro;

  bool get _temOutras => widget.estado.contas.length > 1;

  @override
  void dispose() {
    _atual.dispose();
    _nova.dispose();
    _repita.dispose();
    super.dispose();
  }

  Future<void> _salvar() async {
    if (_atual.text.isEmpty || _nova.text.isEmpty) {
      setState(() => _erro = 'Preencha a senha atual e a nova senha.');

      return;
    }
    if (_nova.text.length < 8 || !RegExp(r'[A-Za-z]').hasMatch(_nova.text) || !RegExp(r'\d').hasMatch(_nova.text)) {
      setState(() => _erro = 'A nova senha precisa de 8 caracteres ou mais, com letras e números.');

      return;
    }
    if (_nova.text != _repita.text) {
      setState(() => _erro = 'A confirmação não é igual à nova senha.');

      return;
    }
    setState(() {
      _ocupado = true;
      _erro = null;
    });
    final navegador = Navigator.of(context);
    final mensagens = ScaffoldMessenger.of(context);
    try {
      final outras = await widget.estado.alterarSenha(atual: _atual.text, nova: _nova.text, nasOutras: _temOutras && _nasOutras);
      navegador.pop();
      mensagens.showSnackBar(
        SnackBar(content: Text(outras > 0 ? 'Senha alterada nesta e em mais $outras agência(s).' : 'Senha alterada.')),
      );
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

  InputDecoration _campo(String rotulo, {bool olho = false}) => InputDecoration(
        labelText: rotulo,
        border: const OutlineInputBorder(),
        suffixIcon: olho
            ? IconButton(
                tooltip: _mostrar ? 'Ocultar senha' : 'Mostrar senha',
                icon: Icon(_mostrar ? Icons.visibility_off : Icons.visibility),
                onPressed: () => setState(() => _mostrar = !_mostrar),
              )
            : null,
      );

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(title: const Text('Alterar minha senha')),
      body: SafeArea(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(20),
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 480),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.stretch,
              children: [
                TextField(key: const Key('senha-atual'), controller: _atual, obscureText: !_mostrar, decoration: _campo('Senha atual', olho: true)),
                const SizedBox(height: 12),
                TextField(key: const Key('senha-nova'), controller: _nova, obscureText: !_mostrar, decoration: _campo('Nova senha')),
                const SizedBox(height: 12),
                TextField(key: const Key('senha-repita'), controller: _repita, obscureText: !_mostrar, decoration: _campo('Repita a nova senha')),
                const SizedBox(height: 8),
                Text('Mínimo de 8 caracteres, com letras e números.', style: Theme.of(context).textTheme.bodySmall),
                if (_temOutras)
                  CheckboxListTile(
                    key: const Key('nas-outras'),
                    contentPadding: EdgeInsets.zero,
                    controlAffinity: ListTileControlAffinity.leading,
                    value: _nasOutras,
                    onChanged: (v) => setState(() => _nasOutras = v ?? false),
                    title: const Text('Usar a mesma senha nas outras agências deste aparelho'),
                    subtitle: const Text('Só muda onde a senha atual for a mesma.'),
                  ),
                if (_erro != null) ...[
                  const SizedBox(height: 12),
                  Text(_erro!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                ],
                const SizedBox(height: 16),
                FilledButton(
                  key: const Key('salvar-senha'),
                  onPressed: _ocupado ? null : _salvar,
                  child: _ocupado
                      ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                      : const Text('Salvar nova senha'),
                ),
                const SizedBox(height: 8),
                const Text('Os outros aparelhos conectados a esta conta serão desconectados.', textAlign: TextAlign.center),
              ],
            ),
          ),
        ),
      ),
    );
  }
}
