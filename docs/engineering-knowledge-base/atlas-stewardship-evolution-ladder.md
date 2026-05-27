---
id: atlas-stewardship-evolution-ladder
type: engineering_knowledge
title: Atlas Stewardship Evolution Ladder
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_with_ap730_ap731_ap733_ap734_ap735_ap736_ap737_ap738_self_expanding_v0_ap739_product_mode_cockpit_ap740_outcome_bridge_ap741_domain_creation_handoff_ap742_cockpit_history_ap743_area_stewardship_active_handoff_ap744_active_operating_slice_ap745_scheduler_safe_tick_ap746_recurring_scheduler_runner_ap747_release_ap748_outcome_ap749_owner_consumption_ap758_owner_runtime_execution_adapter_ap759_owner_sandbox_runtime_runner_ap760_product_mode_owner_sandbox_visibility_ap761_desktop_end_to_end_console_ap762_live_cycle_certification_ap750_owner_runtime_result_bridge_ap751_portfolio_result_signal_intake_ap752_executive_allocation_handoff_ap753_product_mode_allocation_visibility_ap754_product_mode_operational_controls_ap755_control_receipts_and_ap756_branch_sandbox_materializer
summary: Canonical evolution ladder beyond Atlas Continuous Stewardship Loop, Area Focus Loop and Area Stewardship: Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. It defines how Atlas grows from a 24h governed software loop to owning one area, governing a portfolio, making executive tradeoff recommendations and proposing new areas under operator review. AP-738 materializes Self-Expanding Software Company v0 on top of AP-737 without execution or runtime creation. AP-739 exposes the executive/new-area/self-expanding review state in Product Mode/Cockpit. AP-740/AP-748 bridge AP-731/AP-738/AP-747 outcomes into canonical Evidence Ledger, Morning Inbox and Portfolio feed; AP-741 creates the gated Domain Runtime Creation handoff packet; AP-742 exposes AP-740/AP-741 history inside the same Product Mode/Cockpit; AP-743 creates the Area Stewardship active handoff packet after AP-732 readiness; AP-744 consumes it for the first governed active operating slice; AP-745 makes that active slice scheduler-safe; AP-746 makes the Continuous Stewardship motor recurring-scheduler-safe; AP-756 materializes AP-726 branch sandboxes into local isolated git worktrees with operator receipt; AP-757 binds AP-749 to that materialized sandbox; AP-747 releases AP-726 handoffs to Dev/Forge owner queues; AP-749 gates owner-specific consumption; AP-758 adapts ready consumption into AP-750-compatible owner results through existing Dev/Forge projections; AP-759 executes approved owner CLI commands inside the AP-756 sandbox; AP-760 exposes AP-759 in Product Mode/Cockpit for operator review; AP-761 makes Atlas Desktop render the full Product Mode pipeline end-to-end; AP-762 certifies the live cycle end-to-end in projection and optional sandbox-execution modes; AP-763 audits the 29 practical requirements and blocks 100% claims unless every row is proven; AP-750 bridges owner runtime results back into Evidence, Morning Inbox and Portfolio; AP-751 turns those results into AP-733 Portfolio health/risk/rebalance signals; AP-752 turns accepted AP-735 executive recommendations into governed owner allocation handoffs; AP-753 exposes those handoffs in Product Mode/Cockpit; AP-754 exposes Product Mode operational controls read-only in that cockpit; AP-755 makes those controls append-only and replayable through AP-731.
human_summary: Escada canonica da autonomia de stewardship: de uma area ate uma empresa de software que se expande com governanca.
human_what: Define os proximos patamares acima do Atlas Continuous Stewardship Loop sem criar OS novo.
human_purpose: Deixar explicito que Continuous Stewardship Loop nao e o teto: ele e o motor 24h que permite Area Stewardship, Portfolio Stewardship, Autonomous Executive e Self-Expanding Software Company.
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
  - continuous_stewardship_loop
  - portfolio_stewardship
  - autonomous_executive_layer
  - self_expanding_software_company
  - portfolio_health_model
  - executive_decision_inbox
  - stewardship_autonomy_ceiling
  - self_expansion_proposal_gate
  - company_loop_24h_to_self_expanding
  - software_company_stewardship_stack
