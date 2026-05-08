---
title: AP Work Intake Contract
status: foundation-contract-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApWorkIntakeContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApWorkIntakeContractTest.php
---

# AP-195 - AP Work Intake Contract

## 1. Proposito

AP-195 cria um contrato read-only para decidir como uma demanda nova entra no sistema de APs.

Ele evita criar AP novo quando o trabalho ja tem AP dono, e bloqueia intake quando a governanca esta quebrada.

## 2. Escopo Implementado

- `AtlasApWorkIntakeContract`
- consumo do AP-194 para impacto por paths pretendidos
- consumo do AP-191 para criacao segura de AP novo
- slug deterministico a partir do titulo quando nao informado
- recomendacao para revisar AP existente, criar AP novo ou bloquear
- testes para AP existente, AP novo, input invalido e governanca bloqueada

## 3. Autoridade

Schema: `atlas.ap_work_intake_contract.v1`  
Modo: `read_only_work_intake_contract`  
Autoridade: `ap_work_intake_only_no_file_writes`

O contrato nao escreve arquivo, nao cria AP, nao edita AP existente e nao usa matching semantico por IA.

## 4. Regras

- `workTitle` e obrigatorio
- slug precisa ser lowercase kebab-case
- paths ja documentados recomendam revisar AP existente
- paths descobertos recomendam AP novo somente se AP-191 permitir
- governanca em `attention` bloqueia intake operacional

## 5. Beneficio

Antes de codar, um agente recebe uma decisao objetiva sobre onde o trabalho deve viver.

Isso reduz AP duplicado, escopo solto e implementacao sem dono documental.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao consulta git
- nao executa matching semantico
- nao substitui revisao humana ou do agente principal

## 7. Definition of Done

- path coberto recomenda AP existente
- path descoberto permite AP novo quando governanca esta ok
- input invalido bloqueia antes da recomendacao
- governanca bloqueada impede intake
