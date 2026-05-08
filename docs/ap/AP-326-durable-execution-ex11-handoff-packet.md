---
title: AP-326 Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Handoff Packet
status: implemented
ap: AP-326
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp326HandoffPacket.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowAp326HandoffPacketTest.php
depends_on:
  - AP-204
  - AP-325
---

# AP-326 - Durable Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Execution Handoff Packet
## Naming
Este AP usa classe PHP curta por AP para evitar limite de filename do macOS. Schema e status seguem completos e canonicos.

## Proposito
AP-326 entrega o receipt AP-325 aceito para um futuro AP da execucao repetida do payload do executor real duravel.
Ele nao executa payload, nao cria job e nao escreve no Evidence Ledger.

## Posicao no Fluxo
Vem depois do AP-325.
Ele separa aceite humano da execucao repetida do payload de transferencia governada para o proximo AP.

## Entrada
- receipt AP-325 com status `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_acceptance_reported`
- evidencias booleanas de handoff
- referencias de plano append-only, policy receipt, guardrails, destino e owner

## Saida
Schema: `atlas.ap_agent_workflow_release_evidence_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_packet.v1`

Campos principais:
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_receipt_summary`
- `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence`
- `handoff_target`
- `next_action`
- `guardrails`

## Status
- pronto: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_ready_for_future_execution_ap`
- receipt bloqueado: `blocked_by_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_decision_receipt`
- shape invalido: `blocked_invalid_runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_shape`
- evidencia incompleta: `runtime_execution_activation_implementation_execution_result_persistence_execution_ledger_write_execution_durable_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_execution_handoff_evidence_incomplete`

## Guardrails
- nao escreve arquivo
- nao executa comando
- nao persiste pacote
- nao publica release
- nao emite evento
- nao escreve Evidence Ledger
- nao cria runtime job
- nao executa payload
- nao faz ledger write
- nao aceita sem receipt AP-325 aceito

## Beneficio
O Atlas preserva a fronteira entre receipt humano aceito e qualquer futura execucao operacional duravel.

## Criterios de Aceite
- receipt aceito + evidencias completas gera handoff ready
- receipt nao aceito bloqueia
- shape invalido bloqueia
- evidencia incompleta retorna attention
- AP-204, registry e static scanner reconhecem AP-326