decisions:
  - Esta ladder e membro futuro da Atlas Software Company Stewardship Stack.
  - A evolucao alem de Area Stewardship e uma ladder canonica, nao um OS novo.
  - Atlas Continuous Stewardship Loop nao e o teto de autonomia; ele e o motor 24h/always-on governado que alimenta os niveis acima.
  - O teto canonico desta stack e Self-Expanding Software Company proposal-only: Atlas propoe novas areas, loops e capacidades, mas operador aprova a promocao.
  - Acima desta stack, a conversa pertence a lineage maior do Atlas, como Autonomous Company OS, World Action Engine e Civilization Intelligence Engine.
  - Portfolio Stewardship coordena multiplas areas e dependencias entre elas.
  - Autonomous Executive Layer define estrategia, orcamento, cadencia, tradeoffs e alocacao de energia.
  - Self-Expanding Software Company propoe nascimento de novas areas quando o portfolio exige.
  - AP-739 integra AP-736/AP-737/AP-738 ao Product Mode/Cockpit visual como surface read-only para review.
  - AP-740/AP-748 registram outcomes AP-731/AP-738/AP-747 no Evidence Ledger, Morning Inbox e Portfolio reutilizando owners existentes.
  - AP-741 gera handoff packet para Domain Runtime Creation Gate sem registrar manifest ou criar dominio.
  - AP-742 expoe AP-740/AP-741 history dentro do mesmo Product Mode/Cockpit sem criar surface paralela.
  - AP-743 gera o Area Stewardship active handoff packet depois de AP-731 accept + AP-732 readiness, sem iniciar execucao.
  - AP-744 roda o primeiro active operating slice de Area Stewardship reutilizando AP-722/AP-718/AP-726 sem mutacao irreversivel.
  - AP-745 envolve AP-744 com admission scheduler-safe do Continuous Stewardship Loop; AP-746 envolve AP-745 com runner recorrente seguro, pause policy, kill switch, lock lease, rate limit e JSONL opcional.
  - AP-747 libera AP-726 para filas Dev/Forge; AP-748 torna visivel; AP-749 gates consumo owner-specific; AP-758 adapta o consumo em resultado owner-runtime sem provider direto ou mutacao; AP-759 executa comando owner CLI allowlisted dentro do sandbox AP-756 sob receipt; AP-760 torna esse run visivel no Product Mode/Cockpit; AP-750 retorna resultado owner-runtime para Evidence, Morning Inbox e Portfolio; AP-751 alimenta Portfolio Health Model com esses resultados.
  - AP-752 consome AP-735 recommendation + AP-731 accept e gera handoff de alocacao para o owner correto, mantendo Executive como recomendador governado, nao executor.
  - AP-753 torna AP-752 visivel no Product Mode/Cockpit como review item, counters, health e comandos, mantendo o cockpit read-only.
  - AP-754 torna repo onboarding state, autonomy tier, budget, branch review, evidence inspector, risk policy e kill switch visiveis no Product Mode/Cockpit sem mutar politica.
  - AP-755 transforma Product Mode controls em receipts AP-731 `product_mode_control`, sem ledger paralelo.
  - AP-756 materializa branch/worktree git local isolado para handoff AP-726 somente com receipt explicito; nao executa Dev/Forge, provider, fix, merge, deploy ou secrets.
  - AP-757 exige sandbox AP-756 materializado e correspondente antes de AP-749 ficar pronto para owner runtime input.
  - AP-762 certifica o ciclo vivo end-to-end da stack e bloqueia qualquer claim de 100% quando AP-749/AP-758/AP-759/AP-750/AP-752/Product Mode nao fecham.
  - AP-763 transforma a lista pratica do operador em audit completion 29/29: projection-only para em 18/29; full audit com AP-762 owner-command sandboxado pode autorizar claim 29/29.
  - Nenhuma camada autoriza merge, deploy, secrets, destructive changes ou auto-promocao sem operador.
  - Agentic Engineering OS continua sendo a primeira area para provar a ladder.
maintenance:
  - Atualize quando novos niveis de stewardship forem promovidos, implementados ou renomeados.
  - Mantenha este doc como ladder; detalhes de runtime devem nascer em APs filhos.
  - Nao transforme nomes futuros em claims de implementacao atual.
