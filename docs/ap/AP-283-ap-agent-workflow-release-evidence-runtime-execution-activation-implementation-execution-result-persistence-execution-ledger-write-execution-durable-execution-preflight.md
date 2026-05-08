---
title: AP-283 Durable Execution Preflight
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionPreflightTest.php
depends_on:
  - AP-204
  - AP-281
  - AP-282
---

# AP-283 - Durable Execution Preflight
## Proposito
AP-283 valida o preflight para uma futura execucao real duravel de ledger write.
Ele ainda nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-282, que entrega o handoff duravel aceito.
Vem antes de qualquer decisao humana sobre permitir ou rejeitar a execucao real duravel.

## Entrada
- handoff AP-282 com status `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_ready_for_future_execution_ap`
- evidencia de preflight com surface duravel, plano append-only, idempotencia, payload hash, policy receipt, confirmacao humana, replay/rollback, budget guard e observability guard

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_evidence`
- `durable_execution_target`
- `next_action`
- `guardrails`

## Status
- pronto: `ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_review`
- bloqueio por handoff: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_handoff_packet`
- bloqueio por shape: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_shape`
- incompleto: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_preflight_incomplete`

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste preflight
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao faz ledger write
- nao aceita sem handoff duravel

## Beneficio
O Atlas ganha uma ultima camada de inspeção antes da decisao humana sobre execucao real duravel.

## Criterios de Aceite
- handoff AP-282 pronto com evidencia completa gera ready
- handoff nao pronto bloqueia
- evidencia incompleta retorna attention
- shape invalido bloqueia
- AP-204, registry e static scanner reconhecem AP-283
