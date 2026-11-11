/// Marca d'água gravada na própria imagem antes de salvar: data, hora,
/// coordenadas e PDV. O servidor grava outra marca ao receber, mas a do
/// aparelho garante a evidência mesmo na cópia local.
library;

import 'dart:typed_data';

import 'package:image/image.dart' as img;

import 'datas.dart';

/// Lado máximo da foto enviada (o servidor aceita até 4096 px e 5 MB).
const int ladoMaximoFoto = 1600;

/// Texto das linhas da marca d'água.
List<String> linhasMarcaDagua({
  required String pdvNome,
  required int pdvId,
  required String tipo,
  required double lat,
  required double lng,
  required DateTime gpsEm,
}) =>
    [
      'PDV $pdvId - ${semAcentos(pdvNome)}',
      '${tipo.toUpperCase()} ${formatarDataHoraSegundosBr(gpsEm)}',
      'LAT ${lat.toStringAsFixed(6)} LNG ${lng.toStringAsFixed(6)}',
    ];

/// Parâmetros da marca, serializáveis para rodar em outro isolate.
class PedidoMarcaDagua {
  const PedidoMarcaDagua({required this.jpeg, required this.linhas, this.ladoMaximo = ladoMaximoFoto});

  final Uint8List jpeg;
  final List<String> linhas;
  final int ladoMaximo;
}

/// Redimensiona, corrige orientação, desenha a faixa com as linhas e devolve um JPEG novo.
/// Lança [ArgumentError] se os bytes não forem uma imagem válida.
Uint8List aplicarMarcaDagua(PedidoMarcaDagua pedido) {
  var imagem = img.decodeImage(pedido.jpeg);
  if (imagem == null) {
    throw ArgumentError('Imagem inválida.');
  }
  imagem = img.bakeOrientation(imagem);

  final maior = imagem.width > imagem.height ? imagem.width : imagem.height;
  if (maior > pedido.ladoMaximo) {
    imagem = imagem.width >= imagem.height
        ? img.copyResize(imagem, width: pedido.ladoMaximo)
        : img.copyResize(imagem, height: pedido.ladoMaximo);
  }

  final fonte = imagem.width >= 1000 ? img.arial24 : img.arial14;
  final alturaLinha = fonte.lineHeight + 4;
  final margem = 8;
  final alturaFaixa = pedido.linhas.length * alturaLinha + margem * 2;
  final topo = imagem.height - alturaFaixa;

  img.fillRect(
    imagem,
    x1: 0,
    y1: topo,
    x2: imagem.width - 1,
    y2: imagem.height - 1,
    color: img.ColorRgba8(0, 0, 0, 170),
  );

  for (var i = 0; i < pedido.linhas.length; i++) {
    img.drawString(
      imagem,
      pedido.linhas[i],
      font: fonte,
      x: margem,
      y: topo + margem + i * alturaLinha,
      color: img.ColorRgb8(255, 255, 255),
    );
  }

  return Uint8List.fromList(img.encodeJpg(imagem, quality: 85));
}

/// As fontes bitmap do pacote `image` não têm acentos: troca por equivalentes simples.
String semAcentos(String texto) {
  const mapa = {
    'á': 'a', 'à': 'a', 'â': 'a', 'ã': 'a', 'ä': 'a',
    'é': 'e', 'è': 'e', 'ê': 'e', 'ë': 'e',
    'í': 'i', 'ì': 'i', 'î': 'i', 'ï': 'i',
    'ó': 'o', 'ò': 'o', 'ô': 'o', 'õ': 'o', 'ö': 'o',
    'ú': 'u', 'ù': 'u', 'û': 'u', 'ü': 'u',
    'ç': 'c', 'ñ': 'n',
    'Á': 'A', 'À': 'A', 'Â': 'A', 'Ã': 'A', 'Ä': 'A',
    'É': 'E', 'È': 'E', 'Ê': 'E', 'Ë': 'E',
    'Í': 'I', 'Ì': 'I', 'Î': 'I', 'Ï': 'I',
    'Ó': 'O', 'Ò': 'O', 'Ô': 'O', 'Õ': 'O', 'Ö': 'O',
    'Ú': 'U', 'Ù': 'U', 'Û': 'U', 'Ü': 'U',
    'Ç': 'C', 'Ñ': 'N',
  };
  final resultado = StringBuffer();
  for (final rune in texto.runes) {
    final caractere = String.fromCharCode(rune);
    resultado.write(mapa[caractere] ?? caractere);
  }

  return resultado.toString();
}
