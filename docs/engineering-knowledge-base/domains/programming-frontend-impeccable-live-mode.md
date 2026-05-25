---
id: atlas-ai-programming-frontend-impeccable-live-mode
type: engineering_knowledge
title: Impeccable Live Mode Teardown
status: active
category: architecture
priority: 96
summary: Dissecacao do Live Mode do Impeccable, incluindo server, browser injection, event loop, variants, accept/discard, journal e recovery.
tags:
  - atlas-ai
  - programming
  - frontend
  - impeccable
  - live-mode
capabilities:
  - frontend_live_iteration_teardown
  - browser_source_patch_runtime
decisions:
  - Live Mode e a maior vantagem operacional do Impeccable contra Atlas Frontend atual.
  - Atlas deve superar usando Software Twin, ACRUI, AVEOR, evidence e outcome.
maintenance:
  - Atualizar quando scripts live-* mudarem.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-software-twin-verified-evolution-runtime.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-live-mode
graph_title: Impeccable Live Mode Teardown
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_status: active
graph_source: repo
human_name: Impeccable Live Mode Teardown
canonical_name: Impeccable Live Mode Teardown
technical_name: atlas-ai-programming-frontend-impeccable-live-mode
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-live-mode.md
allowed_changes:
  - Atualizar event flow, scripts e Atlas implications.
forbidden_changes:
  - Criar live source mutation no Atlas sem boundary/evidence.
depends_on:
  - atlas-ai-programming-frontend-impeccable-competitive-teardown
flows_to:
  - programming.frontend
unlocks:
  - atlas-frontend-live-iteration-runtime
governs:
  - domains
evidence:
  - skill/scripts/live*.mjs
  - skill/scripts/live-browser.js
  - notes/adr-live-variant-mode.md
  - tests/live-*.test.mjs
  - tests/live-e2e/*
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia antes de implementar selecao visual no browser ou variants accept flow.
ai_usage_notes:
  - Live source mutation exige safety maior que prototipo visual.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Patch em arquivo errado ou gerado.
observability_signals:
  - docs-health status ok
next_actions:
  - Especificar AtlasFrontendLiveIterationRuntime com AVEOR boundary.
---
# Impeccable Live Mode Teardown

## Resumo

Live Mode permite selecionar um elemento no browser, gerar variantes HTML/CSS,
visualizar via HMR e aceitar uma variante que e aplicada no source.

## Papel no Atlas

Este e o subsistema que Atlas mais precisa igualar/superar para competir em
frontend multiempresa. A versao Atlas deve registrar evidence, respeitar
Software Twin/ACRUI e gerar outcome memory.

## Onde Se Encaixa

`programming.frontend` quando o usuario quer iterar visualmente em tela real,
especialmente hero, card, tabela, formulário, nav, empty state, dashboard ou
landing.

## Contratos

Event loop principal:

```text
live.mjs -> live-server.mjs -> live-inject.mjs -> live-browser.js
browser event -> live-poll.mjs -> agent writes variants
accept/discard -> live-accept.mjs -> live-complete/resume/status as needed
```

## Fluxo

1. `live.mjs` verifica config, inicia server, injeta script e carrega contexto.
2. `live-server.mjs` serve `/live.js`, `/detect.js`, SSE e poll.
3. `live-browser.js` cria picker, bar, annotations, variants UI e source fetch.
4. `live-wrap.mjs` localiza elemento no source e insere wrapper.
5. Agente escreve variantes em bloco unico.
6. Browser detecta variants via DOM/HMR.
7. Usuario aceita/descarta.
8. `live-accept.mjs` preserva variante, limpa scaffolding e CSS.
9. `live-session-store.mjs` permite recovery por journal/snapshot.

## Regras para IA

1. Nunca usar helper port como app URL.
2. Poll deve ser long timeout e re-poll apos cada evento.
3. Screenshot anotado deve ser lido antes de planejar.
4. `--text` ajuda a desambiguar elementos repetidos.
5. Nao escrever em arquivo gerado.
6. Cada variant div deve conter exatamente um top-level element.
7. Accept carbonize precisa cleanup antes de complete.

## Escopo de Implementacao

Scripts auditados:

| Script | Linhas | Papel |
|---|---:|---|
| `live-browser.js` | 4861 | UI injetada, picker, annotations, variants, design panel |
| `live-server.mjs` | 839 | HTTP/SSE/poll/token/events/status/source |
| `live-wrap.mjs` | 633 | source search, wrapper, syntax/style mode |
| `live-accept.mjs` | 596 | accept/discard, parse markers, cleanup |
| `live-inject.mjs` | 447 | config, script tag, CSP patch/revert |
| `live-session-store.mjs` | 255 | journal, snapshot, pending event |
| `live-poll.mjs` | 201 | agent long poll and auto accept/discard replies |

Documento arquitetural auditado:

| Documento | Papel |
|---|---|
| `notes/adr-live-variant-mode.md` | ADR implementado do Live Variant Mode: source modification, SSE/fetch, skill scripts self-contained, long-poll agent bridge, wrapper `display: contents` e fallback no-HMR |

## Dependencias

Dev server com HMR ou HTML estatico, browser mutavel, config
`.impeccable/live/config.json`, token local, source files rastreaveis.

## Evidencias

Testes relevantes:

- `tests/live-wrap.test.mjs`;
- `tests/live-accept.test.mjs`;
- `tests/live-server.test.mjs`;
- `tests/live-session-store.test.mjs`;
- `tests/live-browser-regression.test.mjs`;
- `tests/live-e2e.test.mjs`;
- `tests/framework-fixtures.test.mjs`.

## Riscos

| Risco | Mitigacao Atlas |
|---|---|
| Source errado | Code Intelligence, text disambiguation, Software Twin |
| Arquivo gerado | ACRUI/is-generated + deny write |
| HMR nao atualiza | recovery, replay, explicit status |
| CSS/JSX invalido | framework adapters + tests |
| Aceite sem evidence | receipt + screenshot + diff |

## Exemplos

Atlas alvo: operador seleciona um hero, pede "mais premium", recebe 3
variantes, aceita a segunda, e o Atlas gera patch, screenshots, diff,
detector findings, Dev/Forge receipt e AEMOR outcome.

## Proximas Acoes

1. Criar spec `AtlasFrontendLiveIterationRuntime`.
2. Definir schema `atlas.frontend.live_iteration_event.v1`.
3. Provar source patch com fixtures React/Vite/Next/Astro.