related_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
  - docs/ap/AP-714-stewardship-evolution-ladder-contract.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-733-portfolio-stewardship-health-model-contract.md
  - docs/ap/AP-734-portfolio-steward-inbox-contract.md
  - docs/ap/AP-735-autonomous-executive-recommendation-contract.md
  - docs/ap/AP-736-executive-decision-inbox-surface-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-741-self-expanding-domain-runtime-creation-handoff-contract.md
  - docs/ap/AP-742-product-mode-cockpit-stewardship-history-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-745-continuous-stewardship-loop-scheduler-safe-contract.md
  - docs/ap/AP-746-continuous-stewardship-recurring-scheduler-contract.md
  - docs/ap/AP-747-area-focus-dev-forge-release-contract.md
  - docs/ap/AP-748-stewardship-release-outcome-bridge-contract.md
  - docs/ap/AP-749-owner-specific-dev-forge-queue-consumption-gate-contract.md
  - docs/ap/AP-758-owner-runtime-execution-adapter-contract.md
  - docs/ap/AP-759-owner-sandbox-runtime-runner-contract.md
  - docs/ap/AP-760-product-mode-owner-sandbox-runtime-visibility-contract.md
  - docs/ap/AP-761-product-mode-desktop-end-to-end-stewardship-console-contract.md
  - docs/ap/AP-762-end-to-end-stewardship-live-cycle-certification-contract.md
  - docs/ap/AP-763-software-company-stewardship-completion-audit-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - docs/ap/AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionOperatorDecisionService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipLiveCycleCertificationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/ProductModeCockpitController.php
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/StewardshipSurface.tsx
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelServiceTest.php
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-creation-gate.md
  - docs/engineering-knowledge-base/atlas-reality-outcome-gates.md
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
  - self_expanding_company_proposal_loop
governs:
  - atlas.stewardship_evolution
  - atlas.portfolio_stewardship
  - atlas.autonomous_executive
  - atlas.self_expanding_software_company
  - atlas.software_company_stewardship_stack
evidence:
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
  - docs/ap/AP-733-portfolio-stewardship-health-model-contract.md
  - docs/ap/AP-737-new-area-proposal-gate-contract.md
  - docs/ap/AP-738-self-expanding-software-company-v0-contract.md
  - docs/ap/AP-739-product-mode-cockpit-stewardship-review-contract.md
  - docs/ap/AP-740-stewardship-outcome-evidence-and-morning-inbox-contract.md
  - docs/ap/AP-743-area-stewardship-active-handoff-contract.md
  - docs/ap/AP-744-area-stewardship-active-operating-slice-contract.md
  - docs/ap/AP-734-portfolio-steward-inbox-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
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
  - current_autonomy_ceiling
  - portfolio_health_score
  - area_dependency_count
  - executive_recommendation_count
  - resource_allocation_change_count
  - new_area_proposal_count
  - operator_acceptance_rate
next_actions:
  - Usar AP-744 para operar ciclos ativos somente depois de AP-743 ready.
  - Rodar AP-740/AP-748 `--record-evidence --release-file=<ap747>` e AP-741 `--record-handoff` nos ciclos aceitos pelo operador.
  - Usar AP-746 para runner recorrente seguro; ele nao instala scheduler nem libera mutacao irreversivel.
  - Usar AP-747 para release operator-owned de AP-726, AP-748 para outcome evidence, AP-749 para consumption gate, AP-758 para owner runtime adapter, AP-759 para comando owner CLI sandboxed quando autorizado, AP-760 para review no Product Mode e AP-750 para result bridge antes de qualquer Portfolio rebalance ou follow-up.
  - Usar AP-752 depois de AP-735 + AP-731 accept para entregar alocacao executiva ao owner correto sem execucao automatica.
---
# Atlas Stewardship Evolution Ladder

## Resumo

Atlas Stewardship Evolution Ladder define a evolucao canonica da autonomia de
stewardship. Ela responde ate onde o Atlas pode ir depois de cuidar de uma area:
cuidar de um portfolio, tomar decisoes executivas sob review e propor novas
areas quando a empresa de software precisar.

Resposta canonica:

