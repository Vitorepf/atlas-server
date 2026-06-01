---
id: atlas-programming-domain-adapter-integration-plan
type: engineering_knowledge
title: Atlas Programming Domain Adapter Integration Plan
status: active
category: atlas-ai
priority: 100
summary: Plano canonico Meta 7 para conectar Programming Domain (Atlas Dev, Forge, Repair, Review, QA, Security, Database, Visual, Forge handoff) ao novo Kernel do Atlas AI (Mission Foundation, Domain Runtime, Policy, Evidence, Tool Economy, Router, Control Plane) por bridges adaptadores, sem refatorar runtime critico nem fundir Atlas Dev com Atlas Forge.
tags:
  - atlas-ai
  - programming
  - meta-7
  - integration-plan
  - adapter
capabilities:
  - programming_domain_adapter
  - mission_to_workorder_bridge
  - dev_runtime_kernel_bridge
  - forge_handoff_bridge
  - evidence_publisher_bridge
  - policy_enforcer_bridge
  - control_plane_projection
decisions:
  - Programming Adapter integra Atlas Dev/Forge ao novo Kernel por bridges, nao por refatoracao do runtime.
  - Atlas Dev permanece flow real e completo dentro do Programming Domain; nao vira proxy do Kernel nem fusao com Forge.
  - Atlas Forge permanece sistema completo para Obra; Kernel chama Forge via handoff packet, nunca atalho.
  - Cada adapter e thin bridge: traduz contratos, nao reimplementa logica de Dev/Forge.
  - Meta 7 entrega backend; nao toca Atlas Dev UI nem Forge UI nesta meta.
  - Programming Adapter promove o dominio a Domain Stage 3 (Department) com handoff cross-domain auditavel.
maintenance:
  - Atualize este doc antes de mudar bridges, contratos de entrada/saida ou fases de implementacao.
  - Nao adicionar capability a Atlas Dev/Forge fora do dominio Programming sem novo manifest.
  - Nao implementar bridge sem Meta 1/2/3/4 active e Programming Domain ready.
related_paths:
  - docs/engineering-knowledge-base/atlas-autonomous-software-company-runtime.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-index.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-programming-governance-system.md
  - docs/engineering-knowledge-base/domains/programming.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-domain-runtime-contract.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-tool-economy.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-programming-domain-adapter-integration-plan
graph_title: Atlas Programming Domain Adapter Integration Plan
graph_world: atlas
graph_layer: system
graph_kind: contract
graph_parent: atlas-domain-runtime-contract
graph_status: active
graph_source: repo
human_name: Atlas Programming Domain Adapter Integration Plan
canonical_name: Atlas Programming Domain Adapter Integration Plan
technical_name: atlas-programming-domain-adapter-integration-plan
cartography_type: contract
canonical_source: docs/engineering-knowledge-base/atlas-programming-domain-adapter-integration-plan.md
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-domain-adapter-integration-plan.md
allowed_changes:
  - Refinar contratos de bridge, fases de rollout e testes obrigatorios.
  - Adicionar adapter quando surgir capability nova do Programming Domain.
forbidden_changes:
  - Implementar bridges neste pack; ele e design only.
  - Refatorar AtlasDevRuntimeService, AtlasProgrammingOrchestrator ou AtlasForgeProviderInvocationService sem AP dedicado.
  - Fundir Atlas Dev e Atlas Forge.
  - Adicionar capability nova ao Programming fora do Domain Manifest.
  - Pular Mission Foundation, Domain Runtime, Policy ou Evidence ao executar trabalho de programacao.
depends_on:
  - atlas-kernel-mission-foundation
  - atlas-domain-runtime-contract
  - atlas-permission-budget-safety-layer
  - atlas-evidence-certification-runtime
  - atlas-tool-economy
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-autonomous-software-company-runtime
  - atlas-dual-core-engineering-system
  - atlas-programming-governance-system
  - atlas-dev-efficient-programming-flow-v1
  - atlas-forge-operating-system
