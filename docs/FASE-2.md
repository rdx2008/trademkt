# Fase 2 — Cadastros e roteiros

Referência: `docs/ESCOPO.md`. Tudo no banco do cliente (`database/migrations/tenant`), com testes
em `tests/Feature`. Entregar em PRs/commits pequenos, na ordem abaixo.

## 2.1 Regiões, redes e PDVs

- Tabelas: `regioes`, `redes`, `pdvs` (CNPJ, nome, rede_id, canal, endereço completo, lat, lng,
  `raio_m` padrão 200 e limite 150–300, regiao_id, ativo).
- Cadastro web (admin da instalação e admin da agência), com busca e filtros.
- Geocoding do endereço → lat/lng só no cadastro, com cache; permitir ajuste manual das coordenadas
  num mapa Leaflet (arrastar o pino).
- Importação de PDVs por planilha (CSV/XLSX) com tela de revisão.
- `pdv_usuarios`: vincular o gerente do supermercado ao(s) PDV(s).
- `representante_pdv`: carteira de PDVs do representante comercial.

**Aceite:** raio fora de 150–300 é recusado; CNPJ validado e único; usuário da indústria na
instalação de agência só vê PDVs com contrato da sua indústria.

## 2.2 Catálogo de SKUs

- Tabelas: `skus` (industria_id, código, descrição, categoria, foto, ativo) e `sku_embalagens`
  (sku_id, tipo unidade/reembalagem/caixa_master, quantidade, gtin, código).
- Cadastro manual web, com foto.
- Validação de GTIN (8, 12, 13 ou 14 dígitos + dígito verificador) como regra reutilizável.

**Aceite:** código único por indústria; GTIN com dígito errado é marcado, não bloqueado.

## 2.3 Importador de catálogo (OpenAI)

- Upload de PDF, XML ou planilha (até 60 MB) → job na fila.
- PDF: converter cada página em imagem + texto e enviar à API da OpenAI (modelo com visão), pedindo
  saída em JSON estrito com schema fixo (produtos → embalagens). Chave em `OPENAI_API_KEY`.
- XML e planilha: tentar mapeamento direto primeiro; usar IA só se o layout não for reconhecido.
- Tabela `importacoes_catalogo` (status, arquivo, página atual, erros, custo estimado) e
  `importacao_itens` (dados extraídos, alertas, decisão).
- Tela de revisão: tabela editável, itens com alerta em amarelo (GTIN inválido, duplicado, campo
  faltando, produto já existente com dados diferentes). Botão "gravar selecionados".
- Reimportação atualiza pelo código do produto, sem duplicar.
- Testes com a API mockada (sem chamar a OpenAI nos testes).

**Aceite:** importar o catálogo da Gaboardi e revisar sem editar o banco na mão; nada é gravado em
`skus` antes da confirmação.

### 2.3a Importação sem IA (implementada)

Decisão do dono em 01/11/2026: enquanto não há créditos da OpenAI, o catálogo entra por planilha
(CSV/XLSX) ou por PDF com texto selecionável, lido por regras. A importação com IA continua
prevista acima para layouts que as regras não entendem. Telas em **Catálogo → Importar produtos**
(admin da instalação escolhe a indústria; gerente de trade importa só na própria marca).

- Fluxo igual ao de PDVs: envio → revisão → «Gravar selecionados». A análise fica em arquivo
  JSON na pasta do cliente (`importacoes/skus`); nada vai para `skus` antes da confirmação.
- Código: `app/Services/Catalogo/` (`LeitorPlanilhaSkus`, `LeitorPdfCatalogo`, `ImportacaoSkus`) e
  `ImportacaoSkuController`. O PDF é lido com `smalot/pdfparser` (PHP puro, sem binários extras).
  Limite de 60 MB (`trade.importacao_skus.max_kb`); a leitura roda na própria requisição,
  sem fila (um catálogo de 12 páginas leva cerca de 2 s).
- **Planilha:** modelo para baixar. Aceita uma linha por produto (GTIN unidade, reembalagem e
  caixa master com suas quantidades) ou uma linha por embalagem (Tipo, Quantidade, GTIN) com o
  código repetido; as linhas do mesmo código são unidas. GTIN numérico do Excel é tratado.
- **PDF:** cada linha do texto é classificada (GTIN de 12 a 14 dígitos com ou sem marcador,
  código de produto, título em maiúsculas, descrição de embalagem). Um produto é o bloco de GTINs
  e códigos sob um título; GTIN depois de um código inicia outro produto. O primeiro GTIN de 13
  dígitos é a unidade; GTIN-14 sozinho é a caixa master; com vários, os de menor indicador são
  reembalagem. Variações marcadas por símbolos viram produtos separados. Calibrado com o
  catálogo da Gaboardi (69 produtos reconhecidos nas 12 páginas; teste com o texto extraído em
  `tests/Fixtures`). Catálogo com outro desenho pode exigir novas regras ou a IA.
- **Erros** (bloqueiam o item): código ou descrição vazios, tipo de embalagem desconhecido,
  GTIN fora de 8/12/13/14 dígitos, quantidade inválida. **Avisos** (não bloqueiam): dígito
  verificador errado (fica marcado no catálogo), GTIN repetido no arquivo ou em outro SKU,
  descrição diferente da cadastrada, quantidade suposta, mais de um código no bloco,
  produtos diferentes sob o mesmo título.
- Reimportação atualiza pelo código na indústria. Não apaga embalagens ausentes; campos vazios
  mantêm o valor atual; quantidade que o PDF só supôs não sobrescreve a cadastrada.
- Limitações conhecidas: a tela de revisão não edita campos (ajustes são feitos na edição do SKU
  depois de gravar); PDF escaneado não é lido (sem OCR); quando o PDF inverte a ordem entre
  títulos e dados (Embalaprático, página 8) o produto sai com aviso para conferir.

## 2.4 Contratos de visita

- Tabela `contratos_visita`: pdv_id, industria_id, agencia_id, frequencia_semana (1, 2 ou 3),
  dias da semana, vigência (início/fim), ativo.
- Cadastro pelo admin da agência (e admin da instalação).

**Aceite:** dias informados batem com a frequência; um PDV pode ter contratos de várias indústrias.

## 2.5 Roteiros

- Tabelas: `roteiros` (promotor_id, data, supervisor_id) e `visitas` (roteiro_id, pdv_id,
  industria_id, origem contrato/demanda, status previsto/em_andamento/realizada/em_atencao/
  nao_executada, ordem).
- Geração semanal: comando agendado cria as visitas previstas a partir dos contratos; o supervisor
  atribui ao promotor e ajusta a ordem.
- Tela do supervisor: semana por promotor, arrastar visitas entre dias/promotores, mapa do dia.
- Endpoint da API para o app baixar o roteiro do dia (`GET /api/v1/roteiro?data=`), com PDVs,
  coordenadas, raio e SKUs da indústria — base para a fase 3.

**Aceite:** só o supervisor/admin da agência atribui visitas (regra trabalhista); a indústria vê
os roteiros, mas não edita.

## Fora da fase 2

Execução da visita no app, fotos, ruptura, demandas e notificações (fases 3 e 4).
