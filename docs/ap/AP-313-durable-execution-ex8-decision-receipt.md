---
title: AP-313 Durable Execution Execution Execution Execution Execution Execution Execution Execution Decision Receipt
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionExecutionExecutionExecutionExecutionExecutionDecisionReceiptTest.php
depends_on:
  - AP-204
  - AP-312
---

# AP-313 - Durable Execution Execution Execution Execution Execution Execution Execution Execution Decision Receipt
## Proposito
AP-313 emite receipt read-only da decisao humana AP-312.
Ele nao executa payload, nao cria job, nao persiste estado e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-312.
Ele transforma a decisao humana em receipt governado para consumo de um futuro AP.

## Entrada
- payload AP-312 com status de aceite, ajustes, rejeicao ou bloqueio
- resumo do preflight AP-311
- referencias de destino, owner, policy receipt e guardrails

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_decision_summary`
- `next_action`
- `guardrails`

## Status
- aceite reportado: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported`
- ajustes reportados: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair`
- rejeicao reportada: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection`
- decisao bloqueada: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract`

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
- nao bypassa futuro AP operacional

## Beneficio
O Atlas mantem trilha de aceite humano sem confundir receipt de decisao com execucao real.

## Criterios de Aceite
- accept AP-312 vira acceptance reported
- change request volta para repair
- rejection para o caminho
- bloqueio do decision contract bloqueia receipt
- AP-204, registry e static scanner reconhecem AP-313