flows_to:
  - atlas-autonomous-control-plane
  - atlas-dual-core-engineering-system
  - atlas-forge-operating-system
unlocks:
  - programming-as-kernel-domain
  - cross-domain-programming-handoff
  - mission-driven-dev-and-forge
governs:
  - atlas_ai.programming.adapter
  - atlas_ai.programming.mission_bridge
  - atlas_ai.programming.handoff
evidence:
  - docs/engineering-knowledge-base/atlas-programming-domain-adapter-integration-plan.md
evidence_refs:
  - symbol: DomainHandoffService
  - command: atlas:ai:domain-runtime
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Onde Se Encaixa, Bridges Obrigatorios, Escopo de Implementacao e Rollback antes de implementar.
ai_usage_notes:
  - Este pack e design. Implementacao real abre AP por fase; nao executar tudo numa sessao.
quality_gates:
  - manifest-loaded
  - mission-bridge-emits-work-order
  - evidence-bridge-passes-certification
  - policy-bridge-blocks-forbidden
  - handoff-bridge-carries-evidence
  - dev-runtime-untouched
  - forge-runtime-untouched
failure_modes:
  - Bridge virar refatoracao silenciosa do runtime de Dev ou Forge.
  - Manifest declarar capability que o Programming nao implementa.
  - Mission Bridge perder mission_id no caminho ate o work_order.
  - Evidence Bridge emitir Receipt fora do dominio programming.
  - Policy Bridge bypassar SafetyDecisionService.
  - Handoff Bridge perder evidence_refs ao cruzar fronteira.
observability_signals:
  - programming_domain_stage
  - mission_bridge_work_orders_emitted
  - evidence_bridge_packs_built
  - certification_pass_rate
  - handoff_count
  - policy_block_count
  - dev_to_forge_escalations
next_actions:
  - Validar invariantes com docs-health antes de abrir AP da Fase 1.
  - Abrir AP da Fase 1 (ManifestLoader + ReadinessProbe) somente apos Meta 1/2/3/4 confirmadas active e Domain Runtime backend implementado.
  - Coordenar com sessoes do Forge OS antes de adicionar handoff bridge.
line_limit: 520
---
# Atlas Programming Domain Adapter Integration Plan
## Resumo
Programming Adapter (Meta 7) e o conjunto canonico de bridges que conecta o
Programming Domain (Atlas Dev, Forge, Repair, Review, QA, Security, Database,
Visual, Forge handoff) ao novo Atlas AI Kernel (Mission Foundation Meta 1,
Domain Runtime Meta 2, Policy Meta 3, Evidence/Certification Meta 4, Router,
Tool Economy, Control Plane). Este pack e design only: nenhuma migration,
model ou service e criado nesta sessao. Implementacao real abre AP por fase.
## Papel no Atlas
Programming e o primeiro dominio real completo a ser plenamente plugado no
novo Kernel. O Adapter:
- Mantem Atlas Dev como flow rapido e completo dentro do dominio.
- Mantem Atlas Forge como sistema de Obra, intacto, plugado por handoff.
- Garante que todo trabalho relevante de programacao carregue `mission_id`,
  `objective_id`, `work_order_id`, `evidence_pack_id`, `certification_id`.
- Promove Programming a Domain Stage 3 (Department) por evidencia, nao por
  declaracao.
