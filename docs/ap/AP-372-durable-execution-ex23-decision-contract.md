---
title: "AP-372 Durable Execution EX23 Decision Contract"
status: implemented
owner: ai-kernel
ap: AP-372
line_limit: 120
depends_on:
  - AP-204
  - AP-371
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp372DecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp372DecisionContractTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-372 Durable Execution EX23 Decision Contract

## Purpose

AP-372 normaliza a decisao humana sobre o preflight AP-371.

Ela aceita, pede mudanca ou rejeita o proximo passo da cadeia EX23 sem executar
payload, sem criar runtime job, sem persistir decisao e sem escrever no Evidence
Ledger.

## Position

AP-371 valida o handoff AP-370. AP-372 exige que esse preflight esteja ready
antes de qualquer aceite humano.

O contrato existe para separar revisao humana de execucao real. Mesmo quando o
humano aceita, a saida e apenas declarativa e deve ser consumida por um receipt
futuro.

## Contract

Entrada: array produzido por `AtlasApAgentWorkflowAp367Preflight`.

Saida:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract.v1`

## Allowed decisions

- `accept_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight`
- `request_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_changes`
- `reject_real_durable_execution_executor_payload_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight`

Every valid decision requires a non-empty human reason.

## Data carried forward

The decision copies a preflight summary:

- schema and status
- future AP reference
- receipt reference and package
- real execution surface
- append-only write plan reference
- idempotency strategy
- operator confirmation surface
- rollback/replay, budget and observability guards
- payload hash, destination, owner and policy receipt source

## Guardrails

AP-372 always declares:

- no command execution
- no file write
- no decision persistence
- no runtime job creation
- no dry-run
- no authorized work execution
- no payload execution
- no Evidence Ledger write
- no evidence event emission
- no bypass of ready preflight

## Registry

AP-372 follows AP-371 in the AP-204 post-completion review chain.

The registry declares `AtlasApAgentWorkflowAp372DecisionContract` as the human
decision component for this phase.

## Tests

`AtlasApAgentWorkflowAp372DecisionContractTest` verifies:

- acceptance is normalized
- change request returns to repair
- rejection stops the future path
- invalid decision or empty reason blocks
- unready preflight blocks acceptance

## Acceptance

- class exists and is read-only
- AP-204 registry reaches AP-372
- static scanner requires doc, class and test
- focused tests pass
- architecture-validate remains ok
