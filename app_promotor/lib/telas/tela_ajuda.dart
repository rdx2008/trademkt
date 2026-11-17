import 'package:flutter/material.dart';

/// Ajuda: o passo a passo da visita, em linguagem simples. Funciona sem internet.
class TelaAjuda extends StatelessWidget {
  const TelaAjuda({super.key});

  static const passos = <({String titulo, String texto})>[
    (
      titulo: '1. Veja o seu roteiro',
      texto: 'Na tela inicial estão as visitas do dia. Use as setas para ver outros dias e puxe a tela para baixo para atualizar o roteiro.',
    ),
    (
      titulo: '2. Foto antes da gôndola',
      texto: 'Ao chegar no ponto de venda, abra a visita e tire a foto antes da gôndola. Fique perto do local: o aplicativo confere o raio '
          'e guarda a localização e a hora da foto. Ele só usa o GPS ao tirar as fotos.',
    ),
    (
      titulo: '3. Arrume e reponha',
      texto: 'Faça a reposição e a arrumação normalmente.',
    ),
    (
      titulo: '4. Produtos e estoque',
      texto: 'Marque os produtos em que você trabalhou e diga se há estoque ou se o estoque está somente na gôndola.',
    ),
    (
      titulo: '5. Foto depois e envio',
      texto: 'Tire a foto depois da gôndola e toque em "Concluir e enviar visita".',
    ),
    (
      titulo: 'Produto em falta (ruptura)',
      texto: 'Se o produto está em falta no ponto de venda, use "Ruptura de estoque": foto antes, foto da ruptura e a lista de produtos.',
    ),
    (
      titulo: 'Não consegui entrar na loja',
      texto: 'Use "Impedimento de acesso", tire a foto da entrada e escreva o que aconteceu. A sua agência é avisada.',
    ),
    (
      titulo: 'Sem internet',
      texto: 'Pode trabalhar normalmente: tudo fica guardado no aparelho e é enviado quando a internet voltar. '
          'O rodapé mostra o que falta enviar, e o botão "Enviar agora" tenta de novo.',
    ),
    (
      titulo: 'Meu crachá e minha produção',
      texto: 'No menu, "Meu crachá" mostra o QR para o supermercado conferir quem você é, e "Minha produção" mostra o que você trabalhou no mês.',
    ),
    (
      titulo: 'Dúvidas',
      texto: 'Fale com o supervisor da sua agência.',
    ),
  ];

  @override
  Widget build(BuildContext context) {
    final tema = Theme.of(context);

    return Scaffold(
      appBar: AppBar(title: const Text('Ajuda')),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          for (final p in passos)
            Card(
              child: Padding(
                padding: const EdgeInsets.all(16),
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: [
                    Text(p.titulo, style: tema.textTheme.titleMedium),
                    const SizedBox(height: 6),
                    Text(p.texto),
                  ],
                ),
              ),
            ),
        ],
      ),
    );
  }
}
