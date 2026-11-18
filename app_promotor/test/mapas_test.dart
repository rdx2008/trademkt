import 'package:app_promotor/modelos/roteiro.dart';
import 'package:app_promotor/servicos/mapas.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  test('com coordenada, navega até ela', () {
    final url = urlComoChegar(const Pdv(id: 1, nome: 'Mercado', lat: -29.168, lng: -51.179, endereco: 'Rua A, 10'))!;
    expect(url.host, 'www.google.com');
    expect(url.path, '/maps/dir/');
    expect(url.queryParameters['destination'], '-29.168,-51.179');
    expect(url.queryParameters['api'], '1');
  });

  test('sem coordenada, usa o endereço escrito (codificado no link)', () {
    final url = urlComoChegar(const Pdv(id: 1, nome: 'Mercado', endereco: ' Rua Sinimbu, 100 - Caxias do Sul/RS '))!;
    expect(url.queryParameters['destination'], 'Rua Sinimbu, 100 - Caxias do Sul/RS');
    expect(url.toString(), isNot(contains(' ')));
  });

  test('sem coordenada nem endereço não há como chegar', () {
    expect(urlComoChegar(const Pdv(id: 1, nome: 'Mercado')), isNull);
    expect(urlComoChegar(const Pdv(id: 1, nome: 'Mercado', endereco: '   ')), isNull);
  });
}
