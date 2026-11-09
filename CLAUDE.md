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
- Fluxo da visita: foto antes da gôndola, que é o check-in (hora e GPS da foto, dentro do raio do
  PDV, padrão 200 m, configurável 150–300 m; sem botão de check-in nem selfie) → reposição/arrumação → SKUs trabalhados + situação de estoque
  ("tem estoque" / "estoque somente na gôndola") → foto depois → enviar.
- GPS só no check-in e nas fotos de antes e depois. Sem rastreio contínuo.
- Fotos só pela câmera do app, com marca d'água (data, hora, coordenadas, PDV). Bloquear GPS falso.
- Ruptura exige check-in, conta como visita e notifica indústria → representante → gerente → supervisor.
- Impedimento de acesso: visita "em atenção", alerta aos superiores e destaque no admin.
- Visita agendada não executada: vermelho no admin + notificação.
- Gerente do supermercado: vê marcas, agências e promotores da loja; botões "Avisar ruptura" e "Pedir visita".
- Freelancer: só relatório de SKUs trabalhados por dia. Pagamento não passa pelo sistema.
- Importador de catálogo: planilha e PDF com texto, por regras e sem IA (implementado; a versão com OpenAI foi
  retirada do escopo em 08/11/2026). Tela de revisão, validação de GTIN. Produto tem várias embalagens (unidade, reembalagem, caixa master), cada uma com GTIN.
- Política de privacidade e termo de uso: texto padrão editável por instalação.
- Etapa futura (fora das fases): rede de freelancers estilo "Uber" (demanda para todos,
  primeiro que aceita executa, valor fixo). Identidade do promotor fica no painel central
  (`users.promotor_global_id` já existe).

## Fases

1. Base e infraestrutura — concluída.
2. Cadastros e roteiros — implementados; importação de catálogo por planilha e PDF sem IA feita (versão com OpenAI retirada do escopo).
3. Execução — backend 3.1–3.3 e app Flutter Android (3.4) implementados; teste em aparelho real pendente.
4. Demandas e alertas — web e alertas internos implementados (e-mail e push retirados do escopo).
5. Indicadores — painel, produção, galeria privada e Excel implementados.
6. Piloto Gaboardi — pendente.
7. Materiais de PDV — pendente.
8. App iOS — pendente.

## Estado consolidado — atualizado em 07/11/2026

### Base, cadastros e roteiros

- Laravel 12 com isolamento de banco, arquivos, cache e autenticação por instalação;
  super admin central, criação/suspensão de clientes, descoberta da empresa e login da API.
- Cadastros de usuários e perfis, indústrias, agências, regiões, redes de supermercados,
  PDVs com coordenadas/raio, importação de PDVs por planilha e vínculos de carteira/loja.
- Catálogo manual de SKUs e embalagens com validação de GTIN (`App\Rules\Gtin`);
  contratos recorrentes, tela semanal, atribuição/movimentação e `GET /api/v1/roteiro`.
- `roteiros:gerar` roda diariamente às 04:30. Visitas guardam `data` (agenda) e
  `data_prevista` (contrato); mover a agenda não é desfeito pela geração.
- No cadastro de PDV, o CEP preenche o endereço (BrasilAPI, com ViaCEP de reserva) e latitude/longitude saem
  sozinhas pelo endereço: Google com `GOOGLE_MAPS_API_KEY` ou, sem chave, OpenStreetMap (`GEOCODING_OSM`,
  ligado por padrão). O pino arrastado ou clicado no mapa é ajuste manual e prevalece.

### Vínculo indústria–agência e domínios

Referência: `docs/REDE-E-DOMINIOS.md`. Modelo "as pessoas moram na agência, a operação mora
na indústria" (decidido em 07/11/2026; substitui o diretório com solicitação e aceite).

- Só o super admin vincula uma agência a uma indústria, em **Clientes → Vínculos**, com regiões
  informativas (UF e município, até 30). Uma indústria pode ter várias agências e vice-versa.
- O vínculo cria a agência no banco da indústria (`agencias.agencia_tenant_id`), que é o que
  contratos, visitas e demandas referenciam. Desativar o vínculo desativa esse acesso; os dados
  ficam. A indústria consulta as agências vinculadas em **Agências** e não cadastra agência.
