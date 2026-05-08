---
title: AP Status Taxonomy Audit
status: foundation-audit-implemented
owner: Atlas Documentation Operating System
line_limit: 120
related_paths:
  - app/Services/Ai/Kernel/Architecture/AtlasApStatusTaxonomyAudit.php
  - tests/Unit/Ai/Kernel/Architecture/AtlasApStatusTaxonomyAuditTest.php
---

# AP-197 - AP Status Taxonomy Audit

## 1. Proposito

AP-197 cria uma auditoria read-only para status declarados em frontmatter de APs.

Ela impede que cada agente invente um status novo e quebre a leitura operacional da documentacao.

## 2. Escopo Implementado

- `AtlasApStatusTaxonomyAudit`
- lista canonica de status permitidos
- contagem de status declarados
- deteccao de status fora da taxonomia
- integracao como blocker no AP-188
- testes para repo real e fixture com status invalido

## 3. Autoridade

Schema: `atlas.ap_status_taxonomy_audit.v1`  
Modo: `read_only_audit`  
Autoridade: `ap_status_taxonomy_only_no_doc_writes`

O auditor nao normaliza status, nao escreve docs e nao exige frontmatter em todos os APs.

## 4. Regras

- somente `status` declarado em frontmatter e auditado
- status vazio segue sendo responsabilidade do AP-190
- status fora da taxonomia gera `status_not_in_ap_taxonomy`
- AP-188 agrega a falha como `ap_status_taxonomy_violation`

## 5. Beneficio

Agentes passam a usar uma linguagem operacional comum para maturidade de AP.

Isso evita estados ambiguos como "quase pronto", "feito talvez" ou variantes duplicadas.

## 6. Nao Escopo

- nao cria comando
- nao cria API
- nao migra status antigos automaticamente
- nao altera scanner estatico
- nao substitui AP-190

## 7. Definition of Done

- status reais declarados passam
- status invalido falha
- AP-188 inclui o auditor
- guardrails provam ausencia de escrita
