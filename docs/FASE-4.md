# Fase 4 — Demandas e alertas

Pedidos de visita e relatos de ruptura pelo supermercado ou indústria, sempre com contrato
vigente e agência responsável. Apenas supervisor/admin da agência aprova, atribui ou recusa.
Prazo no mesmo dia. Aprovação idempotente cria uma visita sob demanda; conclusão a atende.
Alertas internos persistentes por usuário para demanda, ruptura, impedimento, atraso e visita
não executada. Leitura individual, destinatários por indústria, carteira e agência.
Encerramento diário por instalação, com recuperação após indisponibilidade e deduplicação.
Sem envio de e-mail, SMS ou push (retirados do escopo pelo dono em 08/11/2026); a caixa de alertas fica na operação do cliente.

Aceite: pedido de gerente vinculado ao PDV, aprovação da agência, execução e atendimento;
tentativas fora do escopo recusadas; encerramento repetido não duplica alertas.
