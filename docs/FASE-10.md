# Fase 10 — Coletas configuráveis e visão nacional

Escopo definido em 15/11/2026, a partir das telas de sistemas concorrentes que o dono recebeu dos clientes
(ShelfSpy, TradePro Promoter e outro de roteiro de lojas) e da decisão de **vender para agências e indústrias do
país todo**, não só para o primeiro cliente. **Nada desta fase está implementado.**

## 1. Premissa

O produto será vendido a agências e indústrias de segmentos diferentes (alimentos, bebidas, limpeza, higiene,
cosméticos, bazar). Cada uma coleta coisas diferentes na loja: validade é crítica em alimento, share em
categoria disputada, preço em quem negocia com a rede. Por isso as coletas **não podem ser telas fixas**: o
sistema precisa de um **motor de modelos de coleta** configurável, com módulos prontos construídos em cima dele.

Os recursos abaixo apareceram nas telas dos concorrentes e o dono os considera todos importantes. A ordem de
entrega está na seção 6.

## 2. O que o mercado tem e nós ainda não

| Recurso | Visto em | Situação hoje |
| --- | --- | --- |
| Pesquisa de preço | ShelfSpy, TradePro, sistema de roteiro | não existe |
| Produtos a vencer e alerta de validade | ShelfSpy, TradePro | não existe |
| Levantamento de estoque (contagem por SKU) e BI de estoque | TradePro, sistema de roteiro | só a situação de estoque por SKU |
| Pesquisa de share de gôndola e BI de share | sistema de roteiro | não existe |
| Perguntas e checklists configuráveis ("Pergunta", "Modelo") | TradePro | não existe |
| Avaliação (estrelas) e tag de foto | TradePro | não existe |
| Fila de downloads para exportações grandes | TradePro | Excel direto, sem fila |
| Relatórios com filtros ricos (regional, supervisor, rede, cidade, categoria, última foto…) | TradePro | filtros menores |
| Book de fotos, meus documentos, central de atendimento | sistema de roteiro | não existe |
| Menu de cartões que o usuário reordena | ShelfSpy | menu fixo |
| "Ordem de serviço" (Relatórios O.S.) e "Sistemática" | sistema de roteiro | **a confirmar com os clientes** o que significam |

O que já temos e eles também têm: rota, fotos antes e depois com marca d'água (data, hora, loja), ruptura, BI,
galeria, Excel, alertas, GPS.

## 3. Motor de modelos de coleta

Um **modelo de coleta** é um formulário que roda dentro da visita, no app, offline.

- **Campos:** sim/não, número, texto curto e longo, escolha (uma ou várias), data, foto, preço (moeda),
  quantidade, e "para cada SKU" (lista de SKUs da indústria, com os campos acima por SKU).
- **Regras:** obrigatório ou não, condicional simples ("se não, justifique"), limites de valor com aviso.
- **Escopo:** por indústria; opcionalmente por rede, categoria ou loja. Uma visita recebe os modelos que
  valem para a indústria, a rede e a loja dela.
- **Versão:** alterar um modelo cria nova versão; respostas antigas guardam a versão em que foram dadas.
- **Quem cria:** o admin da indústria (o conteúdo é dela). Em indústria gerida por agência (fase 9), a agência.
  A agência vinculada a uma indústria independente executa, não cria, salvo permissão no vínculo.
- **Onde entra no fluxo:** um passo configurável entre os SKUs trabalhados e a foto depois; não muda a regra da
  foto antes como abertura da visita.
- **Dados:** `modelos_coleta`, `modelo_campos`, `coleta_respostas` (visita, modelo, versão, campo, SKU opcional,
  valor), no banco da indústria. Sem IA.

## 4. Módulos prontos (modelos de partida sobre o motor)

1. **Pesquisa de preço:** preço do produto próprio e de concorrentes (preço normal e promocional, foto da
   etiqueta opcional); comparativo por rede, cidade e período; histórico por loja.
2. **Validade (produtos a vencer):** SKU, lote, validade, quantidade e ação (retirar, trocar, promover). A
   indústria define a janela de alerta em dias (por exemplo 30, 60, 90); lote dentro da janela gera alerta
   para representante, gerente e agência, e entra em "Produtos a vencer" (relatório e painel).
3. **Levantamento de estoque:** contagem por SKU (gôndola, estoque da loja, depósito), com **BI de controle de
   estoque** (cobertura, rupturas por loja e rede). Complementa a situação de estoque que já existe.
4. **Pesquisa de share:** frentes ou espaço por marca (a própria e as concorrentes) em cada categoria; share
   calculado; **BI de share** por rede e região.
5. **Perguntas livres (checklist):** execução de promoção, display, planograma e outros, com sim/não e foto.

Cada módulo é um conjunto de modelos de partida que a indústria pode copiar e ajustar.

## 5. Outros itens

- **Avaliação e tag de foto:** supervisor, agência e indústria dão de 1 a 5 estrelas e tags à foto (por
  exemplo "gôndola ok", "refazer"); filtro por avaliação e tag; registro de quem avaliou. Sem reconhecimento
  de imagem por IA (continua fora do escopo).
- **Relatórios com filtros ricos:** indústria, agência, regional, rede, UF, cidade, loja, supervisor,
  promotor, representante, categoria, tipo de foto, tag, avaliação e "última foto da loja".
