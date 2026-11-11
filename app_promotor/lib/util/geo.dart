import 'dart:math';

/// Raio médio da Terra em metros (mesmo valor usado pela API).
const double raioTerraMetros = 6371000;

/// Distância em metros entre dois pontos pela fórmula de Haversine.
/// Replica o cálculo do servidor para validar o raio antes de enviar.
double distanciaMetros(double lat1, double lng1, double lat2, double lng2) {
  final latA = _radianos(lat1);
  final latB = _radianos(lat2);
  final deltaLat = latB - latA;
  final deltaLng = _radianos(lng2 - lng1);

  final a = pow(sin(deltaLat / 2), 2) + cos(latA) * cos(latB) * pow(sin(deltaLng / 2), 2);

  return raioTerraMetros * 2 * asin(sqrt(max(0, min(1, a.toDouble()))));
}

double _radianos(double graus) => graus * pi / 180;

/// Coordenadas válidas para a API.
bool coordenadasValidas(double lat, double lng) =>
    lat >= -90 && lat <= 90 && lng >= -180 && lng <= 180;
