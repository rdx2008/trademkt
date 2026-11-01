# Fase 3 — Execução da visita e app Android

Referência: `ESCOPO.md`. Entregar em etapas pequenas, com testes.

## 3.1 Check-in na API

- `POST /api/v1/visitas/{visita}/check-in`, com token do promotor atribuído.
- Conferir coordenadas pelo cálculo de Haversine e pelo raio cadastrado no PDV.
- Registrar coordenadas, distância, hora do GPS, hora do aparelho e recebimento no servidor.
- Aceitar sincronização posterior: a hora do GPS deve corresponder ao dia da visita no fuso da instalação.
- Recusar GPS declarado como simulado. A detecção no Android pertence ao item 3.4; a declaração enviada à API não comprova autenticidade do GPS.
- Passar de prevista para em andamento. Reenvios devolvem o primeiro check-in sem sobrescrever seus dados.
- Não permitir check-in em visita de outro promotor, sem coordenadas no PDV ou já encerrada.

Implementado. Exemplo de corpo JSON (datas ISO 8601 com fuso obrigatório):

```json
{
  "lat": -29.168,
  "lng": -51.179,
  "gps_em": "2026-10-31T12:00:00-03:00",
  "aparelho_em": "2026-10-31T12:00:02-03:00",
  "gps_simulado": false
}
```

Retorna `201` na primeira gravação e `200` no reenvio, com `id`, `status` e `check_in`.
O roteiro também devolve o check-in registrado em cada visita. Erros: `403` para perfil
inadequado/inativo, `404` para visita não atribuída ao promotor, `409` para estado incompatível
e `422` para dados inválidos, PDV sem coordenadas ou distância fora do raio.
A hora do GPS tolera até cinco minutos à frente do servidor. Todos os horários são
declarados pelo aparelho, exceto `recebido_em`; a API não comprova a origem física do GPS.

## 3.2 Execução e envio

Fotos antes/depois com coordenadas e horários, SKUs da indústria e situação de estoque,
conclusão e tempo de execução. Armazenamento isolado por cliente e gravação idempotente.

Implementado. Todas as chamadas usam o domínio do cliente e token Bearer do promotor
ativo atribuído à visita. Ordem de sincronização: check-in → fotos antes → fotos depois
→ conclusão. Manter os UUIDs e os arquivos originais na fila até a confirmação.

- `GET /api/v1/visitas/{visita}`: estado atual, fotos e itens para reconciliação.
- `POST /api/v1/visitas/{visita}/fotos`: multipart com `uuid`, `tipo` (`antes`, `depois`
  ou `ruptura`), `foto`, `lat`, `lng`, `gps_em`, `aparelho_em`, `gps_simulado=false`.
  Datas ISO 8601 com fuso, como no check-in. JPEG/PNG até 5 MB, 4096 px por dimensão
  e 8 milhões de pixels. Revalida o raio do PDV e a sequência dos horários.
  Primeira gravação 201; reenvio idêntico 200; UUID com conteúdo alterado 409.
- `POST /api/v1/visitas/{visita}/concluir`: JSON com `uuid`, `tipo` (`normal`,
  `ruptura`, `impedimento`), `finalizada_em`, `observacao` e `itens`.
  Cada item contém `sku_id` e `estoque` (`tem_estoque` ou `somente_gondola`).
  SKUs devem estar ativos e pertencer à indústria da visita. Normal exige ao menos
  um SKU e fotos antes/depois. Ruptura exige SKU e foto de ruptura; estoque é gravado
  como `ruptura`, sem contar como produção trabalhada. Impedimento exige observação;
  pode enviar `itens: []`. Reenvio de mesma conclusão é idempotente; alteração gera 409.

As fotos são recodificadas como JPEG com marca d'água e sem metadados originais,
armazenadas em `FOTOS_DISK=local` privado por cliente. Não publicar via storage link.
Links de foto exigem autenticação e o mesmo escopo de acesso da visita.
Recebimento e horário de execução são armazenados separadamente; sincronização tardia
conserva os horários declarados. Divergência GPS/aparelho acima de 5 minutos é sinalizada.
O servidor exige GD. O cliente Android ainda precisa garantir uso da câmera e detectar
GPS simulado: os dados declarados à API, isoladamente, não comprovam sua autenticidade.

## 3.3 Ruptura e impedimento

Registro após check-in, evidências e transições de estado conforme o escopo.
Notificações e demandas ficam na fase 4.

Implementado no backend: ruptura conta como realizada; impedimento fica em atenção.
Alertas internos são gerados por indústria, agência e vínculos de loja/carteira.
Visitas previstas sem check-in após o fim do dia são marcadas não executadas pelo
comando `operacao:encerrar-dia` (scheduler a cada dez minutos, com recuperação de dias
anteriores). Check-in offline válido pode reabrir uma visita marcada não executada,
preservando o alerta histórico e registrando a sincronização tardia.

## 3.4 App Flutter Android

Descoberta da empresa, login, download do roteiro, câmera com marca d'água,
detecção de localização simulada, execução offline e fila persistente de sincronização.

Implementado em `app_promotor/` (Flutter 3.47, Dart 3.13, somente Android; iOS fica para a
fase 8). Textos em português. Validado com `flutter analyze` e `flutter test` (74 testes).

### Estrutura