O Adapter nao decide rota, modelo, provider, ferramenta, custo ou prompt. Quem
decide: Router (flow), Atlas Decide (provider), Policy (permission/budget),
Tool Economy (tool). O Adapter traduz contratos.
## Onde Se Encaixa
```text
Atlas AI Surface (Desktop, CLI, Chat, MCP)
-> Mission Mode + Objective Intelligence (cria mission e objectives)
-> Router (decide flow_id; ex.: atlas_dev | atlas_review | atlas_debug | atlas_forge)
-> Domain Registry (resolve domain_id = programming)
-> Programming Domain Runtime (this Adapter)
   -> Manifest + Capability Catalog
   -> AtlasProgrammingOrchestrator
      -> programming.dev      -> AtlasDevRuntimeService
      -> programming.repair   -> ProgrammingRepairExecutor + RepairOrchestrator
      -> programming.review   -> Review pipeline
      -> programming.qa | .security | .database | .visual
      -> programming.forge    -> handoff packet -> AtlasForge*
-> Programming Governance Gates (placement, spec, scope, evidence, completion)
-> Evidence/Certification Runtime (Receipts, Artifacts, TestResults, GateRuns)
-> Mission Lifecycle (running -> certifying -> completed | blocked | failed)
-> Control Plane Projection
```

Programming entra como Stage 3 Department porque ja tem departamentos
internos (Dev, Forge, Review, Repair, QA, Security), workflows, gates,
artifacts e metrics. Stage 4/5 ficam para metas posteriores.

## Contratos

Bridges adapter publicam e consomem contratos das Metas 1-4 e do Router:

- Consome `atlas.ai.mission.v1`, `atlas.ai.objective.v1`,
  `atlas.ai.work_order.v1` (Meta 1).
- Publica `atlas.ai.domain_manifest.v1`, `atlas.ai.domain_capability.v1`,
  `atlas.ai.domain_handoff.v1`, `atlas.ai.domain_delivery.v1`,
  `atlas.ai.domain_certification.v1`, `atlas.ai.domain_maturity_assessment.v1`
  (Meta 2).
- Consome `atlas.ai.policy_profile.v1` (`programming.default`),
  `atlas.ai.safety_decision.v1` (Meta 3).
- Publica `atlas.ai.evidence.receipt.v1`,
  `atlas.ai.evidence.artifact.v1`, `atlas.ai.evidence.test_result.v1`,
  `atlas.ai.evidence.gate_run.v1`, `atlas.ai.evidence.claim.v1`,
  `atlas.ai.evidence.evidence_pack.v1`, `atlas.ai.evidence.certification.v1`,
  `atlas.ai.evidence.blocker.v1`, `atlas.ai.evidence.audit_event.v1` (Meta 4).
- Consome `atlas.ai.router.flow_decision.v1`,
  `atlas.ai.specialist_flow_runtime.v1`,
  `atlas.ai.specialist_flow_receipt.v1` (Router).
- Publica `atlas.ai.tool.decision.v1` advisory enquanto Tool Registry nao
  existe.

Entrada principal do Programming via Kernel:

```text
DomainExecutionRequest {
  mission_id, objective_id, work_order_id,
  domain_id = "programming",
  capability_id in {programming.dev|.repair|.review|.refactor|.qa|.security|.database|.visual|.forge},
  context_pack { workspace_path, task_contract_hash, risk_level,
                 autonomy_level, evidence_refs[], safety_decision_id? },
  expected_output { artifacts[], tests[], receipt_kinds[] }
}
```

Saida principal por capability:

```text
DomainExecutionResult {
  mission_id, work_order_id,
  status in {ok, partial, blocked, failed},
  evidence_pack_id, certification_id?,
  blocker_refs[], next_action, receipt_hash
}
```

Handoff Dev -> Forge:

```text
DomainHandoff {
  mission_id, objective_id,
  source_domain = "programming", source_capability in {.dev|.repair|.review},
  target_domain = "programming", target_capability = "programming.forge",
  reason in {scope_expansion|sdd_required|multiagent|evidence_insufficient|high_risk|time_budget_exceeded},
  context_pack { workspace, task_contract, evidence_refs, risk_register },
  expected_output, receipt_hash
}
```

## Fluxo

1. Surface coleta prompt e contexto (workspace, anexos, slash command).
2. Mission Mode classifica trivial | task | mission | obra. Para `task` ou
   acima, cria `Mission` (Meta 1) com `mission_type`, `autonomy_level`,
   `risk_level`.
