# Fase 5 — Indicadores e evidências

Painel com período e filtros por indústria, agência, região, rede, PDV, supervisor e promotor.
Cobertura das visitas previstas em contrato efetivamente geradas (coorte por data_prevista),
produção de SKUs trabalhados por dia/promotor, ruptura por SKU/PDV, visitas não executadas,
impedimentos, demandas atendidas no prazo/atrasadas e tempo de execução.
Galeria antes/depois, detalhe da visita, fotos privadas e exportação XLSX com os mesmos filtros.
Ruptura conta como visita realizada, mas SKU em ruptura não conta como SKU trabalhado.
Recebimento tardio offline conserva a hora de execução informada e a de recebimento.

Aceite: indicadores conferidos com dados conhecidos, Excel correspondente ao filtro,
evidências não acessíveis por outra agência/marca/loja/cliente.

## BI da indústria e da agência (02/11/2026)

Tela `/bi`, em **Resultados → BI da indústria / BI da agência**. Usa as consultas e o escopo de
permissões de `Indicadores`, então cada perfil só vê o que já podia ver. Período padrão: últimos
30 dias; os mesmos filtros dos indicadores (indústria, agência, PDV, região, rede, promotor,
supervisor). Períodos acima de 62 dias agrupam a tendência por semana.

- **Indicadores com variação** em relação ao período imediatamente anterior, de mesma duração:
  cobertura dos contratos, visitas realizadas, tempo médio, SKUs trabalhados, rupturas,
  impedimentos, visitas não executadas e visitas na agenda. A cor da variação considera se subir
  é bom ou ruim (mais rupturas é pior).
- **Comuns às duas visões:** execução da agenda por dia (realizadas, em atenção, não executadas,
  pendentes), situação do estoque, SKUs mais trabalhados, SKUs e PDVs com mais rupturas,
  desempenho por rede e por região, PDVs que pedem atenção e atendimento de demandas.
- **Indústria:** desempenho por agência prestadora (cobertura, não executadas, impedimentos,
  tempo médio).
- **Agência:** produtividade por promotor (visitas, cobertura, SKUs, impedimentos, tempo),
  visitas realizadas por dia da semana e atendimento por indústria cliente.
- O botão **Exportar Excel** reaproveita a exportação dos indicadores com os mesmos filtros.
- Testes: `tests/Feature/TemaEBiTest.php` (acesso por perfil, números, comparação com o período
  anterior, isolamento entre marcas, filtros e períodos longos).

