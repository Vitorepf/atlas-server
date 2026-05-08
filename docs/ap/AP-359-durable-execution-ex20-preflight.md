---
title: "AP-359 Durable Execution EX20 Preflight"
status: implemented
owner: ai-kernel
ap: AP-359
line_limit: 120
depends_on:
  - AP-358
  - AP-358
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp359Preflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp359PreflightTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-359 Durable Execution EX20 Preflight

## Purpose

AP-358 valida o handoff AP-358 antes de qualquer decisao humana da proxima
camada da cadeia enterprise.

Ele mantem o Atlas em modo de governanca: revisa forma, evidencias e referencias
do handoff, mas nao executa payload, nao cria runtime job, nao persiste estado e
nao escreve no Evidence Ledger.

## Position

AP-358 entrega um handoff packet read-only. AP-358 consome esse pacote e produz
um preflight tambem read-only.

Se o handoff nao estiver ready, o preflight bloqueia. Se a evidencia estiver
incompleta ou mal formada, o preflight retorna attention ou blocked.

## Contract

Schema:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight.v1`

Accepted input status:

`runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap`

Ready output status:

`ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review`

## Required Evidence

AP-359 requires explicit confirmation that:

- AP-358 handoff was reviewed
- handoff is ready for future AP
- real execution surface is declared
- append-only plan and idempotency strategy are declared
- payload hash, policy receipt and destination are declared
- operator confirmation is declared
- rollback/replay, budget and observability guards are declared
- no auto execution, command, runtime job, ledger write, event or payload happened

## Carry Forward

The preflight carries:

- handoff schema and status
- future AP reference
- receipt reference and package summary
- owner, operator confirmation surface and receipt destination
- real execution surface
- append-only write plan
- idempotency strategy
- rollback/replay, budget and observability guards
- payload hash and policy receipt source

## Guardrails

AP-359 always declares no file write, no command execution, no state persistence,
no release publishing, no evidence event, no Evidence Ledger write, no runtime
job creation, no payload execution and no ledger write.

## Registry

AP-358 follows AP-358 in the AP-358 post-completion review chain.

It is a preflight contract, not an executor.