3. Objective Intelligence emite `Objective` com `success_criteria`,
   `constraints`, `definition_of_done`.
4. Router decide `flow_id` (`atlas_dev`, `atlas_review`, `atlas_debug`,
   `atlas_forge`, ...) e emite `AtlasAiRouterDecision`. Para todos esses,
   `domain_id = programming`.
5. AtlasDevMissionAdapter consome `objective` e produz `WorkOrder` (Meta 1)
   com `expected_artifacts`, `expected_tests`, `risk_notes`, `rollback_plan`,
   `receipt_hash`.
6. `AtlasProgrammingOrchestrator` recebe `ProgrammingExecutionRequest`
   enriquecido com `mission_id`, `objective_id`, `work_order_id` e executa o
   flow.
7. Cada etapa emite Receipt + Artifact + TestResult + GateRun via
   ProgrammingEvidenceBridge.
8. `CertificationRuntimeService::certify(target_type=work_order)` antes do
   Mission Lifecycle aceitar `completed`.

## Bridges Obrigatorios

Cada bridge e um servico thin em `app/Services/Ai/Programming/Kernel/`:

| # | Bridge | Path sugerido | Papel |
| --- | --- | --- | --- |
| 1 | ProgrammingDomainManifestSeeder | `database/seeders/Ai/ProgrammingDomainManifestSeeder.php` | Carrega `atlas.ai.domain_manifest.v1` em `ai_domain_manifests` com charter, departments (dev/repair/review/qa/security/database/visual/forge), capabilities `programming.*`, `policy_profile=programming.default`, `maturity_stage=specialist` |
| 2 | ProgrammingDomainRuntimeAdapter | `app/Services/Ai/Programming/Kernel/ProgrammingDomainRuntimeAdapter.php` | Implementa interface `DomainRuntime` Meta 2 sobre `AtlasProgrammingOrchestrator`: `canHandle`, `plan`, `execute`, `validate`, `certify`, `handoff`, `describeCapabilities` |
| 3 | AtlasDevMissionAdapter | `app/Services/Ai/Programming/Kernel/AtlasDevMissionAdapter.php` | Traduz `WorkOrder` em `ProgrammingExecutionRequest` para `programming.dev`. Propaga `mission_id`, `objective_id`, `work_order_id`, `task_contract_hash`, `risk_level`, `autonomy_level` ate `AtlasDevRuntimeService` |
| 4 | AtlasForgeHandoffAdapter | `app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php` | Cobre `programming.forge`. Recebe escalacao Dual-Core, emite `DomainHandoff` Meta 2 com `context_pack` + `evidence_refs`, dispara Forge, acompanha resposta |
| 5 | ProgrammingEvidenceBridge | `app/Services/Ai/Programming/Kernel/ProgrammingEvidenceBridge.php` | Liga stage receipts, gate runs, completion audit, repair attempts aos contratos Meta 4 via `EvidencePackService::build(target_type=work_order)` |
| 6 | ProgrammingPolicyBridge | `app/Services/Ai/Programming/Kernel/ProgrammingPolicyBridge.php` | Antes de provider call, repo clone, npm install, git push, deploy ou secret access, chama `SafetyDecisionService::decide` e respeita `allow|require_approval|sandbox_required|blocked` |
| 7 | ProgrammingToolBridge | `app/Services/Ai/Programming/Kernel/ProgrammingToolBridge.php` | Liga decisao de ferramenta (lints/SAST/scanners/builders) ao Tool Economy. Enquanto Tool Registry nao existir, registra `tool.decision.v1` advisory e gateia via Policy |
| 8 | ProgrammingControlPlaneProjection | `app/Services/Ai/Programming/Kernel/ProgrammingControlPlaneProjection.php` | Projecta para Control Plane: open work_orders, blocked, certification_pass_rate, dev_to_forge_escalations, evidence_pack_count, policy_block_count |
| 9 | AtlasAiProgrammingDomainCommand | `app/Console/Commands/AtlasAiProgrammingDomainCommand.php` | Comando `atlas:ai:programming-domain` com acoes `readiness`, `seed-manifest`, `smoke`, `control-plane`. Espelha padrao das outras metas |

