---
title: AP Change Impact Read Model
status: foundation-read-model-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApChangeImpactReadModel.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApChangeImpactReadModelTest.php
---

# AP-194 - AP Change Impact Read Model

## 1. Proposito

AP-194 cria um read model para mapear arquivos alterados aos APs que os documentam.

Ele ajuda agentes a saber quais docs revisar antes de concluir uma mudanca.

## 2. Escopo Implementado

- `AtlasApChangeImpactReadModel`
- consumo do AP-193 manifest
- entrada explicita de `changedPaths`
- matching por `related_paths`
- deteccao de AP doc alterado
- separacao de paths cobertos e descobertos
- testes com repo real, fixture coberta e fixture com governanca bloqueada

## 3. Autoridade

Schema: `atlas.ap_change_impact_read_model.v1`  
Modo: `read_only_change_impact`  
Autoridade: `ap_change_impact_only_no_file_writes`

O read model nao consulta git, nao escreve arquivo e nao altera `related_paths`.

## 4. Regras

- `changedPaths` e entrada explicita, nao inferida por git
- path coberto e aquele citado em `related_paths` ou o proprio AP doc alterado
- path descoberto exige revisar AP existente ou criar novo AP via AP-191/AP-192
- governanca em `attention` bloqueia uso operacional do impacto

## 5. Beneficio

Antes de finalizar uma mudanca, um agente consegue ver quais APs precisam ser revisados.

Isso reduz codigo sem documentacao, AP esquecido e duplicacao de escopo.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao le `git status`
- nao edita documentacao automaticamente
- nao substitui AP-188 nem AP-193

## 7. Definition of Done

- path real mapeia AP relacionado
- AP doc alterado aparece em `docs_changed`
- path descoberto aparece em `uncovered_changed_paths`
- governanca bloqueada muda `next_action`
