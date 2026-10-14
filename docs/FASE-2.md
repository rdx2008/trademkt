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
