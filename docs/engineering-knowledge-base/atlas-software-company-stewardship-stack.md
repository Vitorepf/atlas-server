---
id: atlas-software-company-stewardship-stack
type: engineering_knowledge
title: Atlas Software Company Stewardship Stack
status: future
category: agentic-engineering
priority: 100
implementation_state: future_target_with_area_focus_runtime_and_ap730_ap731_ap733_ap734_ap735_ap736_ap737_ap738_top_proposal_models_ap739_product_mode_cockpit_ap740_outcome_bridge_ap741_domain_creation_handoff_ap742_cockpit_history_ap743_area_stewardship_active_handoff_ap744_active_operating_slice_ap745_scheduler_safe_tick_ap746_recurring_scheduler_runner_ap747_release_ap748_outcome_ap749_owner_consumption_gate_ap750_owner_runtime_result_bridge_ap751_portfolio_result_signal_intake_ap752_executive_allocation_handoff_ap753_product_mode_allocation_visibility_ap754_product_mode_operational_controls_read_model_ap755_control_receipts_ap756_branch_sandbox_materializer_ap757_sandbox_binding_ap758_owner_runtime_execution_adapter_ap759_owner_sandbox_runtime_runner_ap760_product_mode_owner_sandbox_visibility_ap761_desktop_end_to_end_console_and_ap762_live_cycle_certification
summary: Canonical umbrella stack for every capability that lets Atlas care for and improve software as a governed autonomous software company: Night Shift, Night Shift Product Mode, Atlas Continuous Stewardship Loop, Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive Layer and Self-Expanding Software Company. Atlas Continuous Stewardship Loop is the 24h motor, not the autonomy ceiling; Self-Expanding Software Company is the ceiling inside this stack. AP-738 materializes Self-Expanding Software Company v0 as proposal-only on top of AP-737 without creating a domain, department or runtime. AP-739 integrates AP-736/AP-737/AP-738 into the Product Mode/Cockpit visual review surface. AP-740 bridges AP-731/AP-738/AP-747 outcomes into canonical Evidence Ledger and Morning Inbox; AP-741 turns accepted/evidenced proposals into Domain Runtime Creation Gate handoff packets without creating domains; AP-742 exposes AP-740/AP-741 history inside the same Product Mode/Cockpit; AP-743 turns AP-732 Area Stewardship readiness into an operator-reviewable active handoff packet; AP-744 runs the first governed active Area Stewardship operating slice; AP-745 makes AP-744 scheduler-safe for Continuous Stewardship; AP-746 makes AP-745 recurring-scheduler-safe without installing a scheduler; AP-747 releases AP-726 handoffs to real Atlas Dev/Forge owner queues; AP-748 feeds those releases into Evidence/Morning Inbox/Portfolio; AP-749 gates owner-specific consumption; AP-756 materializes AP-726 branch sandboxes into isolated local git worktrees only with an explicit operator receipt; AP-757 binds AP-749 consumption to that materialized sandbox; AP-758 adapts ready owner consumption into an AP-750-compatible owner result by reusing Atlas Dev/Forge owner projections; AP-759 executes an explicitly approved allowlisted owner CLI inside the AP-756 sandbox; AP-760 exposes AP-759 inside Product Mode/Cockpit as read-only review/control visibility; AP-761 makes the Atlas Desktop `stewardship` surface render the full end-to-end Product Mode operating pipeline; AP-762 certifies that the whole chain reaches Product Mode visibility in projection and optional sandbox-execution modes without creating another OS/runtime/provider path; AP-763 audits the operator's 29 practical requirements and blocks 100% claims unless every row is proven; AP-750 bridges owner runtime results back into Evidence/Morning Inbox/Portfolio; AP-751 makes those results affect Portfolio health, risk and rebalance; AP-752 turns accepted executive recommendations into owner allocation handoff packets without execution; AP-753 makes those AP-752 packets visible in Product Mode/Cockpit; AP-754 adds Product Mode operational controls as a read-only cockpit projection; AP-755 makes those controls receipt-backed by reusing AP-731.
human_summary: Nome canonico da pilha inteira de cuidado autonomo de software do Atlas.
human_what: Define o guarda-chuva, nomes, fronteiras e ordem de leitura para Night Shift, Product Mode, Continuous Stewardship Loop, Area Focus e Stewardship.
human_purpose: Impedir que IAs inventem outro OS/nome/runtime para a mesma area e facilitar descoberta imediata.
human_input: Intencoes sobre Night Shift, areas, stewardship, portfolio, executive recommendations, autonomous software company e melhorias em loop.
human_output: Nome canonico, stack tree, regras para IA, docs filhos, fronteiras e prioridade de implementacao.
human_change_when: Mexa quando qualquer capability da familia Night Shift/Product Mode/Area Focus/Stewardship mudar nome, autoridade ou fronteira.
human_block_when: Bloqueie quando IA tentar criar OS novo, runtime paralelo, umbrella name concorrente ou claim de implementacao sem evidence.
tags:
  - atlas-ai
  - software-company
  - stewardship-stack
  - night-shift
  - continuous-stewardship-loop
  - area-focus-loop
  - area-stewardship
capabilities:
  - software_company_stewardship_stack
  - night_shift
  - night_shift_product_mode
  - continuous_stewardship_loop
  - area_focus_loop
  - area_stewardship
  - portfolio_stewardship
  - autonomous_executive_layer
  - self_expanding_software_company
