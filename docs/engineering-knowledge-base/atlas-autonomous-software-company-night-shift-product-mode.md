---
id: atlas-autonomous-software-company-night-shift-product-mode
type: engineering_knowledge
title: Atlas Autonomous Software Company Night Shift Product Mode
status: active
category: agentic-engineering
priority: 99
implementation_state: partial_runtime_with_future_scope
summary: Product-grade target for Night Shift and Atlas Continuous Stewardship Loop: a software-company cockpit where the operator authorizes repositories, configures autonomy tiers, monitors 24h cycles, reviews branches, inspects evidence, controls budgets and approves or rejects changes. The current implemented slice is a read-only Product Mode/Cockpit over Area Focus, Executive, Self-Expanding, Continuous Stewardship, Dev/Forge release, owner-result, AP-752 allocation-handoff state, AP-754 operational controls read model, AP-755 receipt-backed controls and AP-756 operator-receipted branch sandbox materialization. It is a Product Mode/control surface inside the Stewardship Stack, not a new OS.
human_summary: Produto final de stewardship: um cockpit para operar uma empresa de software autonoma com seguranca.
human_what: Define UX, controles, onboarding, tiers, Atlas Continuous Stewardship Loop e trust surfaces do produto final.
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
  - continuous-stewardship-loop
  - continuous-loop
  - area-focus-loop
  - area-stewardship
  - stewardship-stack
capabilities:
  - night_shift_product_cockpit
  - repo_onboarding_wizard
  - autonomy_tier_control
  - continuous_stewardship_loop
  - continuous_software_company_loop
  - area_focus_loop
  - area_stewardship_layer
  - software_company_stewardship_stack
  - branch_review_center
  - evidence_trust_surface
  - budget_and_kill_switch
decisions:
  - Product Mode e filho de Night Shift; nao e OS novo.
  - Product Mode e membro da Atlas Software Company Stewardship Stack.
  - Atlas Continuous Stewardship Loop e o nome canonico do modo 24h/always-on com budget, locks, rate limits, pause policy, inbox e kill switch.
  - NS-v3 Continuous Loop e apenas alias historico/transicional; IAs nao devem usar NS-v3 como nome canonico.
  - NS-v4 entrega cockpit produto-final para operador individual.
  - NS-v5 entrega operacao multi-company autorizada.
  - Area Focus Loop permite ao operador escolher uma area canonica, como Agentic Engineering OS, para melhoria continua governada.
  - Area Stewardship Layer e o proximo nivel acima do Area Focus Loop: Atlas assume responsabilidade continua pela saude, roadmap e priorizacao da area.
  - AP-747 e o controle de release operator-owned que transforma handoff AP-726 em fila real Atlas Dev/Forge; AP-748 torna o release visivel em Evidence, Morning Inbox e Portfolio sem criar branch, provider, merge, deploy ou secrets.
  - AP-739/AP-753 tornam Product Mode/Cockpit um agregador read-only dos estados de review, incluindo AP-752 allocation handoffs; o cockpit nao executa.
  - AP-754 adiciona o primeiro read model de controles operacionais: repo onboarding state, autonomy tier, budget/rate limit, branch review, evidence inspector e kill switch aparecem no cockpit sem mutar politica.
  - AP-755 registra controles do Product Mode como receipts AP-731 append-only e permite `--use-recorded-controls`, sem criar ledger paralelo nem executar trabalho.
  - AP-756 cria branch/worktree local isolado somente com receipt explicito; o cockpit pode mostrar o sandbox, mas continua bloqueando Dev/Forge dispatch, provider, merge, deploy e secrets.
  - AP-793 normaliza o substrato isolado por tras do Product Mode: provider port, sandbox provider, worktree lifecycle, branch strategy, session store e result parser sao vistos no cockpit como estado operacional, mas a surface continua sem executar.
  - `max_governed` significa Dev/Forge no maximo util dentro de budget, WIP, gates, branch isolation, evidence, inbox e kill switch.
  - Produto final ainda bloqueia merge, deploy, secrets e high-risk sem aprovacao.
