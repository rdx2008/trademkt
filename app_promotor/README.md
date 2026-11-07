# App do promotor (Android)

Aplicativo Flutter usado pelo promotor para executar as visitas do roteiro: check-in por GPS,
fotos antes/depois pela câmera do app com marca d'água, SKUs trabalhados com situação de estoque,
ruptura e impedimento, tudo funcionando offline com fila persistente de sincronização.

A documentação completa (fluxo, contratos da API, como compilar e configurar a URL) está em
[`docs/FASE-3.md`](../docs/FASE-3.md), seção **3.4 App Flutter Android**.

```bash
flutter pub get
flutter analyze
flutter test
flutter build apk --release --dart-define=URL_CENTRAL=https://trade.mastervps.online
```