## Atlas Dev: Flow Dentro Do Programming Domain

Atlas Dev permanece runtime de patch, repair, refactor lite, code generation
com mini-spec, task contract, scope guard, verification receipt. O Adapter
adiciona apenas:

- Entrada: AtlasDevMissionAdapter traduz `WorkOrder` para `ProgrammingExecutionRequest`.
- Saida: ProgrammingEvidenceBridge traduz Receipts/Artifacts/Tests internos
  em contratos `atlas.ai.evidence.*.v1` referenciando o `WorkOrder`.

Forbidden: refatorar `AtlasDevRuntimeService` internamente, mudar pipeline,
gates ou prompts. Tudo isso continua governado pelo
`atlas-dev-efficient-programming-flow-v1.md`.

## Atlas Forge: Obra, Sem Fusao

Atlas Forge permanece sistema completo de Obra (multi-agente, packets,
integration queue). O Adapter trata Forge como flow `programming.forge` com
handoff explicito:

- ProgrammingDualCoreEscalationDetector aplica regra do
  `atlas-dual-core-engineering-system.md`: escalacao quando scope expande,
  SDD obrigatorio, multiagente, risco alto, evidencia insuficiente.
- AtlasForgeHandoffAdapter constroi `DomainHandoff` com
  `source_domain=programming`, `target_capability=programming.forge`,
  `context_pack`, `expected_output`, `receipt_hash`.
- Forge consome packet e responde com `DomainDelivery` certificada.

Forbidden: tratar Forge como executor sincrono do Dev, fundir state, escrever
no Forge sem packet.

## Estado Atual Do Kernel E Do Programming

| Camada | Status | Backend principal |
| --- | --- | --- |
| Meta 1 Mission Foundation | active | tabelas `ai_missions`, `ai_objectives`, `ai_work_orders`, `ai_mission_events`, `ai_mission_evidence_refs`, `ai_mission_certifications`; services `Mission*`; cmd `atlas:ai:mission-foundation` |
| Meta 2 Domain Runtime | active | `ai_domain_manifests`, `ai_domain_capabilities`, `ai_domain_handoffs`, `ai_domain_maturity_assessments`; services `DomainManifestLoader`, `DomainRegistryService`, `CapabilityCatalogService`, `DomainHandoffService`, `DomainMaturityAssessmentService` |
| Meta 3 Policy/Safety | active | 7 tabelas `ai_policy_*`/`ai_permission_*`/`ai_approval_*`/`ai_budget_*`/`ai_risk_*`/`ai_safety_*`/`ai_forbidden_*`; services `PolicyCanon`, `PermissionGateService`, `SafetyDecisionService`; `programming.default` seed |
| Meta 4 Evidence/Certification | active | 11 tabelas `ai_evidence_*`/`ai_receipts`/`ai_claims`/`ai_artifacts`/`ai_source_refs`/`ai_gate_runs`/`ai_test_results`/`ai_operator_decisions`/`ai_certifications`/`ai_blockers`/`ai_audit_events`; 14 services `app/Services/Ai/Evidence/`; cmd `atlas:ai:evidence` |
| Router Runtime | active | `AtlasAiRouterService`, `AtlasAiRouterDecision` (`FLOW_DEV=atlas_dev`), tabelas `ai_router_decisions`, `ai_specialist_flow_executions` |
| Tool Economy | active (contrato) | sem Tool Registry backend ainda |

Programming hoje: 310 `.php` em `app/Services/Ai/Programming/`,
`AtlasProgrammingOrchestrator` registrado como `programming` (ready 9/9), 16
arquivos `Governance/` + 10 gates, 9 migrations `atlas_programming_*`,
comandos `atlas:programming:*`, surfaces `AtlasDesktopAiAdapter`,
`AtlasCliDevAdapter`, `AtlasApiInteractionAdapter`, `AtlasAppAdapter`. Lacuna:
zero `*Programming*Bridge*`/`*Mission*`/`*Adapter*Kernel*`/`*Handoff*`. Atlas
Dev/Forge nao consomem `mission_id`, `work_order_id`, `safety_decision_id`,
`certification_id`, `domain_handoff_id`.

