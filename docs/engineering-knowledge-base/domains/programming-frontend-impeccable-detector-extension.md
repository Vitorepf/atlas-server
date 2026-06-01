---
id: atlas-ai-programming-frontend-impeccable-detector-extension
type: engineering_knowledge
title: Impeccable Detector And Browser Extension
status: active
category: architecture
priority: 96
summary: Dissecacao do detector deterministico e da extensao browser do Impeccable como referencia para Atlas Frontend Quality Detector.
tags:
  - atlas-ai
  - programming
  - frontend
  - impeccable
  - detector
capabilities:
  - frontend_quality_detector_teardown
  - browser_overlay_teardown
decisions:
  - O diferencial competitivo e codificar parte do gosto em regras deterministicas verificaveis.
  - Atlas deve criar detector proprio contextualizado por brand/product lane.
maintenance:
  - Atualizar quando as regras ou engines do Impeccable mudarem.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-detector-extension
graph_title: Impeccable Detector And Browser Extension
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_status: active
graph_source: repo
human_name: Impeccable Detector And Browser Extension
canonical_name: Impeccable Detector And Browser Extension
technical_name: atlas-ai-programming-frontend-impeccable-detector-extension
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-detector-extension.md
allowed_changes:
  - Atualizar rules, engines e extension flow.
forbidden_changes:
  - Tratar regras opinativas como universais sem contexto de marca.
depends_on:
  - atlas-ai-programming-frontend-impeccable-competitive-teardown
flows_to:
  - programming.visual
  - programming.frontend
unlocks:
  - atlas-frontend-quality-detector
governs:
  - domains
evidence:
  - cli/engine/registry/antipatterns.mjs
  - cli/engine/rules/checks.mjs
  - cli/engine/engines/*
  - extension/*
evidence_refs:
  - symbol: AtlasProgrammingFrontendImpeccableDetectorExtensionService
  - command: atlas:aaeos:programming-frontend-impeccable-detector-extension
  - test: AtlasProgrammingFrontendImpeccableDetectorExtensionTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia antes de implementar anti-slop detector ou visual overlay no Atlas.
ai_usage_notes:
  - Separar scan tecnico de julgamento humano/design director.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Detector virar gosto global e quebrar marcas legitimas.
observability_signals:
  - docs-health status ok
next_actions:
  - Implementar Atlas Frontend Quality Detector minimo.
---
# Impeccable Detector And Browser Extension

## Resumo

Impeccable tem um detector real com 29 regras, CLI, engines regex/static/browser
e fallback visual por screenshot. A extensao leva os achados para DevTools,
popup e sidebar.

## Papel no Atlas

Atlas deve criar `AtlasFrontendQualityDetector` para transformar criterio visual
em evidence: anti-slop, contraste, tipografia, spacing, responsivo, motion,
estado e copy.

## Onde Se Encaixa

Em `programming.visual`, `programming.frontend`, Dev run certification, Forge
packets e pre-ship gauntlet.

## Contratos

Output Atlas alvo:

```text
schema_version: atlas.frontend.detector_findings.v1
target, engine, viewport, findings[], severity, evidence_refs, false_positive_policy
```

## Fluxo

```text
target file/dir/url/stdin
-> choose engine
-> collect findings
-> annotate file/import context
-> format json or human report
-> exit 2 when findings exist
```

## Regras para IA

1. Detector e evidencia, nao prova de design final.
2. Achados precisam de severity e impacto.
3. Regras devem aceitar false-positive policy.
4. Browser scan e mais forte que regex para layout real.
5. Pixel contrast cobre casos onde CSS analitico falha.

## Escopo de Implementacao

Componentes Impeccable:

| Arquivo | Papel |
|---|---|
| `registry/antipatterns.mjs` | catalogo de regras, categoria e descricao |
| `rules/checks.mjs` | pure checks e adapters |
| `engines/regex/detect-text.mjs` | CSS/JSX/TSX/Svelte/Vue textual |
| `engines/static-html/detect-html.mjs` | parse HTML/CSS cascade |
| `engines/browser/detect-url.mjs` | Puppeteer URL scan |
| `engines/visual/screenshot-contrast.mjs` | contraste por pixel |
| `browser/injected/index.mjs` | overlay/spotlight em pagina |
| `extension/*` | Chrome extension UI e lifecycle |

## Dependencias

`htmlparser2`, `css-select`, `css-tree`, `domutils`, optional `puppeteer`.
Extension usa background service worker, content script, devtools panel,
sidebar e popup.

## Evidencias

29 regras auditadas: 16 `slop`, 13 `quality`. Exemplos:
`gradient-text`, `ai-color-palette`, `nested-cards`, `dark-glow`,
`icon-tile-stack`, `low-contrast`, `line-length`, `cramped-padding`,
`skipped-heading`, `tiny-text`, `wide-tracking`.

## Riscos

| Risco | Mitigacao Atlas |
|---|---|
| Regras matam identidade legitima | Brand/product lane + project policy |
| Regex gera falso positivo | Browser/static engine e suppressions |
| Visual overlay sem source link | conectar com Code Intelligence |
| Detector vira claim de pronto | Pre-ship gauntlet exige outros gates |

## Exemplos

Impeccable sugere rodar `impeccable detect src/` ou URL local. Atlas deve expor
`atlas:frontend:audit --target=... --json` com evidence refs e Dev/Forge wiring.

## Proximas Acoes

1. Criar catalogo Atlas minimo de anti-slop.
2. Reusar visual-smoke existente como engine de browser.
3. Integrar findings ao DevRunCertification e Forge packets.
