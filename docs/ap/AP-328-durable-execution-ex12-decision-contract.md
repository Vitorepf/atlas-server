---
title: "AP-328 Durable Execution EX12 Decision Contract"
status: implemented
owner: ai-kernel
ap: AP-328
depends_on:
  - AP-204
  - AP-327
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp328DecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp328DecisionContractTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-328 Durable Execution EX12 Decision Contract

## Purpose

AP-328 normaliza a decisao humana sobre o preflight AP-327 antes de qualquer
futuro AP que possa reportar aceite da proxima etapa de execucao real duravel.

Ele existe para manter a estrutura mae enterprise em modo revisavel: o humano
pode aceitar, pedir mudancas ou rejeitar o caminho, mas este contrato nao cria
job, nao executa payload, nao grava arquivo e nao escreve no Evidence Ledger.

## Position in the mother structure

AP-327 produz o preflight read-only do handoff da decisao da execucao da
execucao da execucao da execucao da execucao da execucao da execucao da
execucao da execucao da execucao da execucao do payload do executor da execucao
real duravel de ledger write.

AP-328 consome esse preflight e gera apenas a decisao normalizada. A decisao
aceita libera um AP futuro de receipt/handoff, ainda sem ledger write real.

## Contract

Schema:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract.v1`

Ready input status:

`ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review`

Allowed decisions:

- `accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution`
- `request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_changes`
- `reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution`

Every allowed decision requires a non-empty human reason.

## Output statuses

- accepted by human
- changes requested by human
- rejected by human
- blocked by invalid decision
- blocked by AP-327 preflight

## Data carried forward

The decision includes a read-only preflight summary with:

- AP-327 schema and status
- future AP reference
- receipt reference and package summary
- real execution surface
- append-only write plan reference
- idempotency strategy
- operator confirmation surface
- rollback or replay plan reference
- budget and observability guards
- payload hash
- receipt destination
- owner and policy receipt source

## Guardrails

AP-328 always declares:

- no command execution
- no file write
- no runtime job creation
- no payload execution
- no Evidence Ledger write
- no evidence event emission
- no decision persistence
- no acceptance without ready AP-327 preflight

## Implementation

Runtime contract:

`AtlasApAgentWorkflowAp328DecisionContract::decide(array $preflight, string $decision, ?string $reason = null): array`

Tests verify:

- accepted decision over ready AP-327 preflight
- change request path
- rejection path
- invalid decision and empty reason block
- unready preflight blocks

## Registry

AP-328 is part of the AP-204 post-completion review chain after AP-327.

It should be treated as a governance contract, not an executor.
