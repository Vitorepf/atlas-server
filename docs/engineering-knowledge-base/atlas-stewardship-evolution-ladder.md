---
id: atlas-stewardship-evolution-ladder
type: engineering_knowledge
title: Atlas Stewardship Evolution Ladder
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_not_current_runtime
summary: Canonical evolution ladder beyond Area Focus Loop and Area Stewardship: Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. It defines how Atlas grows from improving one area to governing a portfolio, making executive tradeoffs and proposing new areas under operator review.
human_summary: Escada canonica da autonomia de stewardship: de uma area ate uma empresa de software que se expande com governanca.
human_what: Define os proximos patamares depois de Area Stewardship sem criar OS novo.
human_purpose: Dar uma rota clara para autonomia mais absurda sem duplicacao, sprawl ou perda de controle humano.
human_input: Areas, portfolio, health scores, roadmap candidates, budgets, risks, outcomes, operator feedback e company objectives.
human_output: Portfolio health, executive recommendations, resource allocation, new-area proposals, evidence packs e decision inbox.
human_change_when: Mexa quando Area Stewardship, Night Shift Product Mode, Autonomous Software Company Runtime, Self-Directed Evolution, Dev, Forge ou Evidence mudarem autonomia de portfolio.
human_block_when: Bloqueie quando IA tentar declarar Portfolio/Executive/Self-Expanding como implementado sem runtime evidence, ou criar OS/executor paralelo.
tags:
  - atlas-ai
  - stewardship
  - portfolio-stewardship
  - autonomous-executive
  - self-expanding-software-company
  - stewardship-stack
capabilities:
  - stewardship_evolution_ladder
  - portfolio_stewardship
  - autonomous_executive_layer
  - self_expanding_software_company
  - portfolio_health_model
  - executive_decision_inbox
  - software_company_stewardship_stack
decisions:
  - Esta ladder e membro futuro da Atlas Software Company Stewardship Stack.
  - A evolucao alem de Area Stewardship e uma ladder canonica, nao um OS novo.
  - Portfolio Stewardship coordena multiplas areas e dependencias entre elas.
  - Autonomous Executive Layer define estrategia, orcamento, cadencia, tradeoffs e alocacao de energia.
  - Self-Expanding Software Company propoe nascimento de novas areas quando o portfolio exige.
  - Nenhuma camada autoriza merge, deploy, secrets, destructive changes ou auto-promocao sem operador.
  - Agentic Engineering OS continua sendo a primeira area para provar a ladder.
maintenance:
  - Atualize quando novos niveis de stewardship forem promovidos, implementados ou renomeados.
  - Mantenha este doc como ladder; detalhes de runtime devem nascer em APs filhos.
  - Nao transforme nomes futuros em claims de implementacao atual.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-714-stewardship-evolution-ladder-contract.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-stewardship-evolution-ladder
graph_title: Atlas Stewardship Evolution Ladder
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-area-stewardship-layer
graph_status: future
graph_source: repo
human_name: Atlas Stewardship Evolution Ladder
canonical_name: Atlas Stewardship Evolution Ladder
technical_name: atlas-stewardship-evolution-ladder
cartography_type: roadmap
canonical_source: docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
allowed_changes:
  - Refinar ladder, schemas, gates, promotion criteria and future layer boundaries.
forbidden_changes:
  - Declarar Portfolio/Executive/Self-Expanding implementado sem runtime evidence.
  - Criar OS novo ou runtime paralelo.
  - Autorizar mudancas irreversiveis sem operador.
depends_on:
  - atlas-area-stewardship-layer
  - atlas-autonomous-software-company-night-shift-product-mode
  - atlas-autonomous-software-company-runtime
  - atlas-self-directed-evolution-layer
flows_to:
  - area_stewardship
  - portfolio_stewardship
  - autonomous_executive
  - self_expanding_software_company
unlocks:
  - portfolio_health_model
  - executive_decision_inbox
  - new_area_proposal_gate
governs:
  - atlas.stewardship_evolution
  - atlas.portfolio_stewardship
  - atlas.autonomous_executive
  - atlas.self_expanding_software_company
  - atlas.software_company_stewardship_stack
evidence:
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
quality_gates:
  - ladder-owner-docs-required
  - no-new-os
  - no-parallel-executor
  - no-permissionless-autonomy
  - promotion-evidence-required
  - operator-approval-required
failure_modes:
  - IA confunde nome futuro com runtime pronto.
  - Portfolio Stewardship duplica Autonomous Software Company Runtime.
  - Autonomous Executive vira decisor sem Evidence ou operator inbox.
  - Self-Expanding cria areas sem Domain Runtime Creation Gate.
observability_signals:
  - stewardship_level
  - portfolio_health_score
  - area_dependency_count
  - executive_recommendation_count
  - resource_allocation_change_count
  - new_area_proposal_count
  - operator_acceptance_rate
next_actions:
  - Implementar Area Stewardship read-only antes de Portfolio.
  - Definir Portfolio Health Model.
  - Definir Executive Decision Inbox.
  - Definir New Area Proposal Gate integrado ao Domain Runtime Creation Gate.
---
# Atlas Stewardship Evolution Ladder

## Resumo

Atlas Stewardship Evolution Ladder define a evolucao canonica da autonomia de
stewardship. Ela responde ate onde o Atlas pode ir depois de cuidar de uma area:
cuidar de um portfolio, tomar decisoes executivas sob review e propor novas
areas quando a empresa de software precisar.

## Papel no Atlas

Este doc e um roadmap/contrato de futuro. Ele nao implementa runtime e nao cria
OS novo. Ele impede que IAs inventem nomes concorrentes quando o operador pedir
"algo mais absurdo" depois de Area Stewardship.

