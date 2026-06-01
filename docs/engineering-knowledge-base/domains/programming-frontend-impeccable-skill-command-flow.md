---
id: atlas-ai-programming-frontend-impeccable-skill-command-flow
type: engineering_knowledge
title: Impeccable Skill And Command Flow
status: active
category: architecture
priority: 96
summary: Dissecacao da skill, referencias, comandos e fluxo de design do Impeccable para orientar Atlas Frontend.
tags:
  - atlas-ai
  - programming
  - frontend
  - impeccable
  - skill-flow
capabilities:
  - frontend_skill_command_teardown
  - design_command_taxonomy
decisions:
  - O valor do Impeccable vem da combinacao de contexto, lanes brand/product, comandos e referencias especializadas.
  - Atlas deve converter comandos em intents/runtimes governados, nao copiar slash commands.
maintenance:
  - Atualizar quando novos comandos ou referencias aparecerem no benchmark.
related_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-competitive-teardown.md
  - docs/engineering-knowledge-base/domains/programming-frontend-superpower.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-ai-programming-frontend-impeccable-skill-command-flow
graph_title: Impeccable Skill And Command Flow
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-ai-programming-frontend-impeccable-competitive-teardown
graph_status: active
graph_source: repo
human_name: Impeccable Skill And Command Flow
canonical_name: Impeccable Skill And Command Flow
technical_name: atlas-ai-programming-frontend-impeccable-skill-command-flow
cartography_type: module
canonical_source: docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
owner: domains
repo_paths:
  - docs/engineering-knowledge-base/domains/programming-frontend-impeccable-skill-command-flow.md
allowed_changes:
  - Atualizar comando, referencia e fluxo quando houver nova auditoria.
forbidden_changes:
  - Declarar comandos Atlas implementados apenas porque estao documentados aqui.
depends_on:
  - atlas-ai-programming-frontend-impeccable-competitive-teardown
flows_to:
  - programming.frontend
unlocks:
  - atlas-frontend-design-runtime
governs:
  - domains
evidence:
  - skill/SKILL.md
  - skill/reference/*.md
  - skill/scripts/command-metadata.json
evidence_refs:
  - symbol: AtlasProgrammingFrontendImpeccableSkillCommandFlowService
  - command: atlas:aaeos:programming-frontend-impeccable-skill-command-flow
  - test: AtlasProgrammingFrontendImpeccableSkillCommandFlowTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
visual_tags:
  - system
  - module
  - frontend
ai_entrypoints:
  - Leia para entender a taxonomia de design que Atlas deve transformar em runtime.
ai_usage_notes:
  - Trate comandos como padroes de produto e nao como API final do Atlas.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
failure_modes:
  - Copiar vocabulario sem enforcement.
observability_signals:
  - docs-health status ok
next_actions:
  - Mapear comandos para `atlas:frontend:*` e AEDPDS gates.
---
# Impeccable Skill And Command Flow

## Resumo

`skill/SKILL.md` e a doutrina principal. Ele exige contexto antes de edicoes,
separa brand/product register, aplica leis compartilhadas de design e roteia 23
subcomandos.

## Papel no Atlas

Atlas deve absorver a taxonomia como intents operacionais de
`programming.frontend`: `teach`, `shape`, `craft`, `live`, `audit`, `critique`,
`polish`, `harden`, `extract` e `document` viram runtimes/gates/evidence.

## Onde Se Encaixa

Dentro de `programming.frontend`, acionado pelo AEDPDS quando uma tarefa envolve
UI, design system, landing, dashboard, app shell, responsividade, copy ou
visual polish.

## Contratos

Contexto obrigatorio:

| Arquivo | Obrigatoriedade | Papel |
|---|---|---|
| `PRODUCT.md` | requerido | usuarios, proposito, register, brand, anti-referencias |
| `DESIGN.md` | recomendado | tokens, tipografia, cores, componentes e do/don't |

Referencias principais: `brand`, `product`, `craft`, `shape`, `teach`,
`document`, `extract`, `critique`, `audit`, `live`, `typography`,
`color-and-contrast`, `motion-design`, `spatial-design`, `interaction-design`,
`responsive-design`, `ux-writing`, `cognitive-load`, `heuristics-scoring` e
`personas`.

## Fluxo

```text
load PRODUCT/DESIGN
-> infer brand/product register
-> load matching reference
-> route command
-> load command reference
-> execute with stops/gates
-> verify visually/technically
```

## Regras para IA

1. Nao trabalhar sem carregar contexto.
2. `PRODUCT.md` ausente bloqueia e chama `teach`.
3. `DESIGN.md` ausente gera nudge para `document`.
4. Brand e product usam criterios diferentes.
5. `craft` nao pula `shape`.
6. Quando Codex tem image generation, `craft` para em quatro gates antes de
   codigo: perguntas, paleta, mocks, direcao aprovada.
7. Screenshot sem leitura/inspecao nao conta como evidencia.

## Escopo de Implementacao

Para Atlas, criar:

- `FrontendProjectContext` equivalente a PRODUCT/DESIGN;
- `FrontendCommandIntent` para cada familia;
- selector brand/product;
- gates de shape, visual direction, build, inspect e harden.

## Dependencias

`load-context.mjs`, `command-metadata.json`, referencias Markdown, scripts de
live/detector, `HARNESSES.md` e o subagente Codex
`impeccable_asset_producer`.

## Evidencias

O metadata lista os comandos e quando usar. Familias:

| Familia | Comandos |
|---|---|
| Build | `craft`, `teach`, `document`, `extract`, `shape` |
| Evaluate | `critique`, `audit` |
| Refine | `polish`, `bolder`, `quieter`, `distill`, `harden`, `onboard` |
| Enhance | `animate`, `colorize`, `typeset`, `layout`, `delight`, `overdrive` |
| Fix | `clarify`, `adapt`, `optimize`, `live` |

## Riscos

| Risco | Mitigacao Atlas |
|---|---|
| Prompt bonito sem runtime | AEDPDS gate e certification |
| Contexto generico para empresa real | Project onboarding por empresa |
| Comando vira magia solta | Intent + schema + evidence |
| Design exagerado em produto operacional | Brand/product lane |

## Exemplos

Landing nova: `teach -> shape -> craft -> visual inspect -> audit`.  
Dashboard confuso: `critique -> layout -> clarify -> harden -> audit`.  
Design system drift: `document -> extract -> polish -> audit`.
Craft com mock aprovado: `shape -> codex visual gates -> asset producer ->
build -> visual inspect`.

## Proximas Acoes

1. Criar mapeamento `impeccable command -> Atlas runtime`.
2. Adicionar gates de contexto frontend ao `AtlasProgrammingOrchestrator`.
3. Medir comandos em corpus frontend Atlas Forge Rivals.
