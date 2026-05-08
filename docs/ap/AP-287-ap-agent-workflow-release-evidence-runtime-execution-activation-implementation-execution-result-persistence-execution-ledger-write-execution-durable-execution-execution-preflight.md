---
title: AP-287 Durable Execution Execution Preflight
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionPreflightTest.php
depends_on:
  - AP-204
  - AP-286
---

# AP-287 - Durable Execution Execution Preflight
## Proposito
AP-287 valida a preflight do futuro executor real duravel que recebe o handoff AP-286.
Ele nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-286.
Ele impede que handoff aceito seja tratado como permissao direta para rodar o executor.

## Entrada
- handoff AP-286 com status `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_ready_for_future_execution_ap`
- evidencias booleanas de preflight do executor
- referencias de plano append-only, policy receipt, guards, payload hash e destino do receipt

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight_evidence`
- `real_durable_execution_target`
- `next_action`
- `guardrails`

## Status
- pronto: `ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_review`
- handoff bloqueado: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_packet`
- shape invalido: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight_shape`
- evidencia incompleta: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_preflight_incomplete`

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
- nao aceita sem handoff AP-286 pronto

## Beneficio
O Atlas ganha uma trava adicional antes de qualquer executor real duravel, preservando decisao humana e rastreabilidade.

## Criterios de Aceite
- handoff AP-286 aceito + evidencias completas gera ready
- handoff nao pronto bloqueia
- shape invalido bloqueia
- evidencia incompleta retorna attention
- AP-204, registry e static scanner reconhecem AP-287
