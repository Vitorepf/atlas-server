---
title: AP-281 Durable Ledger Write Execution Decision Receipt
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionReceiptTest.php
depends_on:
  - AP-204
  - AP-279
  - AP-280
---

# AP-281 - Durable Ledger Write Execution Decision Receipt
## Proposito
AP-281 emite um receipt read-only da decisao humana duravel do AP-280.
Ele confirma o resultado da decisao sem executar payload, sem criar job e sem escrever no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-280 e antes de qualquer handoff que possa preparar uma execucao duravel futura.
Este AP existe para impedir que aceite humano seja interpretado como permissao operacional imediata.

## Entrada
- payload AP-280
- status accepted, changes requested ou rejected
- resumo duravel carregado do AP-279

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_receipt.v1`

Campos principais:
- `status`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_summary`
- `next_action`
- `guardrails`

## Status
- aceite reportado: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_acceptance_reported`
- retorno para reparo: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_returned_for_repair`
- parada por rejeicao: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_stopped_by_rejection`
- bloqueio por decisao invalida: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_contract`

## Resumo Duravel
O receipt preserva decisao, motivo, surface duravel, plano append-only, idempotencia, confirmacao humana, rollback/replay, payload hash, receipt futuro, owner e policy receipt source.

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
- nao bypassa futuro AP de execucao duravel

## Beneficio
O Atlas ganha uma fronteira auditavel entre a decisao humana duravel e a futura execucao operacional.

## Criterios de Aceite
- accepted vira acceptance reported
- changes requested vira returned for repair
- rejected vira stopped by rejection
- decisao bloqueada nao gera receipt aceito
- AP-204, registry e static scanner reconhecem AP-281