## Arquivos Tocados E Intocaveis

A tocar (novos): `app/Services/Ai/Programming/Kernel/*.php` (9 bridges),
`database/seeders/Ai/ProgrammingDomainManifestSeeder.php`,
`app/Console/Commands/AtlasAiProgrammingDomainCommand.php`,
`tests/Feature/Ai/Programming/Kernel/*.php`.

Costura minima (uma linha cada, sob review): aceitar `mission_id`,
`objective_id`, `work_order_id` em `AtlasProgrammingOrchestrator` /
`ProgrammingExecutionRequest`; flag em `config/atlas_ai.php`.

NAO tocar nesta meta: `app/Services/Ai/Programming/AtlasDev/{Pipeline,Runtime,
Repair,Gate,SeniorLoop}/**`, `AtlasForge*InvocationService*`,
`AtlasForge*CliInvocationDriver*`, `AtlasCodeForgeFastPathService*`,
`Governance/**`, qualquer `AtlasRivals*`, migrations `atlas_programming_*` e
`atlas_dev_*` existentes. Mudancas reais nesses caminhos exigem AP dedicado.

## Integration Points

- **Mission Foundation:** AtlasDevMissionAdapter consome `mission_id`/
  `objective_id`/`work_order_id`; nunca cria mission propria.
- **Domain Runtime:** ManifestSeeder + RuntimeAdapter registram `programming`
  no `DomainRegistryService`; nunca burlam capability catalog.
- **Policy/Safety:** PolicyBridge chama `SafetyDecisionService::decide` antes
  de cada acao com risco; nunca executa antes da decisao.
- **Evidence/Certification:** EvidenceBridge constroi EvidencePack por
  WorkOrder e chama `CertificationRuntimeService::certify`; nunca declara
  `completed` sem `Certification.status=passed`.
- **Tool Economy:** ToolBridge registra `tool.decision.v1` advisory; aguarda
  Tool Registry.
- **Router Runtime:** Adapter consome `flow_id` ja decidido; nao decide flow.
  Apenas resolve `flow_id -> capability_id` dentro do dominio.
- **Control Plane:** Projection alimenta `atlas.ai.control_plane.snapshot.v1`
  com bloco `programming.*`.

## Regras para IA

- Nao implementar bridge neste pack; ele e contrato.
- Nao adicionar campos a `ProgrammingExecutionRequest`/`Result` sem AP.
- Nao escrever em `AtlasDev/*` ou `AtlasForge*Service*` enquanto desenvolve
  bridge.
- Nao fundir `programming.dev` com `programming.forge`.
- Nao declarar Stage 4/5 sem maturity assessment + evidencia.
- Nao tocar `AtlasRivals*` em nenhuma fase.

## Escopo de Implementacao

Pre-requisitos: Meta 1, 2, 3, 4 `active` em CI verde; Domain Runtime backend
implementado; `atlas:ai:policy --action=readiness` reporta
`programming.default` seed.