```text
Atlas Continuous Stewardship Loop nao e o maximo.
Ele e o motor 24h.
O maximo desta stack e Self-Expanding Software Company proposal-only.
```

Em outras palavras: primeiro o Atlas trabalha continuamente; depois ele assume
responsabilidade por areas; depois governa o portfolio; depois recomenda como
um executivo; por fim, propoe novas areas, loops e capacidades quando detecta
que a empresa de software precisa se expandir.

## Papel no Atlas

Este doc e um roadmap/contrato de futuro. Ele nao implementa runtime e nao cria
OS novo. Ele impede que IAs inventem nomes concorrentes quando o operador pedir
"algo mais absurdo" depois de Area Stewardship.

AP-730 ja materializa esta ladder como read model read-only/proposal-only. Isso
torna os niveis superiores inspecionaveis e testaveis, mas nao autoriza mutacao
autonoma.

AP-731 materializa o proximo passo: decisoes explicitas do operador para cada
output da ladder em um ledger JSONL append-only. Isso cria memoria/replay de
review sem autorizar execucao, auto-promocao ou novo runtime.

AP-733 materializa o primeiro nivel de Portfolio Stewardship como health model
persistente/replayable. Ele calcula saude do portfolio, dependencias, risco e
candidatos de rebalanceamento para inbox humano. Ele nao executa decisao,
nao chama Dev/Forge e nao cria scheduler ou runtime paralelo.

AP-734 materializa o inbox desse nivel: cada candidato de rebalanceamento vira
item de decisao humana, com target `portfolio_stewardship` e registro AP-731.
Aceitar um item libera apenas o proximo AP/slice governado.

AP-739 materializa a surface visual de review para a parte alta desta ladder:
Executive Decision Inbox, New Area Proposal Gate e Self-Expanding Software
Company v0 aparecem no Product Mode/Cockpit sem executar nada. O operador ve a
fila agregada e usa os comandos AP-731/AP-737 existentes para decidir.

AP-743 materializa o handoff ativo da primeira camada de stewardship: quando
AP-732 confirma readiness para Area Stewardship, AP-743 emite o pacote que um
slice ativo devera consumir.

AP-744 materializa esse slice ativo: ele consome AP-743, roda AP-722, cria
drafts AP-718 e prepara handoffs AP-726. Ele nao invoca provider, nao cria
branch real, nao dispara Dev/Forge e nao substitui Area Focus, SDE, Dev, Forge
ou Evidence.

AP-745 materializa o primeiro limite scheduler-safe do motor 24h: ele admite um
tick AP-744. AP-746 materializa o runner recorrente seguro, disabled-by-default,
AP-756 materializa branch/worktree local isolado com receipt, AP-757 vincula
esse sandbox ao AP-749, e AP-747/AP-748/AP-759/AP-760/AP-750 materializam a liberacao operator-owned para filas
reais de Dev/Forge, o consumo owner-specific e o retorno do resultado para
Evidence/Morning Inbox/Portfolio sem autoexecucao, provider, merge, deploy,
secrets ou mutacao irreversivel. Com pause policy, kill switch, lock/rate AP-745 e JSONL,
nenhum deles instala scheduler, ganha autoridade de mutacao ou libera Dev/Forge
sem review.

Este doc tambem impede uma confusao importante:

```text
Continuous Stewardship Loop = continuidade operacional.
Stewardship Evolution Ladder = aumento de responsabilidade.
Self-Expanding Software Company = teto desta stack.
```

## Onde Se Encaixa

```text
Night Shift Product Mode
-> Atlas Continuous Stewardship Loop
-> Area Focus Loop
-> Area Stewardship Layer
-> Portfolio Stewardship Layer
-> Autonomous Executive Layer
-> Self-Expanding Software Company
```

## Gradiente De Autonomia

| Nivel | Pergunta que o Atlas responde | Autonomia nova | Saida principal |
|---:|---|---|---|
| 0 | Como trabalhar o tempo inteiro sem fugir do controle? | Operacao 24h governada | ciclos, evidence, inbox |
| 1 | Em qual area devo focar agora? | Foco por area | findings, specs, work orders |
| 2 | Como mantenho esta area saudavel continuamente? | Responsabilidade por area | health model, roadmap, priorizacao |
| 3 | Como comparo varias areas e dependencias? | Responsabilidade por portfolio | portfolio health, rebalance proposals |
| 4 | Onde investir capacidade limitada? | Recomendacao executiva | tradeoffs, budget, cadence, alocacao |
| 5 | Que nova area/capacidade deveria nascer? | Auto-expansao governada | new area proposals, loop proposals |