maintenance:
  - Atualize quando Night Shift Product Cockpit, repo onboarding, autonomy tiers, budget, kill switch ou branch review mudarem.
  - Atualize quando Area Focus Loop, area_id, Dev/Forge routing, focus budgets ou area inbox mudarem.
  - Atualize quando Area Stewardship, area health model, roadmap policy ou area decision inbox mudarem.
  - Mantenha este doc como alvo de produto; implementacao operacional continua no Night Shift e owners existentes.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/ap/AP-712-night-shift-area-focus-loop-contract.md
  - app/Services/Ai/NightShift/AreaFocusLoopReadModelService.php
  - app/Services/Ai/NightShift/AtlasNightShiftAreaFocusContractRegistry.php
  - app/Console/Commands/AtlasNightShiftAreaFocusCommand.php
  - tests/Unit/Ai/NightShift/AreaFocusLoopReadModelServiceTest.php
  - docs/ap/AP-721-area-focus-product-mode-surface-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-793-atlas-isolated-agent-execution-substrate-contract.md
  - app/Services/Ai/SoftwareCompany/AreaFocusProductModeSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/AreaFocusController.php
  - tests/Feature/Ai/SoftwareCompany/AreaFocusControllerTest.php
  - tests/Unit/Ai/SoftwareCompany/AreaFocusProductModeSurfaceServiceTest.php
  - docs/ap/AP-726-area-focus-branch-sandbox-preflight-handoff-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxPreflightServiceTest.php
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseServiceTest.php
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
graph_status: active
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
  - continuous_stewardship_loop
  - continuous_software_company_product
governs:
  - atlas.night_shift.product_mode
  - atlas.night_shift.cockpit
  - atlas.continuous_stewardship_loop
  - atlas.night_shift.continuous_loop
  - atlas.night_shift.area_focus_loop
  - atlas.area_stewardship
  - atlas.software_company_stewardship_stack
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

Quando a operacao deixa de ser janela noturna/agendada e passa a rodar o tempo
inteiro, o nome canonico nao e mais Night Shift. O nome canonico e:

```text
Atlas Continuous Stewardship Loop
```

Product Mode e o cockpit/controle desse loop 24h: budget, locks, rate limits,
pause policy, kill switch, inbox, evidence e review humano.

## Onde Se Encaixa

```text
Night Shift scheduled loop -> Product Mode Cockpit -> Operator decisions
Atlas Continuous Stewardship Loop -> 24h governed operation
Atlas Code / Mission Control -> UI
Evidence / Trust Ledger -> confidence
Dev / Forge / Self-Construction -> execution
```

## Contratos

### Product Ladder

```text
NS-v1 Atlas Internal Night Shift
NS-v2 External Company Night Shift
Atlas Continuous Stewardship Loop (legacy alias: NS-v3 Continuous Loop)
NS-v4 Product Cockpit
NS-v5 Multi-Company Software Company Product
```

### Naming Boundary

