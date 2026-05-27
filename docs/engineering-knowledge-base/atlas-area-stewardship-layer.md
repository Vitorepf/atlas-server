---
id: atlas-area-stewardship-layer
type: engineering_knowledge
title: Atlas Area Stewardship Layer
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_not_current_runtime
summary: Canonical layer above Area Focus Loop where Atlas becomes the governed steward of a chosen area: it monitors health, detects bugs and gaps, drafts specs, prioritizes roadmap work, routes Atlas Dev/Forge execution, measures outcomes and sends operator decisions to inbox.
human_summary: Atlas deixa de apenas rodar ciclos em uma area e passa a cuidar continuamente da saude e evolucao dela.
human_what: Define o contrato de stewardship por area: health model, roadmap, priorizacao, routing Dev/Forge, evidence e inbox.
human_purpose: Fazer o operador delegar uma area inteira, como Agentic Engineering OS, para melhoria continua governada.
human_input: area_id, owner docs, repos autorizados, risk policy, budgets, WIP, evidence, outcomes e decisoes do operador.
human_output: Area health score, findings, roadmap candidates, spec drafts, Dev/Forge work orders, evidence packs e decision inbox.
human_change_when: Mexa quando Area Focus Loop, Night Shift Product Mode, Self-Directed Evolution, Atlas Dev, Forge ou Evidence mudarem responsabilidade por area.
human_block_when: Bloqueie quando IA chamar stewardship de OS novo, executor paralelo, merge/deploy automatico ou autonomia sem review.
tags:
  - atlas-ai
  - area-stewardship
  - stewardship-stack
  - area-focus-loop
  - night-shift
  - product-mode
  - agentic-engineering
capabilities:
  - area_health_model
  - area_roadmap_stewardship
  - area_finding_backlog
  - area_dev_forge_routing
  - area_evidence_pack
  - area_operator_decision_inbox
  - software_company_stewardship_stack
decisions:
  - Area Stewardship Layer e membro da Atlas Software Company Stewardship Stack.
  - Area Stewardship Layer e a evolucao canonica acima de Area Focus Loop.
  - Area Focus Loop executa ciclos; Area Stewardship assume responsabilidade continua pela area.
  - Product Mode continua sendo cockpit e controle; Night Shift continua sendo loop operacional.
  - Este layer nao e OS novo e nao cria executor paralelo.
  - Agentic Engineering OS e a primeira area alvo.
  - Atlas Dev recebe trabalho pequeno, local e verificavel.
  - Forge recebe trabalho longo, multi-agente, cross-system ou de alto contexto.
  - Self-Directed Evolution governa gaps/spec drafts antes de implementacao.
  - Evidence e Morning Inbox sao obrigatorios para qualquer claim.
maintenance:
  - Atualize quando area_id, health model, roadmap steward, routing Dev/Forge ou inbox por area mudarem.
  - Mantenha o doc pequeno; mova implementacao detalhada para APs filhos quando passar de contrato para runtime.
  - Sincronize canonical indexes apos qualquer alteracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-area-stewardship-layer
graph_title: Atlas Area Stewardship Layer
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-software-company-night-shift-product-mode
graph_status: future
graph_source: repo
human_name: Atlas Area Stewardship Layer
canonical_name: Atlas Area Stewardship Layer
technical_name: atlas-area-stewardship-layer
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
allowed_changes:
  - Refinar health model, roadmap stewardship, routing policy, evidence and inbox contracts.
forbidden_changes:
  - Criar OS novo para stewardship.
  - Criar executor paralelo a Night Shift, Dev, Forge, Self-Construction ou Evidence.
  - Declarar merge, deploy, secrets ou destructive changes automaticos.
depends_on:
  - atlas-autonomous-software-company-night-shift-product-mode
  - atlas-autonomous-software-company-night-shift
  - atlas-self-directed-evolution-layer
  - atlas-dev-efficient-programming-flow-v1
  - atlas-forge-operating-system
  - atlas-evidence-certification-runtime
flows_to:
  - stewardship_evolution_ladder
  - area_focus_loop
  - atlas_dev
  - atlas_forge
  - self_directed_evolution
  - evidence
  - morning_inbox
