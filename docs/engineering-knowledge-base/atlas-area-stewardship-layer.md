---
id: atlas-area-stewardship-layer
type: engineering_knowledge
title: Atlas Area Stewardship Layer
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_with_ap732_ap743_ap744_ap745_ap746_ap747_ap748_ap749_owner_consumption_gate_ap758_owner_runtime_execution_adapter_ap759_owner_sandbox_runtime_runner_ap760_product_mode_owner_sandbox_visibility_ap761_desktop_end_to_end_console_ap750_owner_runtime_result_bridge_ap751_portfolio_result_signal_intake_ap752_executive_allocation_handoff_input_and_ap756_branch_sandbox_materializer
summary: Canonical layer above Area Focus Loop where Atlas becomes the governed steward of a chosen area: it monitors health, detects bugs and gaps, drafts specs, prioritizes roadmap work, routes Atlas Dev/Forge execution, measures outcomes and sends operator decisions to inbox. AP-732 adds the read-only gate that proves when an AP-730 proposal plus AP-731 accept can move toward active Area Stewardship; AP-743 turns ready evidence into an operator-reviewable active handoff packet; AP-744 consumes that packet and runs the first governed active operating slice without irreversible mutation; AP-745 wraps AP-744 in a disabled-by-default scheduler-safe Continuous Stewardship Loop tick; AP-746 wraps AP-745 in a recurring runner without installing a scheduler; AP-756 materializes AP-726 branch sandboxes into local isolated git worktrees under explicit operator receipt; AP-757 binds AP-749 to that materialized sandbox; AP-747 releases AP-726 handoffs to Atlas Dev/Forge owner queues; AP-748 bridges outcomes into Evidence/Morning Inbox/Portfolio; AP-749 gates owner-specific consumption; AP-758 adapts ready consumption into AP-750-compatible owner results through existing Dev/Forge projections; AP-759 executes explicitly approved owner CLI commands inside the AP-756 sandbox; AP-760 exposes AP-759 in Product Mode/Cockpit for operator review; AP-761 renders this end-to-end chain in the Atlas Desktop `stewardship` Product Mode console; AP-750 bridges owner runtime results back into Evidence/Morning Inbox/Portfolio; AP-751 feeds those results into Portfolio health/risk/rebalance; AP-752 may hand accepted executive allocations back to this layer as reviewable owner packets.
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
  - AP-743 e o handoff ativo governado desta camada: ele prepara o pacote antes da operacao ativa.
  - AP-744 e o primeiro operating slice ativo: conduz AP-722, AP-718 e AP-726, mas nao invoca providers, nao cria branch real, nao dispara Dev/Forge e nao muta repos.
  - AP-745 promove AP-744 para tick scheduler-safe do Continuous Stewardship Loop; AP-746 promove AP-745 para runner recorrente seguro, sem instalar scheduler ou liberar mutacao.
  - AP-756 cria branch/worktree git local isolado para AP-726 somente com receipt explicito; ele nao executa Dev/Forge, provider, fix, merge, deploy, push externo ou secrets.
  - AP-757 exige que AP-749 carregue um sandbox AP-756 materializado e correspondente antes de owner runtime input ficar pronto.
  - AP-747 promove AP-726 para fila Dev/Forge; AP-748 alimenta Evidence/Morning Inbox/Portfolio; AP-749 gates consumo sem provider, merge, deploy ou secrets; AP-758 adapta o consumo em owner_result AP-750-compatible; AP-759 executa comando owner CLI allowlisted dentro do sandbox AP-756 somente com receipt; AP-760 torna esse run visivel no Product Mode/Cockpit; AP-750 recebe o resultado owner-runtime e o devolve a Evidence/Morning Inbox/Portfolio com identity/evidence/isolation gates; AP-751 transforma esse retorno em sinal Portfolio.
  - AP-752 pode rotear recomendacoes executivas aceitas para Area Stewardship/Area Focus, mas esta camada ainda precisa rodar seus proprios gates antes de qualquer execucao.
maintenance:
  - Atualize quando area_id, health model, roadmap steward, routing Dev/Forge ou inbox por area mudarem.
  - Mantenha o doc pequeno; mova implementacao detalhada para APs filhos quando passar de contrato para runtime.
  - Sincronize canonical indexes apos qualquer alteracao.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-713-area-stewardship-layer-contract.md
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - docs/ap/AP-733-portfolio-stewardship-health-model-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipRecurringSchedulerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
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
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
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
  - Usar AP-732 para decidir quando `agentic_engineering_os` pode passar para Area Stewardship active.
  - Usar AP-743 para preparar ou registrar o handoff ativo somente apos AP-731 accept e AP-732 ready_for_active_handoff.
  - Usar AP-744 para operar o primeiro ciclo ativo da area, roteando por Area Focus, Self-Directed Evolution e Dev/Forge preflight sem criar executor paralelo.
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

