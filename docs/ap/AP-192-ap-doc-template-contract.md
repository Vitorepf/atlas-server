---
title: AP Doc Template Contract
status: foundation-contract-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApDocTemplateContract.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApDocTemplateContractTest.php
---

# AP-192 - AP Doc Template Contract

## 1. Proposito

AP-192 cria um contrato read-only para renderizar template canonico de AP antes de qualquer escrita.

Ele transforma a decisao AP-191 em um esqueleto consistente, reduzindo AP duplicado, incompleto ou fora da lei documental.

## 2. Escopo Implementado

- `AtlasApDocTemplateContract`
- consumo obrigatorio do AP-191 creation decision
- frontmatter canonico com `title`, `status`, `owner`, `line_limit` e `related_paths`
- template com proposito, escopo, autoridade, regras, nao escopo e DoD
- testes para render permitido, input invalido e governanca bloqueada

## 3. Autoridade

Schema: `atlas.ap_doc_template_contract.v1`  
Modo: `read_only_template_contract`  
Autoridade: `ap_template_render_only_no_file_writes`

O contrato nao escreve arquivo, nao cria AP e nao renumera documentacao.

## 4. Regras

- template so renderiza quando AP-191 permite criacao
- `title`, `owner` e `status` sao obrigatorios
- `line_limit` precisa ser inteiro positivo
- `related_paths`, quando informado, precisa conter strings nao vazias
- `recommended_doc_path` vem da decisao AP-191

## 5. Beneficio

Agentes podem gerar a estrutura inicial de um AP sem improvisar formato.

Isso cria uma ponte segura entre governanca documental e trabalho incremental de implementacao.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao altera Architecture Operations
- nao altera scanner estatico
- nao escreve em `docs/ap`

## 7. Definition of Done

- fluxo real renderiza template do proximo AP sugerido
- inputs ruins bloqueiam antes de renderizar
- governanca bloqueada impede template
- validacoes de arquitetura continuam verdes