decisions:
  - Atlas Software Company Stewardship Stack e o nome canonico da area inteira.
  - A stack vive dentro do Atlas Autonomous Software Company Runtime.
  - A stack nao e OS novo, runtime paralelo, domain runtime novo ou substituta do AAEOS.
  - Night Shift e o modo agendado/janela/batch; nao e o nome canonico do modo 24h.
  - Atlas Continuous Stewardship Loop e o nome canonico do modo 24h/always-on governado.
  - NS-v3 Continuous Loop e apenas alias historico/transicional para Atlas Continuous Stewardship Loop.
  - Area Focus Loop e o motor de ciclos por area.
  - Area Stewardship e responsabilidade continua por uma area.
  - Portfolio Stewardship, Autonomous Executive e Self-Expanding vivem na ladder; AP-733, AP-734, AP-735, AP-736, AP-737 e AP-738 materializam os primeiros review/surface/gate/top-level proposal models sem execucao.
  - AP-739 integra Executive Decision Inbox, New Area Proposal Gate e Self-Expanding Software Company v0 ao Product Mode/Cockpit visual sem gravar decisoes nem executar trabalho.
  - AP-740/AP-748 integram decisions/outcomes AP-731/AP-738/AP-747 ao Evidence Ledger, Morning Inbox e Portfolio reutilizando owners existentes.
  - AP-741 cria pacote de handoff AP-738 -> Domain Runtime Creation Gate somente com AP-731 accept, AP-737 sem blockers e AP-740 evidence registrada.
  - AP-742 conecta o Product Mode/Cockpit ao historico AP-740/AP-741 de outcomes, Morning Inbox items e handoff packets sem criar outro cockpit.
  - AP-743 cria o pacote de active handoff da Area Stewardship apos AP-731 accept + AP-732 ready_for_active_handoff, sem iniciar o loop ativo.
  - AP-744 consome o AP-743 e roda o primeiro operating slice ativo da Area Stewardship, reutilizando AP-722/AP-718/AP-726 sem mutacao irreversivel.
  - AP-745 promove o AP-744 para tick scheduler-safe do Atlas Continuous Stewardship Loop; AP-746 promove AP-745 para runner recorrente seguro, disabled-by-default, com pause policy, kill switch, lock lease, rate limit e JSONL append-only.
  - AP-747 libera handoffs AP-726 para filas reais de Atlas Dev/Forge somente com receipt explicito do operador; nao cria branch, nao chama provider, nao muta repo e nao permite merge/deploy/secrets.
  - Atlas Continuous Stewardship Loop nao e o maximo; ele e o motor 24h que permite os niveis acima.
  - Self-Expanding Software Company e o teto canonico desta stack, sempre proposal-only ate existir evidence e operator approval.
  - Acima desta stack, use a lineage canonica maior do Atlas, nao invente outro OS.
  - AP-749 e o gate de consumo owner-specific: Atlas Dev/Forge recebem input apenas apos AP-748 + receipt, com branch isolation, evidence pack e review humano.
  - AP-750 e o bridge de resultado owner-runtime: depois que Atlas Dev/Forge rodam sob autoridade propria, o resultado so alimenta Evidence, Morning Inbox e Portfolio se vier com schema, identidade, evidence pack, branch isolation e approval explicito para qualquer claim irreversivel.
  - AP-751 e o intake Portfolio de resultado owner-runtime: o AP-733 passa a priorizar review/follow-up/rebalance usando sinais AP-750, sem executar nada.
  - AP-752 e o gate de handoff executivo: AP-735 + AP-731 accept viram pacote de alocacao para o owner correto, sem executar Dev/Forge, branch, provider, merge, deploy ou secrets.
  - AP-753 expoe os handoffs AP-752 dentro do Product Mode/Cockpit existente, com review queue, counters, health e comandos de operador, sem executar nada.
  - AP-754 expoe controles operacionais de Product Mode dentro do cockpit: repo onboarding state, autonomy tier, budget/rate limit, branch review, evidence inspector, risk policy e kill switch, sem mutar politica nem executar trabalho.
  - AP-755 registra esses controles como receipts AP-731 `product_mode_control`, permitindo replay e uso em AP-754 sem criar ledger paralelo.
  - AP-756 materializa handoffs AP-726 em branch/worktree git local isolado somente com receipt explicito do operador, sem executar Dev/Forge, provider, fix, merge, deploy ou secrets; AP-770 reserva a identidade da branch antes da mutacao git para bloquear colisoes paralelas.
  - AP-757 vincula AP-749 ao sandbox AP-756 materializado antes de qualquer owner runtime input ficar pronto.
  - AP-758 adapta um AP-749 pronto em owner_result compativel com AP-750 reutilizando Atlas Dev/Forge existentes, sem provider direto ou mutacao pelo adapter.
  - AP-759 executa um comando owner CLI allowlisted dentro do worktree AP-756 somente com receipt explicito do operador, gerando owner_result AP-750-compatible; nao cria OS/runtime/provider path, merge, deploy, push externo, secrets ou acao destrutiva.
  - AP-760 expoe AP-759 no Product Mode/Cockpit como section, counters, health, review item e command anchors; o cockpit nao executa AP-759.
  - AP-761 torna a surface Desktop `stewardship` uma console Product Mode end-to-end: mostra AP-740/AP-741/AP-743/AP-744/AP-745/AP-746/AP-747/AP-759/AP-750/AP-752/AP-754 em um unico pipeline visual read-only.
  - AP-762 certifica o ciclo vivo end-to-end da stack: AP-722 -> AP-743 -> AP-744 -> AP-745 -> AP-746 -> AP-747 -> AP-748 -> AP-749 -> AP-758 -> AP-759 -> AP-750 -> AP-751/AP-733 -> AP-734 -> AP-735 -> AP-752 -> AP-739/AP-761, em modo projection e em modo owner command sandboxado opcional.
  - AP-763 responde "em qual numero estamos?" com uma matriz de 29 requisitos: projection-only para em 18/29 porque execucao real exige AP-759; com `--include-execution-certification`, a claim 29/29 so passa se AP-762 owner-command sandboxado estiver certificado.
  - AP-764 corrige a fronteira de ativacao: Stewardship sempre roda pelo Atlas Server e projeta handoffs nativos de Obra para Atlas Dev/Forge; automacao externa do Codex pode no maximo invocar comando durante desenvolvimento, mas nao e runtime, scheduler, owner ou dependencia do Atlas.
  - Merge, deploy, secrets e destructive changes continuam proibidos sem operador.
