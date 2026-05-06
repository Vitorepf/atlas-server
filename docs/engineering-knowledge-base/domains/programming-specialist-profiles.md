---
id: atlas-ai-programming-specialist-profiles
type: engineering_knowledge
title: Atlas AI Programming Specialist Profiles
status: active
category: architecture
priority: 97
summary: Estrutura alvo de specialist profiles dentro do Programming Domain para elevar QI bruto, qualidade de resultado, verificacao e roteamento sem criar dominios paralelos.
tags:
  - atlas-ai
  - programming
  - specialist-profiles
  - frontend
  - quality
capabilities:
  - programming_specialist_profiles
  - frontend_specialist
  - engineering_quality_routing
decisions:
  - Specialist profiles vivem dentro de Programming, nao como Atlas AI Domains separados.
  - `programming.visual` e evidencia visual existem, mas nao substituem `programming.frontend`.
  - Frontend Specialist e P1 porque fecha um gap real entre validar tela e construir frontend de alta qualidade.
  - Cada specialist profile precisa declarar contexto, ferramentas, gates, evidence e criterios de promocao.
  - Model selection por specialist profile pertence ao Atlas Decide/AP-99, nao a hardcode de surface.
  - Specialist profiles podem ser subflows formais no futuro ou profiles internos enquanto o catalogo nao for expandido.
maintenance:
  - Manter abaixo de 260 linhas.
  - Atualizar antes de criar `programming.frontend`, `programming.backend_api`, `programming.mobile` ou outro subflow especializado.
  - Nao criar novo Atlas AI Domain para especialidade de programacao sem provar que nao cabe em Programming.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/programming-power-tools-catalog.md
  - docs/engineering-knowledge-base/super-tool-runtime-core.md
  - docs/engineering-knowledge-base/atlas-ai-agent-behavior-contract.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/AiSkillStore.php
---

# Atlas AI Programming Specialist Profiles

Este documento define como o Atlas deve evoluir o Programming Domain para atingir
maximo QI bruto e qualidade de resultado sem fragmentar a arquitetura.

## Regra Mae

```text
Programming e o dominio.
Specialist Profile e a lente tecnica.
Flow e o processo executavel.
Gate e a prova.
Evidence e a memoria operacional.
```

Nao criar `frontend`, `backend`, `mobile` ou `devops` como Atlas AI Domains por
padrao. Eles sao especializacoes internas de `programming`.

## Estado Atual

O Atlas ja tem:

1. `programming.visual` para smoke visual, responsividade, screenshots e evidence;
2. `ui-verification` como skill de verificacao visual;
3. TypeScript, ESLint, Biome, Playwright, axe-core, Pa11y, Lighthouse e Visual
   Smoke no Super Tool Runtime/catalogo;
4. roteamento de `frontend`, `ui`, `visual`, `e2e` para `visual`;
5. Engineering Harness capaz de produzir evidence visual.

Gap: isso valida UI, mas ainda nao e um especialista completo em engenharia
frontend, arquitetura de componentes, estado, design system, a11y, performance e
UX implementation.

## Specialist Profiles Alvo

| Profile | Status | Papel | Prioridade |
|---|---|---|---|
| `programming.frontend` | future P1 | React/Expo/Web UI, componentes, estado, design system, a11y, visual/perf evidence | P1 |
| `programming.backend_api` | future P1 | APIs, services, contracts, auth, queues, transactions, rate limits | P1 |
| `programming.mobile` | future P1 | Expo/React Native/iOS surface, offline, push, device UX, mobile performance | P1 |
| `programming.architecture` | future P1 | boundaries, modularidade, dependency graph, coupling, ADRs, migration path | P1 |
| `programming.performance` | future P1 | profiling, latency, memory, bundle, query, queue and runtime budgets | P1 |
| `programming.accessibility` | future P1 | WCAG, axe/Pa11y, keyboard/screen reader, touch targets, contrast | P1 |
| `programming.testing` | future P2 | unit/integration/e2e/mutation/property strategy and repair loops | P2 |
| `programming.api_contract` | future P2 | OpenAPI, schema drift, consumer/provider contract, backwards compatibility | P2 |
| `programming.devops_sre` | future P2 | CI/CD, deploy, rollback, logs, alerts, infra safety | P2 |
| `programming.security_appsec` | future P2 | AppSec deeper than generic `programming.security` | P2 |
| `programming.data` | future P3 | ETL, analytics code, data contracts, migrations at scale | P3 |
| `programming.dx_docs` | future P3 | developer experience, docs, examples, onboarding, maintainability | P3 |

