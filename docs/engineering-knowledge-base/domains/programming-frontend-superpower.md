---
id: atlas-ai-programming-frontend-superpower
type: engineering_knowledge
title: Atlas AI Programming Frontend Superpower
status: active
category: architecture
priority: 98
summary: Contrato alvo para transformar `programming.frontend` em um harness frontend/design superior a skills isoladas e ao fluxo de Claude Designer.
tags:
  - atlas-ai
  - programming
  - frontend
  - design-harness
  - huashu-design
capabilities:
  - frontend_design_harness
  - design_asset_protocol
  - visual_quality_gates
  - frontend_ap99_learning
decisions:
  - `programming.frontend` e specialist profile dentro do Programming Domain, nao novo Atlas AI Domain.
  - Atlas pode estudar Huashu Design como source material, mas nao deve copiar assets/scripts/licenca comercial restrita para produto sem revisao juridica.
  - O ganho do Atlas vem do loop completo: contexto real, assets, skill, harness, gates, AP-99, memoria e self-improvement.
  - Frontend pronto exige evidence visual, a11y, responsividade, estado, console/network e criterios esteticos; screenshot sozinho nao basta.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de implementar `programming.frontend`, design harness, skill import, visual QA ou provider benchmark frontend.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-specialist-profiles.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-ai-skill-system.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-superpower

graph_title: Atlas AI Programming Frontend Superpower

graph_world: atlas

graph_layer: system

graph_kind: module

graph_parent: atlas-ai-canonical-architecture-index

graph_status: active

graph_source: repo

owner: domains

repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md

allowed_changes:
  - Atualizar este doc quando codigo, arquitetura, fluxo, evidencia ou decisao canonica mudar.

forbidden_changes:
  - Declarar runtime, maturidade ou prontidao sem evidencia verificavel e gates verdes.

depends_on:
  - atlas-ai-documentation-operating-system

flows_to:
  - atlas-cartography
  - atlas-code

unlocks:
  - ai-safe-implementation-context

governs:
  - domains

evidence:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: medium

visual_tags:
  - system
  - module
  - domains

ai_entrypoints:
  - Leia Resumo, Contratos, Regras para IA, Evidencias e Riscos antes de implementar.

ai_usage_notes:
  - Use repo_paths, allowed_changes, forbidden_changes e required_tests como limites operacionais.

quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"

failure_modes:
  - Contexto desatualizado entre doc, codigo, teste e evidencia.

observability_signals:
  - docs-health status ok

next_actions:
  - Manter este doc sincronizado com codigo, testes, evidencias e Cartografia.
---
# Atlas AI Programming Frontend Superpower

Este documento define o caminho para o Atlas Frontend ter capacidade muito acima
de uma skill isolada de design. A tese: Claude Designer/Huashu vencem por bons
prompts e componentes; Atlas deve vencer por **sistema fechado de qualidade**.

## Fonte Avaliada

Huashu Design (`github.com/alchaincyf/huashu-design`) e valioso como source
material porque combina:

1. design context first;
2. fact verification before assumptions;
3. Core Asset Protocol;
4. 5-10-2-8 asset quality rule;
5. design direction advisor;
6. junior designer workflow;
7. variations and tweaks;
8. starter components;
9. Playwright verification;
10. video/PPTX/PDF export;
11. 5-dimension critique.

Restricao: o README declara uso pessoal livre e uso enterprise/comercial
restrito por autorizacao. Atlas pode absorver principios e criar implementacao
propria, mas nao deve vender/distribuir copia direta do skill/assets/scripts sem
revisao e permissao.

## O Que Vale Absorver

| Ideia | Valor Para Atlas | Como Incorporar |
|---|---|---|
| Fact verification first | evita produto/versao falsa e retrabalho | preflight de frontend/design quando houver produto, marca, versao ou evento recente |
| Core Asset Protocol | design cresce de assets reais | `FrontendAssetPack`: logo, produto, UI screenshots, tokens, fontes, guidelines, fontes citadas |
| 5-10-2-8 assets | impede visual mediocre | gate de asset quality antes de hero/media decisivo |
| Design context first | reduz generic AI slop | context pack le design system, screenshots, rotas, componentes, tokens, concorrentes |
| Direction advisor | resolve briefs vagos | gerar 3 direcoes distintas antes de implementar quando escopo e nebuloso |
| Junior designer workflow | reduz erro cedo | assumptions -> placeholder -> variation -> polish -> verify |
| Tweaks/variations | explora espaco visual | gerar variantes controladas e persistir escolhas no evidence |
| Starter components | acelera craft | Atlas-owned components para device frames, decks, browser windows, animation stage |
| Playwright verification | transforma gosto em evidence | screenshots multi-viewport, console, click/state, visual diff |
| 5D critique | revisão estetica estruturada | score: filosofia, hierarquia, craft, funcionalidade, originalidade |

## O Que Nao Copiar Cegamente

1. HTML-only como resposta universal: Atlas precisa produzir app real quando o
   repo e React/Expo/Vue/Laravel Blade, nao prototipo solto.
2. Assets/scripts licenciados para uso comercial restrito sem permissao.
3. Brand-from-zero como qualidade final; sem contexto, deve virar exploration
   ou pedir material, nao declarar final.
4. PPTX/video workflow dentro do frontend app sem separar output type.
5. Score estetico sem gates tecnicos: typecheck, lint, a11y, performance e
   estado continuam obrigatorios.

## Frontend Harness Alvo

```text
Input
-> Frontend Intent
-> Design/Product Context Pack
-> FrontendAssetPack
-> Atlas Decide model/profile
-> Builder/Designer pass
-> Variant pass when useful
-> Visual/A11y/Perf/State Gates
-> 5D Critique
-> Repair Loop
-> Evidence Ledger + AP-99
-> Output patch/prototype/report
```