AP-732 implementa o gate de promocao deste layer: ele verifica AP-730, AP-731,
health, roadmap, Dev/Forge policy, evidence e inbox antes de qualquer slice
active. Ele nao executa a promocao.

AP-743 implementa o proximo limite: quando AP-732 retorna
`ready_for_active_handoff`, ele cria um pacote deterministico de handoff ativo
para revisao do operador. Esse pacote declara rotas, budgets, WIP, safety e
gates obrigatorios.

AP-744 consome esse pacote e implementa o primeiro operating slice ativo. Ele
roda o ciclo AP-722, cria drafts AP-718 para gaps e prepara handoffs AP-726 para
Dev/Forge. Mesmo assim, ele continua sem provider, sem branch real, sem dispatch
Dev/Forge, sem merge, sem deploy, sem secrets e sem mutacao de repo.

AP-745 envolve esse operating slice com admission do Atlas Continuous
Stewardship Loop; AP-746 envolve AP-745 como runner recorrente seguro. Ambos
sao disabled-by-default, respeitam kill switch/locks/rate limits/evidence e nao
instalam scheduler nem adquirem autoridade de mutacao.

AP-756 materializa o sandbox fisico: com receipt `materialize_sandbox` apontando
para `target_handoff_hash`, ele cria branch/worktree git local isolado para um
handoff AP-726. Ele ainda nao chama provider, nao despacha Dev/Forge, nao aplica
fix, nao commita, nao faz merge/deploy/push externo e nao toca secrets.

AP-747 e o primeiro release operator-owned apos AP-726: se o operador assina um
receipt `decision=release` apontando para `target_handoff_hash`, o Atlas emite
um item de fila para o owner real (`atlas.dev_runtime.v1` ou
`atlas.forge.parallel_durable.v1`). Ele ainda nao chama provider, nao executa
runtime e nao permite merge/deploy/secrets.

AP-748 torna esse release visivel em Evidence, Morning Inbox e Portfolio. AP-749
so libera input owner-specific para Atlas Dev/Forge depois dessa visibilidade e
de um receipt explicito de consumo. AP-758 adapta esse consumo em resultado
owner-runtime AP-750-compatible; AP-759 executa comando owner CLI allowlisted
dentro do worktree AP-756 quando houver receipt explicito; AP-760 mostra esse
run no Product Mode/Cockpit. AP-750 so aceita o
resultado produzido pelo owner runtime quando schema, consumo, release, queue
item, owner, evidence pack, changed files e approval de qualquer claim
irreversivel passarem.

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
-> AP-759 runs approved owner command inside AP-756 sandbox
-> AP-760 shows AP-759 run in Product Mode/Cockpit
-> AP-750 bridges owner runtime result
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
7. AP-743 active handoff.
8. AP-744 active operating slice.
9. AP-745 scheduler-safe Continuous Stewardship Loop tick.
10. AP-746 recurring scheduler-safe runner.
11. AP-747/AP-748/AP-749 release, outcome e owner-consumption gate.
12. AP-759 owner sandbox runtime runner para comando owner CLI allowlisted dentro do worktree AP-756 sob receipt explicito.
13. AP-760 Product Mode visibility para revisar AP-759 no cockpit antes do bridge.
14. AP-750 owner runtime result bridge antes de merge, deploy, follow-up ou Portfolio rebalance.
15. AP-751 Portfolio result signal intake antes de rebalance ou proximo ciclo autonomo.
16. AP-752 executive allocation handoff recebido somente como pacote de review; Area Stewardship ainda decide via seus proprios gates.

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

1. Usar AP-756 para materializar branch/worktree isolado quando houver receipt e AP-757/AP-749/AP-758/AP-759/AP-760 para entregar input Dev/Forge, executar owner CLI allowlisted, revisar no cockpit e gerar owner_result apenas apos AP-747/AP-748, sandbox materializado e receipt.
2. Medir outcomes reais do AP-744/AP-745/AP-746/AP-747/AP-748/AP-749/AP-758/AP-759/AP-760/AP-750 e alimentar Portfolio Stewardship via AP-751; consumir AP-752 apenas como entrada de review.
3. Usar AP-754 Product Mode operational controls para budget, WIP, kill switch, branch review, evidence inspector e release queue review; UI/editor persistente ainda fica no Product Mode.
