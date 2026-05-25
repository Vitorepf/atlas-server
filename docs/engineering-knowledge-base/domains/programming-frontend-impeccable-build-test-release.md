---
id: atlas-ai-programming-frontend-impeccable-build-test-release
type: engineering_knowledge
title: Impeccable Build Test Release Teardown
status: active
category: architecture
priority: 96
summary: Dissecacao do build multi-provider, site, testes, fixtures e release do Impeccable para orientar certificacao Atlas Frontend.
tags:
  - atlas-ai
  - programming
  - frontend
  - impeccable
  - build-test-release
capabilities:
  - frontend_competitive_test_matrix
  - provider_bundle_teardown
decisions:
  - A maturidade do Impeccable vem tambem de testes e distribuicao multi-provider.
  - Atlas deve superar com certification strict e corpus/rivals, nao apenas build.
maintenance:
  - Atualizar quando scripts ou matriz de testes do benchmark mudarem.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-build-test-release
graph_title: Impeccable Build Test Release Teardown
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_status: active
graph_source: repo
human_name: Impeccable Build Test Release Teardown
canonical_name: Impeccable Build Test Release Teardown
technical_name: atlas-ai-programming-frontend-impeccable-build-test-release
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-build-test-release.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-build-test-release.md
allowed_changes:
  - Atualizar build/test/release mapping.
forbidden_changes:
  - Declarar paridade sem testes equivalentes no Atlas.
depends_on:
  - atlas-ai-programming-frontend-impeccable-competitive-teardown
flows_to:
  - programming.frontend
unlocks:
  - atlas-frontend-design-certification
governs:
  - domains
evidence:
  - package.json
  - scripts/build.js
  - scripts/lib/transformers/*
  - tests/*
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia antes de desenhar certification/test matrix para Atlas Frontend.
ai_usage_notes:
  - Comparar por cobertura e tipos de regressao, nao so numero de testes.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Build de provider driftar da skill source.
observability_signals:
  - docs-health status ok
next_actions:
  - Criar matriz de testes Atlas equivalente.
---
# Impeccable Build Test Release Teardown

## Resumo

Impeccable tem build multi-provider, site Astro, CLI npm, extensao Chrome e uma
suite de testes extensa. Isso transforma a skill em produto distribuivel.

## Papel no Atlas

Atlas deve usar a matriz como benchmark para `atlas:frontend:certify`: build,
provider compatibility, detector, live mode, docs, site/demo e release.

## Onde Se Encaixa

Na futura certificacao do Atlas Frontend Design Runtime e no corpus de
programming/frontend Rivals.

## Contratos

Build principal:

```text
skill source + reference + scripts
-> scripts/build.js
-> provider transforms
-> dist/universal.zip
-> site/build assets
-> release scripts
```

## Fluxo

`package.json` define:

- `build:skills`: gera provider bundles;
- `build:site`: Astro build;
- `build`: skills + site + dist copy;
- `build:browser`: bundle detector para browser;
- `build:extension`: Chrome extension;
- `test`: Bun + Node tests;
- `test:live-e2e`: fixtures reais com dev servers;
- `release:skill`, `release:cli`, `release:ext`.

## Regras para IA

1. Alterar source, nao provider output gerado.
2. Depois de regra detector, rebuild browser/extension/site counts.
3. Live scripts exigem live E2E focado.
4. Release recusa dirty tree, HEAD nao enviado, changelog ausente e build stale.

## Escopo de Implementacao

Build files:

| Arquivo | Papel |
|---|---|
| `scripts/build.js` | orquestra provider bundles, counts, validators, site data |
| `scripts/lib/transformers/providers.js` | provider matrix |
| `scripts/lib/transformers/factory.js` | gera output por provider |
| `scripts/lib/utils.js` | parsing, placeholders, provider blocks |
| `scripts/release.mjs` | release skill/cli/ext |
| `scripts/build-browser-detector.js` | browser detector bundle |
| `scripts/build-extension.js` | extension bundle |
| `.claude-plugin/*` | manifest/marketplace plugin |
| `functions/api/download/*` | download de bundles por provider |

## Dependencias

`HARNESSES.md` mapeia suporte por harness: frontmatter, diretorios nativos,
sidecars e subagents. Providers suportados incluem Claude Code, Cursor, Codex,
Agents, Gemini, GitHub Copilot, Kiro, OpenCode, Pi, Qoder, Trae e Rovo Dev.

## Evidencias

Testes por area:

| Area | Tests |
|---|---|
| build/provider | `tests/build.test.js`, `tests/lib/*` |
| detector | `detect-antipatterns*.test.*`, fixtures antipatterns |
| live mode | `live-*.test.mjs`, `live-e2e/*` |
| frameworks | `framework-fixtures/*` |
| context/design | `load-context`, `design-parser`, `critique-storage` |
| CLI/install | `skills-cli`, `windows-path-fix` |

## Riscos

| Risco | Mitigacao Atlas |
|---|---|
| Provider output diverge | source-of-truth + generated checks |
| Detector browser desatualizado | build:browser gate |
| Live funciona so em fixture simples | framework fixture matrix |
| Certificacao estreita | Atlas deve exigir Dev/Forge/evidence/outcome |

## Exemplos

Impeccable testa Next, Nuxt, SvelteKit, Astro, Vite React, Tailwind v3/v4,
CSS Modules, Emotion, styled-components, vanilla-extract, Radix/Dialog e CSP.

## Proximas Acoes

1. Criar `atlas:frontend:certify --strict --json`.
2. Adicionar fixtures Atlas para Vite, Next, Astro, Expo web e dashboard.
3. Medir provider/model por corpus frontend e outcomes.