- Promotor, supervisor e admin de agência existem só na instalação da agência
  (`Perfil::daInstalacao`). A indústria cadastra admin, gerente de trade, representante e
  gerente de loja. Roteiros, demandas e alertas guardam o id da pessoa com a agência ao lado
  (`roteiros.agencia_id`, `alertas.agencia_id`, `demandas.solicitante_agencia_id`), sem FK.
- `App\Services\Operacao\Equipe` lê a equipe da agência pelo banco dela (conexão própria,
  `instalacao_<id>`, sem trocar o tenant nem mexer em transação). A indústria vê só o primeiro
  nome do promotor (`Visita::nomePromotor`). Agência sem `agencia_tenant_id` (dados antigos)
  continua lendo usuários locais.
- A agência entra na própria instalação; `AbrirOperacaoDaAgencia` troca para o banco da
  indústria escolhida (seletor no cabeçalho, sessão `operacao`) e apresenta o usuário como
  gente da agência local (`App\Services\Rede\Operacao`, `App\Support\Contexto`). Ficam na
  agência: usuários, aparência, entrada/saída. Na indústria: roteiros, contratos (consulta e
  frequência), demandas, indicadores, BI, e PDVs/catálogo/regiões/redes só para consulta.
- O app entra pela agência: `GET /api/v1/roteiro` soma as indústrias atendidas e cada visita
  leva `industria` (código da empresa); a execução usa
  `/api/v1/industrias/{industria}/visitas/{visita}/...`.
- Base inicial: `TRADE_DOMINIO_CLIENTES=trade.mastervps.online`. Super admin escolhe
  subdomínio do sistema ou domínio próprio no cadastro do cliente. Não há cadastro público.
- Gerenciamento de endereços permite adicionar alternativas, trocar o principal e remover
  antigos mantendo pelo menos um. Banco, usuários e histórico são mantidos. DNS, proxy e
  certificados HTTPS continuam sendo configurados externamente no provedor/Coolify.

### Promotores da agência

Tela própria em **Equipe → Promotores** (só instalação de agência); o promotor não aparece em Usuários.

- Admin da agência cadastra, edita, desativa e importa por planilha; supervisor só consulta.
- Cadastro com CPF (criptografado, mascarado nas listas, consulta registrada no histórico), foto privada,
  cidade base e vínculo. Indústria e supermercado nunca veem CPF; a indústria vê só o primeiro nome.
- Primeiro acesso por convite (link de 7 dias, uso único, guardado como hash) com aviso de privacidade.
- Ficha (`/promotores/{id}`): agenda dos 7 dias e histórico de 30 dias somando as indústrias atendidas
  (lidas do banco de cada indústria, `FichaOperacional`), métricas, afastamentos, ocorrências internas,
  aparelhos conectados (com desconectar) e histórico de alterações.
- Habilitação por indústria (`promotor_industrias` + `users.habilitacao_restrita`, na agência): promotor novo
  nasce restrito e sem nenhuma indústria, e o admin marca onde ele atua (ou "todas"). Os cadastrados antes
  seguem em "todas". Restringe atribuição (`Equipe::promotorAtivo`), listas e o roteiro do app. Afastamento
  também barra a atribuição no período; a repetição semanal automática pula quem está afastado.
- Redistribuição (`Redistribuicao`): ao afastar ou desativar com visitas já atribuídas, a ficha oferece a prévia
  e passa as visitas previstas para colegas ativos, habilitados e livres (primeiro a cidade base do PDV, depois
  quem tem menos visitas no dia). Sem substituto, a visita fica como está. Admin e supervisor podem aplicar.
- Lista de promotores: visitas de hoje (total e iniciadas), último aparelho, alerta "ainda não abriu o app hoje"
  e "sem indústria habilitada". Ficha: relatório de SKUs trabalhados por dia (mês) com CSV, para conferir o
  pagamento do freelancer (ruptura não conta); ocorrências podem ser editadas e removidas pelo admin.
- Cidade base: nas telas de atribuição o promotor aparece com a cidade e, na decisão de demanda, quem é da
  cidade do PDV vem primeiro.
- Crachá digital: `/promotores/{id}/cracha` (imprimir, QR) e página pública `/verificar/{codigo}` (código de
  40 caracteres, só foto, primeiro nome e ativo/inativo; nunca CPF, e-mail, telefone nem afastamento).
  O app tem "Meu crachá" no menu (QR do endereço vindo em `usuario.cracha_url`; sessão antiga precisa entrar de novo).

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

