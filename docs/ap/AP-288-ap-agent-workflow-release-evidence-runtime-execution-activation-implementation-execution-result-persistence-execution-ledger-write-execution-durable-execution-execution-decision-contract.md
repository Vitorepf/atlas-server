---
title: AP-288 Durable Execution Execution Decision Contract
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionDecisionContractTest.php
depends_on:
  - AP-204
  - AP-286
  - AP-287
---

# AP-288 - Durable Execution Execution Decision Contract
## Proposito
AP-288 normaliza a decisao humana sobre o preflight AP-287 do executor futuro da execucao real duravel.
Ele nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-287 e antes de qualquer receipt da decisao do executor.
Este contrato impede que preflight do executor seja confundido com permissao operacional imediata.

## Entrada
- payload AP-287 com status `ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_review`
- decisao humana
- motivo humano nao vazio

## Decisoes Permitidas
- `accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution`
- `request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_changes`
- `reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution`

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_decision_contract.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_decision`
- `next_action`
- `guardrails`

## Status
- aceite: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_accepted_by_human`
- ajustes: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_changes_requested_by_human`
- rejeicao: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_rejected_by_human`
- decisao invalida: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_decision`
- preflight nao pronto: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight`

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste decisao
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao faz ledger write
- nao aceita sem preflight AP-287 pronto

## Beneficio
O Atlas ganha checkpoint humano auditavel entre o preflight do executor e qualquer receipt futuro, mantendo silencio operacional.

## Criterios de Aceite
- accepted gera accepted by human sem ledger write
- changes requested volta para reparo do AP-287
- rejected encerra o caminho ate reabertura de escopo
- decisao invalida ou motivo vazio bloqueia
- AP-204, registry e static scanner reconhecem AP-288