unlocks:
  - agentic_engineering_os_stewardship
  - governed_area_health_loop
  - autonomous_roadmap_recommendation
governs:
  - atlas.area_stewardship
  - atlas.area.health_model
  - atlas.area.roadmap
  - atlas.area.operator_inbox
  - atlas.software_company_stewardship_stack
evidence:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
quality_gates:
  - area-owner-docs-required
  - area-health-model-required
  - area-focus-loop-required
  - self-directed-spec-gate-required
  - dev-forge-routing-required
  - evidence-pack-required
  - operator-inbox-required
  - no-merge-without-operator
  - no-deploy-without-operator
  - no-secrets
failure_modes:
  - IA cria OS novo ou runtime paralelo para stewardships.
  - Area health vira score cosmetico sem evidence.
  - Roadmap vira opiniao sem findings, outcomes ou operator feedback.
  - Dev/Forge maximo gera excesso de branches sem WIP limit.
  - Stewardship tenta executar high-risk work sem review humano.
observability_signals:
  - area_id
  - area_health_score
  - area_findings_count
  - area_roadmap_candidate_count
  - area_spec_draft_count
  - area_dev_work_order_count
  - area_forge_work_order_count
  - area_evidence_pack_count
  - operator_acceptance_rate
  - false_positive_rate
next_actions:
  - Implementar read-only Area Health Model para `agentic_engineering_os`.
  - Projetar Area Steward Inbox a partir do Area Focus Loop.
  - Conectar findings a Self-Directed Evolution spec drafts.
  - Conectar work orders pequenos ao Atlas Dev e longos ao Forge.
  - Usar Atlas Stewardship Evolution Ladder para qualquer proximo patamar alem de Area Stewardship.
---
# Atlas Area Stewardship Layer

## Resumo

Atlas Area Stewardship Layer e a camada em que o operador delega uma area
canonica ao Atlas, e o Atlas passa a cuidar continuamente da saude, roadmap,
priorizacao, evidence e decisoes daquela area.

A formula curta:

```text
Area Focus Loop = melhorar uma area em ciclos.
Area Stewardship = ser responsavel pela evolucao continua da area.
```

## Papel no Atlas

Este layer fica acima do Area Focus Loop e abaixo da Autonomous Software Company
completa. Ele transforma uma area escolhida pelo operador em unidade viva de
responsabilidade.

O proximo patamar apos este layer vive em
`atlas-stewardship-evolution-ladder.md`.

Ele nao substitui Night Shift, Product Mode, Self-Directed Evolution, Dev, Forge,
Self-Construction ou Evidence. Ele coordena esses owners para uma area.

## Onde Se Encaixa

```text
Operator chooses area_id
-> Area Stewardship owns health and roadmap
-> Area Focus Loop scans and executes cycles
-> Self-Directed Evolution drafts specs for gaps
-> Atlas Dev handles small scoped work
-> Forge handles long-horizon work
-> Evidence proves outcomes
-> Morning/Live Inbox asks operator decisions
```

## Contratos

### Area Stewardship Contract

Schema canonico:

```text
atlas.area.stewardship.v1
```

Campos minimos:

| Campo | Descricao |
|---|---|
| `area_id` | Identificador canonico da area. |
| `area_name` | Nome humano. |
| `area_owner_docs` | Docs que governam a area. |
| `area_scope` | Repos, paths, flows e surfaces autorizados. |
| `stewardship_mode` | `read_only`, `proposal`, `branch_sandbox`, `active`. |
| `health_model` | Modelo de saude usado para priorizar. |
| `roadmap_policy` | Como Atlas escolhe proximos trabalhos. |
| `dev_budget` | Budget para Atlas Dev. |
| `forge_budget` | Budget para Forge. |
| `wip_limit` | Limite de specs, branches e work orders simultaneos. |
| `risk_policy` | Restrições de risco e safety. |
| `operator_inbox` | Destino de decisoes humanas. |

### Area Health Model

Schema canonico:

```text
atlas.area.health_model.v1
```

O modelo deve medir:

- bugs e regressões;
- testes falhando ou flaky;
- docs stale;
- gaps sem spec;
- flows quebrados;
- handoffs fracos;
- duplicacao de runtime;
- debt estrutural;
- qualidade de evidence;
- taxa de aceite do operador;
- falso positivo;
- outcome real.