maintenance:
  - Atualize este doc antes de criar qualquer doc novo sobre Night Shift, Product Mode, Continuous Stewardship Loop, Area Focus, Stewardship, Portfolio ou Executive dentro da software company.
  - Mantenha este doc como o primeiro ponto de leitura para IAs.
  - Nao duplique esta stack com nomes como AGOS, Autonomous Company Genesis OS, Stewardship OS ou Night Shift OS.
related_paths:
  - docs/ap/AP-715-software-company-stewardship-stack-contract.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift.md
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-night-shift-product-mode.md
  - docs/engineering-knowledge-base/atlas-area-stewardship-layer.md
  - docs/engineering-knowledge-base/atlas-stewardship-evolution-ladder.md
  - docs/ap/AP-732-area-stewardship-promotion-readiness-gate-contract.md
  - docs/ap/AP-730-stewardship-evolution-read-model-contract.md
  - docs/ap/AP-731-stewardship-evolution-operator-decision-ledger-contract.md
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
  - docs/ap/AP-764-atlas-native-stewardship-obra-runner-contract.md
  - docs/ap/AP-765-stewardship-runtime-result-bridge-contract.md
  - docs/ap/AP-767-dev-forge-runtime-execution-bridge-contract.md
  - docs/ap/AP-750-owner-runtime-result-bridge-contract.md
  - docs/ap/AP-751-portfolio-owner-runtime-result-signal-contract.md
  - docs/ap/AP-752-autonomous-executive-allocation-handoff-contract.md
  - docs/ap/AP-753-product-mode-cockpit-executive-allocation-handoff-visibility-contract.md
  - docs/ap/AP-754-product-mode-operational-controls-read-model-contract.md
  - docs/ap/AP-755-product-mode-operational-control-receipts-contract.md
  - docs/ap/AP-756-area-focus-branch-sandbox-materializer-contract.md
  - docs/ap/AP-757-owner-queue-sandbox-binding-contract.md
  - docs/ap/AP-773-stewardship-branch-safety-audit-contract.md
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipPromotionReadinessService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaStewardship/AreaStewardshipActiveOperatingService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipLoopService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ContinuousStewardship/AtlasContinuousStewardshipRecurringSchedulerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusBranchSandboxMaterializerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusOwnerQueueConsumptionGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipEvolutionDecisionLedgerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeExecutionAdapterService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerSandboxRuntimeRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipLiveCycleCertificationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOwnerRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipRuntimeResultBridgeService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeRuntimeResultEventService.php
  - app/Services/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipNativeObraRunnerService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipHealthModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/PortfolioStewardship/PortfolioStewardshipInboxService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveRecommendationService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/ExecutiveDecisionInboxSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/NewAreaProposalGateService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingSoftwareCompanyService.php
  - app/Services/Ai/SoftwareCompanyStewardship/SelfExpanding/SelfExpandingDomainRuntimeCreationHandoffService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeCockpitSurfaceService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlsReadModelService.php
  - app/Services/Ai/SoftwareCompanyStewardship/ProductMode/ProductModeOperationalControlReceiptService.php
  - app/Http/Controllers/Ai/SoftwareCompanyStewardship/ProductModeCockpitController.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/StewardshipEvolution/StewardshipOutcomeEvidenceBridgeServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/AreaFocusDevForgeReleaseServiceTest.php
  - tests/Unit/Ai/SoftwareCompanyStewardship/AutonomousExecutive/AutonomousExecutiveAllocationHandoffServiceTest.php
  - ../atlas-desktop/apps/desktop/src/surfaces/stewardship/StewardshipSurface.tsx
  - docs/engineering-knowledge-base/atlas-ai-evolution-lineage-and-target-state.md
  - docs/engineering-knowledge-base/atlas-agentic-engineering-os.md
  - docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md
  - docs/engineering-knowledge-base/atlas-self-directed-evolution-layer.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-software-company-stewardship-stack
graph_title: Atlas Software Company Stewardship Stack
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-autonomous-software-company-runtime
graph_status: future
graph_source: repo
human_name: Atlas Software Company Stewardship Stack
canonical_name: Atlas Software Company Stewardship Stack
technical_name: atlas-software-company-stewardship-stack
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
allowed_changes:
  - Refinar nomes, fronteiras, stack tree, AI rules, promotion gates and child doc links.
