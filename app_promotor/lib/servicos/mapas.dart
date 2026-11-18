/// "Como chegar": abre o aplicativo de mapas no ponto de venda.
library;

import 'package:url_launcher/url_launcher.dart';

import '../modelos/roteiro.dart';

/// Endereço de navegação do PDV: pela coordenada, quando há; senão, pelo endereço escrito.
/// `null` se o PDV não tem nenhum dos dois.
Uri? urlComoChegar(Pdv pdv) {
  final lat = pdv.lat;
  final lng = pdv.lng;
  final destino = (lat != null && lng != null)
      ? '$lat,$lng'
      : (pdv.endereco != null && pdv.endereco!.trim().isNotEmpty ? pdv.endereco!.trim() : null);
  if (destino == null) {
    return null;
  }

  return Uri.https('www.google.com', '/maps/dir/', {'api': '1', 'destination': destino, 'travelmode': 'driving'});
}

/// Abre o link fora do app (aplicativo de mapas ou navegador). Devolve `false` se não conseguiu.
Future<bool> abrirNoMapa(Pdv pdv) async {
  final url = urlComoChegar(pdv);
  if (url == null) {
    return false;
  }
  try {
    return await launchUrl(url, mode: LaunchMode.externalApplication);
  } on Object {
    return false;
  }
}
