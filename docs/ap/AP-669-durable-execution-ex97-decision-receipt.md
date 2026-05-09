---
title: "AP-669 Durable Execution EX97 Decision Receipt"
status: implemented
owner: ai-kernel
ap: AP-669
line_limit: 120
depends_on:
  - AP-204
  - AP-668
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp669DecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp669DecisionReceiptTest.php
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowRegistry.php
---

# AP-669 Durable Execution EX97 Decision Receipt

## Purpose

AP-669 emite receipt read-only da decisao humana AP-668.

Ele transforma aceite, pedido de mudanca ou rejeicao em estado auditavel para um
AP futuro consumir, sem executar payload, sem criar job, sem gravar arquivo e
sem escrever no Evidence Ledger.

## Position in the mother structure

AP-668 normaliza a decisao humana sobre o preflight AP-667.

AP-669 sela essa decisao como receipt declarativo. O receipt informa se o proximo
passo pode consumir o aceite, deve voltar para reparo ou deve parar ate o escopo
ser reaberto.

## Contract

Entrada: array produzido por `AtlasApAgentWorkflowAp668DecisionContract`.

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

AP-669 always declares:

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

AP-669 is part of the AP-204 post-completion review chain after AP-668.

The registry declares `AtlasApAgentWorkflowAp669DecisionReceipt` as the receipt
component for this phase.

## Tests

`AtlasApAgentWorkflowAp669DecisionReceiptTest` verifies:

- accepted decision is reported as a receipt
- change request returns to repair
- rejection stops the future path
- invalid or blocked decision contract blocks receipt

## Acceptance

- class exists and is read-only
- AP-204 registry reaches AP-669
- static scanner requires doc, class and test
- focused tests pass
- architecture-validate remains ok
