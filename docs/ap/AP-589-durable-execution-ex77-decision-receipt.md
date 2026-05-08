---
title: "AP-589 Durable Execution EX77 Decision Receipt"
status: implemented
owner: ai-kernel
ap: AP-589
line_limit: 120
depends_on:
  - AP-204
  - AP-588
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp589DecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp589DecisionReceiptTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-589 Durable Execution EX77 Decision Receipt

## Purpose

AP-589 emite receipt read-only da decisao humana AP-588.

Ele transforma aceite, pedido de mudanca ou rejeicao em estado auditavel para um
AP futuro consumir, sem executar payload, sem criar job, sem gravar arquivo e
sem escrever no Evidence Ledger.

## Position in the mother structure

AP-588 normaliza a decisao humana sobre o preflight AP-587.

AP-589 sela essa decisao como receipt declarativo. O receipt informa se o proximo
passo pode consumir o aceite, deve voltar para reparo ou deve parar ate o escopo
ser reaberto.

## Contract

Entrada: array produzido por `AtlasApAgentWorkflowAp588DecisionContract`.

Saida:

`atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1`

## Status

- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection`
- `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract`

## Data carried forward

The receipt copies a read-only decision summary:

- decision schema and status
- decision value and human reason
- future AP reference
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

AP-589 always declares:

- no command execution
- no file write
- no receipt persistence
- no runtime job creation
- no dry-run
- no authorized work execution
- no payload execution
- no Evidence Ledger write
- no evidence event emission
- no bypass of future AP

## Registry

AP-589 is part of the AP-204 post-completion review chain after AP-588.

The registry declares `AtlasApAgentWorkflowAp589DecisionReceipt` as the receipt
component for this phase.

## Tests

`AtlasApAgentWorkflowAp589DecisionReceiptTest` verifies:

- accepted decision is reported as a receipt
- change request returns to repair
- rejection stops the future path
- invalid or blocked decision contract blocks receipt

## Acceptance

- class exists and is read-only
- AP-204 registry reaches AP-589
- static scanner requires doc, class and test
- focused tests pass
- architecture-validate remains ok
