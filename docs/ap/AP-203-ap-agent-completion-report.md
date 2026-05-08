---
title: AP-203 AP Agent Completion Report
status: foundation-contract-implemented
owner: atlas-kernel
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApAgentCompletionReport.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApAgentCompletionReportTest.php
depends_on:
  - AP-196
  - AP-202
  - AP-205
---

# AP-203 - AP Agent Completion Report

## Proposito

AP-203 cria um envelope read-only para encerrar uma sessao de implementacao em APs.

Ele nao substitui o checklist. Ele junta o gate de abertura com o checklist final e produz uma conclusao canonica.

## Posicao

Este contrato fecha o loop iniciado pelo AP-202.

Ele deve ser usado quando um agente terminou um bloco e precisa reportar se pode declarar conclusao.

## Fontes

- AP-202 confirma se a sessao estava autorizada
- AP-205 valida o shape da evidencia
- AP-196 avalia o resultado da evidencia explicita

## Entradas

- titulo do trabalho
- paths pretendidos
- evidencia de validacao
- AP alvo opcional
- numero AP solicitado opcional
- slug proposto opcional

## Saida

Schema: `atlas.ap_agent_completion_report.v1`  
Modo: `read_only_completion_report`  
Autoridade: `ap_agent_completion_report_only_no_command_execution`

Status possiveis:

- `blocked_by_session_gate`
- `blocked_by_validation_evidence_shape`
- `incomplete`
- `complete`

## Regra

Se o gate AP-202 nao esta pronto, o relatorio nao pode ser completo mesmo com checklist passando.

Se o shape AP-205 esta invalido, o relatorio bloqueia antes de aceitar a conclusao.

Se gate e shape estao prontos, o status final segue o AP-196.

## Evidencia

O relatorio preserva somente evidencia publica e resumida:

- flags de validacao
- quantidade de paths nao cobertos
- comandos declarados
- notas declaradas

## Guardrails

- nao escreve arquivos
- nao executa comandos
- nao reroda validacao
- nao marca completo sem gate
- nao marca completo sem shape de evidencia valido
- nao marca completo sem checklist
- exige evidencia explicita

## Beneficio

O Atlas passa a ter um recibo de conclusao de trabalho que humanos e IAs conseguem ler rapidamente.

Isso reduz "terminei" sem prova, evita conclusao com gate bloqueado e preserva rastreabilidade entre abertura e fechamento.

## Criterios de Aceite

- gate pronto + checklist completo retorna `complete`
- gate bloqueado retorna `blocked_by_session_gate`
- evidencia malformada retorna `blocked_by_validation_evidence_shape`
- checklist incompleto retorna `incomplete`
- comandos sao declarados, nao executados
- guardrails impedem conclusao automatica sem evidencia
