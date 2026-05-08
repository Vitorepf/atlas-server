---
title: AP Completion Checklist Contract
status: foundation-contract-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApCompletionChecklistContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApCompletionChecklistContractTest.php
---

# AP-196 - AP Completion Checklist Contract

## 1. Proposito

AP-196 cria um contrato read-only para decidir se um bloco AP pode ser considerado completo.

Ele exige evidencia explicita de escopo, testes, docs, arquitetura e higiene de diff.

## 2. Escopo Implementado

- `AtlasApCompletionChecklistContract`
- checklist deterministico por evidencia fornecida
- comandos obrigatorios declarados sem execucao automatica
- bloqueio quando testes, docs, arquitetura ou diff nao foram comprovados
- regra para paths descobertos pelo AP-194
- testes para completo, incompleto e path descoberto revisado

## 3. Autoridade

Schema: `atlas.ap_completion_checklist_contract.v1`  
Modo: `read_only_completion_checklist`  
Autoridade: `ap_completion_checklist_only_no_command_execution`

O contrato nao executa comando, nao escreve arquivo e nao substitui revisao humana.

## 4. Regras

- escopo precisa estar declarado como contido
- testes focados precisam passar
- docs-health precisa passar
- architecture-validate precisa passar
- `git diff --check` precisa passar
- AP doc precisa ter sido atualizado ou justificado
- paths descobertos precisam ser revisados

## 5. Beneficio

Antes de declarar uma entrega como pronta, o agente recebe uma lista objetiva do que ainda falta.

Isso evita fechamento falso de AP e reduz regressao documental.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao roda testes automaticamente
- nao edita documentacao
- nao substitui review do Codex principal

## 7. Definition of Done

- evidencia completa retorna `complete`
- evidencia faltante retorna `incomplete`
- path descoberto sem revisao falha
- guardrails provam ausencia de execucao e escrita
