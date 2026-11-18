import 'dart:io';

import 'package:flutter/material.dart';

import '../modelos/usuario.dart';

/// Logo da empresa (cópia salva no aparelho) ou, sem logo, o ícone padrão.
class LogoMarca extends StatelessWidget {
  const LogoMarca({super.key, required this.marca, this.altura = 48, this.cor});

  final Marca? marca;
  final double altura;
  final Color? cor;

  @override
  Widget build(BuildContext context) {
    final caminho = marca?.logoLocal;
    final padrao = Icon(Icons.storefront, size: altura, color: cor ?? Theme.of(context).colorScheme.primary);
    if (caminho == null) {
      return padrao;
    }

    return Image.file(
      File(caminho),
      height: altura,
      fit: BoxFit.contain,
      errorBuilder: (_, _, _) => padrao,
    );
  }
}
