---
title: AP-293 Durable Execution Execution Execution Decision Receipt
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionDecisionReceiptTest.php
depends_on:
  - AP-204
  - AP-291
  - AP-292
---

# AP-293 - Durable Execution Execution Execution Decision Receipt
## Proposito
AP-293 emite um receipt read-only da decisao humana AP-292.
Ele registra o resultado declarativo do payload do executor sem executar payload e sem escrever no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-292.
Ele transforma aceite, pedido de ajuste ou rejeicao do payload do executor em estado consumivel por um futuro AP.

## Entrada
- payload AP-292
- status de accepted, changes requested, rejected ou blocked
- resumo do preflight AP-291

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_decision_receipt.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_decision_summary`
- `next_action`
- `guardrails`

## Status
- aceite reportado: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_acceptance_reported`
- ajustes: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_returned_for_repair`
- rejeicao: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_stopped_by_rejection`
- bloqueio: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_decision_contract`

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
- nao pula AP futuro do payload do executor real duravel

## Beneficio
O Atlas ganha uma prova declarativa de aceite humano do payload do executor sem confundir decisao com execucao.

## Criterios de Aceite
- accepted vira acceptance reported
- changes requested volta para reparo do AP-291
- rejected para o caminho ate reabertura de escopo
- payload bloqueado no AP-292 bloqueia receipt
- AP-204, registry e static scanner reconhecem AP-293