forbidden_changes:
  - Chamar a stack de OS novo.
  - Criar runtime paralelo a Autonomous Software Company Runtime, Night Shift, Product Mode, Dev, Forge, Self-Construction ou Evidence.
  - Declarar Portfolio, Executive ou Self-Expanding implementados sem runtime evidence.
depends_on:
  - atlas-autonomous-software-company-runtime
  - atlas-autonomous-software-company-night-shift
  - atlas-autonomous-software-company-night-shift-product-mode
  - atlas-area-stewardship-layer
  - atlas-stewardship-evolution-ladder
flows_to:
  - night_shift
  - product_mode
  - continuous_stewardship_loop
  - area_focus_loop
  - area_stewardship
  - stewardship_evolution_ladder
unlocks:
  - ai_discovery_for_stewardship_stack
  - area_focus_loop_priority
  - stewarded_software_company
governs:
  - atlas.software_company_stewardship_stack
  - atlas.night_shift
  - atlas.night_shift.product_mode
  - atlas.continuous_stewardship_loop
  - atlas.night_shift.area_focus_loop
  - atlas.area_stewardship
  - atlas.stewardship_evolution
evidence:
  - docs/engineering-knowledge-base/atlas-software-company-stewardship-stack.md
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
requires_evidence: true
risk_level: critical
ai_entrypoints:
  - Leia este doc primeiro quando a tarefa mencionar Night Shift, Product Mode, Continuous Stewardship Loop, Area Focus Loop, Area Stewardship, Portfolio Stewardship, Autonomous Executive, Self-Expanding Software Company ou software company que melhora a si mesma.
  - Depois leia o doc filho especifico da capability que sera alterada.
quality_gates:
  - canonical-stack-name-required
  - child-doc-owner-required
  - no-new-os
  - no-parallel-runtime
  - evidence-required
  - operator-review-required
  - no-merge-without-operator
  - no-deploy-without-operator
failure_modes:
  - IA cria outro nome guarda-chuva para a mesma stack.
  - IA comeca em Portfolio/Executive antes de Area Focus Loop operacional.
  - IA chama Product Mode de runtime implementado sem cockpit/evidence.
  - IA chama o modo 24h de Night Shift/NS-v3 como nome canonico em vez de Atlas Continuous Stewardship Loop.
  - IA cria executor paralelo para Night Shift ou Stewardship.
observability_signals:
  - stewardship_stack_level
  - current_priority_capability
  - active_area_id
  - child_doc_loaded
  - duplicate_name_blocked
next_actions:
  - Usar AP-741 `--record-handoff` apenas depois de AP-740 `--record-evidence` nos ciclos aceitos.
  - Usar AP-743 `--record-active-handoff` apenas depois de AP-731 accept + AP-732 ready_for_active_handoff.
  - Usar AP-744 `--record-active-operation` apenas para registrar ciclos ativos projetados/revisaveis; ele nao libera mutacao irreversivel.
  - Usar AP-746 `continuous-stewardship-scheduler --enable-continuous-scheduler` para runner recorrente seguro; ele nao instala scheduler nem libera mutacao irreversivel.
  - Passar AP-724 receipts por `--operator-receipts-file=<ap724.json|jsonl>` quando o objetivo for transformar work orders revisados em handoffs AP-726 prontos dentro de AP-744/AP-745/AP-746/AP-764; sem esse arquivo o loop deve continuar em `awaiting_operator_approval`.
  - Usar AP-756 `area-focus-branch-sandbox-materialize --preflight-file=<ap726> --sandbox-receipt-file=<receipt>` antes de qualquer owner execution que precise de worktree fisico; `--materialize-sandbox` cria apenas branch/worktree local isolado.
  - Usar AP-747 `area-focus-dev-forge-release --preflight-file=<ap726> --release-receipt-file=<receipt>` somente depois de AP-726 ready; depois usar AP-748 via `outcome-evidence --release-file=<ap747>`, AP-749 via `owner-queue-consumption-gate`, AP-758 via `owner-runtime-execute`, AP-759 via `owner-sandbox-runtime-run` quando houver receipt de comando, AP-760/Product Mode para revisar esse run e AP-750 via `owner-runtime-result-bridge`.
  - Usar AP-752 `executive-allocation-handoff --pack-id=<ap735> --recommendation-id=<id>` somente depois de AP-731 accept para rotear alocacao executiva ao owner correto.
---
# Atlas Software Company Stewardship Stack

## Resumo

Atlas Software Company Stewardship Stack e o nome canonico da pilha inteira que
faz o Atlas cuidar, melhorar, governar e evoluir software autonomamente sob
review humano.

Frase canonica:

```text
Atlas Software Company Stewardship Stack e a pilha governada que permite ao
Atlas operar ciclos, areas, portfolios e decisoes executivas de uma empresa de
software autonoma, com evidence, branch isolation, inbox e aprovacao humana para
acoes irreversiveis.
```

Resposta curta para IAs:

```text
Atlas Continuous Stewardship Loop = motor 24h.
Area Stewardship = responsabilidade por uma area.
Portfolio Stewardship = responsabilidade entre areas.
Autonomous Executive Layer = recomendacao de estrategia e alocacao.
Self-Expanding Software Company = teto desta stack: propor novas areas/capacidades.
```

