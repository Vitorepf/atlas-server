---
id: atlas-ai-programming-frontend-impeccable-product-site-assets
type: engineering_knowledge
title: Impeccable Product Site Assets And Distribution
status: active
category: architecture
priority: 95
summary: Dissecacao do site, demos, assets, download API e CLI installer do Impeccable como parte do produto competitivo.
tags:
  - atlas-ai
  - programming
  - frontend
  - impeccable
  - product-site
capabilities:
  - frontend_product_site_teardown
  - asset_distribution_teardown
decisions:
  - O site e as demos do Impeccable sao parte do produto, pois provam taste, distribuem bundles e ensinam o workflow.
  - Atlas Frontend deve ter product proof e demos verificaveis, nao so runtime interno.
maintenance:
  - Atualizar quando site, demos, download API ou CLI installer mudarem.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-product-site-assets
graph_title: Impeccable Product Site Assets And Distribution
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_status: active
graph_source: repo
human_name: Impeccable Product Site Assets And Distribution
canonical_name: Impeccable Product Site Assets And Distribution
technical_name: atlas-ai-programming-frontend-impeccable-product-site-assets
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-product-site-assets.md
allowed_changes:
  - Atualizar quando a auditoria do produto/site for refeita.
forbidden_changes:
  - Ignorar site/demos ao comparar maturidade de produto frontend.
depends_on:
  - atlas-ai-programming-frontend-impeccable-competitive-teardown
flows_to:
  - programming.frontend
unlocks:
  - atlas-frontend-product-proof
governs:
  - domains
evidence:
  - site/*
  - demos/*
  - cli/bin/commands/skills.mjs
  - functions/api/download/*
evidence_refs:
  - symbol: AtlasProgrammingFrontendImpeccableProductSiteAssetsService
  - command: atlas:aaeos:programming-frontend-impeccable-product-site-assets
  - test: AtlasProgrammingFrontendImpeccableProductSiteAssetsTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia para entender como Impeccable transforma runtime em produto distribuivel.
ai_usage_notes:
  - Diferenciar prova de produto de core runtime.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Comparar apenas skill e esquecer site, installer e demos.
observability_signals:
  - docs-health status ok
next_actions:
  - Definir product proof publico para Atlas Frontend.
---
# Impeccable Product Site Assets And Distribution

## Resumo

Impeccable tem site Astro, demos, antes/depois, detector lab, live mode docs,
download endpoints e CLI installer. Isso importa porque a ferramenta vende o
proprio taste visual que promete.

## Papel no Atlas

Atlas Frontend multiempresa precisa de proof surfaces equivalentes: demos reais,
casos antes/depois, detector reports, live iteration demonstravel e install/run
path claro.

## Onde Se Encaixa

Na camada de produto e marketing tecnico do futuro Atlas Frontend Design
Runtime, separada do runtime interno.

## Contratos

Areas:

| Area | Papel |
|---|---|
| `site/pages/index.astro` | landing principal |
| `site/pages/designing` | workflow de design |
| `site/pages/live-mode` | pagina de Live Mode |
| `site/pages/detector` | detector lab e fixtures |
| `site/content/skills` | docs publicas por comando |
| `site/content/tutorials` | getting started, live, overlay |
| `site/public/assets` | logos, before/after, OG, case assets |
| `demos/landing-demo` | demo instalavel |
| `cli/bin/commands/skills.mjs` | check/install/prefix skills |
| `functions/api/download/*` | download de bundles |

## Fluxo

```text
source skill/build
-> dist bundles
-> site copies dist into build data
-> download API exposes provider bundles
-> CLI installer checks/downloads/prefixes skills
-> public demos prove visual claim
```

## Regras para IA

1. Produto visual precisa demonstrar visual quality.
2. Site e demos devem passar o proprio detector.
3. Install/download path faz parte da experiencia.
4. Casos antes/depois sao evidence de mercado, nao runtime proof.

## Escopo de Implementacao

Para Atlas, criar product proof sem misturar com kernel: exemplos, docs,
detector reports, live iteration demo e command docs podem existir como surface
separada, mas readiness continua vindo de certification.

## Dependencias

Astro, assets WebP/PNG/SVG, Cloudflare Pages Functions, scripts de build,
download zips e CLI installer.

## Evidencias

Arquivos auditados:

- `site/pages/*`;
- `site/content/skills/*`;
- `site/content/tutorials/*`;
- `site/public/antipattern-examples/*`;
- `site/public/assets/*`;
- `demos/landing-demo/*`;
- `cli/bin/commands/skills.mjs`;
- `functions/api/download/*`.

## Riscos

| Risco | Mitigacao Atlas |
|---|---|
| Demo bonita sem runtime | separar product proof de certification |
| Download driftado | build/certify valida bundle hash |
| Installer altera skill errada | dry-run, prefix, rollback |
| Site vira marketing generico | usar detector/visual QA no proprio site |

## Exemplos

Impeccable publica anti-pattern examples e imagens before/after. Atlas deve ter
casos reais de frontend multiempresa com receipts, screenshots, tests e outcome.

## Proximas Acoes

1. Criar catalogo Atlas Frontend public proof.
2. Gerar demos que usem o runtime real.
3. Ligar demo/readiness a `atlas:frontend:certify`.
