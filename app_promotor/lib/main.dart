import 'package:device_info_plus/device_info_plus.dart';
import 'package:flutter/material.dart';
import 'package:flutter_localizations/flutter_localizations.dart';
import 'package:http/http.dart' as http;

import 'dados/repositorio_local.dart';
import 'estado_app.dart';
import 'servicos/fotos.dart';
import 'servicos/localizacao.dart';
import 'servicos/sessao.dart';
import 'telas/tela_empresa.dart';
import 'telas/tela_login.dart';
import 'telas/tela_roteiro.dart';

/// Domínio central do sistema. Troque em tempo de compilação:
/// `flutter build apk --dart-define=URL_CENTRAL=https://trade.exemplo.com.br`.
const String urlCentral = String.fromEnvironment('URL_CENTRAL', defaultValue: 'https://trade.mastervps.online');

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  final estado = EstadoApp(
    httpClient: http.Client(),
    armazenamento: ArmazenamentoSeguro(),
    repositorio: await RepositorioLocal.abrirDaConta('padrao'),
    fabricaRepositorio: RepositorioLocal.abrirDaConta,
    localizacao: const ServicoLocalizacaoGeolocator(),
    fotos: ServicoFotos(),
    urlCentral: urlCentral,
    dispositivo: await _nomeDoAparelho(),
  );
  await estado.carregar();

  runApp(AppPromotor(estado: estado));
}

Future<String> _nomeDoAparelho() async {
  try {
    final info = await DeviceInfoPlugin().androidInfo;

    return '${info.manufacturer} ${info.model}'.trim();
  } on Object {
    return 'Android';
  }
}

/// Cor padrão do app, usada enquanto a empresa não define uma própria.
const Color corPadrao = Color(0xFF1B5E20);

/// Tema do app: a cor principal da empresa (white label) ou a padrão.
ThemeData temaDaMarca(Color? cor) {
  final base = cor ?? corPadrao;
  final esquema = ColorScheme.fromSeed(seedColor: base).copyWith(primary: base, onPrimary: Colors.white);

  return ThemeData(
    colorScheme: esquema,
    useMaterial3: true,
    appBarTheme: AppBarTheme(backgroundColor: base, foregroundColor: Colors.white),
  );
}

class AppPromotor extends StatelessWidget {
  const AppPromotor({super.key, required this.estado});

  final EstadoApp estado;

  @override
  Widget build(BuildContext context) {
    // O tema acompanha a empresa da vez: a do login, a de outra conta sendo acrescentada
    // ou a da conta ativa. Tudo some junto quando o admin tira a marca própria.
    return ListenableBuilder(
      listenable: estado,
      builder: (context, _) => MaterialApp(
        title: estado.marcaAtual?.nome ?? 'Trade Promo',
        debugShowCheckedModeBanner: false,
        locale: const Locale('pt', 'BR'),
        supportedLocales: const [Locale('pt', 'BR')],
        localizationsDelegates: const [
          GlobalMaterialLocalizations.delegate,
          GlobalWidgetsLocalizations.delegate,
          GlobalCupertinoLocalizations.delegate,
        ],
        theme: temaDaMarca(estado.marcaAtual?.cor),
        home: _tela(),
      ),
    );
  }

  Widget _tela() {
    if (!estado.carregado) {
      return const Scaffold(body: Center(child: CircularProgressIndicator()));
    }
    if (estado.adicionandoConta) {
      return estado.empresaPendente != null ? TelaLogin(estado: estado) : TelaEmpresa(estado: estado);
    }
    if (estado.autenticado) {
      return TelaRoteiro(estado: estado);
    }
    if (estado.empresaPendente != null || estado.sessaoExpirada) {
      return TelaLogin(estado: estado);
    }

    return TelaEmpresa(estado: estado);
  }
}
