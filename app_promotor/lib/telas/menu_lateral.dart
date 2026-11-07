import 'package:flutter/material.dart';

import '../estado_app.dart';
import '../modelos/usuario.dart';
import 'logo_marca.dart';
import 'tela_cracha.dart';

/// Menu do app: quem está logado, as contas do aparelho (uma por empresa/agência),
/// troca de conta, acrescentar empresa e sair.
class MenuLateral extends StatelessWidget {
  const MenuLateral({super.key, required this.estado});

  final EstadoApp estado;

  Future<void> _trocar(BuildContext context, Sessao conta) async {
    final navegador = Navigator.of(context);
    final mensagens = ScaffoldMessenger.of(context);
    if (estado.pendentes > 0) {
      final seguir = await showDialog<bool>(
        context: context,
        builder: (contexto) => AlertDialog(
          title: const Text('Trocar de conta'),
          content: Text(
            'Há ${estado.pendentes} item(ns) desta conta ainda não enviados. '
            'Eles ficam guardados no aparelho e seguem para o servidor quando você voltar a ela.',
          ),
          actions: [
            TextButton(onPressed: () => Navigator.pop(contexto, false), child: const Text('Cancelar')),
            TextButton(onPressed: () => Navigator.pop(contexto, true), child: const Text('Trocar')),
          ],
        ),
      );
      if (seguir != true) {
        return;
      }
    }
    navegador.pop();
    final erro = await estado.trocarConta(conta);
    if (erro != null) {
      mensagens.showSnackBar(SnackBar(content: Text(erro)));
    }
  }

  Future<void> _sair(BuildContext context) async {
    final navegador = Navigator.of(context);
    final conta = estado.sessao!;
    final pendentes = estado.pendentes;
    final confirmou = await showDialog<bool>(
      context: context,
      builder: (contexto) => AlertDialog(
        title: Text('Sair de ${conta.empresa.nomeExibido}?'),
        content: Text(
          pendentes > 0
              ? 'Há $pendentes item(ns) ainda não enviados ao servidor. Se sair agora, eles serão perdidos. '
                  'Sincronize antes de sair.'
              : 'Você poderá entrar de novo com e-mail e senha. As outras contas continuam no aparelho.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(contexto, false), child: const Text('Cancelar')),
          TextButton(
            onPressed: () => Navigator.pop(contexto, true),
            child: Text(pendentes > 0 ? 'Sair e perder' : 'Sair'),
          ),
        ],
      ),
    );
    if (confirmou != true) {
      return;
    }
    navegador.pop();
    await estado.sair(forcar: true);
  }

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);
    final atual = estado.sessao;
    final marca = atual?.empresa.marca;
    final outras = estado.contas.where((c) => c.chave != atual?.chave).toList();

    return Drawer(
      child: SafeArea(
        top: false,
        child: ListView(
          padding: EdgeInsets.zero,
          children: [
            Container(
              color: tema.colorScheme.primary,
              padding: EdgeInsets.fromLTRB(16, MediaQuery.of(context).padding.top + 16, 16, 16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Container(
                    padding: const EdgeInsets.all(8),
                    decoration: BoxDecoration(color: Colors.white, borderRadius: BorderRadius.circular(8)),
                    child: LogoMarca(marca: marca, altura: 40),
                  ),
                  const SizedBox(height: 12),
                  Text(
                    atual?.usuario.nome ?? '',
                    style: tema.textTheme.titleMedium?.copyWith(color: tema.colorScheme.onPrimary),
                  ),
                  Text(
                    [atual?.empresa.nomeExibido, atual?.usuario.agenciaNome].whereType<String>().toSet().join(' · '),
                    style: tema.textTheme.bodyMedium?.copyWith(color: tema.colorScheme.onPrimary),
                  ),
                  Text(
                    atual?.usuario.email ?? '',
                    style: tema.textTheme.bodySmall?.copyWith(color: tema.colorScheme.onPrimary.withValues(alpha: 0.85)),
                  ),
                ],
              ),
            ),
            ListTile(
              leading: const Icon(Icons.refresh),
              title: const Text('Atualizar roteiro'),
              onTap: () {
                Navigator.pop(context);
                estado.atualizarRoteiro();
              },
            ),
            ListTile(
              leading: const Icon(Icons.cloud_upload_outlined),
              title: const Text('Sincronizar agora'),
              subtitle: estado.pendentes > 0 ? Text('${estado.pendentes} item(ns) aguardando envio') : null,
              enabled: !estado.sincronizando,
              onTap: () {
                Navigator.pop(context);
                estado.sincronizar();
              },
            ),
            ListTile(
              key: const Key('meu-cracha'),
              leading: const Icon(Icons.badge_outlined),
              title: const Text('Meu crachá'),
              subtitle: const Text('QR para o supermercado conferir'),
              onTap: () {
                Navigator.pop(context);
                Navigator.of(context).push(MaterialPageRoute<void>(builder: (_) => TelaCracha(sessao: atual)));
              },
            ),
            const Divider(),
            Padding(
              padding: const EdgeInsets.fromLTRB(16, 8, 16, 4),
              child: Text('Contas neste aparelho', style: tema.textTheme.labelLarge),
            ),
            ListTile(
              key: const Key('conta-ativa'),
              leading: const Icon(Icons.check_circle, color: Colors.green),
              title: Text(atual?.empresa.nomeExibido ?? ''),
              subtitle: Text(atual?.usuario.nome ?? ''),
            ),
            for (final conta in outras)
              ListTile(
                key: Key('conta-${conta.chave}'),
                leading: const Icon(Icons.swap_horiz),
                title: Text(conta.empresa.nomeExibido),
                subtitle: Text(conta.usuario.nome),
                onTap: () => _trocar(context, conta),
              ),
            ListTile(
              key: const Key('adicionar-conta'),
              leading: const Icon(Icons.add_business_outlined),
              title: const Text('Adicionar outra empresa'),
              subtitle: const Text('Para quem trabalha em mais de uma agência'),
              onTap: () {
                Navigator.pop(context);
                estado.iniciarNovaConta();
              },
            ),
            const Divider(),
            ListTile(
              key: const Key('sair'),
              leading: Icon(Icons.logout, color: tema.colorScheme.error),
              title: Text('Sair de ${atual?.empresa.nomeExibido ?? 'esta conta'}'),
              onTap: () => _sair(context),
            ),
          ],
        ),
      ),
    );
  }
}
