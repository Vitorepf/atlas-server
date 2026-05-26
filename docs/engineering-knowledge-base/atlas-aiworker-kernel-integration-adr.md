---
id: atlas-aiworker-kernel-integration-adr
type: engineering_knowledge
title: Atlas AiWorker to Kernel Integration ADR
status: active
category: architecture
priority: 99
summary: ADR ativo que governa a integracao do path real de prompts HTTP (AiInteractionController -> AiGatewayService -> AiJob -> AiWorker -> AiProviderManager -> AtlasProgrammingOrchestrator) ao Kernel canonico. Autoridade documental esta ativa; implementacao runtime esta parcial por fases. Phase 1 persiste payload.kernel; Phases 4-6 permanecem como rollout controlado ate PermissionGate, Evidence e Certification serem consumidos pelo AiWorker com teste E2E.
implementation_status: partial
implementation_boundary: active_adr_phase_1_gateway_bridge_shipped_worker_phases_4_6_pending
tags:
  - atlas-ai
  - adr
  - kernel-integration
  - aiworker
  - mission-foundation
  - 2026-05-18
capabilities:
  - aiworker_kernel_bridge
  - http_prompt_mission_record
  - canonical_router_dispatch
  - kernel_permission_gate
  - kernel_certification_gate
  - legacy_path_feature_flag_rollback
decisions:
  - Path HTTP real (AiGatewayService + AiWorker) sera plugado ao Kernel canonico via 6 wires finos atras de feature flag, sem refatorar AiWorker, AtlasDevRuntimeService ou AtlasForge*Service.
  - Entrada do Kernel ocorre em AiGatewayService::enqueueInteraction (criacao de Mission e Router Runtime antes do AiJob), nao no AiWorker.
  - AiWorker recebe mission_id via AiJob.payload.kernel e o usa para consultar PermissionGateService (Meta 3), Evidence (Meta 4) e transicionar lifecycle de Mission (Meta 1).
  - Domain Programming usa exclusivamente ProgrammingDomainRuntimeAdapter + AtlasDevMissionAdapter + AtlasForgeHandoffAdapter (Meta 7); AiWorker nao chama Mission/Domain/Evidence direto.
  - Path legado (AiPermissionEngine + legacy Kernel/Pipeline + AtlasEvidenceLedger legacy + AiProviderManager) permanece intocado ate Phase 7 (fora deste ADR).
  - Tool Runtime strict mode deve ficar ativo por default; opt-out local exige configuracao explicita.
maintenance:
  - Atualize este ADR quando uma fase entrar em building/active.
  - Nao implementar codigo de producao sem AP por fase e gates verdes.
related_paths:
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-domain-company-runtimes.md
  - docs/engineering-knowledge-base/domains/domain-routing-governance.md
  - docs/engineering-knowledge-base/atlas-programming-domain-adapter-integration-plan.md
  - docs/engineering-knowledge-base/atlas-ai-router-runtime-enterprise-upgrade.md
  - docs/engineering-knowledge-base/atlas-permission-budget-safety-layer.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-aiworker-kernel-integration-adr
graph_title: Atlas AiWorker to Kernel Integration ADR
human_name: Atlas AiWorker to Kernel Integration ADR
canonical_name: Atlas AiWorker to Kernel Integration ADR
technical_name: AtlasAiWorkerKernelIntegrationAdr
cartography_type: adr
canonical_source: docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
graph_world: atlas
graph_layer: system
graph_kind: adr
graph_parent: atlas-architecture-critical-judgment-report
graph_status: active
graph_source: repo
owner: atlas-ai
repo_paths:
  - docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
allowed_changes:
  - Refinar contratos de bridge, fases, feature flag names e testes obrigatorios.
  - Atualizar evidencias quando um wire entrar em building/active.
forbidden_changes:
  - Declarar este ADR implementado sem teste Feature E2E que cubra controller HTTP real e termine com Certification passed.
  - Marcar AiWorker como "consumindo Kernel" sem evidencia de mission_id persistido em payload de AiJob real (nao smoke).
  - Refatorar AiWorker, AiProviderManager, AtlasDevRuntimeService, AtlasForge*InvocationService* ou Programming/Governance fora dos wires declarados aqui.
  - Fundir o legacy Kernel/Pipeline/Decision/Evidence com o Mission Kernel.
  - Remover a tolerancia silenciosa de ToolPolicyBridgeService/ToolReceiptService dentro deste ADR (escopo de AP irmao).
