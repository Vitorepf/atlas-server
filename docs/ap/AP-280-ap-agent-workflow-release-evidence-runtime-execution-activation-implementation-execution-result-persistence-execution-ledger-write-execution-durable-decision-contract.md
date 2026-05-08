---
title: AP-280 Durable Ledger Write Execution Decision Contract
status: implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowReleaseEvidenceRuntimeExecutionActivationImplementationExecutionResultPersistenceExecutionLedgerWriteExecutionDurableDecisionContractTest.php
depends_on:
  - AP-204
  - AP-278
  - AP-279
---

# AP-280 - Durable Ledger Write Execution Decision Contract
## Proposito
AP-280 normaliza a decisao humana sobre o preflight duravel de execucao de ledger write.
Ele nao escreve no Evidence Ledger; apenas registra, em memoria de retorno, se o humano aceitou, pediu ajustes ou rejeitou o caminho duravel.

## Posicao no Fluxo
Vem depois do AP-279, que prova que a execucao duravel futura tem surface, plano append-only, idempotencia, confirmacao humana, replay/rollback, payload hash e ausencia de execucao automatica.
Vem antes de qualquer receipt ou handoff que possa preparar uma futura execucao real.

## Entrada
- payload AP-279 com status `ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_review`
- decisao humana
- motivo humano nao vazio

## Decisoes Permitidas
- `accept_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable`
- `request_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_changes`
- `reject_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable`

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision_contract.v1`

Campos principais:
- `status`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision`
- `next_action`
- `guardrails`

## Status
- aceite: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_accepted_by_human`
- ajustes: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_changes_requested_by_human`
- rejeicao: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_rejected_by_human`
- bloqueio por decisao invalida: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_decision`
- bloqueio por preflight nao pronto: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_preflight`

## Resumo Duravel
O contrato carrega os campos do AP-279: surface duravel, plano append-only, estrategia de idempotencia, confirmacao humana, replay/rollback, payload hash, receipt futuro, owner e fonte de policy receipt.

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste decisao
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao aceita sem preflight duravel pronto

## Beneficio
O Atlas passa a separar a decisao humana duravel da execucao real, evitando que um aceite documental seja confundido com write operacional.

## Criterios de Aceite
- aceite humano gera status accepted sem ledger write
- pedido de ajustes volta para reparo do AP-279
- rejeicao encerra o caminho ate reabertura de escopo
- decisao invalida ou motivo vazio bloqueia
- preflight AP-279 incompleto bloqueia
- AP-204, registry e static scanner reconhecem AP-280