## Papel no Atlas

Este doc e o guarda-chuva de descoberta. Qualquer IA que veja Night Shift,
Product Mode, Area Focus Loop, Area Stewardship, Portfolio Stewardship,
Autonomous Executive ou Self-Expanding Software Company deve carregar este doc
primeiro.

Ele nao substitui o Atlas Autonomous Software Company Runtime. Ele organiza a
stack de stewardship que vive dentro dele.

## Onde Se Encaixa

```text
Atlas Agentic Engineering OS
-> Atlas Autonomous Software Company Runtime
   -> Atlas Software Company Stewardship Stack
      -> Night Shift
      -> Night Shift Product Mode
      -> Atlas Continuous Stewardship Loop
      -> Area Focus Loop
      -> Area Stewardship Layer
      -> Portfolio Stewardship Layer
      -> Autonomous Executive Layer
      -> Self-Expanding Software Company
```

## Contratos

### Nome Canonico

Use sempre:

```text
Atlas Software Company Stewardship Stack
```

Slug:

```text
atlas-software-company-stewardship-stack
```

Aliases aceitos:

- Software Company Stewardship Stack;
- Stewardship Stack;
- stack de stewardship da software company;
- pilha de stewardship da empresa de software.

Nomes proibidos:

- Atlas Stewardship OS;
- Night Shift OS;
- Area Stewardship OS;
- Continuous Stewardship OS;
- AGOS;
- Autonomous Company Genesis OS;
- qualquer OS novo para esta mesma area.

### Stack Tree

| Nivel | Nome | Papel |
|---:|---|---|
| 1 | Night Shift | Roda ciclos sandboxed em janela agendada/batch, primeiro no Atlas. |
| 2 | Night Shift Product Mode | Cockpit, onboarding, tiers, budget, kill switch e review. |
| 3 | Atlas Continuous Stewardship Loop | Opera 24h/always-on com budget, locks, rate limit, pause policy, kill switch e inbox. |
| 4 | Area Focus Loop | Foca uma area e busca bugs, gaps, specs e melhorias. |
| 5 | Area Stewardship Layer | Assume responsabilidade continua por uma area. |
| 6 | Portfolio Stewardship Layer | Coordena multiplas areas e dependencias. |
| 7 | Autonomous Executive Layer | Recomenda estrategia, alocacao e tradeoffs. |
| 8 | Self-Expanding Software Company | Propoe novas areas sob gates e operador. |

### Nome Canonico Do Loop 24h

Use sempre:

```text
Atlas Continuous Stewardship Loop
```

Slug:

```text
atlas-continuous-stewardship-loop
```

Aliases aceitos:

- Continuous Stewardship Loop;
- continuous software company loop;
- loop 24h governado;
- always-on stewardship loop;
- NS-v3 Continuous Loop, apenas como alias historico/transicional.

Regra: `Night Shift` continua significando janela agendada/noturna/batch. Quando
o Atlas opera o tempo inteiro com budget, locks, rate limits, pause policy, kill
switch e inbox, o nome canonico e `Atlas Continuous Stewardship Loop`.

### Regra Do Teto

`Atlas Continuous Stewardship Loop` nao e o maximo desta stack. Ele resolve
continuidade operacional. O salto acima e responsabilidade crescente:

```text
continuous operation
-> area responsibility
-> portfolio responsibility
-> executive recommendation
-> governed self-expansion
```

O teto desta stack e `Self-Expanding Software Company`: Atlas detecta que uma
nova area, loop, capability ou runtime contract deveria existir, escreve a
proposta/spec e envia ao operador. Ele nao promove sozinho.

Acima desse teto, a conversa sai da software company stewardship stack e entra
na lineage maior do Atlas em `atlas-ai-evolution-lineage-and-target-state.md`.

AP-730 implementa a primeira versao em codigo dessa regra: um read model
read-only/proposal-only que projeta Area Stewardship, Portfolio Stewardship,
Autonomous Executive e Self-Expanding Software Company a partir do Area Focus
Loop, sem executar nada.

AP-731 adiciona o ledger append-only de decisoes do operador sobre esses
outputs. Ele torna a ladder revisavel/replayable, mas ainda nao executa Dev,
Forge, branch, merge, deploy ou promocao.

AP-733 adiciona o primeiro Portfolio Health Model persistente/replayable. Ele
mede saude do portfolio, dependencias, risco e candidatos de rebalanceamento,
mas ainda nao executa decisoes, nao chama Dev/Forge e nao promove camadas.

AP-734 adiciona Portfolio Steward Inbox sobre AP-733: transforma candidatos de
rebalanceamento em itens revisaveis, grava inbox JSONL append-only e registra
decisoes explicitas via AP-731. Ele nao executa rebalanceamento.

AP-735 adiciona Autonomous Executive Recommendation sobre AP-734: transforma
itens do Portfolio Steward Inbox em recomendacoes executivas de estrategia,
capacidade, budget, regret e risco, grava packs JSONL append-only e registra
decisoes via AP-731. Ele nao aloca agentes, cria branch, invoca Forge, faz
merge, deploy ou gasto autonomo.

AP-736 adiciona Executive Decision Inbox Surface sobre AP-735: transforma
recomendacoes executivas e receipts AP-731 em uma surface read-only para
cockpit/Product Mode, com estados pendente/aceito/deferido/rejeitado e anchors
estaveis. Ela nao grava decisoes nem executa trabalho.

