# Rede de agências e endereços dos clientes

## Modelo aprovado

Uma agência pode atender várias indústrias com sua conta administrativa. Uma indústria pode
ter várias agências. O diretório fica no banco central; a operação continua no banco isolado
da indústria. A regra comercial anterior que impedia vender aos dois lados foi substituída
por este modelo, conforme aprovado pelo dono.

## Fluxo de uso

1. O super admin cria uma instalação do tipo agência em **Clientes → Novo cliente**.
2. Em **Rede de agências**, cadastra o perfil, contato comercial, cobertura e aprovação.
3. A indústria pode escolher uma agência no cadastro inicial (opcional) ou depois em
   **Buscar agências**. A escolha envia uma solicitação, nunca um aceite automático.
4. A indústria informa a região desejada e uma mensagem. O filtro de município exige UF;
   a busca ignora diferenças de maiúsculas e acentuação. Cobertura estadual inclui os municípios.
5. A agência entra na própria instalação e abre **Parcerias com indústrias** para aceitar
   ou recusar. Recusa exige motivo. A indústria pode cancelar apenas uma solicitação pendente.
6. Ao aceitar, o sistema cria a representação da agência na instalação da indústria.
   Não cria contratos, PDVs, visitas, usuários comuns ou cópias de equipes automaticamente.
7. A indústria define os contratos de PDVs com a agência aceita. A agência usa **Abrir operação
   da indústria** para gerenciar sua equipe local, ajustar frequências e montar roteiros.
   Cadastros de PDVs e produtos e novos contratos continuam sob administração da indústria.

Administradores da agência autorizados na instalação de origem podem abrir as operações das
parcerias aceitas sem compartilhar senhas. Cada operação mantém sua própria equipe local.
Promotores e supervisores dessa operação são cadastrados nela; o código da empresa usado no app
é o da instalação da indústria. A sincronização global de identidades de colaboradores não
faz parte desta entrega.

## Aprovação e acesso

- Só o super admin aprova a participação no diretório. A agência pode editar apresentação,
  contato e cobertura e pausar novas solicitações; não pode aprovar a si mesma.
- Só perfis de gestão autorizados da indústria solicitam parceria. Apenas administradores
  autorizados da agência de destino podem responder.
- Solicitações duplicadas pendentes/aceitas são recusadas. Após recusa ou cancelamento,
  a indústria pode reenviar, reabrindo o mesmo registro com a solicitação mais recente.
- O acesso entre instalações usa um token aleatório, armazenado como hash, válido por um minuto,
  utilizável uma única vez e preso à instalação de destino. O usuário local recebe perfil
  `admin_agencia`, nunca `admin_instalacao`. A sessão é regenerada e vinculada à instalação.
- Toda requisição protegida de um usuário da agência vinculada confere se a parceria está aceita,
  a agência aprovada e a instalação de origem ativa. Para administradores que entram pelo atalho,
  confere também o usuário e a permissão na origem. Suspensões bloqueiam sessões já abertas.
- A agência acessa seus contratos, PDVs contratados, catálogo dessas indústrias, equipe e roteiros.
  Não administra o cadastro geral da indústria ou vê operações de outras agências.
- A indústria pode desativar a representação local da agência para suspender seu acesso àquela
  instalação. A aprovação global é controlada pelo super admin. Retirar a disponibilidade
  apenas pausa novas solicitações, preservando parcerias existentes.

Agências cadastradas manualmente nas instalações existentes são preservadas. Não são publicadas
no diretório nem convertidas em contas compartilhadas automaticamente. Para usar o novo fluxo,
é preciso uma instalação de agência e o perfil aprovado; cadastros legados não são mesclados
apenas por coincidência de nome ou CNPJ.

## Domínios e subdomínios

Base inicial: `trade.mastervps.online`, configurável por `TRADE_DOMINIO_CLIENTES`.

O super admin escolhe **Subdomínio do sistema** ou **Domínio próprio** no cadastro do cliente.
Exemplos: `gaboardi.trade.mastervps.online` e `trade.gaboardi.com.br`.
Não há cadastro público de clientes nesta versão: o super admin registra a preferência do cliente.

Em **Clientes → Gerenciar endereços**, é possível adicionar endereços alternativos, escolher o
principal e remover os antigos. Sempre deve restar pelo menos um. O identificador, banco,
usuários e histórico são mantidos. O endereço principal é usado na descoberta do app e nos
atalhos de parceria; os endereços alternativos continuam aceitando acesso. A mudança de host
pode exigir novo login. URLs antigas só continuam funcionando enquanto o domínio antigo for mantido.

### Configuração de produção no Coolify

```env
TRADE_DOMINIO_CLIENTES=trade.mastervps.online
```

Se o painel central também for migrado para a base escolhida:

```env
APP_URL=https://trade.mastervps.online
CENTRAL_DOMAINS=trade.mastervps.online
```

Durante a transição, `CENTRAL_DOMAINS` pode listar o domínio central antigo e o novo, separados
por vírgula. Não incluir domínios de clientes nessa variável.

O cadastro no aplicativo não altera DNS nem a configuração do proxy:

- Apontar os registros DNS de `trade.mastervps.online` e dos subdomínios utilizados para o VPS.
- Um registro curinga `*.trade.mastervps.online` cobre o DNS dos clientes, mas não substitui
  o roteamento do serviço `app` no Coolify nem a configuração de certificado.
- Para começar, cadastrar cada domínio usado no serviço `app`, porta 8080, e fazer deploy.
- Para automatizar muitos subdomínios, configurar roteamento curinga e certificado curinga.
  No Traefik do Coolify, certificado curinga exige desafio DNS. O guia oficial está em
  https://coolify.io/docs/core/networking/proxy/traefik/wildcard-certs.
- Domínio próprio de cliente precisa apontar para o mesmo VPS e ser associado ao serviço `app`.
- Testar o novo endereço e seu HTTPS antes de torná-lo principal e remover o antigo.

Não há integração com a API do Coolify ou com o provedor DNS nesta entrega. A página de endereços
não afirma que um domínio foi verificado ou que o certificado já foi emitido.

## Deploy e testes

As migrações centrais criam o diretório, as regiões, as solicitações e os tokens de acesso.
As migrações de cada cliente adicionam a identidade da agência da rede e a origem dos usuários
administrativos vinculados. Os campos são opcionais para preservar as instalações existentes.
O entrypoint atual executa `migrate` e `tenants:migrate` ao iniciar o serviço web.

Testes: `DominiosClienteTest`, `ParceriaRedeTest` e toda a suíte existente. Os testes usam SQLite;
o deploy em MySQL/Coolify deve ser verificado após publicar. Nenhuma credencial de produção é
necessária nos testes.
