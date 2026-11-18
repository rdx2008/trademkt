import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:qr_flutter/qr_flutter.dart';
import 'package:app_promotor/modelos/usuario.dart';
import 'package:app_promotor/telas/tela_cracha.dart';

Sessao _sessao({String? crachaUrl}) => Sessao(
      empresa: const Empresa(codigo: 'sul', nome: 'Agência Sul', url: 'https://sul.test', api: 'https://sul.test/api/v1'),
      token: 't',
      usuario: Usuario(id: 5, nome: 'João da Silva', email: 'a@a.test', perfil: 'promotor', agenciaNome: 'Agência Sul', crachaUrl: crachaUrl),
    );

void main() {
  testWidgets('mostra o QR do endereço de conferência', (tester) async {
    await tester.pumpWidget(MaterialApp(home: TelaCracha(sessao: _sessao(crachaUrl: 'https://sul.test/verificar/abc'))));
    expect(find.text('João da Silva'), findsOneWidget);
    expect(find.byKey(const Key('cracha-qr')), findsOneWidget);
  });

  testWidgets('sessão antiga, sem endereço, pede para entrar de novo', (tester) async {
    await tester.pumpWidget(MaterialApp(home: TelaCracha(sessao: _sessao())));
    expect(find.byKey(const Key('cracha-indisponivel')), findsOneWidget);
    expect(find.byType(QrImageView), findsNothing);
  });

  test('o endereço do crachá sobrevive ao salvar a sessão no aparelho', () {
    final volta = Sessao.fromJson(_sessao(crachaUrl: 'https://sul.test/verificar/abc').toJson());
    expect(volta.usuario.crachaUrl, 'https://sul.test/verificar/abc');
  });
}
