---
title: AP-208 AP Agent Workflow Execution Receipt
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowExecutionReceiptTest.php
depends_on:
  - AP-203
  - AP-207
---

# AP-208 - AP Agent Workflow Execution Receipt

## Proposito

AP-208 cria um recibo read-only para aceitar ou bloquear uma execucao de agente no workflow AP.

Ele junta trace audit e completion report sem substituir nenhum dos dois.

## Posicao

Este contrato fica depois do AP-203 e AP-207.

Ele deve ser usado quando um agente precisa resumir se uma execucao completa pode ser aceita para revisao humana.

## Fontes

- AP-207 valida se o rastro de passos seguiu a ordem oficial
- AP-203 valida gate, shape de evidencia e checklist final

## Saida

Schema: `atlas.ap_agent_workflow_execution_receipt.v1`  
Modo: `read_only_execution_receipt`  
Autoridade: `ap_agent_workflow_execution_receipt_only_no_execution`

Status:

- `accepted`
- `blocked_by_trace_audit`
- `blocked_by_completion_report`

## Regra

O recibo aceita somente quando:

- trace audit retorna `valid_trace`
- completion report retorna `complete`

Qualquer falha preserva o `next_action` da fonte bloqueadora.

## Guardrails

- nao escreve arquivos
- nao executa comandos
- nao persiste recibo
- nao aceita sem trace valido
- nao aceita sem completion completo
- nao substitui Evidence Ledger

## Beneficio

O Atlas passa a ter um fechamento compacto do trabalho de um agente.

Isso permite revisar rapidamente se houve ordem correta, evidencia valida e conclusao real.

## Criterios de Aceite

- trace valido + completion completo retorna `accepted`
- trace invalido retorna `blocked_by_trace_audit`
- completion incompleto retorna `blocked_by_completion_report`
- guardrails impedem persistencia ou execucao automatica