- **Fila de downloads:** exportações grandes (Excel, fotos em ZIP) rodam em fila, com aviso no painel quando
  ficam prontas e link privado por 7 dias. Hoje o Excel é direto e trava com volume.
- **Book de fotos:** álbum de fotos por loja, rede ou campanha, exportável em PDF ou ZIP.
- **Meus documentos:** arquivos da empresa (contratos, planogramas, comunicados) com permissão por perfil.
- **Central de atendimento:** chamados do cliente ao fornecedor e base de ajuda dentro do painel.
- **Menu de cartões personalizável:** o usuário ordena e oculta os atalhos do painel inicial.

## 6. Visão nacional (itens transversais)

Coisas que a primeira instalação escondia e que viram obrigação ao vender em todo o país:

- **Fuso horário por empresa.** Hoje o sistema usa um fuso único (`America/Sao_Paulo`). O Brasil tem quatro
  (Noronha, Brasília, Amazonas e Acre). "Dia da visita", geração às 04:30, encerramento do dia e a hora da marca
  d'água dependem disso. Cada empresa passa a ter o seu fuso.
- **Planos, limites e cobrança.** A fase 9 libera a criação de empresas sem limite; falta definir a cobrança
  (por empresa, usuário ou promotor) e o gancho de limite por plano.
- **Distribuição do app (decisão de 15/11/2026).** Sem loja de aplicativos: o APK Android fica num link na
  página do sistema e o promotor baixa por lá. O app iOS será feito por fora do projeto; aqui só se mantém o app
  Android alinhado com o sistema.
- **LGPD em escala.** Termo de operador e controlador entre as empresas, política de retenção de fotos,
  exclusão sob pedido, consentimento do promotor para foto e CPF (o convite já registra o aceite).
- **Escala de infraestrutura.** Um banco por empresa, backup por empresa, monitoramento, filas e armazenamento
  de fotos em disco compatível com S3 (já previsto) com retenção por plano.
- **Onboarding rápido.** Importadores de PDVs, catálogo e promotores já existem; falta uma **lista nacional de
  redes** pré-cadastrada para a empresa novas não digitar tudo.
- **Segmentos.** Pacotes de modelos de coleta prontos por segmento (alimentos, bebidas, limpeza, higiene).
- **Fora do escopo por ora:** integração com ERP e sell-out, API pública, outros idiomas, reconhecimento de
  imagem por IA.

## 7. Itens (um commit por item, sempre com testes)

| Item | Entrega | Porte |
| --- | --- | --- |
| 10.1 | Motor de modelos de coleta: cadastro dos modelos, execução no app offline, respostas versionadas, consulta no painel. | G |
| 10.2 | Pesquisa de preço (modelos de partida, comparativo por rede/região, histórico). | M |
| 10.3 | Validade: coleta, janela de alerta, alertas, relatório "Produtos a vencer". | M |
| 10.4 | Levantamento de estoque e BI de controle de estoque. | M |
| 10.5 | Pesquisa de share e BI de share. | M |
| 10.6 | Perguntas livres (checklist) com foto. | P |
| 10.7 | Avaliação e tag de foto. | P |
| 10.8 | Relatórios com filtros ricos e fila de downloads. | M |
| 10.9 | Book de fotos, meus documentos, central de atendimento, menu personalizável. | G |
| N.1 | Fuso horário por empresa. **Feito em 15/11/2026.** | M |
| N.2 | Planos, limites e cobrança. | G |
| N.3 | Link de download do APK Android na landing page (sem loja; iOS feito por fora). **Feito em 15/11/2026.** | P |
| N.4 | LGPD em escala (termos, retenção, exclusão). | M |
| N.5 | Escala (backup por empresa, monitoramento, retenção de fotos) e lista nacional de redes. | M |

**Ordem sugerida:** N.1 primeiro (barato e evita erro de data fora do Sudeste), depois 10.1, 10.3, 10.2, 10.4,
10.5, 10.6, 10.7, 10.8, 10.9; N.2 a N.5 antes da primeira venda fora do piloto. A fase 9 (rede de empresas) vem
antes ou em paralelo com 10.1, porque define quem cria os modelos.

## 8. Critérios de aceite

- Indústria cria um modelo de validade em minutos, o promotor responde offline no app e o lote dentro da
  janela gera alerta e aparece em "Produtos a vencer".
- Pesquisa de preço mostra o preço próprio e o do concorrente por rede e cidade, com histórico.
- Share calculado bate com as frentes informadas (teste com números conhecidos).
- Alterar um modelo não muda respostas antigas.
- Exportação grande roda em fila, avisa quando pronta e o link expira.
- Empresa em Manaus e empresa em São Paulo veem o "dia de hoje" correto cada uma, e a marca d'água usa o
  fuso da empresa.
- Nenhuma resposta de coleta é visível a quem não tem vínculo com a indústria.

## 9. Pontos em aberto

- O que significam "Sistemática", "Book" e "O.S." para os clientes que enviaram as telas (confirmar antes de
  desenhar 10.9).
- A pesquisa de preço de concorrentes usa uma lista de SKUs concorrentes cadastrada pela indústria ou texto
  livre? Sugestão: lista simples por categoria, sem catálogo completo.
- Validade exige lote? Sugestão: lote opcional, validade obrigatória.
- Quanto tempo guardar as respostas e as fotos por plano (retenção).
- Valores e forma de cobrança (N.2).
