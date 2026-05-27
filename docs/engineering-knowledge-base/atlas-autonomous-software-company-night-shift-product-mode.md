---
id: atlas-autonomous-software-company-night-shift-product-mode
type: engineering_knowledge
title: Atlas Autonomous Software Company Night Shift Product Mode
status: future
category: agentic-engineering
priority: 99
implementation_state: future_target_not_current_runtime
summary: Product-grade target for Night Shift: a complete software-company cockpit where the operator authorizes repositories, configures autonomy tiers, monitors 24h cycles, reviews branches, inspects evidence, controls budgets and approves or rejects changes. It is a child of Night Shift, not a new OS.
human_summary: Night Shift como produto final: um cockpit para operar uma empresa de software autonoma com seguranca.
human_what: Define UX, controles, onboarding, tiers, continuous loop e trust surfaces do produto final.
human_purpose: Transformar o loop noturno em produto completo que o operador usa como centro de comando de engenharia.
human_input: Repos, mandates, autonomy tier, budget, risk policy, findings, branches, evidence packs e decisoes do operador.
human_output: Cockpit vivo, inbox, branch review, timeline, metrics, trust score, budget state e decisions queue.
human_change_when: Mexa quando Night Shift, Atlas Code, Mission Control, Branch Sandbox, Evidence ou Holding mudarem UX/controle.
human_block_when: Bloqueie quando produto final for usado para justificar merge/deploy automatico ou pular v1/v2.
tags:
  - atlas-ai
  - night-shift
  - product-mode
  - cockpit
  - continuous-loop
  - area-focus-loop
  - area-stewardship
capabilities:
  - night_shift_product_cockpit
  - repo_onboarding_wizard
  - autonomy_tier_control
  - continuous_software_company_loop
  - area_focus_loop
  - area_stewardship_layer
  - branch_review_center
  - evidence_trust_surface
  - budget_and_kill_switch
decisions:
  - Product Mode e filho de Night Shift; nao e OS novo.
  - NS-v3 transforma janela noturna em loop 24h com budget, locks e kill switch.
  - NS-v4 entrega cockpit produto-final para operador individual.
  - NS-v5 entrega operacao multi-company autorizada.
  - Area Focus Loop permite ao operador escolher uma area canonica, como Agentic Engineering OS, para melhoria continua governada.
  - Area Stewardship Layer e o proximo nivel acima do Area Focus Loop: Atlas assume responsabilidade continua pela saude, roadmap e priorizacao da area.
  - `max_governed` significa Dev/Forge no maximo util dentro de budget, WIP, gates, branch isolation, evidence, inbox e kill switch.
  - Produto final ainda bloqueia merge, deploy, secrets e high-risk sem aprovacao.
maintenance:
  - Atualize quando Night Shift Product Cockpit, repo onboarding, autonomy tiers, budget, kill switch ou branch review mudarem.
  - Atualize quando Area Focus Loop, area_id, Dev/Forge routing, focus budgets ou area inbox mudarem.
  - Atualize quando Area Stewardship, area health model, roadmap policy ou area decision inbox mudarem.
  - Mantenha este doc como alvo de produto; implementacao operacional continua no Night Shift e owners existentes.
related_paths:
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - docs/ap/AP-711-night-shift-product-mode-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-code-long-session-programming-cockpit.md
  - docs/engineering-knowledge-base/atlas-mission-control-cockpit.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-autonomous-software-company-night-shift-product-mode
graph_title: Atlas Autonomous Software Company Night Shift Product Mode
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-software-company-night-shift
graph_status: future
graph_source: repo
canonical_source: docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
allowed_changes:
  - Refinar UX, product tiers, onboarding, controls, trust surfaces e final product maturity.
forbidden_changes:
  - Declarar produto final implementado sem Cockpit, budget, kill switch, evidence e operator review.
  - Permitir merge/deploy/secret access automatico.
depends_on:
  - atlas-autonomous-software-company-night-shift
  - atlas-code
  - atlas-evidence-certification-runtime
flows_to:
  - atlas_code
  - mission_control
  - night_shift_morning_inbox
unlocks:
  - night_shift_product_cockpit
  - continuous_software_company_product
governs:
  - atlas.night_shift.product_mode
  - atlas.night_shift.cockpit
  - atlas.night_shift.continuous_loop
  - atlas.night_shift.area_focus_loop
  - atlas.area_stewardship