AP-737 adiciona New Area Proposal Gate sobre AP-730/AP-731: transforma propostas
de novas areas em gate items com anchors estaveis, draft
`atlas.domain.creation_proposal.v1`, safety lock para dominios sensiveis e
estado de decisao do operador. Ele nao cria dominio, departamento, branch,
runtime ou executor.

AP-738 adiciona Self-Expanding Software Company v0 sobre AP-737: classifica
candidatos em novos dominios, handoffs de capacidades existentes, dominios
sensiveis e itens prontos para Domain Runtime Creation Gate. Ele e o teto
proposal-only desta stack e nao cria nada sozinho.

AP-739 integra AP-721/AP-736/AP-737/AP-738 em um Product Mode/Cockpit visual
read-only. Ele agrega Area Focus, Executive Decision Inbox, New Area Proposal
Gate e Self-Expanding Software Company v0 em uma unica review queue para o
operador, com HTTP ETag, CLI e surface Desktop `stewardship`. Ele nao grava
decisoes, nao invoca Dev/Forge, nao abre branch e nao cria dominios.

AP-740/AP-748 gravam outcomes AP-731/AP-738/AP-747 em Evidence Ledger e Morning Inbox. AP-749
abre consumo owner-specific para Atlas Dev/Forge. AP-758 adapta esse consumo em
um `owner_result` AP-750-compatible reutilizando os owners existentes. AP-759
executa um comando owner CLI allowlisted dentro do worktree AP-756 quando houver
receipt explicito. AP-750 fecha o ciclo aceitando somente receipts de resultado
owner-runtime com schema AP-750, identidade
AP-749, evidence pack, branch isolation e approval explicito para qualquer claim
de merge/deploy/external push/secret/destructive change. AP-741
usa esses outcomes para montar handoff packet ao Domain Runtime Creation Gate;
se AP-740 nao tiver evidence registrada, o handoff fica blocked. AP-742 torna
AP-740/AP-741 visiveis dentro do mesmo Product Mode/Cockpit AP-739, evitando
outcome invisivel e evitando cockpit paralelo. AP-743 cria o pacote de active
handoff da Area Stewardship quando AP-731 e AP-732 ja provaram readiness; ele
nao inicia o operating loop ativo. AP-744 consome esse pacote, roda o ciclo
AP-722, cria drafts AP-718 e prepara handoffs AP-726 como primeiro operating
slice ativo sem provider, sem branch real, sem dispatch Dev/Forge e sem mutacao
irreversivel.

AP-754 adiciona o primeiro read model de controles operacionais do Product Mode:
repo onboarding state, autonomy tier, budget/rate limit, kill switch/pause/lock,
branch review center, evidence inspector e risk policy. Ele aparece no cockpit
AP-739, mas nao autoriza repos, nao altera tiers, nao cria branches, nao chama
providers e nao executa merge/deploy/secrets.

AP-755 fecha o buraco entre input solto e governanca persistente: os controles
do Product Mode podem ser gravados como receipts AP-731 com
`target_type=product_mode_control`. Apenas receipts aceitos alimentam a projecao
AP-754; rejeitados/deferred/request_changes ficam como historico auditavel.
AP-755 nao cria ledger, executor, branch, provider path, merge/deploy/secrets ou
scheduler.

AP-756 fecha o buraco entre branch plan e sandbox fisico: um handoff AP-726 pode
virar branch/worktree git local isolado somente com receipt explicito do
operador. Ele grava JSONL idempotente do sandbox, mas nao executa Dev/Forge,
provider, fix, commit, merge, deploy, push externo, secrets ou destructive
changes.

AP-757 fecha o buraco entre sandbox fisico e consumo owner-specific: AP-749 so
fica pronto para Dev/Forge quando o record AP-756 materializado pertence ao
mesmo handoff AP-747 e o worktree local existe. Ele apenas carrega
`branch_sandbox` no owner runtime input; nao executa o owner.

### Prioridade Atual

```text
Usar AP-746 para invocar o Continuous Stewardship recorrente apenas depois de
AP-745/AP-744 estarem verdes. AP-756 agora materializa o branch/worktree local
isolado quando houver receipt; AP-757 vincula esse sandbox ao AP-749 antes do
owner runtime input ficar pronto; AP-758 adapta o consumo em resultado owner;
AP-759 executa comando owner CLI allowlisted dentro do sandbox quando houver
receipt; AP-750 continua o unlock seguinte: retorno do resultado para
Evidence/Morning Inbox/Portfolio, ainda sem merge, deploy ou secrets automaticos.
```

Self-Expanding Software Company continua proposal-only: AP-737 permite preparar
o handoff ao Domain Runtime Creation Gate, mas nenhuma area nasce sem evidence,
dual review e operator approval.

## Fluxo

```text
operator selects scope
-> stack resolves current level
-> child doc owns behavior
-> Continuous Stewardship Loop schedules or runs governed cycles when 24h mode is enabled
-> Area Focus Loop scans
-> Self-Directed Evolution drafts specs
-> Dev/Forge route work
-> AP-759 sandboxed owner command when operator-authorized
-> AP-760 Product Mode visibility for AP-759
-> owner runtime result bridge closes AP-750
-> AP-765 runtime result bridge closes the first complete 24h cycle (structured evidence pack + real inbox item + Product Mode event + portfolio signal + cycle receipt)
-> Evidence proves claims
-> Product Mode/Morning Inbox asks operator decisions
-> outcomes feed Area Stewardship
```