`Night Shift` significa ciclo agendado/noturno/batch. `Atlas Continuous
Stewardship Loop` significa operacao 24h/always-on governada. O termo `NS-v3`
so pode aparecer como alias historico para compatibilidade com docs antigos,
nunca como nome canonico novo.

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
Tier 5: Atlas Continuous Stewardship Loop
Tier 6: multi-company supervised
```

Nenhum tier autoriza merge, deploy, secrets ou destructive change por default.

### Area Focus Loop

Area Focus Loop e o modo em que o operador aponta uma area canonica e deixa o
Product Mode operar melhoria continua naquele dominio. Ele pode ser executado
por Night Shift quando a operacao for agendada, ou pelo Atlas Continuous
Stewardship Loop quando a operacao for 24h/always-on.

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

### Implementacao v0.1 (read-only, AP-712)

`AreaFocusLoopReadModelService::project(array $input = [])` (namespace canonico
`App\Services\Ai\NightShift`, com `area_id` dentro de `$input`) entrega o
**Area Focus Command Center read-only** para `agentic_engineering_os`. Schema
canonico `atlas.night_shift.area_focus_loop.v1`. Implementacao unica e canonica
(consolidacao de variantes concorrentes anteriores). Nao e OS novo, nao e
runtime paralelo, nao executa nada.

Reuso de owners (sem duplicacao):

| Passo | Owner reusado |
|---|---|
| Contrato da area | `AtlasNightShiftAreaFocusContractRegistry::resolve()` (11 campos AP-712) |
| Scan da area | `SelfDirectedEvolutionGapReadModelService::project()` (read-only, gap owner canonico) |
| Sinal complementar (opcional, AP-717) | `AgenticEngineeringOsFindingEngineService::scan()` (off por padrao; `include_area_findings`/`area_findings`) |
| Spec proposal | `SelfDirectedSpecProposalAdapter` (alvo de rota advisory, nao invocado) |
| Trabalho pequeno/local | `AtlasDevRuntimeService` (alvo de rota advisory, nao invocado) |
| Trabalho longo/multi-agente | `AtlasForgeParallelDurableCoordinatorService` (alvo de rota advisory, nao invocado) |
| Evidence | Evidence Certification Runtime (requisito declarado) |

Roteamento deterministico **advisory** (nada e despachado): critical/sensivel ->
`inbox_only`; AAEL -> `atlas_forge`; gap de Self-Construction sem contrato ->
`self_directed_evolution`; resto pequeno/local -> `atlas_dev`. O WIP limit
(`max_branches`) corta o excedente de execucao para `queued`. O relatorio inclui
`area` (contrato 11 campos), `area_map`, `findings[]`, `routing_summary`,
`governance` (max_governed: no merge/deploy/secrets/destructive, branch
isolation, budget, WIP, kill switch, evidence, inbox; `execution_enabled=false`),
`budget_state`, `evidence_requirement`, `morning_inbox` (fila de decisao do
operador) e `report_hash` deterministico (exclui `generated_at`).

Garantias v0.1: read-only, sem despacho a Dev/Forge, sem branch, sem provider,
sem merge/deploy/secrets/destructive, sem autoaprovacao; toda mutacao real fica
no owner canonico apos decisao do operador no Morning Inbox.

CLI: `php artisan atlas:night-shift:area-focus --area=agentic_engineering_os
--json [--hours=24] [--limit=N]`.

### Surface HTTP read-only para Product Mode (v0.1, AP-721)

Para Mission Control / Product Mode renderizarem a area sem rodar ciclo mutativo,
existe um read model HTTP Desktop-ready:

```text
GET /ai/software-company-stewardship/area-focus/{area}
```

Auth `atlas.token`, ETag/304 sobre `surface_hash` deterministico. Schema
`atlas.night_shift.area_focus_product_mode_surface.v1` projeta o Area Focus Loop
em 9 secoes Desktop-ready: `area_summary`, `health` (healthy/watch/blocked),
`findings`, `inbox_items`, `work_orders` (sempre `execution_executed=false`,
`status=planned_pending_operator`), `budgets`, `evidence_packs` (vazio no slice
read-only), `kill_switch_state` (`required=true`, `engaged=false`) e
`next_actions`. Area desconhecida -> HTTP 404 `code=unknown_area`.

O GET nao executa, nao cria branch, nao chama provider, nao faz merge/deploy/
secrets. `AreaFocusProductModeSurfaceService` projeta sobre o read model
canonico `AreaFocusLoopReadModelService` (`App\Services\Ai\NightShift`) — reuso,
sem runtime paralelo, sem segundo read model.

Nota de fronteira: o slice AP-721 entrega Area Focus HTTP/API. O cockpit
unificado AP-739/AP-753 agrega essa area, Executive, Self-Expanding, Continuous
Stewardship, Dev/Forge release, owner runtime result e AP-752 allocation
handoffs sem executar mutacoes.

### Branch sandbox preflight + handoff governado (AP-726, dry-run)

Apos o operador aceitar (AP-724) um work order emitido (AP-719) que passa os
safety gates (AP-723), `AreaFocusBranchSandboxPreflightService::project()`
prepara um plano de **branch sandbox em modo dry-run, branch-metadata-only**, e
um handoff packet para Atlas Dev / Forge. Schema
`atlas.software_company_stewardship.area_focus_branch_sandbox_preflight.v1`.

Pre-condicoes (bloqueia se falhar): decision=accept e nao executado; work order
referenciado coincide; route executavel (`atlas_dev`/`forge`, nunca
`self_directed_evolution`/`operator_review`); work order `emitted`; gate report
nao `block`. Saida read-only: `branch_plan` (nome deterministico, base ref plan,
worktree path plan, isolation policy), `handoff_packet` (owner Dev/Forge, refs,
validacoes, scope branch-metadata-only), `safety`, `governance`
(`enforced_autonomy_tier=tier_3_branch_sandbox`, `execution_enabled=false`).

**Nao cria branch nem worktree, nao toca codigo alvo, nao faz merge/deploy/push/
secrets/destructive, nao dispatcha Dev/Forge.** `branch_created=false`,
`branch_creation_receipt_required=true`: materializar a branch agora pertence ao
AP-756, com receipt explicito do operador, branch/worktree git local isolado e
JSONL idempotente. AP-756 tambem nao executa Dev/Forge, provider, fix, commit,
merge, deploy, push externo ou secrets. Reusa AP-719/AP-723/AP-724 — sem owner
novo, sem runtime paralelo.

## Fluxo

```text
operator onboards repo
-> chooses autonomy tier
-> optionally chooses area focus
-> sets budget and risk policy
-> Night Shift or Atlas Continuous Stewardship Loop scans and works
-> cockpit shows active branches and evidence
-> operator approves, rejects or requests changes
-> outcomes feed trust and next cycle
```

## Regras para IA

IA deve tratar Product Mode como UX/controle sobre Night Shift existente. Nao
criar runtime paralelo. Nao usar `NS-v3` como nome canonico do modo 24h; use
`Atlas Continuous Stewardship Loop`. Nao chamar NS-v4/NS-v5 de pronto antes de
existir cockpit, kill switch, budget, branch review e evidence inspector.

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
| Work loop agendado | Night Shift |
| Work loop 24h/always-on | Atlas Continuous Stewardship Loop / AP-745 tick + AP-746 recurring runner |
| Area Focus Loop | Product Mode / Night Shift / Atlas Continuous Stewardship Loop |
| Area Stewardship | Area Stewardship Layer |
| Release Dev/Forge operator-owned | AP-747 Area Focus Dev/Forge Release + AP-748 outcome bridge |
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
| Atlas Continuous Stewardship Loop fugir do controle | AP-745/AP-746 disabled-by-default + rate limit + lock lease + pause policy + kill switch |
| Release virar execucao automatica | AP-747 exige release receipt; AP-748 so registra outcome/inbox/portfolio e ainda bloqueia provider, branch creation, merge, deploy e secrets |
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
2. ~~Criar cockpit read-only.~~ Feito como AP-739/AP-753 para a fila superior da Stewardship Stack.
3. ~~Adicionar controls read-only: tier, budget, pause, kill switch, branch review e evidence inspector.~~ Feito como AP-754 read model.
4. ~~Persistir controls como receipts governados.~~ Feito como AP-755 usando AP-731 `product_mode_control`; editor UI/mutacao direta de policy continua futura.
5. ~~Adicionar Area Focus Command Center em read-only.~~ **Feito (v0.1, AP-712/AP-721)** — `AreaFocusLoopReadModelService` (`App\Services\Ai\NightShift`) + CLI `atlas:night-shift:area-focus` + HTTP surface `GET /ai/software-company-stewardship/area-focus/{area}` para `agentic_engineering_os`.
6. Usar Atlas Continuous Stewardship Loop via AP-745 tick e AP-746 recurring runner antes de qualquer scheduler externo real.
7. Usar AP-756 para materializar branch/worktree isolado quando houver receipt, e AP-747/AP-748 para release/evidence antes de qualquer consumo por Atlas Dev/Forge.
8. Usar AP-752/AP-753 para revisar alocacoes executivas aceitas no Product Mode/Cockpit antes de qualquer owner follow-up.
9. Promover para NS-v4 Product Cockpit somente quando onboarding, tiers UI, branch review center, full evidence inspector e kill switch estiverem operacionais.