Estado backend atual: `programming.frontend` e flow governado do Programming
Domain. `AtlasProgrammingOrchestrator` emite
`atlas.programming.frontend_design_harness.v1` em planos, dispatch e completion
quando a tarefa envolve frontend/design. O contrato exige context pack frontend,
gates de visual/a11y/perf/state, asset provenance, 5D critique e receipts antes
de qualquer claim de done. O craft visual final pode ser delegado a Claude ou
outro provider, mas a autoridade de fluxo, gates e evidencia fica no Atlas.

O harness deve entender quatro entregas diferentes:

| Output Type | Runtime | Gates |
|---|---|---|
| production UI patch | repo framework | type/lint/tests/visual/a11y/perf |
| clickable prototype | HTML/React sandbox | Playwright click/state + screenshot |
| motion/design asset | HTML animation | render/video verification + asset provenance |
| deck/infographic | HTML-first deck | slide QA + export QA + typography |

## Context Pack Frontend

O context pack deve incluir:

1. framework, routes, components, design tokens and CSS strategy;
2. existing UI screenshots and design system docs;
3. target viewport/device matrix;
4. user-provided screenshots/images;
5. brand/product assets with provenance;
6. accessibility baseline;
7. performance budget and bundle constraints;
8. console/network errors from current page when available;
9. prior visual regressions and repair outcomes;
10. relevant skills activated and versions.

## Gates Obrigatorios

```text
typescript_or_reason
eslint_or_biome_or_reason
console_error_check
visual_smoke_multi_viewport
no_text_overlap
responsive_check
a11y_check_or_reason
state_transition_check
asset_provenance_check
design_5d_review
performance_budget_or_reason
```

Para release/high-risk, adicionar Lighthouse, visual baseline strict e review
humano quando houver mudanca visual ampla.

## Model Selection

`programming.frontend` deve sinalizar para Atlas Decide:

```text
needs_multimodal=true when screenshots/assets exist
needs_visual_reasoning=true for layout/design/craft
needs_code_patch=true for production UI
needs_long_context=true for design system/codebase audit
specialist_profile=programming.frontend
```

Decide escolhe provider/modelo via AP-99. Nao hardcodar "frontend sempre Claude",
"sempre Codex" ou "sempre Gemini"; o vencedor precisa ser medido por tarefa,
framework, output type, repair rate, visual score, bugs e custo.

## Skill Strategy

Huashu-like skill deve entrar como `frontend-design-harness` Atlas-owned:

1. provider-neutral;
2. versionada e hashada;
3. com allowed tools declaradas;
4. com tests/evals de prompt;
5. com licenca propria segura;
6. integrada ao Evidence Ledger;
7. ativada por specialist profile, nao por surface manual.

External skills podem ser usadas como referencia ou skill pessoal local, mas o
produto Atlas precisa de skill propria para evitar dependencia/licenca/confusao.

## AP-99 Frontend Metrics

Registrar por run:

```text
provider/model, output_type, framework, specialist_profile,
asset_pack_quality, visual_5d_score, gate_pass_rate, repair_iterations,
console_errors, a11y_findings, responsive_failures, human_correction,
latency, cost, accepted_by_operator
```

Essas metricas alimentam Self-Improvement e Model Selection.

## Roadmap

| Fase | Entrega |
|---|---|
| AFD-0 | Doc canonica e Huashu evaluation |
| AFD-1 | `FrontendAssetPack` schema + provenance |
| AFD-2 | `programming.frontend` payload/profile routing |
| AFD-3 | Atlas-owned frontend design skill |
| AFD-4 | Frontend harness com Playwright multi-viewport |
| AFD-5 | 5D critique gate + no-text-overlap gate |
| AFD-6 | AP-99 frontend rollups |
| AFD-7 | Variant/council workflow para tarefas complexas |

## Definition Of Done

Atlas Frontend so pode ser chamado superior quando:

1. produz patch/prototype com evidence reproduzivel;
2. passa visual/a11y/perf/state gates;
3. usa assets reais ou declara ausencia;
4. mede provider/modelo por AP-99;
5. aprende com correcoes humanas;
6. evita duplicar design/product/marketing fora do Programming;
7. deixa replay no Evidence Ledger.

## Resumo

Contrato alvo para transformar `programming.frontend` em um harness frontend/design superior a skills isoladas e ao fluxo de Claude Designer.

## Papel no Atlas

Define a responsabilidade desta peca dentro da arquitetura Atlas.

## Onde Se Encaixa

Relaciona esta peca com seu sistema, camada, fluxo ou modulo pai.

## Contratos

Declara invariantes, entradas, saidas, limites e obrigacoes relevantes.

## Fluxo

Descreve o caminho operacional ou a sequencia de uso quando aplicavel.

## Regras para IA

Agentes devem respeitar escopo, evidencias, testes e proibicoes antes de alterar codigo.

## Escopo de Implementacao

Mudancas devem permanecer nos caminhos e limites declarados no frontmatter.

## Dependencias

Dependencias canonicas vivem em frontmatter e no corpo deste documento.

## Evidencias

Evidencias aceitas incluem docs, comandos, testes, receipts, reports e paths verificaveis.

## Riscos

Riscos principais devem ser tratados antes de promover status, runtime ou claims de prontidao.

## Exemplos

Exemplos concretos devem ser adicionados quando reduzirem ambiguidade para humanos ou IAs.

## Proximas Acoes

Proximas acoes devem ser concretas, verificaveis e ligadas a gates de qualidade.
