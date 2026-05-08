---
title: AP-325 Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Decision Receipt
status: implemented
ap: AP-325
line_limit: 120
depends_on:
  - AP-204
  - AP-324
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp325DecisionReceipt.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp325DecisionReceiptTest.php
---

# AP-325 - Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Decision Receipt

## Naming

A partir do AP-324 os componentes PHP usam nome curto por AP para evitar limite de filename do macOS. O schema e os status continuam completos e canonicos.

## Purpose

AP-325 emite receipt read-only da decisao humana AP-324.
Ele traduz aceite, pedido de mudanca ou rejeicao em estado auditavel para o proximo AP futuro, sem executar payload e sem gravar Evidence Ledger.

## Scope

- consumir payload do `AtlasApAgentWorkflowAp324DecisionContract`
- reportar aceite como `acceptance_reported`
- reportar mudanca como `returned_for_repair`
- reportar rejeicao como `stopped_by_rejection`
- bloquear quando o decision contract nao aceitou a decisao
- copiar resumo de preflight, decisao, refs, surface, guards e owner

## Non Scope

- nao escreve arquivos em runtime
- nao executa comandos
- nao cria job
- nao dispara dry-run
- nao executa payload autorizado
- nao grava ledger
- nao emite evento de evidencia
- nao substitui revisao humana

## Contract

Entrada: array do AP-324.
Saida: envelope `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt.v1`.

## Status

- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_returned_for_repair`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_stopped_by_rejection`
- `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_contract`

## Guardrails

Todos os flags operacionais permanecem falsos:

- `writes_files`
- `executes_commands`
- `persists_receipt`
- `persists_result_state`
- `publishes_release`
- `emits_evidence_event`
- `writes_evidence_ledger`
- `creates_runtime_job`
- `runs_dry_run`
- `executes_authorized_work`
- `performs_activation`
- `executes_runtime_payload`
- `performs_ledger_write`

## Registry

AP-325 fica na cadeia pos-completion declarada pelo AP-204.
O registry declara o componente `AtlasApAgentWorkflowAp325DecisionReceipt`.

## Acceptance

- classe existe e e read-only
- teste cobre aceite, mudanca, rejeicao e bloqueio
- AP-204, registry e static scanner reconhecem AP-325
- docs-health permanece ok
- architecture-validate permanece ok
- architecture-readiness permanece ready
