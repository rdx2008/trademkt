import 'package:flutter/material.dart';

import '../api/cliente_api.dart';
import '../estado_app.dart';

/// Primeiro acesso: o promotor digita o código da empresa e o app descobre a instalação.
class TelaEmpresa extends StatefulWidget {
  const TelaEmpresa({super.key, required this.estado});

  final EstadoApp estado;

  @override
  State<TelaEmpresa> createState() => _TelaEmpresaState();
}

class _TelaEmpresaState extends State<TelaEmpresa> {
  final _codigo = TextEditingController();
  final _url = TextEditingController();
  bool _avancado = false;
  bool _ocupado = false;
  String? _erro;

  @override
  void dispose() {
    _codigo.dispose();
    _url.dispose();
    super.dispose();
  }

  Future<void> _continuar() async {
    setState(() {
      _ocupado = true;
      _erro = null;
    });
    try {
      if (_avancado) {
        final url = _url.text.trim();
        if (!url.startsWith('http://') && !url.startsWith('https://')) {
          throw ErroApi(status: 422, mensagem: 'Informe a URL completa, começando com http:// ou https://.');
        }
        widget.estado.usarUrlDireta(url);
      } else {
        await widget.estado.descobrirEmpresa(_codigo.text);
      }
    } on ErroApi catch (erro) {
      setState(() => _erro = erro.status == 404 ? 'Empresa não encontrada. Confira o código.' : erro.descricao);
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
    final acrescentando = widget.estado.adicionandoConta;

    return Scaffold(
      appBar: acrescentando
          ? AppBar(
              title: const Text('Adicionar empresa'),
              leading: IconButton(
                icon: const Icon(Icons.close),
                tooltip: 'Cancelar',
                onPressed: widget.estado.cancelarNovaConta,
              ),
            )
          : null,
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(Icons.storefront, size: 72, color: Theme.of(context).colorScheme.primary),
                  const SizedBox(height: 16),
                  Text('Trade Promo', textAlign: TextAlign.center, style: Theme.of(context).textTheme.headlineMedium),
                  const SizedBox(height: 8),
                  const Text(
                    'Informe o código da empresa fornecido pelo seu supervisor.',
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 24),
                  if (!_avancado)
                    TextField(
                      controller: _codigo,
                      autofocus: true,
                      textInputAction: TextInputAction.done,
                      autocorrect: false,
                      decoration: const InputDecoration(
                        labelText: 'Código da empresa',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.business),
                      ),
                      onSubmitted: (_) => _continuar(),
                    )
                  else
                    TextField(
                      controller: _url,
                      keyboardType: TextInputType.url,
                      autocorrect: false,
                      decoration: const InputDecoration(
                        labelText: 'URL da instalação',
                        hintText: 'https://empresa.trade.mastervps.online',
                        border: OutlineInputBorder(),
                        prefixIcon: Icon(Icons.link),
                      ),
                      onSubmitted: (_) => _continuar(),
                    ),
                  if (_erro != null) ...[
                    const SizedBox(height: 12),
                    Text(_erro!, style: TextStyle(color: Theme.of(context).colorScheme.error)),
                  ],
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _ocupado ? null : _continuar,
                    child: _ocupado
                        ? const SizedBox(height: 20, width: 20, child: CircularProgressIndicator(strokeWidth: 2))
                        : const Text('Continuar'),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: () => setState(() => _avancado = !_avancado),
                    child: Text(_avancado ? 'Usar código da empresa' : 'Avançado: informar URL da instalação'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