## Regras Para IA

Se a tarefa mencionar qualquer item desta familia, faca:

1. Leia este doc.
2. Leia o child doc especifico.
3. Confirme que nao esta criando OS/runtime paralelo.
4. Confirme se o nivel e atual ou futuro.
5. Preserve operator review, evidence, branch isolation, WIP, budget e kill
   switch.

Mapa rapido: Night Shift/Product Mode/Continuous/Area Focus leem o doc Product Mode; Area Stewardship le `atlas-area-stewardship-layer.md`; Portfolio/Executive/Self-Expanding leem `atlas-stewardship-evolution-ladder.md`.

## Escopo De Implementacao

Ordem obrigatoria:

1. Area Focus Loop read-only para `agentic_engineering_os`.
2. Area Focus Loop com findings e Morning Inbox.
3. Area Focus Loop com spec drafts.
4. Area Focus Loop com Dev/Forge routing.
5. Branch sandbox guardado.
6. Atlas Continuous Stewardship Loop scheduler-safe tick, AP-745.
7. Area Stewardship read-only.
8. Area Stewardship active handoff, gated by AP-731 accept + AP-732 readiness.
9. Portfolio Health Model persistente/replayable, AP-733.
10. Portfolio Steward Inbox, AP-734.
11. Autonomous Executive Recommendation Pack, AP-735.
12. Executive Decision Inbox Surface, AP-736.
13. New Area Proposal Gate, AP-737.
14. Self-Expanding Software Company v0 proposal-only, AP-738.
15. Product Mode/Cockpit visual para AP-736/AP-737/AP-738, AP-739.
16. Evidence Ledger + Morning Inbox para AP-731/AP-738/AP-747 outcomes, AP-740/AP-748.
17. Handoff AP-738 -> Domain Runtime Creation Gate quando houver AP-731 accept sem blockers e AP-740 evidence registrada, AP-741.
18. Product Mode/Cockpit history para outcomes AP-740 e handoffs AP-741, AP-742.
19. Area Stewardship active handoff com AP-732 readiness, AP-743.
20. Area Stewardship active operating slice consumindo AP-743, AP-744.
21. Scheduler recorrente seguro AP-746 sobre AP-745, ainda sem mutacao irreversivel.
22. Release AP-747, AP-748 Evidence/Morning Inbox/Portfolio e AP-749 consumption gate para filas Atlas Dev/Forge.
23. Owner runtime execution adapter AP-758 para transformar AP-749 pronto em `owner_result` AP-750-compatible reutilizando Atlas Dev/Forge existentes.
24. Owner sandbox runtime runner AP-759 para executar comando owner CLI allowlisted dentro do worktree AP-756 sob receipt explicito e devolver `owner_result` AP-750-compatible.
25. Product Mode owner sandbox visibility AP-760 para tornar AP-759 revisavel no cockpit unificado, ainda read-only.
26. Product Mode Desktop end-to-end console AP-761 para tornar a surface `stewardship` capaz de revisar o pipeline inteiro sem executar nada.
27. Owner runtime result bridge AP-750 para devolver resultado Dev/Forge a Evidence, Morning Inbox e Portfolio antes de merge, deploy, follow-up ou rebalance.
28. Portfolio owner-runtime result signal intake AP-751 para transformar AP-750 em health/risk/rebalance no AP-733.
29. Autonomous Executive allocation handoff AP-752 para transformar AP-735 + AP-731 accept em pacote de alocacao ao owner correto, ainda sem execucao.
30. Product Mode visibility AP-753 para tornar AP-752 revisavel no cockpit unificado, ainda read-only.
31. Product Mode operational controls AP-754 para tornar onboarding, tiers, budget, branch review, evidence inspector e kill switch visiveis no cockpit, ainda read-only.
32. Product Mode operational control receipts AP-755 para tornar esses controles persistentes/replayable via AP-731, ainda sem ledger paralelo ou execucao.
33. Branch sandbox materializer AP-756 para criar branch/worktree git local isolado sob receipt explicito, ainda sem Dev/Forge dispatch, provider, fix, merge, deploy ou secrets.
34. Owner queue sandbox binding AP-757 para exigir o record AP-756 materializado dentro do AP-749 antes de Dev/Forge owner runtime input ficar pronto.
35. End-to-end live cycle certification AP-762 para provar a cadeia completa em projection e em owner command sandboxado opcional antes de declarar 100% da stack.
36. Requirement-by-requirement completion audit AP-763 para transformar a lista pratica do operador em 29 linhas auditaveis, responder `current_practical_number`, e bloquear claim de 100% quando a prova AP-759/AP-762 nao estiver completa.
37. Atlas-native Stewardship Obra runner AP-764 para transformar ciclos AP-746/AP-744 em handoffs nativos de Obra para Atlas Dev/Forge, sem depender de automacao externa do Codex e sem chamar provider fora de AP-759.
38. Stewardship Runtime Result Bridge AP-765 (Evidence / Product Mode loop closer) para fechar o primeiro ciclo 24h completo: recebe o execution_result direto de Dev/Forge/owner + ids do loop (finding/spec/handoff/sandbox) e produz evidence pack estruturado, inbox item real e leve (via AtlasInboxService/ProposalInboxEmitter), Product Mode visibility event, sinal de portfolio no formato AP-751 e receipt final, sem merge/deploy/secrets, sem auto-approve e reutilizando AP-740/AP-750/AP-751 (sem ledger/inbox paralelo).
39. Dev/Forge Runtime Execution Bridge AP-767 (`dev-forge-execute`, produtor minimo do primeiro ciclo) para fechar o gap entre handoff/finding/spec aprovado + sandbox materializado e o AP-765: escolhe owner atlas_dev|forge, roda owner-specific consumption gate (area, isolamento, allowed_paths, nao-main, sem merge/deploy, kill switch, budget), mapeia capability slots architect/executor/reviewer/certifier sem hardcodar modelo, e em modo execute roda uma local deterministic owner task (read-only + testes allowlisted dentro do worktree) ou reporta provider_bridge_missing com contrato claro. Emite um execution_result que o AP-765 consome direto; nao inventa provider, nao muta fora do sandbox, nao faz merge/deploy/push/secrets e compoe (nao duplica) os owners AP-758/AP-759. Provider real continua operator-gated em AP-758/AP-759.
40. Stewardship Branch Merge Governor AP-769 para transformar branches de ciclo em fluxo enterprise: branch/commit visivel no GitKraken, preflight de conflito via git, classificacao docs/test/code, ledger append-only, `review_required|auto_merge_eligible|merged|blocked`, e auto-merge ff-only apenas para mudancas pequenas/seguras ou bugfix/cleanup explicitamente autorizado com validacao verde. Nao faz rebase, squash, force-push, deploy ou secret access.
41. Stewardship Branch Lifecycle Registry AP-770 para reservar identidade de branch antes do AP-756, bloquear ciclos paralelos disputando o mesmo `repo_root_hash|branch_name`, listar branch WIP ativo e manter ledger append-only `reserved|materialized|merged|released|blocked` sem criar branch, worktree, merge, deploy, push ou secrets.
42. Stewardship Priority Engine AP-771 para ordenar achados, branches e work items por maior avanco e robustez: combina advancement, robustez, reducao de risco, mergeability, confianca, penalidade de conflito e blast radius para alimentar AP-756/AP-769/AP-770 sem substituir seus gates.
43. Stewardship Merge Queue AP-772 para operar fila sequencial de branches: avalia cada branch com AP-769, ordena via AP-771, reavalia contra a base viva antes de cada auto-merge ff-only e bloqueia branches stale/diverged em vez de mesclar em paralelo.
44. Stewardship Branch Safety Audit AP-773 para varrer branches locais ou informadas antes da fila AP-772, reutilizar AP-769/AP-770, bloquear stale/conflict/orphan/already-merged e emitir apenas `queue_ready_branch_refs` como entrada segura para a fila 24h.

