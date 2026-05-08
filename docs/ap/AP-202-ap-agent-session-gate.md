---
title: AP-202 AP Agent Session Gate
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentSessionGate.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentSessionGateTest.php
depends_on:
  - AP-200
  - AP-201
---

# AP-202 - AP Agent Session Gate

## Proposito

AP-202 cria um gate read-only para o inicio de uma sessao de implementacao em APs.

Ele junta o handoff do agente com o estado de reparo documental, sem criar uma nova fonte de verdade.

## Posicao

Este contrato fica no Documentation Operating System.

Ele deve ser consultado antes de um agente editar codigo ou docs quando o trabalho precisa obedecer a estrutura mae.

## Entradas

- titulo do trabalho
- paths pretendidos
- AP alvo opcional
- numero AP solicitado opcional
- slug proposto opcional
- caminho alternativo de `docs/ap` para teste

## Fontes

- AP-200 fornece handoff, contexto alvo, intake e checklist inicial
- AP-201 fornece propostas de reparo documental

## Saida

Schema: `atlas.ap_agent_session_gate.v1`  
Modo: `read_only_session_gate`  
Autoridade: `ap_agent_session_gate_only_no_file_writes`

Status possiveis:

- `blocked_by_documentation_repair`
- `blocked_by_handoff`
- `attention`
- `ready_for_new_ap_work`
- `ready_for_existing_ap_work`

## Regra

Se AP-201 retorna proposta pendente, o gate bloqueia o trabalho antes do handoff.

Se AP-201 esta limpo, o gate segue o status do AP-200.

## Guardrails

- nao escreve arquivos
- nao executa comandos
- nao aplica reparos
- nao cria APs
- nao edita APs
- nao substitui julgamento do agente
- exige checklist de conclusao apos o trabalho

## Beneficio

Um Codex novo consegue saber rapidamente se pode trabalhar, onde deve trabalhar e qual problema documental precisa ser revisado antes.

Isso reduz duplicacao, evita AP esquecido e impede que sujeira documental vire implementacao.

## Criterios de Aceite

- trabalho com AP existente e governanca limpa fica pronto para AP existente
- trabalho novo em escopo limpo fica pronto para novo AP
- reparo documental pendente bloqueia a sessao
- input invalido bloqueia por handoff
- contrato preserva `proposals` e `next_action` das fontes
