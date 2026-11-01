# Trade Promo — contexto do projeto

Sistema de trade marketing: controle da execução de promotores de venda nos PDVs
(supermercados). Dono: Rodolfo de Angelis (mastervps). Responda sempre em português do Brasil.

**Referência oficial do escopo: [`docs/ESCOPO.md`](docs/ESCOPO.md).** Mudança de escopo: atualizar
esse arquivo primeiro. (Origem: https://claude.ai/code/artifact/333ed85c-0d9c-4b22-96b3-3a9c783e0874)

**Antes de começar cada fase, leia `docs/FASE-N.md`** (ex.: `docs/FASE-2.md`), que detalha os itens,
as tabelas e os critérios de aceite. Um commit por item, sempre com testes.

## Modelo de negócio

- Mesmo produto vendido para **agências de promoção** e para **indústrias**, cada cliente isolado.
- Regra trabalhista: a indústria nunca tem promotores próprios nem dá ordem direta ao promotor.
  A indústria solicita à sua agência; a agência verifica e atribui ao promotor responsável.
- Tudo roda no VPS do fornecedor (Coolify). Cliente não tem acesso ao código.
- Primeiro cliente: Gaboardi (bazar/limpeza). Até 30 usuários no início.

## Arquitetura (decidida)

- Um app Laravel 12 único. Cliente identificado pelo domínio (stancl/tenancy v3, multi-banco).
- Banco central: `tenants`, `domains`, `admins` (super admin do fornecedor).
- Banco por cliente: `cli_<id>`. Pasta por cliente: `storage/clientes/<id>`.
- Tipo de instalação: `agencia` ou `industria` (coluna `tipo` do tenant).
- Rotas centrais: `routes/web.php` e `routes/api.php` (só nos `CENTRAL_DOMAINS`).
  Rotas do cliente: `routes/tenant.php`.
- Migrações do cliente ficam em `database/migrations/tenant`.
- Fotos privadas: `FOTOS_DISK=local` hoje; disco S3 compatível pode ser configurado depois. Arquivos com prefixo por cliente, servidos por rotas autenticadas.
- App do promotor: Flutter, Android primeiro, iOS por último (fase 8). Offline.
- Deploy: Coolify via `docker-compose.yml` (app, worker, scheduler, mysql, redis).

## Perfis (`app/Enums/Perfil.php`)

admin_instalacao, gerente_trade e representante (indústria), admin_agencia, supervisor e
promotor (agência; promotor CLT ou freelancer), gerente_pdv (gerente do supermercado).
Só o promotor usa o app; os demais usam o painel web (responsivo).

## Regras de negócio já fechadas

- Visitas recorrentes por contrato (1, 2 ou 3x/semana) e sob demanda (prazo: mesmo dia).
- Fluxo da visita: check-in por GPS no raio do PDV (padrão 200 m, configurável 150–300 m) →
  foto antes → reposição/arrumação → SKUs trabalhados + situação de estoque
  ("tem estoque" / "estoque somente na gôndola") → foto depois → enviar.
- GPS só no check-in e nas fotos de antes e depois. Sem rastreio contínuo.
- Fotos só pela câmera do app, com marca d'água (data, hora, coordenadas, PDV). Bloquear GPS falso.
- Ruptura exige check-in, conta como visita e notifica indústria → representante → gerente → supervisor.
- Impedimento de acesso: visita "em atenção", alerta aos superiores e destaque no admin.
- Visita agendada não executada: vermelho no admin + notificação.
- Gerente do supermercado: vê marcas, agências e promotores da loja; botões "Avisar ruptura" e "Pedir visita".
- Freelancer: só relatório de SKUs trabalhados por dia. Pagamento não passa pelo sistema.
- Importador de catálogo (PDF, XML, planilha) com OpenAI, qualquer layout, tela de revisão,
  validação de GTIN. Produto tem várias embalagens (unidade, reembalagem, caixa master), cada uma com GTIN.
- Política de privacidade e termo de uso: texto padrão editável por instalação.
- Etapa futura (fora das fases): rede de freelancers estilo "Uber" (demanda para todos,
  primeiro que aceita executa, valor fixo). Identidade do promotor fica no painel central
  (`users.promotor_global_id` já existe).

## Fases

1. Base e infraestrutura — concluída.
2. Cadastros e roteiros — implementados; importador de catálogo com OpenAI (2.3) pendente.
3. Execução — backend 3.1–3.3 e app Flutter Android (3.4) implementados; teste em aparelho real pendente.
4. Demandas e alertas — web e alertas internos implementados; e-mail e push pendentes.
5. Indicadores — painel, produção, galeria privada e Excel implementados.
6. Piloto Gaboardi — pendente.
7. Materiais de PDV — pendente.
8. App iOS — pendente.

## Estado consolidado — atualizado em 01/11/2026

### Base, cadastros e roteiros

- Laravel 12 com isolamento de banco, arquivos, cache e autenticação por instalação;
  super admin central, criação/suspensão de clientes, descoberta da empresa e login da API.
- Cadastros de usuários e perfis, indústrias, agências, regiões, redes de supermercados,
  PDVs com coordenadas/raio, importação de PDVs por planilha e vínculos de carteira/loja.
- Catálogo manual de SKUs e embalagens com validação de GTIN (`App\Rules\Gtin`);
  contratos recorrentes, tela semanal, atribuição/movimentação e `GET /api/v1/roteiro`.
- `roteiros:gerar` roda diariamente às 04:30. Visitas guardam `data` (agenda) e
  `data_prevista` (contrato); mover a agenda não é desfeito pela geração.
- Geocoding usa `GOOGLE_MAPS_API_KEY`; sem chave, a posição pode ser marcada manualmente.

### Rede de agências, regiões e domínios

Referência: `docs/REDE-E-DOMINIOS.md`.

- Diretório central de agências aprovado pelo super admin, com apresentação, contato e
  cobertura por UF/município. Uma indústria pode contratar várias agências por região.
- A escolha no cadastro inicial ou depois em **Buscar agências** envia solicitação;
  a agência aceita ou recusa com motivo. Nunca há aceite automático.
- Solicitações/parcerias aceitas permitem múltiplas regiões (até 30), selecionando estados
  e município opcional. A indústria pode editar depois; isso não altera contratos ou
  cobertura declarada da agência automaticamente. Cada parceria tem suas próprias regiões.
- O aceite cria a representação local da agência, sem copiar equipes ou criar contratos
  automaticamente. Administradores da agência abrem a operação da indústria por token
  curto de uso único, com perfil `admin_agencia` e acesso restrito à sua operação.
  Parceria, aprovação e permissões da origem são conferidas em cada requisição protegida.
- Equipes são locais à operação. Promotores usam o código da instalação onde trabalham.
  Cadastros internos legados são preservados e não publicados automaticamente no diretório.
- Nomes adotados no painel da indústria: **Agências internas**, **Buscar agências** e
  **Redes de supermercados**.
- Base inicial: `TRADE_DOMINIO_CLIENTES=trade.mastervps.online`. Super admin escolhe
  subdomínio do sistema ou domínio próprio no cadastro do cliente. Não há cadastro público.
- Gerenciamento de endereços permite adicionar alternativas, trocar o principal e remover
  antigos mantendo pelo menos um. Banco, usuários e histórico são mantidos. DNS, proxy e
  certificados HTTPS continuam sendo configurados externamente no provedor/Coolify.

### Painéis e permissões

Referência: `docs/PAINEIS.md`.

- Super admin em `/visao-geral`: instalações, situação, tipos, domínios e atalhos de gestão.
- Todos os perfis do cliente em `/painel`, com resumo por data, agenda, gráficos,
  filtros, paginação, pontos de atenção e atalhos autorizados; layout responsivo.
- Admin da instalação acompanha toda a operação local; gerente de trade vê sua marca;
  agência/supervisor veem sua agência; representante vê sua carteira; promotor vê suas
  visitas; gerente de supermercado vê suas lojas. Consultas existentes continuam sujeitas
  às permissões específicas dos respectivos controllers.
- Fotos, demandas, alertas e exportações respeitam os recortes de acesso. Alertas deixam
  de aparecer quando o usuário perde o vínculo necessário com a loja/carteira/operação.
- Promotor tem consulta web complementar; a execução no dispositivo depende do app Android.
- Supermercado consulta contratos, marcas, agências, próximo promotor e última visita com
  evidências; botões **Pedir visita** e **Avisar ruptura** por contrato vigente.

### Execução da visita — backend

Referência e contratos completos: `docs/FASE-3.md`.

- `POST /api/v1/visitas/{visita}/check-in`: promotor ativo atribuído à visita, GPS,
  cálculo de raio no servidor, horários GPS/aparelho/recebimento e reenvio idempotente.
- `POST /api/v1/visitas/{visita}/fotos`: fotos antes/depois/ruptura, coordenadas,
  horário com fuso e UUID. Valida raio e sequência; JPEG/PNG até 5 MB, 4096 px por
  dimensão e 8 milhões de pixels. GD recodifica em JPEG com marca d'água, removendo
  metadados originais. Arquivos privados e isolados por cliente, inclusive no caminho.
- `POST /api/v1/visitas/{visita}/concluir`: normal, ruptura ou impedimento. Normal exige
  fotos antes/depois e SKUs ativos da indústria com estoque `tem_estoque`/`somente_gondola`.
  No JSON de conclusão, cada item contém `sku_id` e `estoque`.
- Ruptura exige check-in, foto de ruptura e produtos; conta como visita realizada, sem
  contar os SKUs em falta como produção trabalhada. Impedimento exige observação,
  não registra produtos trabalhados e deixa a visita em atenção.
- `GET /api/v1/visitas/{visita}` permite reconciliar estado, evidências e produtos.
  UUIDs e conferência do conteúdo evitam duplicação em reenvios; alteração conflitante
  é recusada. Ordem de sincronização: check-in → fotos antes → depois → conclusão.
- Duração, conclusão e recebimento ficam registrados separadamente. Sincronização tardia
  válida conserva o horário declarado e pode reabrir visita marcada não executada.
  Divergência GPS/aparelho superior a cinco minutos é sinalizada.
- A API rejeita GPS declarado simulado, mas não comprova a autenticidade física do GPS.
  Garantia de captura pela câmera, detecção no dispositivo e fila offline dependem do Android.

### App do promotor — Android (`app_promotor/`)

Referência: `docs/FASE-3.md`, seção 3.4.

- Flutter (Android). Descoberta da empresa pelo código na API central, login com token no
  armazenamento seguro, roteiro do dia em cache SQLite para uso offline.
- Fluxo da visita com as mesmas regras do servidor validadas no aparelho: check-in por GPS
  dentro do raio, foto antes, SKUs com situação de estoque, foto depois, conclusão; ruptura
  (foto + produtos) e impedimento (observação).
- GPS lido só no check-in e nas fotos; posição simulada (mock location) é bloqueada.
  Fotos só pela câmera do app, com marca d'água (PDV, tipo, data/hora, coordenadas).
- Fila persistente com UUID e payload fixo: check-in → fotos antes → ruptura → depois →
  conclusão; reenvio idempotente; 409 reconciliado via `GET /api/v1/visitas/{visita}`.
- `flutter analyze` e `flutter test` (74 testes) passam. Sem iOS (fase 8).

### Demandas e alertas internos

Referência: `docs/FASE-4.md`.

- Supermercado/indústria solicita por contrato vigente, com motivo, observação,
  SKUs de ruptura e foto opcional. Prazo no mesmo dia.
- Somente agência/supervisor aprova e atribui promotor ativo da própria agência;
  recusa exige justificativa. Aprovação idempotente cria a visita sob demanda,
  reutilizando o roteiro do dia. Indústria nunca atribui diretamente ao promotor.
- Conclusão normal/ruptura atende a demanda vinculada. Impedimento mantém a demanda
  pendente de atendimento e gera alerta.
- Alertas persistentes individuais: solicitação, aprovação/recusa, atendimento, ruptura,
  impedimento, atraso, visita não executada e sincronização tardia. Destinatários por
  agência, indústria, carteira e loja. Leitura individual e deduplicação de eventos.
- `operacao:encerrar-dia` roda a cada dez minutos nas instalações ativas e recupera
  dias anteriores. Marca visitas previstas sem check-in como não executadas e alerta
  demandas vencidas. Execuções repetidas não duplicam os avisos.
- Canais entregues: caixa de alertas dentro do painel. E-mail, SMS e push não implementados.

### Indicadores, galeria e Excel

Referência: `docs/FASE-5.md`.

- Filtros por período, indústria, agência, região, rede, PDV, supervisor e promotor.
- Cobertura contratual, produção SKU/dia por promotor, rupturas por SKU/PDV, visitas
  não executadas, demandas no prazo/atrasadas, tempo e impedimentos por PDV/promotor.
- Cobertura considera `data_prevista` das visitas contratuais já geradas; produção/duração
  usam a data de conclusão, preservada na sincronização tardia. Relatos recebidos de
  ruptura são separados das rupturas registradas nas visitas.
- Galeria privada antes/depois e detalhe de visita com check-in, produtos, estoque,
  duração, observação e evidências. Fotos exigem autenticação e acesso à visita.
- Excel aplica os mesmos filtros e inclui resumo, agenda, produção diária, SKUs por visita,
  cobertura, rupturas, demandas e tempo/impedimentos. Textos são exportados como texto,
  sem execução de fórmulas. Tabelas web mostram até 100 linhas; Excel inclui todo o filtro.
- Freelancer consulta sua produção; pagamentos continuam fora do sistema.

### Validação e deploy

- Última validação da implementação, em 31/10/2026: **165 testes e 1.323 verificações,
  todos passaram**, em PHP 8.4/SQLite; inclui 21 testes de operação. Pint e compilação/
  sintaxe das views passaram. Esta atualização de 01/11 é documental, sem nova execução
  da suíte. Código e mensagens em português; traduções em `lang/pt_BR`.
- Coolify: app, worker, scheduler, MySQL e Redis. Docker instala GD para marca d'água;
  rebuild necessário ao publicar essa mudança. O entrypoint web executa `migrate --force`,
  `tenants:migrate --force` e seed antes de servir a aplicação.
- Preservar `APP_KEY` e volume persistente de storage. Chave de 32 bytes codificada em
  Base64 exige prefixo `base64:`. Fotos usam `FOTOS_DISK=local` por padrão e não devem
  ser publicadas por storage link.
- `CENTRAL_DOMAINS` contém somente domínios centrais; domínios de clientes ficam no
  cadastro do tenant. Subdomínio/domínio próprio precisa também de DNS, proxy e HTTPS.
- Conferir migrações e app/worker/scheduler após deploy. A validação local não comprova
  build Docker, MySQL, HTTPS ou funcionamento em produção; validar no Coolify.

### Pendências e próximos passos

1. App Android: testar em aparelho real (câmera, GPS, mock location), configurar assinatura
   de release e publicar o APK para o piloto.
2. Notificações por e-mail e push; hoje os alertas são internos ao painel.
3. Importador de catálogo com OpenAI (2.3), adiado por falta de créditos informada pelo
   dono. Ao retomar, revisar limite de upload de 50 MB versus requisito de 60 MB e
   `retry_after` do Redis (90 s): processar por página ou ajustar a reentrega dos jobs.
4. Piloto Gaboardi: carga de catálogo/dados, testes em campo e ajustes da operação.
5. Materiais de PDV e app iOS. Rede aberta de freelancers e sincronização global de
   identidades/equipes continuam futuras, sem implementação nesta entrega.

## Comandos

Antes dos testes em clone novo: `cp .env.example .env` e instalar dependências.

```bash
composer install
php artisan test
php vendor/bin/phpunit
php vendor/bin/pint --dirty --test
php artisan cliente:criar gaboardi --nome=Gaboardi --tipo=industria --dominio=gaboardi.localhost \
  --admin-nome=Admin --admin-email=admin@gaboardi.com.br
php artisan cliente:listar
php artisan tenants:migrate
php artisan roteiros:gerar
php artisan operacao:encerrar-dia
```

App do promotor (`app_promotor/`): `flutter pub get`, `flutter analyze`, `flutter test`,
`flutter build apk --release --dart-define=URL_CENTRAL=https://trade.mastervps.online`.