## Dependencias

| Componente | Owner |
|---|---|
| Organizacao de software | Autonomous Software Company Runtime |
| Ciclos | Night Shift |
| Produto/cockpit | Night Shift Product Mode |
| Operacao 24h/always-on | Atlas Continuous Stewardship Loop |
| Foco por area | Area Focus Loop |
| Stewardship por area | Area Stewardship Layer |
| Futuro portfolio/executivo | Stewardship Evolution Ladder |
| Specs | Self-Directed Evolution |
| Execucao | Atlas Dev / Forge |
| Prova | Evidence Certification Runtime |

## Evidencias

Claims aceitos exigem:

- doc/AP owner;
- runtime ou read-model quando claimar implementacao;
- evidence pack;
- operator inbox;
- tests/docs-health/architecture validation;
- prova de que nao criou OS/runtime paralelo.

## Riscos

| Risco | Mitigacao |
|---|---|
| IA inventa nome novo | Este doc e o guarda-chuva canonico. |
| IA pula para Executive cedo demais | Promotion gates obrigam evidence do nivel anterior, AP-731 receipts e review no cockpit antes de qualquer execucao. |
| Product Mode vira bot perigoso | Budget, WIP, kill switch, inbox e no-merge/no-deploy. |
| Portfolio duplica Company Runtime | Portfolio decide entre areas; Company Runtime coordena organizacao. |

## Exemplos

- `agentic_engineering_os`: AP-747 release -> AP-748 outcome -> AP-749 owner input -> AP-758 owner execution adapter -> AP-759 sandboxed owner command when authorized -> AP-760 Product Mode visibility -> AP-750 result bridge -> AP-751 portfolio health signal -> AP-735 recommendation -> AP-752 allocation handoff after operator accept -> AP-753 cockpit review.

## Proximas Acoes

1. Usar AP-758 para adaptar AP-749 pronto, AP-759 para executar comando owner CLI allowlisted dentro do sandbox quando houver receipt, AP-760 para revisar esse run no Product Mode e AP-750 para registrar resultados owner-runtime.
2. Alimentar Portfolio Stewardship via AP-751 com outcomes reais de AP-750 antes de qualquer rebalance ou follow-up autonomo.
3. Usar AP-752 para transformar recomendacoes executivas aceitas em handoff de owner, sem execucao automatica.
4. Revisar handoffs AP-752 pelo Product Mode/Cockpit AP-753 antes de qualquer owner executar follow-up.
5. Usar AP-756 para materializar branch/worktree isolado, AP-757 para vincular esse sandbox ao AP-749, AP-758 para projetar o caminho owner-runtime, AP-759 para executar owner command e AP-760 para cockpit visibility antes do AP-750.