| Fase | Sessoes | Entrega |
| --- | --- | --- |
| 0 | 1 | AP de Pre-flight; valida invariantes documentais, ajusta este pack, abre AP-mae Meta 7 |
| 1 | 1 | ManifestSeeder + ProgrammingDomainReadinessService + comando `atlas:ai:programming-domain --action=readiness`; smoke mostra `programming` registrado sem execucao real |
| 2 | 1-2 | ProgrammingDomainRuntimeAdapter (canHandle, plan, describeCapabilities); sem execute ainda; unit tests |
| 3 | 2 | AtlasDevMissionAdapter + ProgrammingEvidenceBridge para `programming.dev`; WorkOrder -> Dev plan -> Dev run -> EvidencePack -> Certification; runtime intocado |
| 4 | 1 | ProgrammingPolicyBridge intercepta provider call, git push e acoes de risco; smoke vira `Blocker` real |
| 5 | 1 | AtlasForgeHandoffAdapter cobre `programming.forge` como handoff puro |
| 6 | 1 | Capabilities restantes (.repair, .review, .qa, .security, .database, .visual) |
| 7 | 1 | ProgrammingToolBridge advisory ate Tool Registry |
| 8 | 1 | ProgrammingControlPlaneProjection + dashboard read model |
| 9 | 1 | Maturity Assessment formal via `DomainMaturityAssessmentService` -> Stage 3 Department |

Total estimado: 10-12 sessoes pequenas, AP por fase, sem dependencia de UI.

## Testes Obrigatorios

- **Manifest:** `php artisan atlas:ai:programming-domain --action=readiness`
  reporta manifest carregado.
- **Mission Bridge:** dado `objective`, gera `WorkOrder` valido.
- **Dev Adapter:** WorkOrder `programming.dev` percorre plan -> run ->
  evidence -> certification com runtime intacto.
- **Evidence Bridge:** stage receipt interno gera `Receipt` + `Artifact`;
  falha sem evidence_refs.
- **Policy Bridge:** `git push` em risco high sem `OperatorDecision` vira
  `Blocker(missing_permission)`.
- **Handoff Bridge:** escalacao Dev->Forge emite `DomainHandoff` com
  `evidence_refs` + `context_pack`; rejeita packet sem evidence_refs.
- **Anti-fusion:** `AtlasDevRuntimeService` nao importa servico do Forge e vice-versa.
- **Anti-bypass:** capability programming sempre passa por `PermissionGateService` e `CertificationRuntimeService`.
- **Control Plane:** projection mostra open work_orders, blockers e
  cert_pass_rate corretos.
- **Maturity:** `DomainMaturityAssessmentService::assess(programming)`
  retorna `department` com evidencia citada.

## Dependencias

Meta 1-4 (mission-foundation, domain-runtime-contract,
permission-budget-safety-layer, evidence-certification-runtime), Tool Economy,
Router Runtime, Software Company Runtime, Dual-Core, Programming Governance,
Dev Flow v1, Forge OS, `domains/programming.md`. Ver `related_paths`.

## Evidencias

Doc versionado; 310 `.php` em `app/Services/Ai/Programming/` mapeados; 9
migrations `atlas_programming_*` identificadas; zero `*Programming*Bridge*`
hoje (caminho `app/Services/Ai/Programming/Kernel/` livre). Futuras (por AP):
seeder + readiness; feature tests por bridge;
`atlas:ai:programming-domain --action=control-plane --json`; architectural
tests anti-fusao e anti-bypass; maturity assessment receipt.

## Riscos

| Risco | Mitigacao |
| --- | --- |
| Bridge vira refator (editar `AtlasDev/*` ou `AtlasForge*Service`) | Bridges proibidos de editar runtime; architectural test bloqueia import cruzado |
| `mission_id` perdido entre bridges | Testes exigem `mission_id` em todo Receipt emitido |
| Manifest declara capability que nao roda | `readiness` cruza manifest com `AtlasDomainProfileRegistry` real |
| Forge handoff vira chamada sincrona | Handoff sempre via `DomainHandoffService::emit`; teste rejeita call sincrona |
| Policy ignorada por fast path do Dev | PolicyBridge interceptado no `SurfaceContractFactory` antes do flow |
| EvidencePack inflado | ClaimVerifier rejeita refs sem cobertura de requirement |
| Stage 3 prematuro | `DomainMaturityAssessmentService::assess` exige `evidence_refs` reais |

## Rollback Plan

- Fase 1: seeder reversivel via `down()` + `migrate:rollback`.
- Fase 2: RuntimeAdapter so instancia quando Router resolve
  `domain_id=programming`; binding off desliga.
