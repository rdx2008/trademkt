# Fase 9 — Rede de empresas (autoatendimento)

Escopo definido em 15/11/2026, a partir da conversa do dono com os clientes. **Nada desta fase está
implementado.** Substitui a regra de 07/11/2026 de que só o super admin cria empresas e vínculos
(ver `docs/REDE-E-DOMINIOS.md`, que continua valendo para o funcionamento por dentro).

## 1. O que os clientes pediram

1. A **indústria** pode ter uma **agência interna**, sem vínculo com outras empresas.
2. A **indústria** pode cadastrar uma **agência externa**, já vigente (já usa o sistema) ou nova.
3. A **agência** pode cadastrar as **indústrias** em que já trabalha e as que serão clientes dela
   (prospects), mesmo que a indústria ainda não use o sistema.
4. A **agência** pode **importar o catálogo de SKUs** da indústria.

## 2. Decisões do dono (15/11/2026)

| # | Decisão | Consequência |
| --- | --- | --- |
| D1 | **Criação sem limite no começo**: a própria indústria ou agência cria empresas pelo painel. | Precisa de gancho para limite por plano no futuro (não há cobrança nesta fase). |
| D2 | A agência cria **indústria gerida**: opera normalmente e a indústria pode **assumir** o cadastro depois. | Fluxo de "assumir" com transferência de dono; dados mantidos. |
| D3 | A agência pode **importar catálogo por padrão**; a indústria (cliente por conta própria) pode desligar por vínculo. | Permissão `agencia_mantem_catalogo` no vínculo, ligada por padrão. |
| D4 | Prospect é **só uma situação no cadastro** (prospect, cliente ativo, encerrado), sem funil. | Campo no vínculo; sem CRM. |

## 3. Referências de mercado (pesquisa de 15/11/2026)

Fontes de fornecedores, sem avaliação independente. O que o mercado confirma e o que adotamos:

- Agile Promoter (Involves) atende agências e indústrias com o mesmo produto, como o nosso.
- Repsly (brokers) dá aos clientes da agência acesso sob demanda a dados, fotos e painéis por marca: nosso
  acesso da indústria às visitas da agência cobre isso.
- Planado tem importação em massa de lojas e equipe e relatórios ao cliente: já temos importação de PDVs,
  catálogo e promotores, e Excel.
- MáximaTech e pdvaction vendem o promotor compartilhado entre indústrias: já temos (habilitação por indústria).
- Não achamos documentação pública sobre permissões entre agência e indústria; o desenho abaixo é nosso.

## 4. Modelo

**Um único mecanismo: "uma empresa cria ou convida a outra".** Todos os casos viram combinações de três
coisas: o **modo** da empresa, o **dono** dela e o **vínculo** entre indústria e agência.

### 4.1 Modo e dono da empresa (tabela `tenants`, banco central)

| Modo | O que é | Dono |
| --- | --- | --- |
| `propria` | Empresa independente (hoje, todas). | Ela mesma. |
| `interna` | **Agência interna** de uma indústria: instalação de agência de verdade, vinculada automaticamente. | A indústria que a criou. |
| `gerida` | **Indústria gerida** por uma agência: a agência opera PDVs, catálogo, contratos e visitas. | A agência que a criou, até a indústria assumir. |

Colunas novas: `modo`, `dono_tenant_id` (nulo em `propria`), `cnpj`, `origem` (`super_admin` ou
`autocadastro`), `criado_por_tenant_id`.

- **CNPJ é a chave global**: um CNPJ só existe uma vez por tipo de empresa. Quem tentar cadastrar um CNPJ
  já existente não cria outra: pede acesso ao cadastro existente (vínculo) ou, se for a indústria dona de uma
  gerida, pede para assumir.
- Agência interna é instalação separada (banco próprio, admin próprio, promotores próprios). A indústria
  continua sem dar ordem direta ao promotor e vê só o primeiro nome dele, como com qualquer agência.

### 4.2 Vínculo indústria–agência (tabela `vinculos`)

