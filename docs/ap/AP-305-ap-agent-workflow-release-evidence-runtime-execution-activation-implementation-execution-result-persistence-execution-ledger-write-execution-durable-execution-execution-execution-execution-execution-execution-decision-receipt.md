---
title: AP-305 Durable Execution Execution Execution Execution Execution Execution Decision Receipt
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceiptTest.php
depends_on:
  - AP-204
  - AP-303
  - AP-304
---

# AP-305 - Durable Execution Execution Execution Execution Execution Execution Decision Receipt
## Proposito
AP-305 emite um receipt read-only da decisao humana AP-304.
Ele registra o resultado declarativo da execucao do payload sem executar payload e sem escrever no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-304.
Ele transforma aceite, pedido de ajuste ou rejeicao em estado consumivel por um futuro AP.

## Entrada
- payload AP-304
- status de accepted, changes requested, rejected ou blocked
- resumo do preflight AP-303

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_decision_receipt.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_decision_summary`
- `next_action`
- `guardrails`

## Status
- aceite reportado: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_acceptance_reported`
- ajustes: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_returned_for_repair`
- rejeicao: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_stopped_by_rejection`
- bloqueio: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_decision_contract`

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste receipt
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao faz ledger write
- nao pula AP futuro da execucao do payload do executor real duravel

## Beneficio
O Atlas ganha uma prova declarativa de aceite humano da execucao do payload sem confundir decisao com execucao.

## Criterios de Aceite
- accepted vira acceptance reported
- changes requested volta para reparo do AP-303
- rejected para o caminho ate reabertura de escopo
- payload bloqueado no AP-304 bloqueia receipt
- AP-204, registry e static scanner reconhecem AP-305
