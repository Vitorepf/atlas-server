---
title: AP-327 Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Preflight
status: implemented
ap: AP-327
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp327Preflight.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp327PreflightTest.php
depends_on:
  - AP-326
  - AP-326
---

# AP-327 - Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Preflight
## Naming
Este AP usa classe PHP curta por AP para evitar limite de filename do macOS. Schema e status seguem completos e canonicos.

## Proposito
AP-326 valida o handoff AP-326 antes de qualquer futura decisao humana sobre nova execucao governada.
Ele nao executa payload, nao cria job e nao escreve Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-326.
Ele transforma handoff aceito em pacote de preflight revisavel para o proximo decision contract.

## Entrada
- handoff AP-326 com status `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap`
- evidencias booleanas de preflight
- refs de surface, plano append-only, idempotencia, rollback, guards, payload hash, destino e owner

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_summary`
- `real_durable_execution_executor_payload_execution_target`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_evidence`
- `next_action`
- `guardrails`

## Status
- pronto: `ready_for_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_review`
- handoff bloqueado: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_packet`
- shape invalido: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_shape`
- evidencia incompleta: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_preflight_incomplete`

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste preflight
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao faz ledger write
- nao aceita sem handoff AP-326 ready

## Beneficio
O Atlas mantem uma fronteira verificavel entre handoff aceito e decisao humana futura, preservando revisao antes de qualquer operacao duravel.

## Criterios de Aceite
- handoff ready + evidencias completas gera preflight ready
- handoff nao ready bloqueia
- shape invalido bloqueia
- evidencia incompleta retorna attention
- AP-204, registry e static scanner reconhecem AP-327