Ganha situação, origem, relação comercial e permissões:

- `status`: `pendente`, `ativo`, `recusado`, `encerrado`.
- `origem`: `super_admin`, `industria` ou `agencia` (quem pediu). Pedido pendente precisa de aceite da outra
  ponta, exceto quando as duas empresas pertencem à mesma cadeia (interna e gerida são `ativo` na criação).
- `relacao` (visão da agência): `prospect`, `cliente`, `encerrado`. Prospect **não gera visitas** (o
  `roteiros:gerar` ignora) e serve para já montar PDVs e catálogo.
- `permissoes` (json): `agencia_mantem_catalogo` (padrão `true`). Em indústria gerida a agência tem todos os
  direitos de cadastro; em indústria independente, PDVs, regiões, redes e contratos continuam só consulta.

### 4.3 Casos de uso

1. **Indústria cria agência interna.** Painel da indústria → Agências → "Nova agência interna". Cria a
   instalação de agência (`modo=interna`, dono = a indústria), gera o link de primeiro acesso do admin dela e
   o vínculo já nasce `ativo`.
2. **Indústria cadastra agência externa já vigente.** Informa o CNPJ; o sistema acha a agência e cria um
   vínculo `pendente` (`origem=industria`). A agência vê o pedido na caixa dela e aceita ou recusa.
3. **Indústria cadastra agência externa nova.** Cria a instalação de agência `propria` com o link de
   primeiro acesso do admin; o vínculo nasce `ativo`. A agência passa a ser independente (a indústria não a
   controla, só tem o vínculo).
4. **Agência cadastra indústria que já usa o sistema.** Informa o CNPJ; vínculo `pendente`
   (`origem=agencia`); a indústria aceita e decide a permissão de catálogo.
5. **Agência cadastra indústria que ainda não usa o sistema.** Cria uma **indústria gerida** (`modo=gerida`,
   dono = a agência), com `relacao` prospect ou cliente. A agência opera nela com direitos de cadastro.
6. **Indústria assume o cadastro.** A indústria entra com o CNPJ de uma gerida: pede para assumir; a agência
   gestora confirma (ou o super admin decide). Passa a `propria`, `dono` nulo; a agência continua vinculada
   com `relacao=cliente` e permissões padrão; os dados ficam como estão. Fica registrado quem assumiu e quando.
7. **Agência importa catálogo.** Dentro da indústria, Catálogo → Importar produtos, com a mesma revisão e
   validação de GTIN de hoje. Permitido em gerida e, em independente, quando `agencia_mantem_catalogo` estiver
   ligado. Cada importação guarda a agência que a fez.

### 4.4 Visibilidade

- Cada empresa vê só o que é dela e o que o vínculo `ativo` libera (igual a hoje).
- A indústria dona de uma agência interna vê o resultado operacional como em qualquer agência; nunca o
  cadastro do promotor (CPF, foto, contato) nem as ocorrências internas.
- Prospect e vínculo encerrado: a agência perde o acesso à operação, os dados ficam (como hoje ao desativar).
- Pedidos e avisos ficam numa **caixa de pedidos dentro do painel** (sem e-mail nem push, por decisão de
  08/11/2026), lida do banco central.

## 5. Impacto no que já existe

- `AbrirOperacaoDaAgencia` e `Contexto` passam a conhecer o **modo da indústria** e as permissões do vínculo:
  hoje a agência é sempre "gente da agência com cadastro bloqueado". Em indústria gerida ela precisa agir como
  gestora da operação (PDVs, catálogo, regiões, redes, contratos).
- Os `perfil:` das rotas de cadastro e importação da indústria precisam aceitar o contexto "agência gestora" ou
  "agência com permissão de catálogo", além do perfil da própria indústria.
- `Equipe`, promotores, convite, crachá, habilitação e redistribuição **não mudam**: a agência interna é uma
  agência comum.
