---
title: "AP-343 Durable Execution EX16 Preflight"
status: implemented
owner: ai-kernel
ap: AP-343
line_limit: 120
depends_on:
  - AP-204
  - AP-342
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp343Preflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp343PreflightTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-343 Durable Execution EX16 Preflight

## Purpose

AP-343 valida o handoff AP-342 antes de qualquer decisao humana da proxima
camada da cadeia enterprise.

Ele mantem o Atlas em modo de governanca: revisa forma, evidencias e referencias
do handoff, mas nao executa payload, nao cria runtime job, nao persiste estado e
nao escreve no Evidence Ledger.

## Position

AP-342 entrega um handoff packet read-only. AP-343 consome esse pacote e produz
um preflight tambem read-only.

Se o handoff nao estiver ready, o preflight bloqueia. Se a evidencia estiver
incompleta ou mal formada, o preflight retorna attention ou blocked.

## Contract

Schema:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight.v1`

Accepted input status:

`runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap`

Ready output status:

`ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review`

## Required Evidence

AP-343 requires explicit confirmation that:

- AP-342 handoff was reviewed
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

AP-343 always declares no file write, no command execution, no state persistence,
no release publishing, no evidence event, no Evidence Ledger write, no runtime
job creation, no payload execution and no ledger write.

## Registry

AP-343 follows AP-342 in the AP-204 post-completion review chain.

It is a preflight contract, not an executor.