evidence:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: critical
next_actions:
  - Implementar NS-v1 antes de Product Mode.
  - Criar cockpit read-only.
  - Adicionar controls: tier, budget, pause, kill switch.
---
# Atlas Autonomous Software Company Night Shift Product Mode

## Resumo

Product Mode e o alvo final do Night Shift: um produto completo onde o operador
autoriza repositorios, define autonomia, acompanha ciclos, revisa branches,
inspeciona evidence e aprova ou rejeita mudancas.

## Papel no Atlas

Ele transforma Night Shift de job/runbook em cockpit operacional. O operador
deixa de gerenciar sessoes soltas e passa a gerenciar uma fila viva de software
company: findings, specs, branches, validations, evidence e decisoes.

## Onde Se Encaixa

```text
Night Shift loop -> Product Mode Cockpit -> Operator decisions
Atlas Code / Mission Control -> UI
Evidence / Trust Ledger -> confidence
Dev / Forge / Self-Construction -> execution
```

## Contratos

### Product Ladder

```text
NS-v1 Atlas Internal Night Shift
NS-v2 External Company Night Shift
NS-v3 Continuous Software Company Loop
NS-v4 Product Cockpit
NS-v5 Multi-Company Software Company Product
```

Area Stewardship Layer fica acima do Area Focus Loop:

```text
Area Focus Loop = ciclos de melhoria por area.
Area Stewardship = responsabilidade continua pela saude, roadmap e evolucao da area.
```

### Product Surfaces

O produto final precisa de:

- Repo Onboarding Wizard;
- Autonomy Tier Selector;
- Live Operations Cockpit;
- Area Focus Command Center;
- Branch Review Center;
- Evidence Pack Inspector;
- Morning/Live Inbox;
- Budget, Rate Limit e Kill Switch;
- Risk Policy Editor;
- Promotion Gate Console;
- Outcome/Trust Dashboard.

### Autonomy Tiers

```text
Tier 0: scan only
Tier 1: scan + findings
Tier 2: spec drafts
Tier 3: branch sandbox
Tier 4: low-risk implementation
Tier 5: continuous loop
Tier 6: multi-company supervised
```

Nenhum tier autoriza merge, deploy, secrets ou destructive change por default.

### Area Focus Loop

Area Focus Loop e o modo em que o operador aponta uma area canonica e deixa o
Night Shift Product Mode operar melhoria continua naquele dominio.

Schema canonico:

```text
atlas.night_shift.area_focus_loop.v1
```

Campos minimos:

| Campo | Obrigatorio | Descricao |
|---|---|---|
| `area_id` | sim | Identificador canonico da area. Exemplo: `agentic_engineering_os`. |
| `area_name` | sim | Nome humano da area escolhida pelo operador. |
| `area_owner_docs` | sim | Docs canonicos que governam a area. |
| `repo_scope` | sim | Repos, paths e surfaces autorizados para varredura. |
| `autonomy_tier` | sim | Tier maximo permitido para a area. |
| `dev_budget` | sim | Budget dedicado para Atlas Dev. |
| `forge_budget` | sim | Budget dedicado para Forge. |
| `wip_limit` | sim | Maximo de branches/specs/findings simultaneos. |
| `risk_policy` | sim | Regras de risco, dominios sensiveis e bloqueios. |
| `stop_conditions` | sim | Condicoes que pausam o loop. |
| `inbox_destination` | sim | Inbox matinal ou live inbox para decisao humana. |

O loop deve:

1. construir mapa vivo da area a partir de owner docs, Code Intelligence,
   Evidence, AAEOS, Dev, Forge e Self-Construction;
2. varrer bugs, falhas, flakes, gaps de doc, testes ausentes, handoffs fracos,
   duplicacao, debt, regressao de UX e oportunidades de melhoria;
3. classificar cada finding por impacto, risco, confianca, blast radius e
   roteamento;
4. pedir specs ao Self-Directed Evolution quando o gap ainda nao tiver contrato;
5. mandar trabalho pequeno, local e verificavel para Atlas Dev;
6. mandar trabalho longo, multi-agente, cross-system ou de alto contexto para
   Forge;
