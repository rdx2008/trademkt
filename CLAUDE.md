# Trade Promo — contexto do projeto

Sistema de trade marketing: controle da execução de promotores de venda nos PDVs
(supermercados). Dono: Rodolfo de Angelis (mastervps). Responda sempre em português do Brasil.

Escopo completo: https://claude.ai/code/artifact/333ed85c-0d9c-4b22-96b3-3a9c783e0874

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
- Fotos: disco local hoje; qualquer storage S3 compatível depois, trocando só o `.env`.
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

1. Base e infraestrutura — **feita** (este repositório)
2. Cadastros e roteiros: PDVs, importador de catálogo (OpenAI), contratos de visita, roteiros
3. App do promotor (Android, Flutter)
4. Demandas e alertas: painel do supermercado, ruptura, pedido de visita, notificações
5. Indicadores: dashboards, produção SKU/dia, galeria antes/depois, Excel
6. Piloto Gaboardi
7. Materiais de PDV
8. App iOS

## Comandos

```bash
composer install
php artisan test
php artisan cliente:criar gaboardi --nome=Gaboardi --tipo=industria --dominio=gaboardi.localhost \
  --admin-nome=Admin --admin-email=admin@gaboardi.com.br
php artisan cliente:listar
php artisan tenants:migrate
```

## Estado

- Fase 1 validada: `composer install` ok, `composer.lock` commitado, `php artisan test` verde (24 testes).
  Antes de rodar os testes num clone novo: `cp .env.example .env`.
- Código e mensagens em português. Pint para formatação.