Regra: subir nivel nunca remove gates. Cada nivel herda evidence, branch
isolation, budget, WIP limit, kill switch e operator inbox dos niveis abaixo.

## Contratos

### Nivel 0: Atlas Continuous Stewardship Loop

Atlas opera 24h/always-on com budget, locks, rate limits, pause policy, kill
switch, evidence e inbox. Ele substitui o nome transicional `NS-v3 Continuous
Loop`; `Night Shift` permanece reservado para janelas agendadas/noturnas.

Ele nao escolhe sozinho a estrategia da empresa. Ele apenas garante que o motor
de trabalho continuo exista, seja limitado e seja observavel.

### Nivel 1: Area Focus Loop

Atlas melhora uma area em ciclos. O operador escolhe `area_id`; Atlas varre,
classifica findings, drafts specs, roteia trabalho e entrega inbox.

### Nivel 2: Area Stewardship Layer

Atlas se torna steward de uma area. Ele mantem health model, roadmap local,
priorizacao, evidence, learning e inbox da area.

### Nivel 3: Portfolio Stewardship Layer

Atlas governa um conjunto de areas e suas dependencias. Ele compara health,
gargalos, ROI, risco, desbloqueios e custo de oportunidade entre areas.

Este nivel muda a pergunta de "o que melhorar nesta area?" para "qual area deve
receber energia agora para maximizar a empresa de software inteira?".

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

Este nivel nao e auto-CEO. Ele e um recommendation layer com evidence, cenarios,
regret analysis, reality outcome gates e decision inbox. O operador continua
aprovando decisoes irreversiveis.

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

Este e o teto canonico da Atlas Software Company Stewardship Stack. O Atlas
passa de executar e priorizar para propor a expansao da propria empresa de
software: novas areas, novos loops, novos health models, novos docs owners e
novas capacidades. Tudo com review humano.

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

### Teto E Fronteira Com A Lineage Maior

Dentro da Atlas Software Company Stewardship Stack, o teto e:

```text
Self-Expanding Software Company
```

Isso significa que a empresa de software pode propor sua propria expansao sob
governanca. Nao significa que todo o Atlas chegou ao limite final. Acima desta
stack, a nomenclatura pertence a `atlas-ai-evolution-lineage-and-target-state.md`:

```text
Autonomous Company OS
-> World Action Engine
-> Civilization Intelligence Engine
```

Uma IA nao deve misturar esses nomes. Se a tarefa for software company
stewardship, use esta ladder. Se a tarefa for sociedade, acao externa ampla ou
civilization intelligence, volte para a lineage canonica maior.

## Gates De Promocao

| De | Para | Gate minimo |
|---|---|---|
| Continuous Loop | Area Focus Loop | area_id canonico, owner docs, read-only scan, inbox |
| Area Focus Loop | Area Stewardship | health model, roadmap policy, Dev/Forge routing, evidence pack |
| Area Stewardship | Portfolio Stewardship | 2+ areas stewarded, dependency graph, shared objective, rebalance policy |
| Portfolio Stewardship | Autonomous Executive | executive recommendation schema, budget policy, regret/risk analysis, operator inbox |
| Autonomous Executive | Self-Expanding | repeated unmet capability gap, new area proposal, Domain Runtime Creation Gate, dual review |

Promocao proibida sem:

- evidence pack do nivel anterior;
- operator decision receipt;
- tests/docs-health/architecture validation;
- no-new-runtime proof;
- kill switch e rollback policy;
- reality outcome gates quando houver claim de valor real.

## Fluxo

Continuous Stewardship produz ciclos; Area Stewardship assume uma area; Portfolio coordena areas; Executive recomenda tradeoffs; Self-Expanding propoe novas areas.

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