## Frontend Specialist Contract

`programming.frontend` deve cobrir:

1. component architecture;
2. state management and data flow;
3. design tokens/design system;
4. responsive layout and text fitting;
5. accessibility;
6. rendering performance;
7. navigation and interaction states;
8. visual regression and screenshot evidence;
9. API integration and loading/error/empty states;
10. mobile/web parity when applicable.

Default gates:

```text
typescript_or_reason
eslint_or_biome_or_reason
visual_smoke_or_reason
responsive_check
a11y_check_or_reason
state_transition_check
no_text_overlap
performance_budget_or_reason
```

Default tools:

```text
typescript, eslint/biome, atlas_visual_smoke, playwright,
axe_core/pa11y, lighthouse_ci when release/high risk
```

Frontend output must include evidence, not just prose.

A capacidade frontend/design avançada, inspirada pela avaliacao do Huashu
Design, vive em `programming-frontend-superpower.md`. Este documento define o
contrato do specialist; o outro define harness, asset protocol, visual gates,
5D critique, skill strategy e AP-99 frontend.

## Routing

Routing target:

```text
input mentions UI/frontend/component/layout/screen/mobile visual/design system
-> ai_domain=programming
-> specialist_profile=programming.frontend
-> flow=programming.visual or programming.dev initially
```

When catalog supports specialist subflows:

```text
flow=programming.frontend
```

Until then, use:

```text
flow=programming.visual
payload.specialist_profile=programming.frontend
```

## Quality Model

High-QI programming requires three passes:

1. **Architect pass**: detect owner modules, dependencies, constraints and
   non-goals.
2. **Builder pass**: implement smallest coherent change with specialist profile.
3. **Verifier pass**: run profile-specific gates and summarize residual risk.

For complex tasks, Atlas may use a temporary council:

```text
programming.architecture + relevant specialist + programming.qa/security
```

Council specialists must be scoped, temporary and evidence-bound. They do not
execute tools without a Decision Receipt.

## Model Selection

Specialist profiles descrevem necessidade tecnica; eles nao escolhem provider.
`Atlas Decide` deve usar `atlas-ai-model-selection-strategy.md`, AP-99, provider
health, policy e budget para escolher modelo por tarefa. Exemplo:
`programming.frontend` pode sinalizar multimodal/coding/a11y/performance, mas a
surface nao deve hardcodar Codex, Claude ou Gemini.

## Context Pack Additions

Programming specialist context should include:

1. touched files and neighboring files;
2. framework conventions;
3. tests and stories/routes/screens;
4. design tokens or UI primitives;
5. recent regressions and repair outcomes;
6. relevant docs from Engineering KB;
7. forbidden files and dirty-worktree warning;
8. performance/security/a11y policy for the profile.

## Implementation Roadmap

| Fase | Entrega | Status |
|---|---|---|
| PSP-0 | Doc canonica e reconhecimento do gap | active |
| PSP-1 | Frontend superpower spec e Huashu evaluation | active |
| PSP-2 | Adicionar `specialist_profile` no payload/plan de Programming | future |
| PSP-3 | Implementar `programming.frontend` como profile interno sobre `programming.visual/dev` | future |
| PSP-4 | Criar skill/harness frontend Atlas-owned sem copiar skill externa | future |
| PSP-5 | Gate frontend com visual/a11y/perf/checklist e evidence refs | future |
| PSP-6 | Expandir catalogo para subflows oficiais se o contrato estabilizar | future |
| PSP-7 | Implementar backend_api, mobile, architecture, performance e accessibility | future |

## Anti-Patterns

1. criar `frontend` como Atlas AI Domain dedicado sem Domain Onboarding;
2. tratar screenshot como prova suficiente para frontend;
3. rodar typecheck e declarar UI pronta sem visual/a11y;
4. misturar design de produto, copy, marketing e frontend sem owner;
5. criar especialistas permanentes com memoria propria fora do Programming;
6. deixar surface decidir especialista por hardcode local.

## Definition Of Done

Um specialist profile so esta pronto quando:

1. aparece em docs e no payload/plan;
2. tem roteamento deterministico;
3. declara context additions;
4. declara tools/gates/evidence;
5. tem teste de selecao;
6. tem gate ou finding real;
7. aparece no final packet/replay;
8. nao cria novo domain nem bypassa Policy/Decide.