| Pasta | Conteúdo |
| --- | --- |
| `lib/api/` | `ClienteApi` (HTTP, Bearer, erros tipados), `ApiCentral` (descoberta) e `ApiPromotor` (login, roteiro, check-in, fotos, concluir, visita) |
| `lib/modelos/` | Roteiro/visita/PDV/SKU, usuário e sessão, estado local da execução e **payloads** dos contratos |
| `lib/dominio/regras_visita.dart` | Regras de sequência espelhando as validações do servidor (raio, horários, fotos, conclusão) |
| `lib/dados/repositorio_local.dart` | SQLite (`sqflite`): tabelas `roteiros`, `execucoes` e `fila` |
| `lib/sincronizacao/` | Itens da fila com prioridade e o `Sincronizador` |
| `lib/servicos/` | Sessão no armazenamento seguro, GPS (`geolocator`) com bloqueio de mock location, gravação de fotos com marca d'água |
| `lib/telas/` | Empresa, login, roteiro, visita, câmera e seleção de SKUs |
| `lib/estado_app.dart` | Estado central observado pelas telas; toda gravação passa por ele |
| `test/` | Datas/geo, payloads, regras, fila e repositório, sincronizador (servidor HTTP falso), marca d'água, modelos e fluxo das telas |

### Fluxo no aparelho

1. **Empresa**: o promotor digita o código da instalação; o app chama
   `GET {URL_CENTRAL}/api/v1/empresas/{codigo}` e guarda `api` (`https://.../api/v1`).
   Há um modo avançado para informar a URL da instalação diretamente (homologação/local).
2. **Login**: `POST /login` com e-mail, senha e `dispositivo` (fabricante + modelo). Token e
   usuário ficam no armazenamento seguro do Android. Token inválido (401) leva à tela de login
   sem apagar nada do aparelho; outro usuário só entra sem pendências na fila.
3. **Roteiro**: `GET /roteiro?data=AAAA-MM-DD` salvo em cache por dia; sem rede, o roteiro
   salvo continua disponível. Setas trocam o dia; puxar para baixo atualiza.
4. **Visita**: check-in → foto antes → SKUs com estoque → foto depois → concluir; ou ruptura
   (foto de ruptura + produtos) ou impedimento (observação). Cada passo valida localmente as
   mesmas regras do servidor antes de gravar (`RegrasVisita`).
5. **GPS**: lido apenas no check-in e em cada foto. Posição com `isMocked` (mock location)
   é bloqueada e nada é gravado; por isso `gps_simulado` enviado é sempre `false`.
   Fora do raio do PDV (Haversine, mesmo cálculo do servidor) o passo é recusado.
6. **Fotos**: só pela câmera do app (`camera`), nunca da galeria. A imagem é reduzida a no
   máximo 1600 px, recebe faixa com PDV, tipo, data/hora do GPS e coordenadas e é gravada
   como JPEG na pasta privada do app até o servidor confirmar.
7. **Fila**: cada gravação vira um item com UUID (check-in usa `check-in:<visita>`), payload
   já serializado e prioridade. Ordem de envio por visita: check-in → fotos antes → fotos de
   ruptura → fotos depois → conclusão; visitas na ordem em que começaram. O envio roda após
   cada gravação, ao abrir o app, ao voltar do segundo plano e pelo botão de sincronizar.
8. **Reenvio e reconciliação**: o payload nunca muda, então o reenvio é idempotente
   (201/200). Sem rede, tudo fica pendente. 5xx mantém pendente; 422/404/403 marca erro
   com a mensagem do servidor e bloqueia o resto daquela visita (botão "Tentar novamente").
   409 dispara `GET /visitas/{visita}`: se o servidor já tem o check-in, a foto (por UUID) ou
   a conclusão do mesmo tipo, o item é dado como enviado; senão fica em conflito.
   "Conferir com o servidor" faz a reconciliação manual.

### Compilar e configurar

Requisitos: Flutter stable, Android SDK com `cmdline-tools` e licenças aceitas
(`flutter doctor`), JDK 17 (o do Android Studio serve: `flutter config --jdk-dir`).

```bash
cd app_promotor
flutter pub get
flutter analyze
flutter test
flutter build apk --release --dart-define=URL_CENTRAL=https://trade.mastervps.online
```

- `URL_CENTRAL` é o domínio central (padrão `https://trade.mastervps.online`), usado só na
  descoberta da empresa; a URL de cada instalação vem da resposta da API central.
- Para testar contra uma instalação local, use o modo avançado da primeira tela com a URL da
  instalação (no emulador Android, `http://10.0.2.2:8000` em vez de `localhost`). O build de
  debug libera HTTP sem TLS; o de release exige HTTPS.
- Permissões declaradas: internet, localização precisa e câmera.

### Limites conhecidos

- Não há iOS (fase 8). O build de release ainda usa a chave de debug; configurar a assinatura
  antes de publicar.
- A detecção de GPS simulado usa o `isMocked` do Android; aparelhos com root podem burlá-la.
  O servidor continua tratando os dados declarados como não comprovados.
- Não há reenvio automático em segundo plano com o app fechado: a fila é processada quando o
  app está aberto.

## Aceite final

Executar uma visita completa offline no Android, sincronizar sem duplicação e consultar
o resultado com isolamento entre clientes, agências e indústrias.
