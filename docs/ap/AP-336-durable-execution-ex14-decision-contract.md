---
title: "AP-336 Durable Execution EX14 Decision Contract"
status: implemented
owner: ai-kernel
ap: AP-336
line_limit: 120
depends_on:
  - AP-204
  - AP-335
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp336DecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp336DecisionContractTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-336 Durable Execution EX14 Decision Contract

## Purpose

AP-336 normaliza a decisao humana sobre o preflight AP-335.

Ele permite aceitar, pedir mudancas ou rejeitar a proxima etapa da cadeia
enterprise, mas continua sendo apenas contrato read-only: nao executa payload,
nao cria job, nao grava arquivo e nao escreve no Evidence Ledger.

## Position

AP-335 valida o handoff AP-334. AP-336 consome esse preflight e registra a
decisao humana normalizada para um AP futuro emitir receipt declarativo.

## Contract

Entrada: array produzido por `AtlasApAgentWorkflowAp335Preflight`.

Saida:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract.v1`

Ready input status:

`ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review`

## Allowed Decisions

- accept EX14 preflight
- request EX14 preflight changes
- reject EX14 path

Every accepted decision requires a non-empty human reason.

## Output Statuses

- accepted by human
- changes requested by human
- rejected by human
- blocked by invalid decision
- blocked by AP-335 preflight

## Data Carried Forward

The decision carries a read-only preflight summary:

- AP-335 schema and status
- future AP reference
- receipt reference and package summary
- real execution surface
- append-only write plan
- idempotency strategy
- operator confirmation surface
- rollback or replay plan
- budget and observability guards
- payload hash
- receipt destination
- owner and policy receipt source

## Guardrails

AP-336 always declares no file write, no command execution, no state
persistence, no release publishing, no Evidence Ledger write, no event emission,
no runtime job creation, no dry-run and no payload execution.

## Registry

AP-336 follows AP-335 in the AP-204 post-completion review chain.

It is a decision contract, not an executor.

## Tests

`AtlasApAgentWorkflowAp336DecisionContractTest` verifies:

- accepted decision is normalized
- change request returns to preflight repair
- rejection stops future path
- invalid decision or empty reason blocks
- unready preflight blocks human acceptance
