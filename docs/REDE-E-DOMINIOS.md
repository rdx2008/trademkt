# Vínculo indústria–agência e endereços dos clientes

## Modelo aprovado (07/11/2026)

**As pessoas moram na agência; a operação mora na indústria.**

- A **indústria** é dona de PDVs, catálogo, regiões, redes, contratos, roteiros, visitas, fotos,
  demandas e alertas. Ela não tem promotores, supervisores nem agência interna; cadastra só
  admin da instalação, gerente de trade, representante e gerente de supermercado.
- A **agência** tem instalação própria, onde ficam o admin, os supervisores e os promotores.
  Ela trabalha dentro da operação de cada indústria que atende, com a própria equipe.
- O **vínculo** é feito só pelo super admin, em **Clientes → Vínculos**: indústria + agência +
  regiões informativas (UF e município opcional, até 30). Não há diretório, solicitação, aceite
  nem token de acesso. Uma indústria pode ter várias agências (por região, por exemplo) e uma
  agência atende várias indústrias.
- A indústria nunca guarda dados do promotor: só o id dele, acompanhado da agência. Nas telas da
  indústria aparece apenas o primeiro nome, lido na hora do banco da agência.

## Como funciona por dentro

- O vínculo cria, no banco da indústria, uma linha de `agencias` com `agencia_tenant_id`. Ela é o
  que contratos, visitas e demandas referenciam (`agencia_id`). Desativar o vínculo desativa essa
  linha (a agência perde o acesso); reativar a traz de volta. Nome acompanha o cadastro central.
- Referências a pessoas da agência no banco da indústria: `roteiros.promotor_id` e
  `roteiros.supervisor_id` (com `roteiros.agencia_id`; roteiro único por agência + promotor +
  dia), `demandas.liberada_por` (agência da demanda), `demandas.solicitante_id` (com
  `solicitante_agencia_id`), `alertas.usuario_id` (com `alertas.agencia_id`). Sem chave
  estrangeira: o id é do banco da agência. `agencia_id` nulo = usuário desta instalação.
- `App\Services\Operacao\Equipe` abre uma conexão própria com o banco da agência
  (`instalacao_<id>`, montada a partir do tenant) e devolve `App\Support\Pessoa` (id, nome,
  perfil, situação, vínculo). Uma leitura por agência por requisição; não troca o tenant em uso e
  pode ser chamada antes de uma transação, nunca dentro. Agência sem `agencia_tenant_id`
  (cadastro antigo) lê os usuários locais, para os dados anteriores continuarem valendo.
- `App\Support\Contexto` distingue a **instalação** em que o usuário entrou (pelo domínio) da
  **operação** aberta (a indústria). `Perfil::daInstalacao(tipo)` define os perfis de cada tipo.

### Painel da agência

- O usuário entra na instalação da agência. `AbrirOperacaoDaAgencia` (antes de `SubstituteBindings`)
  escolhe a indústria (sessão `operacao`, ou a primeira vinculada), troca o banco para o dela e
  apresenta o usuário como gente da agência local (`agencia_id` vira a linha local; o admin da
  instalação age como admin da agência). Nada disso é gravado. Ao fim da requisição volta para a
  agência, onde a sessão é salva. Seletor de indústria no cabeçalho (`POST /operacao/{industria}`).
- Ficam na agência: `usuarios.*`, `configuracoes.*`, entrada e saída, arquivos da marca. Dentro
  da indústria a agência pode abrir: painel, roteiros (atribui e reordena), visitas, demandas
  (aprova e atribui), alertas, indicadores, BI, contratos (consulta e ajuste de frequência),
  PDVs, catálogo, regiões e redes (consulta). O resto responde 403: "cadastro da indústria".
- A marca (aparência) mostrada é sempre a da agência, lida do banco dela.
- Alertas: `Alerta::doUsuario` casa `usuario_id` com `agencia_id` da operação aberta, então ids
  iguais em bancos diferentes não se misturam.

### App do promotor

- O promotor entra pelo código da **agência**. `GET /api/v1/roteiro` percorre as indústrias
  vinculadas, abre cada uma e soma as visitas; cada visita e indústria traz `industria` (código).
- Execução: `/api/v1/industrias/{industria}/visitas/{visita}/...` (check-in, fotos, concluir,
  consulta). O middleware abre a indústria pelo código da rota e tira o parâmetro antes dos
  controllers, que são os mesmos da execução na própria indústria.
- No app, visita e indústria têm chave `empresa:id`; banco local versão 2.

## Domínios e subdomínios

Base inicial: `trade.mastervps.online`, configurável por `TRADE_DOMINIO_CLIENTES`.

O super admin escolhe **Subdomínio do sistema** ou **Domínio próprio** no cadastro do cliente.
Exemplos: `gaboardi.trade.mastervps.online` e `trade.gaboardi.com.br`.
Não há cadastro público de clientes nesta versão: o super admin registra a preferência do cliente.

Em **Clientes → Gerenciar endereços**, é possível adicionar endereços alternativos, escolher o
principal e remover os antigos. Sempre deve restar pelo menos um. O identificador, banco,
usuários e histórico são mantidos. O endereço principal é usado na descoberta do app; os
endereços alternativos continuam aceitando acesso. A mudança de host pode exigir novo login.
URLs antigas só continuam funcionando enquanto o domínio antigo for mantido.

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

A migração central `vinculos_entre_industria_e_agencia` cria `vinculos` e apaga as tabelas do
diretório antigo (dados de teste). A migração de cada cliente `operacao_com_agencia_vinculada`
acrescenta `agencias.agencia_tenant_id`, `roteiros.agencia_id`, `alertas.agencia_id` e
`demandas.solicitante_agencia_id`, e remove as chaves estrangeiras para `users` dessas
referências. O entrypoint executa `migrate` e `tenants:migrate` ao iniciar o serviço web.

Testes: `VinculoTest`, `EquipeVinculadaTest`, `PainelAgenciaTest`, `DominiosClienteTest` e a
suíte existente (SQLite). Cada instalação de agência vira um banco a mais por teste. O deploy em
MySQL/Coolify deve ser verificado após publicar: a conexão própria com o banco da agência usa a
mesma configuração da conexão de tenant, trocando o nome do banco.