- Criar empresa sozinho exige provisionar banco e domínio sem o super admin: rodar como job com situação
  visível, reaproveitando o provisionamento de `cliente:criar`.
- **Pré-requisito de infraestrutura:** subdomínio automático para cada empresa nova só funciona sem passo manual
  se o DNS curinga (`*.trade.mastervps.online`), o roteamento curinga do Coolify e o certificado curinga (desafio
  DNS) estiverem configurados. Sem isso, o autocadastro cria a empresa mas alguém precisa liberar o domínio.

## 6. Itens (um commit por item, sempre com testes)

| Item | Entrega | Porte |
| --- | --- | --- |
| 9.1 | Base de dados: `cnpj`, `modo`, `dono_tenant_id`, `origem` em `tenants`; `status`, `origem`, `relacao`, `permissoes` em `vinculos`; migração dos vínculos atuais (`ativo`, `super_admin`, `cliente`); CNPJ único. | M |
| 9.2 | Criar empresa pelo painel (autocadastro): serviço de provisionamento em job, link de primeiro acesso do admin, subdomínio automático, validação de CNPJ, trilha de auditoria. | G |
| 9.3 | Agência interna (item 4.3.1). | M |
| 9.4 | Pedido de vínculo entre empresas existentes, com aceite/recusa e caixa de pedidos (4.3.2 e 4.3.4); agência externa nova (4.3.3). | G |
| 9.5 | Indústria gerida pela agência, com relação prospect/cliente/encerrado e permissões de gestora (4.3.5). | G |
| 9.6 | Assumir cadastro (4.3.6), com confirmação e registro. | M |
| 9.7 | Agência importa catálogo, com chave por vínculo e trilha de origem (4.3.7). | M |
| 9.8 | Painéis "Minhas indústrias" (agência) e "Minhas agências" (indústria, com interna/externa e situação); super admin ganha visão das empresas por origem e modo. | M |
| 9.9 | Documentação, testes de ponta a ponta e revisão de permissões. | M |

Ordem sugerida: 9.1 → 9.2 → 9.3 → 9.4 → 9.7 → 9.5 → 9.6 → 9.8 → 9.9 (o catálogo por agência já vale para
indústria independente antes da gerida existir).

## 7. Critérios de aceite

- Indústria cria agência interna, o admin dela entra pelo link, cadastra promotores e a indústria acompanha as
  visitas vendo só o primeiro nome.
- Pedido de vínculo nos dois sentidos: pendente, aceito, recusado; recusado não libera nada.
- Dois cadastros com o mesmo CNPJ do mesmo tipo são impossíveis; a tentativa leva a "pedir acesso" ou "assumir".
- Agência cria indústria gerida, importa catálogo, cadastra PDVs e contrato, e a visita aparece no app do
  promotor habilitado. Prospect não gera visita.
- Indústria assume o cadastro: dados preservados, dono trocado, agência segue vinculada, tudo auditado.
- Em indústria independente, a agência só importa catálogo com a chave ligada; a indústria desliga e o acesso
  cai na hora.
- Nenhuma empresa vê dados de outra sem vínculo `ativo` (teste de isolamento).

## 8. Pontos em aberto

- **Cobrança e limite por plano:** D1 deixa sem limite; falta definir quando e como cobrar (por empresa, por
  usuário ou por promotor). Prever o gancho de limite já no 9.2.
- **LGPD:** na indústria gerida a agência opera dados da indústria (PDVs, catálogo, fotos). Definir o termo de
  operador/controlador exibido na criação e no "assumir".
- **PDVs e contratos por agência em indústria independente:** hoje só consulta; se os clientes pedirem
  importar PDVs, vira uma segunda chave de permissão no vínculo.
- **Duplicidade por CNPJ digitado errado:** fundir duas empresas é manual pelo super admin; não há ferramenta.
- **Domínio próprio:** autocadastro usa subdomínio do sistema; domínio próprio continua pelo super admin.
- **Agência interna sem admin ativo:** definir quem recupera o acesso (a indústria dona gera novo link).
