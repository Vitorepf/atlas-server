---
id: atlas-ai-programming-frontend-impeccable-code-inventory
type: engineering_knowledge
title: Impeccable Code Inventory For Atlas Frontend
status: active
category: architecture
priority: 96
summary: Inventario de codigo autoral do `pbakaus/impeccable` auditado para orientar o Atlas Frontend multiempresa sem copiar codigo externo.
tags:
  - atlas-ai
  - programming
  - frontend
  - impeccable
  - code-inventory
capabilities:
  - frontend_competitive_code_inventory
  - external_system_architecture_mapping
decisions:
  - O inventario separa fonte autoral de bundles gerados para providers.
  - Atlas usa este mapa para aprender arquitetura e cobertura, nao para copiar implementacao.
maintenance:
  - Atualizar quando o benchmark Impeccable for re-auditado em novo commit.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-code-inventory
graph_title: Impeccable Code Inventory For Atlas Frontend
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_status: active
graph_source: repo
human_name: Impeccable Code Inventory
canonical_name: Impeccable Code Inventory For Atlas Frontend
technical_name: atlas-ai-programming-frontend-impeccable-code-inventory
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-code-inventory.md
allowed_changes:
  - Atualizar inventario, contagens e mapeamento quando houver nova auditoria.
forbidden_changes:
  - Tratar arquivos gerados para providers como fonte autoral primaria.
depends_on:
  - atlas-ai-programming-frontend-impeccable-competitive-teardown
flows_to:
  - programming.frontend
unlocks:
  - atlas-frontend-design-runtime
governs:
  - domains
evidence:
  - clone local /tmp/impeccable-audit commit 84135db0e6bdd58d22828f7bc8331cae7bde3e7f
evidence_refs:
  - symbol: AtlasProgrammingFrontendImpeccableCodeInventoryService
  - command: atlas:aaeos:programming-frontend-impeccable-code-inventory
  - test: AtlasProgrammingFrontendImpeccableCodeInventoryTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia o inventario antes de comparar Impeccable com Atlas Frontend.
ai_usage_notes:
  - Use as contagens como evidencia de cobertura da auditoria, nao como claim de runtime Atlas.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Contar bundles gerados como arquitetura nova.
observability_signals:
  - docs-health status ok
next_actions:
  - Re-auditar quando o repo externo mudar.
---
# Impeccable Code Inventory For Atlas Frontend

Fonte auditada: `pbakaus/impeccable`, commit
`84135db0e6bdd58d22828f7bc8331cae7bde3e7f` em `/tmp/impeccable-audit`.

## Resumo

O repo tem 1691 arquivos totais no clone auditado. Depois de excluir bundles
gerados para providers (`.claude`, `.agents`, `.cursor`, `.gemini`, `.github`,
`.kiro`, `.opencode`, `.pi`, `.qoder`, `.rovodev`, `.trae`, `.trae-cn` e
`plugin`), restam 670 arquivos autorais/operacionais.

## Papel no Atlas

Este inventario mostra quais partes do Impeccable sao produto real. Atlas deve
mirar os subsistemas autorais: skill source, scripts, detector, extension,
build, site, tests, fixtures e release.

## Onde Se Encaixa

Complementa o dossie competitivo e alimenta futuras specs do
`AtlasFrontendDesignRuntime`.

## Contratos

Inventario verificado:

| Area | Arquivos | Papel |
|---|---:|---|
| `tests` | 320 | regressao, detector, live mode, provider transforms, fixtures |
| `site` | 197 | site produto, docs, exemplos, demos publicos |
| `skill` | 62 | fonte da skill, referencias e scripts runtime |
| `extension` | 21 | Chrome extension, devtools panel, popup, content/background |
| `cli` | 20 | detector CLI, engines, rules, browser script, package bin |
| `scripts` | 15 | build, release, zips, provider transforms, assets |
| `demos` | 6 | exemplo de landing/demo |
| `notes` | 2 | ADR/plano de live session recovery |
| `functions` | 2 | endpoints Cloudflare Pages de download |
| `.codex` | 1 | subagente `impeccable_asset_producer` |
| `.claude-plugin` | 2 | manifest e marketplace Claude plugin |

## Fluxo

```text
source skill/reference/scripts
-> build transforms
-> provider bundles
-> CLI/detector package
-> site and extension artifacts
-> tests and fixtures guard regressions
```

## Regras para IA

1. Auditar fonte autoral antes de provider bundles.
2. Considerar `.claude`, `.agents`, `.cursor`, etc. outputs gerados.
3. Tratar `skill/SKILL.md`, `skill/reference`, `skill/scripts`, `cli/engine`,
   `extension`, `scripts` e `tests` como areas primarias.
4. Nao copiar implementacao externa para Atlas.

## Escopo de Implementacao

Para Atlas, o escopo util e mapear padroes: comandos, detector, live mode,
context docs, asset producer, build multi-provider, plugin manifests, extension
overlay, endpoints de download, site/demos e testes. Licenca/codigo externo
exigem revisao antes de reaproveitamento direto.

## Dependencias

- `package.json`: Node >=18, ESM, CLI `impeccable`.
- Dependencies runtime: `css-select`, `css-tree`, `domutils`,
  `htmlparser2`, `marked`.
- Optional: `puppeteer`.
- Dev: Astro, Playwright, AI SDKs, Claude/OpenAI/Google SDKs, zod, wrangler.

## Evidencias

Comandos locais usados:

- `find /tmp/impeccable-audit ... | wc -l`;
- separacao de generated/provider dirs;
- extracao de funcoes/linhas por script via Node;
- leitura de README, skill, references, scripts, detector, extension e tests.

## Riscos

| Risco | Mitigacao |
|---|---|
| Inflar capacidade por contar bundles gerados | Separar 1691 total vs 670 fonte autoral |
| Perder subsistema pequeno mas critico | Inventariar extension, functions, notes e tests |
| Confundir demo/site com runtime | Classificar papel de cada area |

## Exemplos

`cli/engine/rules/checks.mjs` tem 1949 linhas e concentra pure checks.  
`skill/scripts/live-browser.js` tem 4861 linhas e e o cliente visual injetado.  
`tests/live-wrap.test.mjs` tem 803 linhas e prova source wrapping.  
`tests/live-server.test.mjs` tem 766 linhas e prova server/event handling.
`.codex/agents/impeccable_asset_producer.toml` define um subagente de assets
que preserva mock aprovado, recorta/gera raster limpo e devolve manifest.

## Proximas Acoes

1. Usar este inventario para criar matriz Atlas de paridade.
2. Implementar detector e live runtime Atlas por etapas.
3. Re-auditar Impeccable em novo release antes de claims competitivos.