7. criar branch/worktree isolada somente dentro do tier permitido;
8. validar build, testes, docs-health, architecture validation e evidence pack;
9. entregar resumo, spec, branch, evidence, rollback e decisao pendente no Inbox;
10. aprender com aceite/rejeicao do operador para ajustar proximas varreduras.

### Agentic Engineering OS Area

Exemplo de area de primeira classe:

```yaml
area_id: agentic_engineering_os
area_name: Agentic Engineering OS
owner_docs:
  - atlas-agentic-engineering-os.md
  - atlas-agentic-software-engineering-authority-map.md
  - atlas-autonomous-software-company-runtime.md
  - atlas-dev-efficient-programming-flow-v1.md
  - atlas-forge-operating-system.md
dev_mode: max_governed
forge_mode: max_governed
objective: melhorar continuamente todo o fluxo de desenvolvimento de software do Atlas
```

Para esta area, Product Mode deve buscar melhoria extrema no fluxo de
desenvolvimento inteiro: intake, docs, specs, AAEOS phases, Atlas Dev, Forge,
Self-Construction, Mission Control, Desktop surfaces, branch sandbox, replay,
Evidence, governance gates e Morning Inbox.

`max_governed` nao significa autonomia sem limite. Significa usar Atlas Dev e
Forge no maior throughput util permitido por budget, WIP, policy, branch
isolation, evidence, operator review e kill switch.

## Fluxo

```text
operator onboards repo
-> chooses autonomy tier
-> optionally chooses area focus
-> sets budget and risk policy
-> loop scans and works
-> cockpit shows active branches and evidence
-> operator approves, rejects or requests changes
-> outcomes feed trust and next cycle
```

## Regras para IA

IA deve tratar Product Mode como UX/controle sobre Night Shift existente. Nao
criar runtime paralelo. Nao chamar NS-v4/NS-v5 de pronto antes de existir
cockpit, kill switch, budget, branch review e evidence inspector.

## Escopo de Implementacao

Primeiro produto real:

1. Product cockpit read-only consumindo NS-v1 inbox.
2. Repo onboarding para Atlas only.
3. Autonomy tier selector travado em Tier 0-2.
4. Branch review center para branches ja criadas.
5. Evidence inspector.
6. Kill switch global.

## Dependencias

| Area | Owner |
|---|---|
| UX/cockpit | Atlas Code / Mission Control |
| Work loop | Night Shift |
| Area Focus Loop | Product Mode / Night Shift |
| Area Stewardship | Area Stewardship Layer |
| Gap/spec proposal | Self-Directed Evolution |
| Evidence | Evidence Certification Runtime |
| Execution | Dev / Forge / Self-Construction |
| Trust | Trust Ledger / Reality Outcome Gates |

## Evidencias

Product Mode so pode claimar maturidade com:

- screenshots/click tests do cockpit;
- CLI/API parity;
- evidence inspector funcional;
- kill switch testado;
- budget/rate-limit testado;
- branch review center testado;
- docs-health e architecture validation verdes.

## Riscos

| Risco | Mitigacao |
|---|---|
| Produto virar bot autonomo perigoso | tiers + kill switch |
| Usuario confiar sem evidence | evidence inspector obrigatorio |
| Branches demais | branch budget e WIP limit |
| Operacao 24h fugir do controle | rate limit + pause policy |
| Area focus virar tunel cognitivo | rotation policy + stale-area alerts |
| Dev/Forge maximo virar excesso de branches | max_governed budget + WIP + inbox |

## Exemplos

Estado final desejado:

```text
Atlas is working on 3 safe branches, watching 12 findings, paused on 2 high-risk
items, and asking for 5 operator decisions. Budget used: 38%. Kill switch: ready.
```

Area Focus Example:

```text
Area: Agentic Engineering OS. Atlas found 14 flow issues, drafted 5 specs,
opened 2 safe branches, escalated 3 long-horizon works to Forge and paused 4
high-risk items for operator decision. Dev budget: 41%. Forge budget: 28%.
```

## Proximas Acoes

1. Implementar NS-v1 antes de Product Mode.
2. Criar cockpit read-only.
3. Adicionar controls: tier, budget, pause, kill switch.
4. Adicionar Area Focus Command Center em read-only.
5. Promover para NS-v3 continuous loop.
6. Promover para NS-v4 Product Cockpit.