depends_on:
  - atlas-architecture-critical-judgment-report
  - atlas-autonomous-intelligence-operating-system
  - atlas-kernel-mission-foundation
  - atlas-evidence-certification-runtime
  - atlas-domain-company-runtimes
  - atlas-programming-domain-adapter-integration-plan
  - atlas-ai-router-runtime-enterprise-upgrade
  - atlas-permission-budget-safety-layer
flows_to:
  - atlas-autonomous-control-plane
  - atlas-ai-evolution-roadmap
unlocks:
  - canonical-http-prompt-pipeline
  - multi-domain-http-routing
  - end-to-end-mission-certification-from-http
governs:
  - atlas_ai.aiworker.kernel_integration
  - atlas_ai.aigateway.mission_record
  - atlas_ai.aiworker.permission_gate
  - atlas_ai.aiworker.certification_gate
evidence:
  - docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
  - app/Services/Ai/AiWorker.php
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/AiProviderManager.php
  - app/Services/Ai/Programming/AtlasProgrammingOrchestrator.php
  - app/Services/Ai/Mission/MissionFactoryService.php
  - app/Services/Ai/RouterRuntime/IntentKernelService.php
  - app/Services/Ai/Policy/PermissionGateService.php
  - app/Services/Ai/Evidence/CertificationRuntimeService.php
  - app/Services/Ai/Programming/Kernel/ProgrammingDomainRuntimeAdapter.php
  - app/Services/Ai/Programming/Kernel/AtlasDevMissionAdapter.php
required_tests:
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:mission-foundation --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-adapter --action=readiness --json"
  - "/opt/homebrew/bin/php artisan atlas:ai:evidence --action=readiness --json"
requires_evidence: true
risk_level: high
ai_entrypoints:
  - Leia Resumo, Onde Se Encaixa, Contratos, Fluxo, Escopo de Implementacao e Riscos antes de abrir AP de fase.
ai_usage_notes:
  - Este ADR e autoridade ativa de arquitetura, nao prova de que todas as fases runtime estao completas.
  - Phase 1 esta implementada no gateway; Phases 4-6 exigem AP dedicado, evidence e teste E2E antes de claim completed/enforced.
quality_gates:
  - aigateway-mission-recorded
  - aiworker-permission-gate-consulted
  - aiworker-certification-gate-consulted
  - feature-flag-default-off-in-production
failure_modes:
  - Wire perder mission_id entre AiGatewayService e AiWorker.
  - Worker certificar mission sem chamar CertificationRuntimeService de Meta 4.
  - Phase 5 ser ligada com flag default true antes do AP irmao Tool Bridges Strict Mode mergear.
observability_signals:
  - aigateway_mission_records_emitted
  - aiworker_permission_gate_consult_count
  - mission_completed_via_http_count
  - feature_flag_state
next_actions:
  - Manter teste E2E cobrindo HTTP -> payload.kernel -> AiWorker PermissionGate -> Mission Evidence -> Certification.
  - Monitorar kernel_permission_gate e kernel_mission_completion em traces/jobs reais.
  - Promover qualquer falha de certification para repair flow, nunca para completion silencioso.
---

# Atlas AiWorker to Kernel Integration ADR

## Resumo

Este ADR ativo define como o **caminho real de prompts HTTP** do Atlas
AI sera plugado ao **Kernel canonico** (Meta 1 Mission, Meta 2 Domain
Runtime, Meta 3 Policy, Meta 4 Evidence/Certification, Meta 6 Router
Runtime, Meta 7 Programming Adapter) sem refatorar runtime de producao,
sem fundir Atlas Dev e Atlas Forge e sem promover dominio novo.

Status de autoridade vs implementacao:

- `status: active` significa que este ADR governa o boundary e as regras
  para qualquer alteracao no caminho HTTP -> AiWorker -> Kernel.
- `implementation_status: partial` significa que a Phase 1 do gateway
  esta implementada e testada, mas o AiWorker ainda precisa consumir
  PermissionGate/Evidence/Certification nas Phases 4-6 antes de qualquer
  claim `completed` ou `enforced`.
