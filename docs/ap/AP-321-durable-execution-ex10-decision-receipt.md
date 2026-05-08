---
title: AP-321 Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Decision Receipt
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceiptTest.php
depends_on:
  - AP-204
  - AP-315
  - AP-320
---

# AP-321 - Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Decision Receipt
## Proposito
AP-321 emite receipt read-only da decisao humana AP-320.
Ele nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-320.
Ele transforma a decisao humana em resultado consumivel por um futuro handoff sem conceder autoridade operacional.

## Entrada
- decisao AP-320 aceita, rejeitada, alterada ou bloqueada
- resumo do preflight AP-315
- motivo humano e referencias de plano append-only

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_summary`
- `next_action`
- `guardrails`

## Status
- aceite reportado: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported`
- ajustes reportados: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair`
- rejeicao reportada: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection`
- decisao bloqueada: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract`

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
- nao aceita sem decision contract AP-320 aceito

## Beneficio
O Atlas preserva uma trilha auditavel da decisao humana sem transformar o receipt em execucao.

## Criterios de Aceite
- accepted by human gera acceptance reported
- changes requested gera returned for repair
- rejected gera stopped by rejection
- decision contract bloqueado bloqueia receipt
- AP-204, registry e static scanner reconhecem AP-321
