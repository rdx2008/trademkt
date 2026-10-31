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

## Aceite final

Executar uma visita completa offline no Android, sincronizar sem duplicação e consultar
o resultado com isolamento entre clientes, agências e indústrias.
