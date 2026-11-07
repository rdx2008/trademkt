import 'package:flutter/material.dart';
import 'package:qr_flutter/qr_flutter.dart';

import '../modelos/usuario.dart';
import 'logo_marca.dart';

/// Crachá digital: o gerente do supermercado lê o QR e confere, numa página pública da agência,
/// foto, primeiro nome e se o cadastro está ativo. Funciona sem internet (o QR é só um endereço).
class TelaCracha extends StatelessWidget {
  const TelaCracha({super.key, required this.sessao});

  final Sessao? sessao;

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final usuario = sessao?.usuario;
    final url = usuario?.crachaUrl;

    return Scaffold(
      appBar: AppBar(title: const Text('Meu crachá')),
      body: Center(
        child: SingleChildScrollView(
          padding: const EdgeInsets.all(24),
          child: Card(
            child: Padding(
              padding: const EdgeInsets.all(24),
              child: Column(
                mainAxisSize: MainAxisSize.min,
                children: [
                  LogoMarca(marca: sessao?.empresa.marca, altura: 48),
                  const SizedBox(height: 12),
                  Text(usuario?.nome ?? '', style: tema.textTheme.titleLarge, textAlign: TextAlign.center),
                  Text(
                    [usuario?.agenciaNome, sessao?.empresa.nomeExibido].whereType<String>().toSet().join(' · '),
                    style: tema.textTheme.bodyMedium,
                    textAlign: TextAlign.center,
                  ),
                  const SizedBox(height: 16),
                  if (url == null)
                    const Text(
                      'Entre no aplicativo de novo (saia e entre) para gerar o seu crachá.',
                      key: Key('cracha-indisponivel'),
                      textAlign: TextAlign.center,
                    )
                  else ...[
                    QrImageView(key: const Key('cracha-qr'), data: url, size: 220, backgroundColor: Colors.white),
                    const SizedBox(height: 8),
                    const Text('Mostre ao gerente da loja para conferir que você é da agência.', textAlign: TextAlign.center),
                  ],
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }
}