- A foto `antes` abre a visita (check-in pela foto): promotor ativo atribuído, GPS, raio calculado no
  servidor, horários GPS/aparelho/recebimento e reenvio idempotente. No impedimento de acesso, a foto
  da entrada (`tipo=impedimento`) faz o mesmo. `POST .../check-in` fica só para o APK anterior.
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
- Menu lateral com contas do aparelho: o promotor pode atender mais de uma agência (uma conta por
  empresa, cada uma com roteiro, fila e fotos próprios), trocar de conta, adicionar empresa e sair.
- Marca própria por empresa: nome, cor e logo vindos da descoberta (`marca`), logo guardado offline.
- O promotor entra pelo código da **agência**; o roteiro junta as indústrias atendidas. Visita e
  indústria têm chave `empresa:id` (`Visita.chave`), e a execução vai para
  `industrias/{empresa}/visitas/{id}/...`. Banco local versão 2 (recriado na atualização).
- `flutter analyze` e `flutter test` (94 testes) passam. Sem iOS (fase 8).

### Importação de catálogo (planilha e PDF, sem IA)

Referência: `docs/FASE-2.md`, item 2.3a. Em **Catálogo → Importar produtos**.

- Planilha CSV/XLSX (modelo para baixar, formato largo ou longo) e PDF com texto selecionável,
  lido por regras (`smalot/pdfparser`); calibrado com o catálogo da Gaboardi (69 produtos).
- Revisão antes de gravar: erros bloqueiam o item; avisos (GTIN com dígito errado, repetido,
  quantidade suposta, vários códigos) não bloqueiam. Reimportar atualiza pelo código, sem duplicar.
- Admin da instalação escolhe a indústria; gerente de trade importa só na própria marca.
- Sem OCR (PDF escaneado não é lido) e a revisão não edita campos.

### Tema visual, menu e BI

Referências: `docs/PAINEIS.md` (tema e menu) e `docs/FASE-5.md` (BI).

- Telas dos clientes usam o tema Tixia em `public/tema/`; o super admin (domínios centrais) mantém
  o visual antigo. Compatibilidade das telas antigas em `public/css/tema-cliente.css`.
- Menu lateral em tópicos com submenu (`App\Support\MenuLateral`), com as mesmas permissões
  de antes. Modo claro/escuro lembrado no navegador.
- Componentes `x-indicador` e `x-grafico` (ApexCharts via `public/js/graficos.js`).
- BI em `/bi` (visão da indústria ou da agência), com variação contra o período anterior,
  rankings, estoque, rupturas e demandas. Admin da instalação/agência, supervisor e gerente de trade.

### Marca própria (white label)

Referência: `docs/PAINEIS.md`. Ligada por padrão, sem menção ao Trade Promo.

- Admin da instalação personaliza nome, cor principal e logos em **Configurações → Aparência**;
  a cor vira uma folha de estilo por cliente (`/marca/tema.css`), logos em `/marca/{claro,escuro,icone}`.
- Fornecedor liberar/desligar a marca própria e mostrar "Desenvolvido por" em **Clientes → Marca**.
- App Android veste a marca da empresa pela descoberta (`marca` em `GET /api/v1/empresas/{codigo}`).

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
- Canais entregues: caixa de alertas dentro do painel. E-mail, SMS e push fora do escopo (decisão de 08/11/2026).

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

1. App Android: gerar APK novo (o promotor agora entra pela agência e a API de execução mudou),
   testar em aparelho real (câmera, GPS, mock location), configurar assinatura de release e
   publicar o APK para o piloto. No Coolify: rebuild e conferir as migrações `vinculos` (central)
   e `operacao_com_agencia_vinculada` (clientes); criar a instalação da agência e o vínculo.
2. Piloto Gaboardi: catálogo já carregado (08/11/2026); faltam testes em campo e ajustes da operação.
3. Guardado para depois do piloto, sem prazo (detalhes em `docs/ESCOPO.md`, "Ideias guardadas"): materiais de
   PDV (estoque, envio ao promotor e comprovação por foto) e app iOS (exige Mac/Xcode ou build na nuvem e conta
   Apple de US$ 99/ano; não compila a partir do Windows). Rede aberta de freelancers e sincronização global de
   identidades/equipes continuam futuras.
4. Retirados do escopo em 08/11/2026: importador de catálogo com IA (OpenAI) e notificações por e-mail e push.

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
