---
title: AP-286 Durable Execution Handoff Packet
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionHandoffPacket.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableExecutionHandoffPacketTest.php
depends_on:
  - AP-204
  - AP-284
  - AP-285
---

# AP-286 - Durable Execution Handoff Packet
## Proposito
AP-286 entrega o receipt AP-285 aceito para um futuro AP de execucao real duravel.
Ele nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-285.
Ele separa aceite humano de transferencia governada para o proximo AP.

## Entrada
- receipt AP-285 com status `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_acceptance_reported`
- evidencias booleanas de handoff
- referencias de plano append-only, policy receipt, guardrails e owner

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_packet.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_receipt_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_evidence`
- `handoff_target`
- `next_action`
- `guardrails`

## Status
- pronto: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_ready_for_future_execution_ap`
- receipt bloqueado: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_decision_receipt`
- shape invalido: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_shape`
- evidencia incompleta: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_handoff_evidence_incomplete`

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste pacote
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao faz ledger write
- nao aceita sem receipt AP-285 aceito

## Beneficio
O Atlas passa a ter um limite claro entre aceitar a execucao real duravel e entregar o contexto para o futuro AP executor.

## Criterios de Aceite
- receipt aceito + evidencias completas gera handoff ready
- receipt nao aceito bloqueia
- shape invalido bloqueia
- evidencia incompleta retorna attention
- AP-204, registry e static scanner reconhecem AP-286
