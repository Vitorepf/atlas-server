---
title: AP-291 Durable Execution Execution Execution Preflight
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionPreflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionExecutionExecutionPreflightTest.php
depends_on:
  - AP-290
  - AP-290
---

# AP-291 - Durable Execution Execution Execution Preflight
## Proposito
AP-291 valida o preflight do proximo estagio que recebe o handoff AP-290.
Ele nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-290.
Ele impede que handoff do executor seja tratado como permissao direta para rodar payload.

## Entrada
- handoff AP-290 com status `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_ready_for_future_execution_ap`
- evidencias booleanas de preflight
- referencias de plano append-only, policy receipt, guards, payload hash e destino do receipt

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight_evidence`
- `real_durable_execution_executor_target`
- `next_action`
- `guardrails`

## Status
- pronto: `ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_review`
- handoff bloqueado: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_handoff_packet`
- shape invalido: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight_shape`
- evidencia incompleta: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_preflight_incomplete`

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
- nao aceita sem handoff AP-290 pronto

## Beneficio
O Atlas ganha uma trava adicional antes de qualquer payload real do executor, preservando rastreabilidade.

## Criterios de Aceite
- handoff AP-290 aceito + evidencias completas gera ready
- handoff nao pronto bloqueia
- shape invalido bloqueia
- evidencia incompleta retorna attention
- AP-204, registry e static scanner reconhecem AP-291
