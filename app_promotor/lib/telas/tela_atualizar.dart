import 'package:flutter/material.dart';
import 'package:url_launcher/url_launcher.dart';

import '../estado_app.dart';

/// Versão do app abaixo da mínima aceita pelo servidor: pede para baixar a nova (APK por link, sem loja).
class TelaAtualizar extends StatelessWidget {
  const TelaAtualizar({super.key, required this.estado});

  final EstadoApp estado;

  Future<void> _baixar(BuildContext context) async {
    final url = estado.urlAtualizacao;
    final mensagens = ScaffoldMessenger.of(context);
    var abriu = false;
    if (url != null) {
      try {
        abriu = await launchUrl(Uri.parse(url), mode: LaunchMode.externalApplication);
      } on Object {
        abriu = false;
      }
    }
    if (!abriu) {
      mensagens.showSnackBar(const SnackBar(content: Text('Não foi possível abrir o link. Peça o link de download à sua agência.')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Scaffold(
      body: SafeArea(
        child: Center(
          child: SingleChildScrollView(
            padding: const EdgeInsets.all(24),
            child: ConstrainedBox(
              constraints: const BoxConstraints(maxWidth: 420),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Icon(Icons.system_update, size: 72, color: tema.colorScheme.primary),
                  const SizedBox(height: 16),
                  Text('Atualize o aplicativo', textAlign: TextAlign.center, style: tema.textTheme.headlineSmall),
                  const SizedBox(height: 12),
                  Text(
                    'Esta versão (${estado.versaoInstalada}) não é mais aceita. Baixe a versão nova para continuar.',
                    textAlign: TextAlign.center,
                  ),
                  if (estado.pendentes > 0) ...[
                    const SizedBox(height: 12),
                    const Text(
                      'O que ainda não foi enviado continua guardado neste aparelho e segue para o servidor depois da atualização.',
                      textAlign: TextAlign.center,
                    ),
                  ],
                  const SizedBox(height: 24),
                  if (estado.urlAtualizacao != null)
                    FilledButton.icon(
                      key: const Key('baixar-atualizacao'),
                      onPressed: () => _baixar(context),
                      icon: const Icon(Icons.download),
                      label: const Text('Baixar a nova versão'),
                    ),
                  const SizedBox(height: 8),
                  TextButton(
                    key: const Key('verificar-de-novo'),
                    onPressed: estado.verificarVersao,
                    child: const Text('Já atualizei, verificar de novo'),
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