## Onde Se Encaixa

```text
Night Shift Product Mode
-> Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

## Contratos

### Nivel 1: Area Focus Loop

Atlas melhora uma area em ciclos. O operador escolhe `area_id`; Atlas varre,
classifica findings, drafts specs, roteia trabalho e entrega inbox.

### Nivel 2: Area Stewardship Layer

Atlas se torna steward de uma area. Ele mantem health model, roadmap local,
priorizacao, evidence, learning e inbox da area.

### Nivel 3: Portfolio Stewardship Layer

Atlas governa um conjunto de areas e suas dependencias. Ele compara health,
gargalos, ROI, risco, desbloqueios e custo de oportunidade entre areas.

Schema:

```text
atlas.portfolio.stewardship.v1
```

Campos minimos:

| Campo | Descricao |
|---|---|
| `portfolio_id` | Identificador do portfolio. |
| `areas` | Areas stewarded e seus health models. |
| `dependency_graph` | Dependencias entre areas. |
| `portfolio_objective` | Objetivo global. |
| `resource_policy` | Como distribuir Dev, Forge, budget e WIP. |
| `rebalance_policy` | Quando pausar, acelerar ou trocar foco. |
| `operator_inbox` | Decisoes executivas que exigem humano. |

### Nivel 4: Autonomous Executive Layer

Atlas age como executivo operacional governado. Ele decide recomendacoes de
estrategia, cadencia, orcamento, timing, tradeoffs e alocacao de capacidade.

Schema:

```text
atlas.executive.recommendation.v1
```

Ele pode recomendar:

- onde alocar Claudes/Codex/Forge;
- qual area pausar;
- qual area acelerar;
- qual roadmap item tem maior unlock;
- qual risco precisa de decisao humana;
- qual investimento melhora o portfolio inteiro.

Ele nao pode executar decisao irreversivel sem operador.

### Nivel 5: Self-Expanding Software Company

Atlas detecta que o portfolio precisa de uma area nova e propoe o nascimento
dela. Isso nao cria area automaticamente.

Schema:

```text
atlas.software_company.new_area_proposal.v1
```

A proposta deve incluir:

- area candidata;
- lacuna observada;
- evidence;
- owner docs sugeridos;
- health model inicial;
- roadmap inicial;
- risk policy;
- Domain Runtime Creation Gate quando virar dominio;
- operator approval.

## Fluxo

```text
area health reports
-> portfolio dependency graph
-> portfolio health model
-> executive recommendations
-> operator decision inbox
-> approved actions route to Area Stewardship / Dev / Forge / Self-Construction
-> outcomes update portfolio memory
```

## Regras Para IA

IA deve tratar esta ladder como mapa de futuro. Se o operador pedir "mais
autonomia", "proximo patamar", "mais absurdo" ou "empresa rodando sozinha", a IA
deve apontar para esta ladder antes de criar docs novos.

Proibido:

- chamar Portfolio/Executive/Self-Expanding de pronto sem evidence;
- criar OS novo;
- criar executor paralelo;
- pular Product Mode, Area Stewardship, Evidence ou operator inbox;
- criar novas areas sem proposal gate.

## Escopo De Implementacao

Ordem obrigatoria:

1. Area Stewardship read-only para `agentic_engineering_os`.
2. Area Stewardship active com Dev/Forge routing.
3. Portfolio Health Model read-only.
4. Portfolio Steward Inbox.
5. Executive Recommendation read-only.
6. Executive Decision Inbox.
7. New Area Proposal Gate.
8. Self-Expanding Software Company v0 proposal-only.

## Dependencias

| Camada | Owner |
|---|---|
| Ciclos | Night Shift Product Mode |
| Uma area | Area Stewardship Layer |
| Varias areas | Portfolio Stewardship Layer |
| Recomendacao executiva | Autonomous Executive Layer |
| Novas areas | Self-Expanding Software Company + Domain Runtime Creation Gate |
| Specs | Self-Directed Evolution |
| Execucao | Atlas Dev / Forge |
| Prova | Evidence Certification Runtime |

## Evidencias

Cada nivel so pode ser promovido com:

- doc/AP aceito;
- runtime ou read-model testado;
- evidence pack;
- operator inbox;
- acceptance/rejection history;
- docs-health e architecture validation verdes;
- prova de que nao criou runtime paralelo.

## Riscos

| Risco | Mitigacao |
|---|---|
| Nome grandioso virar claim falso | `implementation_state: future_target_not_current_runtime`. |
| Portfolio duplicar empresa autonoma | Portfolio e decisao entre areas; Company Runtime coordena organizacao. |
| Executive virar auto-CEO perigoso | Recomendacao e inbox, nao decisao irreversivel. |
| Self-Expanding virar sprawl | New Area Proposal Gate + operator approval. |

## Exemplos

Portfolio:

```yaml
portfolio_id: atlas_software_company
areas:
  - agentic_engineering_os
  - atlas_dev
  - atlas_forge
  - self_directed_evolution
  - evidence
objective: maximizar evolucao do Atlas como empresa de software autonoma
```

Executive recommendation:

```text
Recomendo investir 2 ciclos em Forge routing e 1 ciclo em Evidence Replay.
Motivo: isso desbloqueia 4 areas e aumenta o portfolio health estimado de 78
para 86. Desktop UI deve esperar porque depende desses fluxos.
```

## Proximas Acoes

1. Implementar Area Stewardship read-only.
2. Criar Portfolio Health Model.
3. Criar Executive Recommendation read-model.
4. Criar New Area Proposal Gate proposal-only.