### Stewardship Modes

```text
read_only      = mede saude e findings, nao cria spec.
proposal       = cria spec drafts e inbox, sem branch.
branch_sandbox = cria branches/worktrees seguros dentro do tier.
active         = opera Dev/Forge routing com budget, WIP, evidence e review.
```

Nenhum modo autoriza merge, deploy, secrets ou destructive changes por default.

## Fluxo

```text
area stewardship tick
-> load owner docs and code intelligence
-> compute area health
-> compare roadmap and actual evidence
-> identify findings
-> classify and dedupe
-> draft specs for uncontracted gaps
-> route small work to Atlas Dev
-> route heavy work to Forge
-> require evidence pack
-> publish operator inbox
-> learn from accepted/rejected decisions
```

## Agentic Engineering OS Como Primeira Area

Primeiro alvo canonico:

```yaml
area_id: agentic_engineering_os
area_name: Agentic Engineering OS
stewardship_mode: read_only_then_active
objective: manter e evoluir o fluxo inteiro de engenharia agentica do Atlas
owned_systems:
  - AAEOS
  - Atlas Dev
  - Atlas Forge
  - Self-Directed Evolution
  - Self-Construction
  - Evidence
  - Mission Control
  - Night Shift
authority:
  can_scan: true
  can_prioritize: true
  can_write_specs: true
  can_open_safe_branches: true
  can_route_dev_forge: true
  can_request_operator_decision: true
  cannot_merge_without_operator: true
  cannot_deploy_without_operator: true
```

## Regras Para IA

IA deve usar este doc quando o operador disser que quer Atlas cuidando de uma
area inteira. A IA deve procurar owner docs, area_id e Product Mode antes de
criar runtime novo.

Se a area for `agentic_engineering_os`, a IA deve tratar AAEOS, Dev, Forge,
Self-Directed Evolution, Self-Construction, Evidence, Mission Control e Night
Shift como um sistema integrado.

## Escopo De Implementacao

Ordem recomendada:

1. Read-only Area Health Model.
2. Area Steward Inbox.
3. Finding -> Self-Directed Evolution spec draft.
4. Dev/Forge work order routing.
5. Branch sandbox guarded by Night Shift/Product Mode.
6. Product cockpit surface.
7. Continuous active stewardship.

## Dependencias

| Area | Owner |
|---|---|
| Ciclo operacional | Night Shift / Area Focus Loop |
| Cockpit e controle | Product Mode / Mission Control |
| Gaps e specs | Self-Directed Evolution / Spec OS |
| Execucao pequena | Atlas Dev |
| Execucao longa | Forge |
| Mutacao estrutural | Self-Construction |
| Prova | Evidence Certification Runtime |

## Evidencias

Uma area so pode ser considerada stewarded quando houver:

- area health report;
- findings deduplicados;
- specs ou vetoes para gaps relevantes;
- work orders roteados;
- evidence packs;
- inbox com decisoes pendentes;
- historico de aceite/rejeicao;
- melhoria real no health score ou outcome.

## Riscos

| Risco | Mitigacao |
|---|---|
| Criar OS novo por empolgacao | Este doc declara que Stewardship e layer, nao OS. |
| Autonomia virar bot sem review | Inbox, WIP, budget, kill switch e no-merge/no-deploy. |
| Score cosmetico | Health model exige evidence e outcomes. |
| Foco excessivo em uma area | Rotation policy e stale-area alerts. |
| Dev/Forge sobrecarregados | `max_governed` com WIP e budget. |

## Exemplos

```text
Area Agentic Engineering OS: health 82/100. Gargalos principais: Forge routing,
docs stale em fases AAEOS e dois flows sem replay. Atlas recomenda 3 specs, 2
branches seguras e 1 Forge work order. 6 decisoes aguardam operador.
```

## Proximas Acoes

1. Implementar Area Health Model read-only para `agentic_engineering_os`.
2. Gerar Area Steward Inbox.
3. Conectar findings a specs revisaveis.
4. Conectar work orders a Dev/Forge.
5. Expor no Night Shift Product Mode.