- Linguagem de fase neste documento e roadmap controlado do ADR, nao
  permissao para IA declarar a espinha HTTP completamente resolvida.

O problema diagnosticado em `atlas-architecture-critical-judgment-report.md`
e dual-architecture: o Kernel existe com 200+ testes proprios, mas o
trafego HTTP real (`POST /ai/interactions`) jamais o invoca. AiWorker
chama `AiProviderManager` e `AtlasProgrammingOrchestrator` direto; a unica
invocacao do Kernel hoje vem de comandos `atlas:ai:*` (CLI smoke).

Decisao: **integrar via wires finos e reversiveis**. Phase 1 grava
`mission_id` no AiJob sem mudar comportamento operacional; Phase 4 registra
`PermissionGateService` em modo `warn_only`; Phase 5 anexa evidence real da
execucao ao Mission; Phase 6 roda certification e so marca `completed`
quando Mission Foundation e Evidence Runtime passam.

Este ADR agora governa codigo de producao em `AiGatewayService` e
`AiWorker`. Mudancas futuras devem preservar rollback, evidence e
anti-false-completion.

## Papel no Atlas

Fecha o gap critico #1 do
`atlas-architecture-critical-judgment-report.md:362-369` ("Kernel canonico
nao integrado ao path HTTP"). Sem isto, todos os investimentos Meta 1..7
sao teatro de auditoria e os invariantes documentados (mission so completa
com evidence + certification, policy gate universal, dominio nao cria
Kernel paralelo) nao sao enforced em producao.

Posicionamento de autoridade: filho do julgamento critico (que
diagnosticou o problema) e irmao de
`atlas-programming-domain-adapter-integration-plan.md` (que entregou
Meta 7 mas nao tinha caminho HTTP). Nao vence Layer -1 nem Kernel; aplica
os contratos canonicos ao caminho operacional.

## Onde Se Encaixa

Estado atual (traceado em
`AiInteractionController` -> `AiGatewayService::enqueueInteraction` ->
`AiWorker::runNextMatching`):

```text
HTTP /ai/interactions
-> AiInteractionController::store
-> AiGatewayService::enqueueInteraction  [legacy Router/* recordRouterDecision]
   -> AiTrace::create
   -> AiJob::create   (sem mission_id, sem flow_route_id, sem domain_runtime_record_id)
-> AiWorker::runNextMatching (worker process, atlas:ai:work)
   -> DecisionReceiptRuntimeGuard       [legacy Kernel/Decision]
   -> KernelPipelineRuntimeGuard        [legacy Kernel/Pipeline]
   -> AiPermissionEngine::authorizeJob  [legacy; NAO eh PermissionGateService Meta 3]
   -> AiProviderManager::get(provider)
   -> provider->runStreaming            [claude_cli | codex_cli | gemini_cli]
   -> AtlasEvidenceLedger::record       [legacy Kernel/Evidence; NAO eh Meta 4]
   -> AtlasProgrammingOrchestrator      [se intent=programming, via applyProgrammingProviderPolicyRuntime / handleNativeProgrammingRepair]
```

Estado alvo declarado em
`atlas-autonomous-intelligence-operating-system.md:177-192`. Sequencia
canonica detalhada na secao "Fluxo".

## Contratos

Contratos consumidos (existentes, nao criados aqui):

- `atlas.ai.mission.v1`, `atlas.ai.objective.v1`, `atlas.ai.work_order.v1`,
  `atlas.ai.mission.definition_of_done.v1` (Meta 1).
- `atlas.ai.domain_manifest.v1`, `atlas.ai.domain_runtime_record.v1`,
  `atlas.ai.domain_handoff.v1` (Meta 2).
- `atlas.ai.policy_profile.v1`, `atlas.ai.safety_decision.v1`,
  `atlas.ai.permission_decision.v1` (Meta 3).
- `atlas.ai.evidence.evidence_pack.v1`, `atlas.ai.evidence.receipt.v1`,
  `atlas.ai.evidence.certification.v1`, `atlas.ai.evidence.blocker.v1`,
  `atlas.ai.evidence.audit_event.v1` (Meta 4).
- `atlas.ai.router.intent_classification.v1`,
  `atlas.ai.router.flow_decision.v1`,
  `atlas.ai.router.runtime_dispatch.v1` (Meta 6,
  `RouterRuntime/RouterRuntimeCanon.php`).
- `atlas.ai.programming.dev_execution_request.v1` (Meta 7,
  `AtlasDevMissionAdapter::buildDevExecutionRequest`).

Contrato novo proposto (Phase 1, vive em JSON dentro de
`ai_jobs.payload`; sem migration nova):

```text
atlas.ai.aiworker.kernel_envelope.v1 {
  schema, enabled_by_flag,
  mission_id, objective_id, work_order_id, domain_runtime_record_id,
  router_intent_id, router_decision_id, flow_route_id, runtime_dispatch_id,
  domain_id, capability,
  source: "ai_gateway.enqueue_interaction",
  recorded_at
}
```

Phase 1 grava em `ai_jobs.payload['kernel']` e
`ai_traces.metadata['kernel']`. Promocao para coluna FK em
`ai_traces`/`ai_jobs` e Phase 7 (fora deste ADR).

## Fluxo

Fluxo canonico alvo (apos todas as fases ligadas):

```text
HTTP /ai/interactions
-> AiInteractionController::store
-> AiGatewayService::enqueueInteraction
   1. IntentKernelService::classify(input)             [Phase 2]
   2. DomainRouterService::route(intent, options)      [Phase 2]
   3. FlowRouterService::decideFlow(decision, intent)  [Phase 2]
   4. RuntimeDispatchService::dispatch(...)            [Phase 2]
   5. MissionFactoryService::create(input, ...)        [Phase 1]
   6. ObjectiveDecomposerService::decompose(mission)   [Phase 1 se task+]
   7. WorkOrderFactoryService::plan(mission)           [Phase 1 se task+]
   8. ProgrammingDomainRuntimeAdapter::plan(...)       [Phase 3, se domain=programming]
   9. AiTrace::create (metadata.kernel = envelope)
  10. AiJob::create   (payload.kernel = envelope)
-> AiWorker::runNextMatching
  11. PermissionGateService::evaluate(...)             [Phase 4, paralelo ao AiPermissionEngine]
  12. AiProviderManager::get(...) + provider->runStreaming  [legacy, intocado]
  13. ProgrammingEvidenceBridge::emitReceipt(...)      [Phase 5, se domain=programming]
  14. MissionEvidenceService::attach(mission, ...)     [Phase 5]
  15. MissionLifecycleService::transition(running->certifying)  [Phase 6]
  16. CertificationRuntimeService::certify(target=mission)      [Phase 6]
  17. MissionLifecycleService::transition(certifying->completed|blocked|failed)  [Phase 6]
```

Wires sao incrementais. Cada Phase pode sair quente sozinha. Se Phase N
quebrar, desligar a flag (ou sub-flag por fase) retorna ao path legado
intacto.

## Regras para IA

- Nao implementar este ADR em uma sessao unica; cada fase abre AP proprio.
- Nao tocar `AtlasDevRuntimeService`, `AtlasForge*InvocationService*`,
  `AiProviderManager`, `ClaudeCliProvider`, `CodexCliProvider`,
  `GeminiCliProvider`, `AtlasProgrammingOrchestrator`,
  `app/Services/Ai/Programming/AtlasDev/*`,
  `app/Services/Ai/Programming/Governance/*` ou
  `app/Services/Ai/Provider/*` em nenhuma fase deste ADR.
- Nao remover guards legados (`KernelPipelineRuntimeGuard`,
  `DecisionReceiptRuntimeGuard`, `AiPermissionEngine`) ao adicionar gates
  canonicos. Os dois rodam em paralelo durante migracao; remocao do
  legado e Phase 7 (fora deste ADR).
- Nao trocar legado `app/Services/Ai/Router/AtlasAiRouterService` ate
  Phase 2 estar provada em CI verde por janela de observacao.
- Nao confundir o legacy "Kernel" namespace
  (`app/Services/Ai/Kernel/Pipeline|Decision|Evidence|Repair|Slo`) com o
  Mission Kernel (`app/Services/Ai/Mission`). Sao camadas distintas com
  escopos distintos.
- Nao tratar fallback de policy/tool runtime como permissao forte quando
  `atlas_ai.tool_runtime.strict_mode=false`; o default canonico agora e
  strict, e qualquer opt-out precisa ficar explicito no ambiente.
- Nao declarar Mission completion sem evidencias anexadas e certification
  gate aprovado; `AiWorker` so promove Mission para `completed` quando
  evidence pack e Mission certification passam.
- Nao expandir wire para Cyber/Finance/Marketing/Research/Strategy nesta
  janela; estes dominios sao scaffold e exigem manifest + adapter
  proprios antes (escopo de outro AP).

## Escopo de Implementacao

Pre-requisitos verificados em 2026-05-18:

- **Meta 1** (`active`): 10 services em `app/Services/Ai/Mission/`;
  comando `atlas:ai:mission-foundation`; 8 feature tests.
- **Meta 2** (`active`): 10 services em `app/Services/Ai/DomainRuntime/`.
- **Meta 3** (`active`): 10 services em `app/Services/Ai/Policy/`.
- **Meta 4** (`active`): 15 services em `app/Services/Ai/Evidence/`;
  comando `atlas:ai:evidence`.
- **Meta 6** (`active`): 11 services em `app/Services/Ai/RouterRuntime/`.
- **Meta 7** (`active`): 12 services em
  `app/Services/Ai/Programming/Kernel/`; comando
  `atlas:ai:programming-adapter`; `ProgrammingAdapterSmokeService` cobre
  ciclo Mission -> WorkOrder -> Runtime Record -> Evidence ->
  Certification.

Pre-requisitos e limites ativos (estado real, sem suavizar):

- **Tool Runtime Strict Mode**:
  `atlas_ai.tool_runtime.strict_mode` tem default canonico ativo. O
  AiWorker registra `PermissionGateService` em modo operacional
  warn-only para preservar compatibilidade com o path legado, mas a
  decisao de execucao continua auditada por policy/permission runtime e
  qualquer opt-out precisa ser explicito.
- **Coluna `mission_id` em `ai_traces`/`ai_jobs`**: hoje nao existe;
  Phase 1 grava em `payload['kernel']`. Promocao a coluna FK e Phase 7.
- **Teste E2E canonico**: a suite de DualCore verifica que a integracao
  HTTP -> Kernel esta documentada como ativa. A promocao runtime para
  `completed` permanece condicionada a evidencia real e certification
  gate no `AiWorker`.

Fases:

| Fase | Sessoes | Wire principal | Arquivo HTTP afetado |
| --- | --- | --- | --- |
| 0 | 1 | Este ADR + atualizar critical judgment report | docs only |
| 1 | 1-2 | AiGateway cria AiMission + grava envelope | `AiGatewayService.php:153-265` |
| 2 | 1-2 | Router Runtime canonico paralelo ao legacy | `AiGatewayService.php:715-848` |
| 3 | 1 | Se domain=programming: AtlasDevMissionAdapter + ProgrammingDomainRuntimeAdapter | bloco novo em AiGatewayService |
| 4 | 1-2 | AiWorker chama PermissionGateService warn-only | `AiWorker.php:249-300` |
| 5 | 1 | AiWorker chama ProgrammingEvidenceBridge + MissionEvidenceService::attach | `AiWorker.php:1427-1730` |
| 6 | 1-2 | AiWorker certifica via CertificationRuntimeService + transicao completed; cria teste E2E | mesmo arquivo + novo test |

Total estimado: 7-10 sessoes pequenas, AP por fase. Cada fase termina com
teste verde e gates passando.

## Dependencias

Documentais: `atlas-architecture-critical-judgment-report.md` (problema),
`atlas-autonomous-intelligence-operating-system.md` (estado canonico),
`atlas-kernel-mission-foundation.md` (Meta 1),
`atlas-evidence-certification-runtime.md` (Meta 4),
`atlas-domain-company-runtimes.md` (Meta 2),
`atlas-programming-domain-adapter-integration-plan.md` (Meta 7),
`atlas-ai-router-runtime-enterprise-upgrade.md` (Meta 6),
`atlas-permission-budget-safety-layer.md` (Meta 3),
`domains/domain-routing-governance.md`.

Tecnicas:

- Laravel 13 + PHP 8.4 (`/opt/homebrew/bin/php`).
- `ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED` env controla rollout de
  HTTP -> Kernel quando o ambiente exige opt-in explicito.
- Tool Runtime Strict Mode e requisito operacional para evitar fallback
  silencioso em execucoes reais.

## Evidencias

Estado pre-integracao (fonte do diagnostico):

- `rg -ln "MissionFactoryService" app/Http app/Filament routes` retorna
  vazio.
- `rg -ln "FlowRouterService" app/Http app/Filament routes` retorna
  vazio.
- `AiWorker.php:41-74` (construtor) injeta `AiProviderManager`,
  `AiPermissionEngine`, `AtlasEvidenceLedger` (legacy
  `app/Services/Ai/Kernel/Evidence/`), `DecisionReceiptRuntimeGuard`,
  `KernelPipelineRuntimeGuard`, `AtlasProgrammingOrchestrator` —
  **nao** injeta `MissionLifecycleService`, `PermissionGateService`
  (Meta 3), `CertificationRuntimeService` (Meta 4), `FlowRouterService`,
  `DomainRouterService`.
- `AiWorker.php:248` `$provider = $this->providers->get($providerKey)`.
- `AiGatewayService.php:779` `recordRouterDecision` usa
  `app/Services/Ai/Router/AtlasAiRouterService` (legacy), nao
  `app/Services/Ai/RouterRuntime/*`.
- `ToolReceiptService.php:64` `'reason' => 'evidence_runtime_unavailable'`
  confirma fallback silencioso (gap critico #2).

Evidencias requeridas para promover a integracao runtime a
`completed/enforced`:

- Teste Feature E2E HTTP em `tests/Feature/Ai/Kernel/` cobrindo Phase 6
  completo (mission via `POST /ai/interactions`, worker roda, mission
  termina `completed` com Certification `passed`).
- `php artisan atlas:ai:mission-foundation --action=control-plane --json`
  mostra missions com origem `ai_gateway`.
- `ai_jobs` recente em producao com `payload.kernel.mission_id` nao nulo.
- `ai_certifications` recente com `target_type='mission'`.

## Riscos

| Risco | Severidade | Mitigacao |
| --- | --- | --- |
| Phase 1 dobra latencia do enqueue | medium | Trivial path skip decomposicao/work_order; medir P50/P99 antes de habilitar em producao |
| Phase 2 cria Router decision duplicada (legacy + canonico) | medium | Assert paridade em log dual-write; remover legacy so apos janela de observacao |
| Phase 4 PermissionGate canonico bloquear mais que AiPermissionEngine | high | Phase 4 inicia em `warn_only`: registra blocker mas nao bloqueia |
| Phase 5 emitir Receipt em ferramenta cuja policy passou silenciosamente | critical | BLOQUEADO ate AP irmao "Tool Bridges Strict Mode" completar |
| Phase 6 marcar mission `completed` sem prova real (shape-only) | critical | `CertificationRuntimeService::certify` ja exige `evidence_refs` nao vazio + `missing_requirements=[]`; teste E2E injeta evidencia falsa e ve recusar |
| Flag default true em producao antes de Phase 6 verde | critical | CI gate: PR nao merge se `ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED=true` aparecer em `.env.production*` |
| `mission_id` perdido entre Gateway e Worker | medium | Envelope vive em `ai_jobs.payload['kernel']` (mesma transacao); teste exige round-trip |
| Dois Kernels (legacy + Mission) gerarem confusao operacional | medium | Nome explicito "Mission Kernel" vs "Legacy Pipeline Guards" em runbooks |
| Repair loop existente (`AiWorker::handleNativeProgrammingRepair`) nao saber de mission | high | Phase 5 inclui hand-off para `MissionEvidenceService::attach` no caminho de repair; nao mudar `repair.loop` SLO probe |

## Exemplos

**"corrigir bug pequeno em /healthz com phpunit" (caminho canonico apos
todas as fases):**

1. `POST /ai/interactions` body `{ input, ... }`.
2. `AiGatewayService` chama `IntentKernelService::classify` ->
   `intent_type=programming` confidence `0.85`.
3. `DomainRouterService::route` ->
   `primary_domain=programming`, `routing_mode=domain_runtime`.
4. `FlowRouterService::decideFlow` -> `flow_id=atlas_dev`,
   `flow_profile=programming.default`,
   `required_gates=[policy.gate,evidence.gate]`.
5. `MissionFactoryService::create` -> `mission_type=task`,
   `autonomy_level=execute_with_approval`, `risk_level=medium`.
6. `AtlasDevMissionAdapter::adapt` decompoe -> 1 objective + 1 work
   order `programming.dev` com `receipt_hash`.
7. `ProgrammingDomainRuntimeAdapter::plan` abre
   `AiDomainRuntimeRecord` ligado ao manifest `programming`.
8. `AiTrace` + `AiJob` criados, `payload.kernel.*` preenchidos.
9. Worker pega job, `PermissionGateService::evaluate` -> `allowed`,
   `AiProviderManager::get('claude_cli')` roda inalterado.
10. `ProgrammingEvidenceBridge` emite `Receipt(diff)` +
    `Receipt(test)` (Meta 4); `MissionEvidenceService::attach`
    registra refs no mission.
11. `MissionLifecycleService::transition(certifying)`;
    `CertificationRuntimeService::certify(target=mission)` ->
    `passed`; `transition(completed)`.
12. Audit event stream + control plane atualizado.

**"deletar tabela users e migrar dados":** intent `programming`,
`detectHighRiskActions` marca high, `MissionFactoryService::create`
emite `risk_level=high`. `PermissionGateService::evaluate` retorna
`require_approval`; `BlockerService::open` cria
`Blocker(kind=missing_permission, severity=high)`. Worker nao executa
provider (Phase 4 enforce); resposta `blocked` com link para approval.

**"explique o que e Mission Mode":** intent `explain`,
`mission_type=trivial`. Phase 1 skip Mission para trivial
(`trivial_skips_kernel=true`); path legado roda intacto; latencia
identica ao pre-integracao. `trivial` permanece fora do Kernel para
nao inflacionar `ai_missions` com pings.

## Proximas Acoes

1. Monitorar `kernel_permission_gate` e `kernel_mission_completion` em
   `ai_jobs.metadata` / `ai_traces.metadata` para detectar mismatch entre
   path legado e Kernel.
2. Promover `mission_id` de `payload.kernel.mission_id` para FK fisica em
   `ai_jobs`/`ai_traces` quando houver volume real suficiente.
3. Adicionar repair flow para Mission que falha certification, com
   evidence pack de erro e work order corretiva.
4. Manter `docs-health`, `programming-runtime` e suites DualCore verdes
   como gates de regressao da integracao.
   `AiWorkerKernelIntegrationE2ETest`; promover a integracao runtime a
   `completed/enforced` apos gates verdes.
8. **AP-Phase-7 (futuro, fora deste ADR)**: deprecar
   `AtlasAiRouterService`, `AiPermissionEngine`, `AtlasEvidenceLedger`
   (Kernel/Evidence) legacy; promover `mission_id` para coluna FK em
   `ai_traces`/`ai_jobs` com migration idempotente.

## Definition of Done

Integracao runtime `completed/enforced` quando todas estas condicoes forem
verdadeiras:

- Phase 6 em CI verde com teste Feature E2E HTTP -> Mission completed
  via Kernel.
- `ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED=true` em `.env` local + CI;
  flag pode estar false em producao ate sign-off operacional.
- `php artisan atlas:ai:mission-foundation --action=control-plane --json`
  mostra missions com `metadata.kernel.source = ai_gateway.enqueue_interaction`.
- `php artisan atlas:ai:evidence --action=control-plane --json` reporta
  certifications recentes com `target_type=mission`.
- `docs-health` sem violacoes para este ADR.
- Gap critico #1 em `atlas-architecture-critical-judgment-report.md`
  atualizado para `resolvido` com link para este ADR + teste E2E.
- AP irmao "Tool Bridges Strict Mode" merged antes de Phase 4 ir a
  enforce.

**Nao** estara DoD enquanto qualquer um for verdade: AiWorker chamar
`AiProviderManager::get` sem consultar `PermissionGateService` (Meta 3)
atras da flag; path HTTP terminar mission shape-only sem
`CertificationRuntimeService::certify`; Programming continuar como unico
dominio plugado sem assert architectural test mostrando extensibilidade
para outros dominios; repair loop continuar emitindo Receipt fora do
Mission Kernel.
