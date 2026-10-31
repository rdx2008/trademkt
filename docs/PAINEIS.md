# Painéis por nível

## Acessos

- Super admin: `/visao-geral` no domínio central. Total de instalações, ativas/suspensas,
  indústrias, agências, domínios e clientes recentes. Atalhos para criação e gestão de clientes.
- Admin da instalação (indústria ou agência): `/painel` no domínio do cliente.
  Resumo completo da instalação, cadastros e consultas operacionais.
- Admin da agência: equipe, contratos e visitas da própria agência, com acesso ao planejamento.
- Gerente de trade: visitas e contratos da própria indústria, catálogo e agências prestadoras.
- Supervisor: visitas da própria agência e acesso ao roteiro para atribuição de responsáveis.
- Representante: resumo da sua indústria somente nos PDVs vinculados à sua carteira.
- Promotor: consulta web dos atendimentos atribuídos ao seu usuário. Sem acesso aos cadastros.
- Gerente do supermercado: visitas das lojas vinculadas, marcas e agências com contratos vigentes.

As permissões das demais telas de cadastro/consulta continuam definidas pelos seus controllers.
O recorte por carteira no resumo do representante não altera os acessos existentes à consulta
de PDVs da indústria. A indústria acompanha a operação; a agência atribui trabalho ao promotor.

## Dados e filtros

- Data obrigatória no formato `YYYY-MM-DD` quando informada; padrão: hoje no fuso da aplicação.
- Total do dia e contagem por cada status já registrado no banco. O percentual é realizadas
  dividido pelo total de visitas da data; não equivale ao indicador contratual de cobertura.
- O filtro de situação altera a lista paginada, preservando o resumo global do dia.
- Gráfico: quantidade de visitas agendadas em cada um dos sete dias encerrados na data selecionada.
- Pontos de atenção: visitas do dia sem promotor e visitas ainda previstas nos seis dias anteriores.
  A consulta não muda o status nem envia notificações.
- Base da operação: PDVs visíveis, contratos vigentes na data, catálogo ativo e equipe visível.
- Check-in exibido com a hora do GPS convertida para o fuso da aplicação.
- Listagens vazias explicam como os dados aparecem. Paginação preserva os filtros.

## Interface

Menu lateral em computador e menu recolhível em celular. Atalhos variam por perfil.
Componentes, formulários e tabelas existentes usam o mesmo layout. Sem bibliotecas externas
de gráficos ou etapa de build de JavaScript. Os gráficos são gerados no servidor com CSS.

## Limites desta entrega

O painel inclui demandas de visita/ruptura, aprovação pela agência, alertas internos,
detalhe da visita, galeria e indicadores com Excel (fases 4–5). O supermercado pode
consultar a última visita com evidências e solicitar atendimento por contrato vigente.
A execução usa a API da fase 3; o app Android ainda precisa ser construído. Alertas
são internos, sem envio por e-mail, SMS ou push. A cobertura considera as visitas
contratuais efetivamente geradas; consulte `FASE-5.md` para a definição dos indicadores.

## Validação

`tests/Feature/PainelTest.php` cobre os oito cenários de acesso, isolamento por indústria,
agência, usuário, carteira e loja, filtros, datas, paginação e proteção do painel central.
Para gerar prévias com dados de teste, definir `PAINEL_PREVIEW_DIR` para uma pasta local
antes de executar essa classe. Nunca são usados dados de produção nas prévias.