1. Area Stewardship read-only para `agentic_engineering_os`.
2. Area Stewardship active handoff, AP-743.
3. Area Stewardship active operating slice, AP-744.
4. Continuous Stewardship scheduler-safe tick + recurring runner, AP-745/AP-746.
5. Operator-owned branch sandbox materializer, sandbox binding, release queue + outcome + consumption/result gates, AP-756/AP-757/AP-747/AP-748/AP-749/AP-759/AP-760/AP-761/AP-750.
6. Portfolio Health Model persistente/replayable, AP-733.
7. Portfolio Steward Inbox, AP-734.
8. Autonomous Executive Recommendation Pack, AP-735.
9. Executive Decision Inbox Surface, AP-736.
10. New Area Proposal Gate, AP-737.
11. Self-Expanding Software Company v0 proposal-only, AP-738.
12. Product Mode/Cockpit visual para Executive + Self-Expanding review, AP-739.
13. Evidence Ledger + Morning Inbox para AP-731/AP-738/AP-747 outcomes, AP-740/AP-748.
14. Handoff AP-738 -> Domain Runtime Creation Gate quando houver AP-731 accept sem blockers e AP-740 evidence registrada, AP-741.
15. Owner sandbox runtime runner AP-759 para executar comando owner CLI allowlisted dentro do worktree AP-756 com receipt explicito.
16. Product Mode owner sandbox visibility AP-760 para revisar AP-759 no cockpit antes do bridge.
17. Product Mode Desktop end-to-end console AP-761 para revisar o pipeline inteiro no `stewardship` cockpit sem executar nada.
18. Owner runtime result bridge AP-750 para alimentar Evidence, Morning Inbox e Portfolio com resultados reais de Dev/Forge antes de rebalance ou proximo ciclo.
19. Portfolio owner-runtime result signal intake AP-751 para AP-733 priorizar review/follow-up/rebalance com outcomes AP-750.
20. Autonomous Executive allocation handoff AP-752 para AP-735 + AP-731 accept virarem pacote de alocacao ao owner correto, sem executar.
21. Product Mode allocation visibility AP-753 para revisar handoffs AP-752 no cockpit unificado.
22. Product Mode operational controls AP-754 para revisar onboarding, tiers, budget, branch review, evidence e kill switch antes de elevar autonomia.
23. Product Mode control receipts AP-755 para tornar esses controles auditaveis, replayable e consumiveis pelo AP-754 via AP-731.
24. Branch sandbox materializer AP-756 e sandbox binding AP-757 para criar branch/worktree isolado e exigir esse record antes de owner consumption.
25. Live cycle certification AP-762 para provar a cadeia completa antes de declarar a stack pronta para operating product mode.
26. Completion audit AP-763 para responder item-a-item se a stack esta em 18/29 projection-only ou 29/29 com execucao sandboxada certificada.
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

- AP-756/AP-757/AP-747/AP-748/AP-749/AP-759/AP-760/AP-750/AP-751 provam sandbox, binding, release, visibilidade, consumo owner-specific, comando owner sandboxed, cockpit review, resultado Dev/Forge e impacto no Portfolio antes de recomendar alocacao; AP-752 entrega a recomendacao aceita ao owner correto sem executar; AP-753 torna essa entrega revisavel no cockpit; AP-754 mostra se os controles de produto permitem elevar autonomia; AP-755 registra esses controles como receipts AP-731.

## Proximas Acoes
1. Usar AP-759, AP-760, AP-750 e AP-751 com outcomes reais do AP-744/AP-745/AP-746/AP-747/AP-748/AP-749 para alimentar Evidence, Morning Inbox e Portfolio.
2. Usar AP-752 para toda recomendacao executiva aceita antes de qualquer follow-up/rebalance.
3. Usar AP-753 para revisar AP-752 no Product Mode/Cockpit antes de qualquer owner consumir follow-up.
4. Usar AP-754 para checar onboarding, autonomy tier, budget, branch review, evidence inspector e kill switch antes de qualquer Product Mode Product Cockpit claim.
5. Usar AP-755 para registrar controles aceitos antes de ativar `--use-recorded-controls` em Product Mode.
6. Usar AP-756 para materializar branch/worktree isolado sob receipt e AP-757 para vincular o record ao AP-749 antes de qualquer owner consumption que precise de workspace fisico.