- Fase 3: flag `atlas_ai.programming.mission_bridge=false` volta a chamar
  `AtlasProgrammingOrchestrator` direto.
- Fase 4: PolicyBridge atras de feature flag.
- Fase 5: HandoffAdapter off faz Dev->Forge cair para
  `AtlasCodeDevToForgePromotionController`.
- Fase 6-8: cada bridge isolado, feature flag dedicada.

Rollback global: desregistrar `programming` no `DomainRegistryService` +
desligar flags. Atlas Dev e Forge continuam operacionais.

## Exemplos

**Patch trivial:** "ajusta typo X em arquivo Y". Mission Mode classifica
`task`; Router decide `atlas_dev`; AtlasDevMissionAdapter monta request;
AtlasDevRuntimeService executa; EvidenceBridge emite Receipt(command),
Artifact(diff), TestResult(smoke), GateRun(scope_guard=passed);
Certification passa.

**Refactor multi-arquivo:** "refatora servico Z em 5 arquivos com SDD".
Mission Mode classifica `obra`; Router decide `atlas_forge`;
AtlasForgeHandoffAdapter emite DomainHandoff com context_pack; Forge executa
multi-agente; emite EvidencePack e DomainDelivery; Mission completed apos
Certification.

**Push remoto:** Atlas Dev tenta `git push origin main`. PolicyBridge consulta
SafetyDecisionService; resultado `require_approval`. AtlasDev emite
`Blocker(missing_permission, high)` com `next_action="atlas:ai:policy
--action=request-approval"`. WorkOrder fica `blocked` no Control Plane.

## Proximas Acoes

1. Meta 7 entregue (2026-05-18): 12 services em `app/Services/Ai/Programming/Kernel/` + comando `atlas:ai:programming-adapter --action=readiness|smoke|control-plane` + 10 testes verdes.
2. Ligar `AtlasDevMissionAdapter` ao Router/Intent para roteamento automatico do `programming` domain.
3. Substituir mock `programming.dev` execution request por chamada real a `AtlasDevRuntimeService` (sem refatorar runtime).
4. Coordenar com Forge para consumir handoff records via `AtlasForgeProviderInvocationDriver` na pipeline existente.
5. Promover manifest para Stage 4 (Operating Unit) com evidence/metrics/certification reais.
6. Atualizar `atlas-domain-company-runtimes.md` quando Stage 3 for provado em prod.

## Definition of Done

- `programming` aparece no `DomainRegistryService` com manifest valido.
- Toda WorkOrder de programacao referencia `mission_id`, `objective_id`,
  `work_order_id`.
- AtlasDevRuntimeService e AtlasForge*Service permanecem inalterados em
  comportamento (architectural test passa).
- EvidencePack e Certification existem para todo trabalho `task+` de
  programacao.
- PolicyBridge bloqueia ou pede aprovacao em acoes de risco; OperatorDecision
  gravada.
- Handoff Dev->Forge usa `DomainHandoff` com `evidence_refs` + `context_pack`.
- Control Plane mostra programming open work_orders, blockers, cert pass rate.
- Maturity Assessment certifica `programming` em Stage 3 (Department).
- docs-health passa.
- Testes cobrem caminho feliz, escalacao, blocker, anti-fusao e anti-bypass.

## Checklist De Implementacao Futura

- [ ] ProgrammingDomainManifestSeeder
- [ ] ProgrammingDomainRuntimeAdapter
- [ ] AtlasDevMissionAdapter
- [ ] AtlasForgeHandoffAdapter
- [ ] ProgrammingEvidenceBridge
- [ ] ProgrammingPolicyBridge
- [ ] ProgrammingToolBridge
- [ ] ProgrammingControlPlaneProjection
- [ ] AtlasAiProgrammingDomainCommand
- [ ] Tests por capability + architectural (anti-fusion, anti-bypass) + Maturity Assessment evidencing Stage 3
