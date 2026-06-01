---
id: atlas-frontend-product-proof-live-mode-repair-loop
type: engineering_knowledge
title: Atlas Frontend Product Proof Live Mode Repair Loop
status: active
category: product-proof
priority: 90
summary: Manifest de prova para ciclo live de selecao no browser, preview de variante, accept/discard, source patch e recovery.
tags: [atlas-ai, programming, frontend, product-proof]
capabilities: [atlas_frontend_product_proof, live_mode_repair_loop]
decisions:
  - Live mode superior exige replay rival real; manifest local nao basta.
maintenance:
  - Atualizar quando houver journal real de bridge, relay, source patch e recovery.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-frontend-product-proof-live-mode-repair-loop
graph_title: Atlas Frontend Product Proof Live Mode Repair Loop
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-superpower
graph_status: active
graph_source: repo
human_name: Atlas Frontend Product Proof Live Mode Repair Loop
canonical_name: Atlas Frontend Product Proof Live Mode Repair Loop
technical_name: atlas-frontend-product-proof-live-mode-repair-loop
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-product-proof-live_mode_repair_loop.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-product-proof-live_mode_repair_loop.md
allowed_changes:
  - Atualizar evidencias e artefatos quando a demo real existir.
forbidden_changes:
  - Declarar superioridade live mode sem replay real contra benchmark rival.
depends_on:
  - atlas-ai-programming-frontend-superpower
flows_to: [programming.frontend]
unlocks: [atlas_frontend_product_proof]
governs: [domains]
evidence:
  - docs/engineering-knowledge-base/domains/programming-frontend-product-proof-live_mode_repair_loop.md
evidence_refs:
  - symbol: AtlasProgrammingFrontendProductProofLiveModeRepairLoopService
  - command: atlas:aaeos:programming-frontend-product-proof-live-mode-repair-loop
  - test: AtlasProgrammingFrontendProductProofLiveModeRepairLoopTest
required_tests:
  - "php artisan atlas:frontend:proof --json"
requires_evidence: true
risk_level: high
visual_tags: [system, module, frontend]
quality_gates:
  - "php artisan atlas:frontend:proof --json"
next_actions:
  - Anexar journal real de browser pick, preview, accept e recover.
---
# Atlas Frontend Product Proof Live Mode Repair Loop

## Resumo
Manifest para componente selecionado no browser receber previews locais,
source patch aceito e recovery por journal.

## Papel no Atlas
Prova o ciclo operacional que aproxima o Atlas do Live Mode do Impeccable.

## Onde Se Encaixa
Fica no product proof catalog do Atlas Frontend.

## Contratos
Viewport: desktop. Evidencias: browser pick event, preview variant event,
accepted variant diff e recover session.

## Fluxo
Selecionar elemento -> gerar preview -> aceitar patch -> provar recovery.

## Regras para IA
Nao declarar superioridade live mode sem replay rival real.

## Escopo de Implementacao
Manifest documental; nao contem journal real.

## Dependencias
Depende de browser bridge, live preview relay e source patch runtime.

## Evidencias
Este arquivo e o manifest local. Evidencia executavel vira receipt futuro.

## Riscos
Confundir contrato local com paridade operacional completa.

## Exemplos
Alt+click seleciona card, preview aplica CSS temporario, accept grava source.

## Proximas Acoes
Anexar replay real.
