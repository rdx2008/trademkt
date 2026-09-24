# Trade Promo — Fase 1

Sistema de trade marketing: execução de promotores no PDV. Um app Laravel único atende
todos os clientes (agências e indústrias). O cliente é identificado pelo domínio; cada um
tem banco próprio (`cli_<id>`) e pasta própria (`storage/clientes/<id>`).

## O que a fase 1 entrega

| Parte | Onde |
| --- | --- |
| Multi-cliente por domínio (stancl/tenancy, um banco por cliente) | `config/tenancy.php`, `app/Providers/TenancyServiceProvider.php` |
| Provisionamento de cliente | `app/Services/ProvisionarCliente.php`, `php artisan cliente:criar` |
| Painel central do fornecedor (login, lista, novo cliente, suspender) | `routes/web.php`, `app/Http/Controllers/Central` |
| API de descoberta do app (código da empresa → URL) | `GET /api/v1/empresas/{codigo}` no domínio central |
| Perfis (7) e permissões | `app/Enums/Perfil.php`, middleware `perfil:` |
| Painel do cliente: login, painel, usuários, indústrias, agências | `routes/tenant.php`, `app/Http/Controllers/Tenant` |
| API do app: login por token, `me`, logout | `POST /api/v1/login` no domínio do cliente |
| Deploy no Coolify | `Dockerfile`, `docker-compose.yml`, `docker/` |
| Testes | `tests/` |

### Perfis

| Perfil | Acesso | Cadastra |
| --- | --- | --- |
| Admin da instalação | Painel | Todos |
| Gerente de trade (indústria) | Painel | Representante da sua indústria |
| Representante comercial (indústria) | Painel | — |
| Admin da agência | Painel | Supervisor e promotor da sua agência |
| Supervisor (agência) | Painel | — |
| Promotor (agência, CLT ou freelancer) | App | — |
| Gerente do supermercado | Painel | — |

O super admin do fornecedor fica no banco central, separado dos usuários dos clientes.

## Rodar localmente

```bash
composer install
cp .env.example .env
php artisan key:generate
# ajuste DB_* (o usuário precisa poder criar bancos com prefixo cli_)
# e CENTRAL_DOMAINS=localhost
php artisan migrate
php artisan admin:criar --nome="Rodolfo" --email=voce@exemplo.com
php artisan cliente:criar gaboardi --nome="Gaboardi" --tipo=industria \
  --dominio=gaboardi.localhost --admin-nome="Admin" --admin-email=admin@gaboardi.com.br
php artisan serve
```

Painel central em `http://localhost:8000`, cliente em `http://gaboardi.localhost:8000`
(`*.localhost` já aponta para a máquina na maioria dos navegadores).

Testes (SQLite, não precisam de MySQL):

```bash
php artisan test
```

## Deploy no Coolify

1. **Novo recurso → Docker Compose**, apontando para este repositório.
2. **Variáveis de ambiente** (no Coolify): `APP_KEY` (gere com `php artisan key:generate --show`),
   `APP_URL`, `CENTRAL_DOMAINS`, `DB_PASSWORD`, `MYSQL_ROOT_PASSWORD`, `SUPER_ADMIN_EMAIL`,
   `SUPER_ADMIN_SENHA`. As demais têm padrão no `docker-compose.yml`.
3. **Domínios do serviço `app`**: o domínio do painel central e os domínios de cada cliente,
   separados por vírgula, na porta 8080 (ex.: `https://painel.seudominio.com.br:8080,https://trade.gaboardi.com.br:8080`).
   O Coolify gera o SSL de cada um.
4. **Deploy.** No primeiro start, o container `app` roda as migrações do banco central, as
   migrações de todos os clientes e cria o super admin.

Serviços: `app` (nginx + PHP-FPM), `worker` (filas), `scheduler` (agendamentos), `mysql`, `redis`.
Os arquivos ficam no volume `storage`.

### Novo cliente em produção

1. No painel central, **Novo cliente** (ou `php artisan cliente:criar` no terminal do container `app`).
2. Aponte o DNS do domínio do cliente para o VPS.
3. Adicione o domínio ao serviço `app` no Coolify e faça redeploy para gerar o SSL.

Para não precisar mexer no Coolify a cada cliente, use subdomínios de um domínio seu
(ex.: `gaboardi.tradepromo.com.br`) com um certificado curinga `*.tradepromo.com.br`.

### Atualização

Push no Git → deploy no Coolify. O container `app` roda `migrate` e `tenants:migrate` a cada
start, então todos os bancos de clientes ficam na mesma versão.

### Comandos úteis

| Comando | Faz |
| --- | --- |
| `php artisan cliente:criar {id}` | Cria cliente (banco, pasta, domínio, admin) |
| `php artisan cliente:listar` | Lista clientes e domínios |
| `php artisan cliente:dominio {id} {dominio} [--remover]` | Adiciona ou remove domínio |
| `php artisan admin:criar` | Cria super admin do painel central |
| `php artisan tenants:migrate` | Roda migrações em todos os clientes |
| `php artisan tenants:run {comando} --tenants={id}` | Roda um comando dentro de um cliente |

## API do app (fase 1)

```
GET  https://<central>/api/v1/empresas/{codigo}   → { nome, url, api, api_versao }
POST https://<cliente>/api/v1/login               { email, password, dispositivo } → { token, usuario }
GET  https://<cliente>/api/v1/me                  Authorization: Bearer <token>
POST https://<cliente>/api/v1/logout              Authorization: Bearer <token>
```

Só o perfil **promotor** entra pelo app. Um token por aparelho; desativar o usuário derruba os tokens.

## Observação

O `composer.lock` não está no repositório: gere com `composer install` e faça commit dele
para fixar as versões usadas no build.
