---
title: AP-207 AP Agent Workflow Trace Audit
status: foundation-audit-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowTraceAudit.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowTraceAuditTest.php
depends_on:
  - AP-206
---

# AP-207 - AP Agent Workflow Trace Audit

## Proposito

AP-207 audita um rastro de passos executados por um agente no workflow AP.

Ele valida a sequencia inteira usando a politica de transicao AP-206.

## Posicao

Este auditor fica depois do AP-206.

Ele deve ser usado quando um agente quer provar que seguiu a ordem documental correta.

## Entrada

Lista ordenada de steps AP, por exemplo:

- AP-200
- AP-202
- AP-205
- AP-203

## Saida

Schema: `atlas.ap_agent_workflow_trace_audit.v1`  
Modo: `read_only_trace_audit`  
Autoridade: `ap_agent_workflow_trace_audit_only_no_execution`

Status:

- `valid_trace`
- `invalid_trace`

## Bloqueios

- trace com menos de dois steps
- step vazio ou nao string
- step desconhecido
- transicao nao declarada
- saida a partir de step terminal
- trace que nao termina em step terminal

## Guardrails

- nao escreve arquivos
- nao executa comandos
- nao avanca workflow
- nao repara trace
- nao cria fluxo paralelo

## Beneficio

O Atlas passa a conseguir auditar se uma sessao seguiu o fluxo correto, nao apenas se uma transicao isolada era valida.

Isso ajuda a encontrar pulos de validacao e conclusoes fora da ordem.

## Criterios de Aceite

- trace AP-200 -> AP-202 -> AP-205 -> AP-203 e valido
- trace AP-202 -> AP-203 e bloqueado
- saida de AP terminal e bloqueada
- step desconhecido e bloqueado
- shape invalido e bloqueado
