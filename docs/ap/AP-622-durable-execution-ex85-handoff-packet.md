---
title: "AP-622 Durable Execution EX85 Handoff Packet"
status: implemented
owner: ai-kernel
ap: AP-622
line_limit: 120
depends_on:
  - AP-204
  - AP-621
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp622HandoffPacket.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp622HandoffPacketTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-622 Durable Execution EX85 Handoff Packet

## Purpose

AP-622 entrega um handoff packet read-only a partir do receipt AP-621.

Ele fecha o ciclo decision -> receipt -> handoff para a etapa EX85 sem criar
runtime job, sem executar payload, sem publicar release e sem escrever no
Evidence Ledger.

## Position

AP-621 reporta a decisao AP-620. AP-622 consome apenas o receipt aceito e
prepara o pacote revisavel para um futuro AP.

Se o receipt nao estiver aceito, o handoff bloqueia. Se a evidencia do handoff
estiver incompleta ou mal formada, o pacote fica em attention ou blocked.

## Contract

Schema:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_packet.v1`

Accepted input status:

`runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported`

Ready output status:

`runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap`

## Required handoff evidence

The handoff requires explicit booleans confirming:

- AP-621 receipt reviewed
- future AP declared
- package declared
- acceptance is receipt-only
- append-only plan, policy receipt and idempotency are attached
- operator confirmation is required
- rollback/replay, budget and observability guards are attached
- payload hash and destination are attached
- no auto execution, command, runtime job, ledger write or event happened

## Carry-forward data

The packet carries:

- receipt schema, status and decision
- future AP
- real execution surface
- append-only write plan reference
- idempotency strategy
- operator confirmation surface
- rollback or replay plan
- budget and observability guards
- payload hash
- receipt destination
- owner and policy receipt source

## Guardrails

AP-622 always declares:

- no file writes
- no command execution
- no packet persistence
- no result-state persistence
- no release publishing
- no evidence event emission
- no Evidence Ledger write
- no runtime job creation
- no payload execution
- no ledger write

## Registry

AP-622 follows AP-621 in the AP-204 post-completion review chain.

It is a handoff contract, not an executor.
