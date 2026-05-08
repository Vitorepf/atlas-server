---
title: AP-206 AP Agent Workflow Transition Policy
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentWorkflowTransitionPolicy.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentWorkflowTransitionPolicyTest.php
depends_on:
  - AP-204
---

# AP-206 - AP Agent Workflow Transition Policy

## Proposito

AP-206 cria uma politica read-only para validar transicoes entre passos do workflow AP de agentes.

Ele consome a sequencia oficial do AP-204 e impede saltos como sair do gate direto para completion sem validar evidencia.

## Posicao

Este contrato fica depois do registry AP-204.

Ele deve ser usado quando um agente quer avancar de um passo para outro dentro do workflow documentado.

## Fonte

- AP-204 fornece steps e `allowed_next_steps`

## Saida

Schema: `atlas.ap_agent_workflow_transition_policy.v1`  
Modo: `read_only_transition_policy`  
Autoridade: `ap_agent_workflow_transition_policy_only_no_execution`

## Transicoes Permitidas

- AP-200 -> AP-201
- AP-200 -> AP-202
- AP-201 -> AP-202
- AP-202 -> AP-205
- AP-205 -> AP-203

## Bloqueios

- step desconhecido
- salto nao declarado
- saida a partir de step terminal

## Guardrails

- nao escreve arquivos
- nao executa comandos
- nao avanca workflow
- nao aceita steps desconhecidos
- nao permite saida de terminal
- nao cria fluxo paralelo

## Beneficio

O Atlas passa a ter uma regra simples para conferir se um agente esta seguindo a ordem documental correta.

Isso reduz pulos de validacao, completion prematuro e fluxo paralelo entre APs.

## Criterios de Aceite

- policy lista transicoes derivadas do AP-204
- transicoes declaradas retornam `allowed`
- AP-202 -> AP-203 retorna `blocked`
- AP terminal nao permite saida
- step desconhecido retorna `blocked`
