# Escopo — Sistema de Trade Marketing

Fonte: doc de escopo aprovado em 24/09/2026 (https://claude.ai/code/artifact/333ed85c-0d9c-4b22-96b3-3a9c783e0874).
Este arquivo é a referência para o desenvolvimento. Mudança de escopo: atualizar aqui primeiro.

## Visão geral

Sistema para controlar a execução do trabalho de promotores de venda nos pontos de venda (PDVs):
check-in por geolocalização, foto da gôndola antes e depois, reposição ou arrumação dos SKUs,
aviso de ruptura e indicadores.

- **Foco:** execução no PDV. Incentivo ao revendedor, verbas de trade e treinamento ficam fora.
- **Modelo de venda:** o mesmo produto é vendido para agências de promoção e para indústrias,
  cada uma em instalação isolada.
- **Regra comercial:** não vender para uma agência e para uma indústria atendida por ela
  (controle do fornecedor, fora do sistema).
- **Hospedagem:** tudo no VPS próprio (Coolify). O cliente não tem acesso ao código-fonte.
- **Primeiro cliente:** Gaboardi (bazar/limpeza), com até 30 usuários no início.

## Tipos de instalação

Mesmo código, definido pela coluna `tipo` do cliente (`agencia` | `industria`). Nos dois, os
promotores são sempre de uma agência: por questão trabalhista, a indústria não tem promotores próprios.

| | Instalação de Agência | Instalação de Indústria |
| --- | --- | --- |
| Dono | Agência | Indústria |
| Dentro dela | Várias indústrias clientes | Uma ou mais agências prestadoras |
| Promotores | Da própria agência | Das agências prestadoras |
| Visão da indústria | Métricas completas, só da sua marca | Métricas completas de tudo |
| Quem atribui tarefa ao promotor | Supervisor da agência | Supervisor da agência prestadora |

**Cuidado trabalhista:** a indústria vê tudo e pode solicitar ações, mas nunca dá ordem direta ao
promotor. A indústria sempre solicita à sua agência, que verifica e atribui ao responsável.

## Perfis

| Grupo | Perfil | Acesso | O que faz |
| --- | --- | --- | --- |
| Plataforma | Super admin (fornecedor) | Painel central | Cria clientes, domínios, suporte |
| Instalação | Admin da instalação | Painel web | Cadastros, usuários, configurações, todos os relatórios |
| Indústria | Gerente de trade | Painel web | Métricas completas, solicita à agência, recebe alertas |
| Indústria | Representante comercial | Painel web + notificações | Recebe aviso de ruptura dos PDVs da sua carteira |
| Agência | Admin da agência | Painel web | Promotores, supervisores, contratos de visita |
| Agência | Supervisor | Painel web | Monta roteiros, atribui visitas e demandas, acompanha execução |
| Agência | Promotor (CLT ou freelancer) | App | Executa visitas, fotos, SKUs trabalhados, ruptura, impedimento |
| PDV | Gerente do supermercado | Painel web responsivo | Vê marcas, agências e promotores da loja; avisa ruptura ou pede visita |

Na instalação de agência, cada usuário da indústria enxerga só os SKUs e PDVs da sua marca.

Cadeia de notificação (ruptura, impedimento, não executada):
indústria → representante comercial → gerente de trade → supervisor.

## Módulos

**No escopo**

1. **Cadastros:** indústrias, agências, PDVs (CNPJ, endereço, coordenadas, raio de check-in, rede,
   canal), catálogo de SKUs por indústria (importação de PDF, XML ou planilha em qualquer layout,
   com revisão antes de gravar), concorrentes, regiões, equipes e usuários.
2. **Roteirização e jornada:** contrato de frequência por PDV (1, 2 ou 3 vezes por semana), roteiro
   do promotor montado pelo supervisor, visitas sob demanda, tempo de execução por visita.
3. **Execução no PDV (app offline):** check-in, foto antes, SKUs trabalhados, situação de estoque,
   foto depois, envio, ruptura e impedimento de acesso.
4. **Materiais de PDV:** estoque de material por indústria, envio ao promotor e comprovação de
   instalação por foto.
5. **Indicadores:** cobertura, produtividade, rupturas, visitas não executadas, tempo de execução,
   produção SKU/dia por promotor.

**Fora do escopo:** acordos e verbas de trade, incentivo ao revendedor, comunicação e treinamento,
integrações com ERP e sell-out, reconhecimento de imagem por IA.

### Importador de catálogo

Lê o catálogo de cada indústria sem padrão fixo: a API da OpenAI lê cada página (texto e imagem) e
devolve os produtos em campos padronizados.

1. A indústria ou o admin envia o PDF, XML ou planilha.
2. A IA extrai por produto: nome, código, categoria, foto e cada embalagem (unidade, reembalagem,
   caixa master) com quantidade e GTIN.
3. O sistema valida os GTINs (quantidade de dígitos e dígito verificador) e aponta duplicados.
4. Tela de revisão em tabela, com itens suspeitos em amarelo, para corrigir antes de gravar.
5. Reimportar atualiza pelo código do produto, sem duplicar.

Observado no catálogo da Gaboardi: layout em colunas embaralha a extração simples de texto; há
produtos com dois códigos (Alumgrill em caixas de 25 e de 12) e variantes marcadas por símbolos na
mesma tabela (espetinhos de 18 cm e 25 cm); alguns GTINs parecem ter erro de digitação.

## Fluxos

### Execução da visita

Check-in (GPS no raio) → foto antes da gôndola (+ GPS) → reposição ou arrumação →
SKUs trabalhados + estoque → foto depois (+ GPS) → enviar.
Atalhos a partir do check-in: ruptura, impedimento de acesso.

O promotor só inicia com check-in válido. Por SKU, marca **tem estoque** ou **estoque somente na
gôndola**. O tempo entre check-in e envio é o tempo de execução.

### Ruptura de estoque

- Exige check-in e conta como visita realizada.
- O promotor aciona o botão de ruptura, informa os SKUs em falta e tira foto.
- Notifica na hora: indústria, representante comercial, gerente de trade e supervisor.

### Demanda (supermercado ou indústria)

Gerente do mercado solicita visita → notifica indústria, gerente e supervisor → supervisor da
agência libera ao promotor do PDV → execução no mesmo dia → se não executada: alerta aos
superiores + vermelho no admin.

Motivos: falta de produto na gôndola ou gôndola desarrumada. A indústria também pode abrir
solicitação, sempre destinada à sua agência.

### Painel do gerente do supermercado

Navegador (celular ou computador). Um cartão por marca atendida na loja: marca, agência
responsável, promotor da loja, próxima visita prevista, última visita (data + fotos antes/depois),
botões **Avisar ruptura** e **Pedir visita**.

- Avisar ruptura: escolhe a marca, marca os produtos em falta (foto opcional). Notifica indústria,
  representante e agência.
- Pedir visita: gôndola desarrumada ou outro motivo, com observação.
- Vê só as marcas da instalação em que foi cadastrado.

### Impedimento de acesso

Registrado com check-in e observação. Visita fica **em atenção**, com alerta aos superiores e
destaque no admin.

### Visita agendada não executada

Ao fim do dia, visitas do roteiro sem check-in viram **não executadas**: vermelho no admin e
notificação. Loja fechada não é motivo: PDV fechado não deve estar no roteiro.

## Regras de negócio e antifraude

- App 100% offline; localização validada no aparelho e conferida de novo no servidor ao sincronizar.
- Raio de check-in: padrão 200 m, configurável por PDV entre 150 e 300 m (Haversine no servidor).
- Sem tempo mínimo ou máximo de permanência; tempo de execução aparece nos relatórios.
- Fotos só pela câmera do app, sem galeria, com marca d'água (data, hora, coordenadas, PDV).
- GPS falso (mock location) no Android bloqueia o check-in.
- Registrar hora do GPS e hora do aparelho, para detectar relógio adulterado.
- GPS coletado só no check-in e nas fotos de antes e depois. Sem rastreio contínuo.
- Visitas offline ficam em fila e sobem quando houver conexão.
- Demanda: prazo no mesmo dia.
- Tarefas ao promotor: sempre atribuídas pelo supervisor da agência.

## Indicadores

Filtros: período, indústria, região, rede, PDV, supervisor, promotor. Exportação em Excel.

| Indicador | Cálculo |
| --- | --- |
| Cobertura | Visitas realizadas ÷ visitas previstas no contrato |
| Visitas não executadas | Agendadas sem check-in no dia |
| Rupturas | Quantidade por SKU, PDV e período |
| Demandas | Solicitadas, atendidas no dia e atrasadas |
| Tempo de execução | Check-in até envio, média por PDV e por promotor |
| Produção SKU/dia | SKUs trabalhados por promotor por dia |
| Impedimentos | Quantidade por PDV e por promotor |

Produção SKU/dia é base para a agência pagar freelancers; nenhum pagamento passa pelo sistema.
Galeria de fotos: antes e depois lado a lado por visita, filtro por PDV e data.

## Arquitetura

- Um app Laravel único no Coolify atende todos os clientes; cliente identificado pelo domínio
  (stancl/tenancy, multi-banco).
- Banco central (clientes, domínios, super admin) + um banco por cliente (`cli_<id>`).
- Pasta por cliente: `storage/clientes/<id>`. Fotos no disco hoje; qualquer S3 compatível depois
  (AWS, R2, Wasabi, B2, MinIO) trocando só o `.env`.
- Filas: Laravel Queue + Redis (notificações, fotos, alertas do fim do dia).
- App do promotor: Flutter, Android primeiro, iOS na fase 8. Um app só nas lojas; o promotor
  digita o código da empresa no primeiro acesso.
- Painel central no domínio principal: clientes, código da empresa → domínio, identidade única do
  promotor.

## Modelo de dados (banco do cliente)

| Tabela | Campos principais |
| --- | --- |
| `industrias` | nome, CNPJ, segmento |
| `agencias` | nome, CNPJ |
| `users` | nome, e-mail, perfil, industria_id, agencia_id, tipo_vinculo (CLT/freela) |
| `pdvs` | CNPJ, nome, rede, canal, endereço, lat, lng, raio_m, regiao_id |
| `skus` | industria_id, código, descrição, categoria, foto |
| `sku_embalagens` | sku_id, tipo (unidade, reembalagem, caixa master), quantidade, GTIN, código |
| `contratos_visita` | pdv_id, industria_id, agencia_id, frequencia_semana, dias |
| `representante_pdv` | usuario_id, pdv_id (carteira do representante) |
| `pdv_usuarios` | gerente do supermercado ↔ PDV |
| `roteiros` | promotor_id, data, supervisor_id |
| `visitas` | roteiro_id, pdv_id, origem (contrato/demanda), status, checkin_em, checkout_em, lat/lng do check-in, tempo_execucao |
| `visita_skus` | visita_id, sku_id, situacao_estoque, trabalhado |
| `visita_fotos` | visita_id, tipo (antes/depois/ruptura/material), caminho, lat, lng, tirada_em |
| `rupturas` | visita_id, sku_id, foto_id |
| `demandas` | pdv_id, solicitante_id, motivo, status, liberada_por, visita_id |
| `ocorrencias` | visita_id, tipo (impedimento/não executada), observação |
| `materiais` / `material_movimentos` | estoque, envio e instalação de materiais de PDV |
| `notificacoes` | usuario_id, tipo, referência, lida_em |
| `documentos_legais` | política de privacidade e termo de uso, texto padrão editável |

## Custos externos

GPS, câmera, cálculo de raio, push (Firebase Cloud Messaging) e e-mail (SMTP): sem custo.
Mapa no painel com Leaflet; geocoding do Google só no cadastro do PDV, com cache.
Pagos: Google Play (US$ 25 uma vez), OpenAI por uso no importador, Apple (US$ 99/ano, só na fase 8).

## Fases

| Fase | Entrega | Estimativa |
| --- | --- | --- |
| 1. Base e infraestrutura | Core Laravel, provisionamento, domínios, perfis, login, painel central | **Concluída** |
| 2. Cadastros e roteiros | PDVs, importador de catálogo (OpenAI), contratos de visita, roteiros | 4 semanas |
| 3. App do promotor (Android) | Flutter offline: login por código, roteiro do dia, check-in, fotos, SKUs, estoque, sync | 5 semanas |
| 4. Demandas e alertas | Painel do supermercado, ruptura, pedido de visita, atribuição pela agência, impedimento, não executadas, push e e-mail | 2 semanas |
| 5. Indicadores | Dashboards, produção SKU/dia, galeria antes/depois, Excel | 2 semanas |
| 6. Piloto | Instalação Gaboardi, carga do catálogo, testes em campo, ajustes | 3 semanas |
| 7. Materiais de PDV | Estoque, envio e comprovação de instalação | 2 semanas |
| 8. App iOS | Mesmo código Flutter para iPhone e testes | 2 semanas |

Publicação nas lojas: definir depois.

### Etapa futura (fora das fases): rede de freelancers

Demanda vai para todos os promotores disponíveis na região; o primeiro que aceita executa, por
valor fixo. O promotor vê só o relatório do total a receber; quem paga é a agência, fora do
sistema. Preparação: identidade única do promotor no painel central (`users.promotor_global_id`).
