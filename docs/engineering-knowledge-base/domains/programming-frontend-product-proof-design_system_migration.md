---
id: atlas-frontend-product-proof-design-system-migration
type: engineering_knowledge
title: Atlas Frontend Product Proof Design System Migration
status: active
category: product-proof
priority: 90
summary: Manifest de prova para migracao de UI legado para design system tokenizado com before/after e drift checks.
tags: [atlas-ai, programming, frontend, product-proof]
capabilities: [atlas_frontend_product_proof, design_system_migration]
decisions:
  - Migracao visual precisa de before/after e drift check.
maintenance:
  - Atualizar quando houver before/after, token inventory, drift check e visual smoke.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-frontend-product-proof-design-system-migration
graph_title: Atlas Frontend Product Proof Design System Migration
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-superpower
graph_status: active
graph_source: repo
human_name: Atlas Frontend Product Proof Design System Migration
canonical_name: Atlas Frontend Product Proof Design System Migration
technical_name: atlas-frontend-product-proof-design-system-migration
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-product-proof-design_system_migration.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-product-proof-design_system_migration.md
allowed_changes:
  - Atualizar evidencias e artefatos quando a demo real existir.
forbidden_changes:
  - Declarar migracao concluida sem diff, tokens e regressao visual.
depends_on:
  - atlas-ai-programming-frontend-superpower
flows_to: [programming.frontend]
unlocks: [atlas_frontend_product_proof]
governs: [domains]
evidence:
  - docs/engineering-knowledge-base/domains/programming-frontend-product-proof-design_system_migration.md
required_tests:
  - "php artisan atlas:frontend:proof --json"
requires_evidence: true
risk_level: high
visual_tags: [system, module, frontend]
quality_gates:
  - "php artisan atlas:frontend:proof --json"
next_actions:
  - Anexar before/after e token drift receipt.
---
# Atlas Frontend Product Proof Design System Migration

## Resumo
Manifest para migrar UI legado para design system tokenizado com espaco,
tipografia, cores, estados e responsividade consistentes.

## Papel no Atlas
Prova capacidade de modernizar produto existente sem bagunca visual.

## Onde Se Encaixa
Fica no product proof catalog do Atlas Frontend.

## Contratos
Viewports: desktop, tablet, mobile. Evidencias: design-system drift check,
anti-slop, visual smoke e completion hash.

## Fluxo
Inventariar UI -> migrar tokens -> comparar before/after -> registrar gates.

## Regras para IA
Nao declarar migracao completa sem escopo e regressao visual.

## Escopo de Implementacao
Manifest documental; nao contem patch de migracao.

## Dependencias
Depende de token inventory, visual smoke e detector.

## Evidencias
Este arquivo e o manifest local. Evidencia executavel vira receipt futuro.

## Riscos
Refactor visual amplo sem prova de regressao.

## Exemplos
Tela legada convertida para componentes e tokens coerentes.

## Proximas Acoes
Anexar artefatos before/after.
