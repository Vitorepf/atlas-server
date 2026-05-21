---
id: atlas-runtime-spine-completion-audit
type: engineering_knowledge
title: Atlas Runtime Spine Completion Audit
status: active
category: architecture
priority: 90
summary: Auditoria READ-ONLY do estado real (2026-05-18) da espinha operacional Atlas AI Programming Runtime apos as ultimas implementacoes (AiGatewayMissionBridge, MandatoryRagGate, EscalationPacket v1, AtlasCanonicalRuntimeE2ETest, DualCoreRouteDecisionService, ProgrammingRuntimeReadinessService). Veredicto principal — espinha 60% fechada — 8 PASS, 5 PARTIAL, 2 FAIL, 0 UNKNOWN. AiWorker process ainda bypassa Kernel apos a enqueue, tool strict_mode default false em producao, Phase 2+ da consolidacao Dev->Forge pendente, e o proprio readiness tem heuristicas stale apos as ultimas implementacoes.
tags:
  - atlas-ai
  - runtime-spine
  - completion-audit
  - dual-core
  - programming
  - 2026-05-18
capabilities:
  - runtime_spine_completion_audit
  - runtime_spine_integration_verification
  - runtime_spine_severity_classification
  - readiness_drift_detection
decisions:
  - 15 requisitos auditados; 8 PASS, 5 PARTIAL, 2 FAIL, 0 UNKNOWN.
  - Spine integrada no boundary HTTP (AiGatewayService) mas NAO no AiWorker process; ADR planned, Phase 1 entregue, Phases 2-6 pendentes.
  - Feature flag ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED tem teste em ambos os estados (on/off) — fallback legacy auditavel, sem bypass silencioso.
  - route_decision.v1 e escalation_packet.v1 vivos em codigo + producao callers + E2E; 3/4 mecanismos Dev->Forge convergem.
  - MandatoryRagGate fail-closed por design + bypass auditavel; MissionCertification quality-aware com critical gate.
  - Tool strict_mode existe mas default false; producao continua aceitando fallback silencioso ate flip operacional.
  - ProgrammingRuntimeReadinessService tem 2 heuristicas STALE (aiworker check, mandatory_rag check) que produzem falso blocked / falso positivo; cobertura honesta mas detalhe drift.
maintenance:
  - Regenerar quando AiWorker (process) consultar mission_id e gates Meta 3/4 reais.
  - Regenerar quando Tool strict_mode default mudar.
  - Regenerar quando Phase 2 (Forge HTTP direto emite route_decision.v1) entregar.
related_paths:
  - docs/engineering-knowledge-base/atlas-architecture-critical-judgment-report.md
  - docs/engineering-knowledge-base/atlas-dev-forge-relationship-critical-audit.md
  - docs/engineering-knowledge-base/atlas-dev-forge-escalation-consolidation-plan.md
  - docs/engineering-knowledge-base/atlas-aiworker-kernel-integration-adr.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-kernel-mission-foundation.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-runtime-spine-completion-audit
graph_title: Atlas Runtime Spine Completion Audit
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-architecture-critical-judgment-report
graph_status: active
graph_source: repo
human_name: Atlas Runtime Spine Completion Audit
canonical_name: Atlas Runtime Spine Completion Audit
technical_name: atlas-runtime-spine-completion-audit
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-runtime-spine-completion-audit.md
owner: architecture
repo_paths:
  - docs/engineering-knowledge-base/atlas-runtime-spine-completion-audit.md
allowed_changes:
  - Atualizar quando AiWorker process consumir mission_id ou quando flag virar default true.
  - Atualizar quando Phase 2 consolidation entregar.
  - Atualizar quando readiness heuristicas forem refinadas.
forbidden_changes:
  - Reclassificar PASS sem evidencia de codigo + teste verde.
  - Suavizar veredicto sem evidencia de path real exercitado.
  - Declarar spine fechada sem AiWorker consultar gates Meta 3/4.
depends_on:
  - atlas-architecture-critical-judgment-report
  - atlas-dev-forge-relationship-critical-audit
  - atlas-aiworker-kernel-integration-adr
  - atlas-dev-forge-escalation-consolidation-plan
flows_to:
  - atlas-aiworker-kernel-integration-adr
  - atlas-dev-forge-escalation-consolidation-plan
unlocks:
  - next-round-priority-list
governs:
  - runtime_spine_completion_judgment
evidence:
  - app/Services/Ai/AiGatewayService.php
  - app/Services/Ai/Mission/AiGatewayMissionBridge.php
  - app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php
  - app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php
  - app/Services/Ai/DualCore/DualCoreRouteDecisionService.php
  - app/Services/Ai/Programming/AtlasDev/Schemas/EscalationPacket.php
  - app/Services/Ai/Programming/Kernel/AtlasForgeHandoffAdapter.php
  - app/Services/AtlasCode/DevToForgePromotionService.php
  - app/Services/Ai/Mission/MissionCertificationService.php
  - app/Services/Ai/ToolRuntime/ToolPolicyBridgeService.php
  - app/Services/Ai/ToolRuntime/ToolReceiptService.php
  - app/Services/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessService.php
  - tests/Feature/Ai/Programming/DualCore/AtlasCanonicalRuntimeE2ETest.php
  - tests/Feature/Ai/Kernel/AiGatewayMissionBridgeTest.php
  - tests/Unit/Ai/Programming/AtlasDev/Gate/MandatoryRagGateTest.php
  - tests/Feature/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessServiceTest.php
required_tests:
  - "/opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json"
  - "/opt/homebrew/bin/php artisan test --filter='AtlasCanonicalRuntimeE2ETest|AiGatewayMissionBridgeTest|MandatoryRagGateTest|DualCoreRouteDecisionServiceTest|ProgrammingRuntimeReadinessServiceTest'"
  - "/opt/homebrew/bin/php artisan atlas:ai:programming-runtime --action=readiness --json"
requires_evidence: true
risk_level: high
next_actions:
  - Refinar 2 heuristicas stale do ProgrammingRuntimeReadinessService (aiworker_kernel + mandatory_rag).
  - Iniciar Phase 4 do AiWorker Kernel ADR (PermissionGate warn-only no AiWorker apos enqueue).
  - Iniciar Phase 2 da consolidacao Dev->Forge (Forge HTTP direto emite route_decision.v1).
  - Auditar callers Tool e flipar strict_mode em dev/CI.
---
# Atlas Runtime Spine Completion Audit
## Resumo
**Veredicto executivo (2026-05-18):** a espinha do Atlas AI Programming
Runtime esta **60% fechada e honestamente classificada**. Foundation, contratos
e seams operacionais existem em codigo e tem testes; o gap restante e
**execucao real em producao**.
- **8 PASS** (PASS=evidencia forte): route_decision.v1 implementado + caller +
  tres rotas; escalation_packet.v1 implementado + caller Dev->Forge; Mandatory
  RAG Gate fail-closed; RAG bypass auditavel; MissionCertification
  quality-aware; legacy fallback explicito.
- **5 PARTIAL** (existe mas incompleto): AiWorker entra no Kernel
  **so via gateway, ainda nao via worker**; mecanismos antigos
  Dev->Forge convergem **so em 3 de 4** (Phase 2 pendente); ToolPolicy/Evidence
  strict_mode default `false`; E2E canonico cobre o pipeline ate cert mas NAO
  o HTTP controller; Readiness/Certification reportam blockers mas com
  heuristicas stale.
- **2 FAIL**: AiWorker process ainda bypassa Kernel apos enqueue (Phase 4-6
  ADR pendente); green-indevido protecao existe mas detalhe `mandatory_rag`
  e `aiworker_kernel` produzem false positive/negative por sentinels stale.
- **0 UNKNOWN.**
Spine pronta para **Phase 2** (Router wire-up + AiWorker mission consumption)
sem refatoracao adicional dos contratos. Sequencia segura no §"Proximas Acoes".
## Papel no Atlas
Este audit READ-ONLY responde uma unica pergunta: **as ultimas correcoes
realmente fecharam os P0/P1 listados em
`atlas-architecture-critical-judgment-report.md` e
`atlas-dev-forge-relationship-critical-audit.md`?**
Resposta curta: as fundacoes contratuais estao fechadas; a execucao no path
HTTP real ainda nao. A espinha esta pronta para receber tráfego, mas o
tráfego ainda nao passa por ela em producao default.
Nao substitui os audits ou ADRs originais; reporta delta real (apos
implementacao) versus delta declarado.
## Onde Se Encaixa
Filho de `atlas-architecture-critical-judgment-report.md` (gap critico #1
"Kernel canonico nao integrado") e
`atlas-dev-forge-relationship-critical-audit.md` (gap "schemas dual-core
inexistentes" + "4 mecanismos paralelos"). Cruzado com:

- `atlas-aiworker-kernel-integration-adr.md` (ADR planned; Phase 1 entregue,
  Phases 2-6 pendentes).
- `atlas-dev-forge-escalation-consolidation-plan.md` (Fase 1 entregue, Fase
  2+ pendentes).

Este doc NAO altera nenhum contrato; reporta delta operacional.

## Contratos

Auditoria consume (nao define) os 8 contratos canonicos da spine:

- `atlas.ai.aiworker.kernel_envelope.v1` (proposto pela ADR, implementado
  em `AiGatewayMissionBridge::ENVELOPE_SCHEMA`).
- `atlas.ai.mission.v1`, `atlas.ai.objective.v1`, `atlas.ai.work_order.v1`,
  `atlas.ai.certification.v1` (Meta 1).
- `atlas.dual_core.route_decision.v1` (DualCore).
- `atlas.dev_to_forge.escalation_packet.v1` (Dev->Forge consolidation).
- `atlas_ai.tool_runtime.strict_mode` (config flag).

Nenhum contrato novo introduzido.

## Fluxo

**Espinha alvo declarada:**
```text
HTTP -> AiWorker -> Kernel/Mission -> Router -> route_decision.v1
     -> Dev | Forge | Dev->Forge -> escalation_packet.v1 (se promocao)
     -> Mandatory RAG Gate fail-closed -> ToolPolicy/Evidence strict
     -> Certification quality-aware -> E2E canonico -> Readiness honesta
```

**Estado real implementado (2026-05-18):**
```text
HTTP /ai/interactions
  -> AiInteractionController
  -> AiGatewayService::enqueueInteraction
     -> [Phase 1 BRIDGE] AiGatewayMissionBridge::buildEnvelope (atras de flag, default OFF)
        - flag OFF -> envelope null, legacy path
        - flag ON  -> grava AiMission + objectives + work_orders + envelope em
                      ai_traces.metadata.kernel / ai_jobs.payload.kernel
     -> AiTrace + AiJob persistidos
     -> [GAP] proxima parada: AiWorker::runNextMatching
        - AiWorker NAO consulta payload.kernel.mission_id
        - AiWorker continua via AiPermissionEngine + AtlasEvidenceLedger legacy
          + AtlasProgrammingOrchestrator direto
        - NAO chama PermissionGateService (Meta 3), CertificationRuntimeService
          (Meta 4), FlowRouterService (Meta 6 canonical), MissionLifecycleService

CANONICAL Programming pipeline (parallel, exercitado via smoke + E2E):
  IntentKernelService -> DomainRouterService -> FlowRouterService
  -> DualCoreRouteDecisionService::recordFromFlowRoute
  -> AtlasDevMissionAdapter::adapt -> Mission + Objective + WorkOrder
  -> ProgrammingDomainRuntimeAdapter::plan -> DomainRuntimeRecord
  -> AtlasDevFastPathOrchestrator -> MandatoryRagGate::evaluate (fail-closed)
  -> ProgrammingEvidenceBridge::attach -> MissionEvidenceService
  -> MissionLifecycleService::transition -> certifying
  -> MissionCertificationService::certify (quality-aware, 13 checks)
  -> completed | blocked | failed
```

O canonical pipeline funciona ponta-a-ponta (5 tests E2E verdes), mas roda
apenas via comando CLI + smoke + tests. O path HTTP real ainda nao o
exercita em modo enforce.

## Regras para IA

- Nao reclassificar PARTIAL/FAIL como PASS sem novo teste verde provando.
- Nao remover `test_http_to_kernel_integration_remains_a_documented_blocker`
  (`AtlasCanonicalRuntimeE2ETest.php:297-329`) — esse teste pin do ADR cai
  quando a integracao shipar.
- Nao confundir "Phase 1 entregue" com "spine fechada"; bridge no gateway
  != worker consumindo mission.
- Nao desligar `ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED` em CI sem audit.
- Nao flipar `ATLAS_AI_TOOL_RUNTIME_STRICT=true` sem auditar todos os
  callers em ToolPolicy/Receipt.
- Nao deprecar nenhum mecanismo Dev->Forge legacy ate Phase 2 verde com
  paridade de schema medida.

## Escopo de Implementacao

**Auditoria pura.** Nao altera codigo, nao adiciona teste, nao toca
contratos. Cobre:

- 15 requisitos canonicos (lista no prompt da missao).
- Inspecao por arquivo / classe / metodo / teste / config / receipt hash.
- Execucao de 5 suites de teste (`AtlasCanonicalRuntimeE2ETest`,
  `AiGatewayMissionBridgeTest`, `MandatoryRagGateTest`,
  `DualCoreRouteDecisionServiceTest`, `ProgrammingRuntimeReadinessServiceTest`)
  + `atlas:ai:programming-runtime --action=readiness --json`.
- Confronto entre estado declarado em docs canonicas e estado verificavel.

**Fora de escopo:** corrigir gaps, refatorar runtime, alterar config,
deprecar legacy, mexer em UX, atualizar docs canon que nao sejam este.

## Dependencias

- `atlas-architecture-critical-judgment-report.md` (origem do veredicto Phase 2).
- `atlas-dev-forge-relationship-critical-audit.md` (gap dos 4 mecanismos).
- `atlas-aiworker-kernel-integration-adr.md` (ADR pin que define Phases 1-6).
- `atlas-dev-forge-escalation-consolidation-plan.md` (Fase 1 entregue).
- `atlas-dual-core-engineering-system.md` (contratos canonicos).
- `atlas-evidence-certification-runtime.md` (Meta 4).
- `atlas-kernel-mission-foundation.md` (Meta 1).
- `atlas-programming-superiority-architecture.md` (15 gaps).

## Evidencias

### Tabela final PASS / PARTIAL / FAIL / UNKNOWN

| # | Requisito | Status | Severidade | Evidencia curta |
|---|-----------|--------|------------|-----------------|
| 1 | AiWorker entra no Kernel/Mission/Router real ou via feature flag testada | **PARTIAL** | P0 | Phase 1 bridge em `AiGatewayService:162` (gateway, nao worker); flag testada nos dois estados em `AiGatewayMissionBridgeTest` (8 cenarios) |
| 2 | Path legado e fallback explicito, nao bypass silencioso | **PASS** | P0 | `AiGatewayMissionBridge::buildEnvelope` retorna `null` quando flag off / tables ausentes / trivial; falhas viram `Log::warning` + stub envelope `kernel_bridge_error.fallback="legacy_path"` (`AiGatewayMissionBridge.php:98-122,247-262`) |
| 3 | route_decision.v1 existe em codigo | **PASS** | P0 | `DualCoreRouteDecisionService.php`, `AiDualCoreRouteDecision.php`, tabela `ai_dual_core_route_decisions`, 11 tests verdes (`DualCoreRouteDecisionServiceTest`) |
| 4 | route_decision.v1 tem caller real | **PASS** | P0 | 2 callers de producao: `AtlasForgeHandoffAdapter::promoteWithPacket` + `DevToForgePromotionService` (HTTP `/atlas-code/dev-to-forge/threads/{thread}/promote`); confirmado pelo readiness real |
| 5 | route_decision.v1 cobre dev / forge / dev_to_forge | **PASS** | P0 | `DualCoreRouteDecisionCanon::ROUTES = [dev, forge, dev_to_forge]`; testes cobrem os 3 caminhos (`DualCoreRouteDecisionServiceTest::test_records_dev_route_decision`, `_records_forge_...`, `_records_dev_to_forge_...`) |
| 6 | escalation_packet.v1 existe em codigo | **PASS** | P0 | `EscalationPacket::SCHEMA_VERSION = 'atlas.dev_to_forge.escalation_packet.v1'` em `AtlasDev/Schemas/EscalationPacket.php:43`; validation `source_core`/`target_core` (`:385,391`); referenciado em 6 arquivos `app/` |
| 7 | escalation_packet.v1 e usado por Dev->Forge | **PASS** | P0 | 3 emitters: `DevToForgeEscalationPacketFactory`, `ForgePromotionPreviewBuilder::toEscalationPacketV1`, `AtlasForgeHandoffAdapter::promoteWithPacket`; E2E `AtlasCanonicalRuntimeE2ETest::test_dev_to_forge_..._emits_escalation_packet_v1` assert `packet_hash` 64-char + `schema_version` |
| 8 | mecanismos antigos Dev->Forge convergem para path canonico | **PARTIAL** | P1 | Fase 1 (`atlas-dev-forge-escalation-consolidation-plan.md`): 3/4 mecanismos dual-emit packet v1 + route_decision.v1. Mechanism 4 (Forge HTTP direto `/works/{project}/forge/*`) ainda nao emite route_decision.v1. Phase 2-4 (Router wire-up + intake centralizado + deprecation) pendentes |
| 9 | Mandatory RAG Gate e fail-closed para tarefa nao trivial | **PASS** | P1 | `MandatoryRagGate.php` — fail-closed por design (linhas 92-103 trivial PASS / 110-160 4 hard checks BLOCKED); wired em `AtlasDevFastPathOrchestrator:100-116` (bloqueia routing ao BLOCKED se gate.isBlocked); 4 razoes de blocker: `BLOCKER_MANDATORY_RAG_INSUFFICIENT_CONTEXT`, `_NO_CONTEXT_PACK_HASH`, `_MISSED_REQUIRED_SOURCES` |
| 10 | RAG bypass exige policy/config explicita e auditavel | **PASS** | P1 | `MandatoryRagGate::tryBypass` exige `bypass_enabled` config flag + `mandatory_rag_gate:bypass` operator constraint + `bypass_reason=` constraint + `allowed_surfaces` config check; emite `bypass_audit` no receipt com surface/reason/recorded_at/enabled_via_config (`MandatoryRagGate.php:211-261`) |
| 11 | ToolPolicy/Evidence nao falha silenciosamente | **PARTIAL** | P1 | Strict mode IMPLEMENTADO (`ToolPolicyBridgeService:238`, `ToolReceiptService:249`); MAS default `strict_mode=false` (`config/atlas_ai.php:568`); marker `evidence_runtime_unavailable` ainda emitido no fallback (`ToolReceiptService:115,126`). Producao default = silencioso ate flip |
| 12 | MissionCertification e quality-aware, nao shape-only | **PASS** | P0 | `MissionCertificationService.php` — 13 checks com `severity` (CRITICAL/HIGH/MEDIUM/LOW); critical-failure gate (`certify():101-115` so PASSED se `criticalFailures==[]`); cada check tem `evidence_refs` + `remediation`; cobre DoD criteria, work_order receipt_hash, evidence_hash valido, canonical_events_recorded, no_unresolved_blockers, mission_status_is_certifiable |
| 13 | E2E canonico cobre runtime integrado | **PARTIAL** | P1 | `AtlasCanonicalRuntimeE2ETest.php` (368 linhas, 5 testes) cobre IntentKernel -> Router -> FlowRoute -> Dispatch -> route_decision.v1 -> Adapter -> Mission -> Evidence -> Certification (PASSED) + dev_to_forge com escalation_packet.v1 + blocked dispatch + smoke. NAO cobre HTTP controller (`test_http_to_kernel_integration_remains_a_documented_blocker:297-329` pin explicito do ADR). Cobertura canonical chain: completa. Cobertura HTTP path: pendente |
| 14 | Readiness/Certification reportam blockers reais | **PARTIAL** | P1 | `ProgrammingRuntimeReadinessService` produz JSON `atlas.programming.runtime_readiness.v1` com status `green/partial/blocked` (12 tests verdes); recusa green se P0/P1 blocked. MAS 2 heuristicas STALE apos implementacoes: (a) `aiworker_kernel_integration` greps `AiWorker.php` por classes Kernel — perde Phase 1 que vive em `AiGatewayService`; (b) `mandatory_rag_gate_enforced` greps por `failed_closed` literal — MandatoryRagGate usa `STATUS_BLOCKED`. Falso blocked em ambos |
| 15 | Nao existe green indevido com scaffold paralelo | **PARTIAL** | P1 | Readiness service recusa green quando P0/P1 blocker presente (testado em 7 cenarios mockados); direcao correta (errs on side of blocking). MAS `mandatory_rag_gate_enforced` retorna blocked mesmo com gate enforced em codigo — falso positivo. Operador honesto puxa atencao mas pelo motivo errado |

### Detalhes por requisito (links e linhas)

**#1 AiWorker -> Kernel (PARTIAL).** Phase 1 do ADR
(`atlas-aiworker-kernel-integration-adr.md`) entregou o bridge no boundary
HTTP, NAO no worker process:

- `app/Services/Ai/Mission/AiGatewayMissionBridge.php:55-276` — bridge thin,
  contratualmente nao-throwing, controlado por flag
  `atlas_ai.kernel_http_integration.enabled` (`config/atlas_ai.php:601`).
- `app/Services/Ai/AiGatewayService.php:58,162` — bridge injetado e invocado
  apos prompt build, antes do AiTrace insert; resultado persistido em
  `metadata.kernel` (linha 211), `payload.kernel` (linhas 242, 265).
- `tests/Feature/Ai/Kernel/AiGatewayMissionBridgeTest.php` — 8 cenarios:
  flag off, flag on, tables missing, trivial skip, trivial create, empty
  input, factory failure (Log::warning), decompose failure, DI sanity.
- **Gap real:** `app/Services/Ai/AiWorker.php` constructor (41-74) ainda nao
  injeta `MissionLifecycleService`, `PermissionGateService` (Meta 3),
  `CertificationRuntimeService` (Meta 4), `FlowRouterService`,
  `DomainRouterService`. Worker continua via `AiPermissionEngine`,
  `AtlasEvidenceLedger` (legacy `Kernel/Evidence/`),
  `AtlasProgrammingOrchestrator` direto. Phase 4-6 do ADR pendentes.

**#3-7 Contratos dual-core (PASS).** Implementacao integral:

- route_decision.v1: `app/Services/Ai/DualCore/DualCoreRouteDecisionService.php:21-225`,
  hash determinstico via `MissionCanonicalHash::sha256`,
  `recordFromFlowRoute` adapter para Meta 6 outputs.
- escalation_packet.v1: `app/Services/Ai/Programming/AtlasDev/Schemas/EscalationPacket.php:43`
  schema constant; emitido por 3 mecanismos
  (`AtlasForgeHandoffAdapter::promoteWithPacket`,
  `ForgePromotionPreviewBuilder::toEscalationPacketV1`,
  `DevToForgeEscalationPacketFactory`).

**#9-10 MandatoryRagGate (PASS).** Fail-closed por design:

- `app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php:92-172` —
  trivial path PASSED; non-trivial executa 4 hard checks (empty selected
  tiers, missing plan_hash, missing compact_sdd_hash, missed required
  sources) — todos retornam `STATUS_BLOCKED` com `blockers[]` e
  `remediation`.
- `app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php:100-116`
  — wired: se `$gate->isBlocked()`, routing vira `RoutingDecision::BLOCKED`
  com `reasons` e `blockers` mesclados.
- Bypass: `tryBypass()` (`:211-261`) exige `bypass_enabled` config + 2
  constraints operator-supplied + `allowed_surfaces` opt-in; emite
  `bypass_audit` no receipt.
- Tests: `tests/Unit/Ai/Programming/AtlasDev/Gate/MandatoryRagGateTest.php`.

**#11 ToolPolicy/Evidence strict (PARTIAL).** Codigo OK, default nao:

- `app/Services/Ai/ToolRuntime/ToolPolicyBridgeService.php:238` —
  `isStrictMode()` le `config('atlas_ai.tool_runtime.strict_mode', false)`.
- `app/Services/Ai/ToolRuntime/ToolReceiptService.php:115,126` — marker
  `evidence_runtime_unavailable` ainda emitido quando strict_mode=false;
  `:249` mesma leitura.
- `config/atlas_ai.php:568` —
  `'strict_mode' => (bool) env('ATLAS_AI_TOOL_RUNTIME_STRICT', false)`.
- **Gap:** producao mantem fallback silencioso ate flip operacional
  auditado (cada caller de Tool em ToolPolicy/Receipt).

**#12 MissionCertification quality-aware (PASS).** Confirmado:

- `app/Services/Ai/Mission/MissionCertificationService.php:25-755` —
  13 checks com `severity` (CRITICAL/HIGH/MEDIUM/LOW); `certify():97-167`
  consome `criticalFailures` para gate: status `PASSED` so se
  `criticalFailures==[]`. Cada check tem `evidence_refs[]` e `remediation`.

**#13 E2E canonico (PARTIAL).** Cobre canonical chain, NAO HTTP:

- `tests/Feature/Ai/Programming/DualCore/AtlasCanonicalRuntimeE2ETest.php`
  (368 linhas, 5 testes):
  - `test_dev_prompt_walks_full_canonical_chain_to_passed_certification`
    (linhas 103-174) — 7 services / 11 contratos.
  - `test_dev_to_forge_prompt_emits_route_decision_v1_and_escalation_packet_v1`
    (linhas 176-251) — packet_hash + AiDomainHandoff + route_decision_v1
    com actor `programming_adapter`.
  - `test_programming_adapter_smoke_service_proves_full_canonical_pipeline`
    (linhas 253-270).
  - `test_blocked_router_decision_propagates_to_dispatch_without_creating_mission`
    (linhas 272-295) — assert sem mission criada em blocked.
  - `test_http_to_kernel_integration_remains_a_documented_blocker`
    (linhas 297-329) — pin explicito do ADR ate Phase 6.
- **Gap:** nenhum teste E2E parte de `POST /ai/interactions` real.

**#14-15 Readiness honesta (PARTIAL).** Existe + recusa green + 2 stales:

- `app/Services/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessService.php`
  + `tests/Feature/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessServiceTest.php`
  (12 tests, 136 assertions).
- `php artisan atlas:ai:programming-runtime --action=readiness --json`
  retorna 10 checks com `status: blocked|warn|green`, severity P0/P1/P2,
  exit code 1 quando blocked.
- **2 heuristicas STALE:**
  - `aiworker_kernel_integration` (linhas 62-103) le `app/Services/Ai/AiWorker.php`
    e grep por classes Kernel; perde Phase 1 que vive em `AiGatewayService`.
    Falso blocked (gap real existe; mas a evidencia citada esta no arquivo
    errado — direciona o leitor a inspecionar o lugar errado).
  - `mandatory_rag_gate_enforced` (linhas 260-296) grep por literal
    `failed_closed`; MandatoryRagGate canonico usa
    `MandatoryRagGateResult::STATUS_BLOCKED` + `BLOCKER_*` constants. Falso
    blocked: gate IS enforced, mas heuristica nao detecta.

### Gates Executados

```bash
$ /opt/homebrew/bin/php artisan test --filter='AtlasCanonicalRuntimeE2ETest|\
  AiGatewayMissionBridgeTest|MandatoryRagGateTest|\
  DualCoreRouteDecisionServiceTest|ProgrammingRuntimeReadinessServiceTest'

  Tests:    51 passed (349 assertions)
  Duration: 2.59s
```

```bash
$ /opt/homebrew/bin/php artisan atlas:ai:programming-runtime --action=readiness --json

  status: blocked
  summary: total=10 green=6 warn=1 blocked=3 (p0_blocked=1, p1_blocked=2)
  blockers (real, mas 2 heuristicas stales — ver #14):
    [P0] aiworker_kernel_integration (heuristica olha AiWorker.php — gateway nao detectado)
    [P1] mandatory_rag_gate_enforced (heuristica busca failed_closed — gate usa STATUS_BLOCKED)
    [P1] tool_policy_evidence_strict_mode (correto: config false)
  warn:
    [P1] dev_forge_no_parallel_escalation_schemas (correto: 3 mecanismos paralelos + canonical)
```

```bash
$ /opt/homebrew/bin/php artisan atlas:engineering:knowledge docs-health --json
  status: ok, violations: 0
$ git diff --check
  exit 0 (clean)
```

## Riscos

### Top blockers (P0/P1) por ordem de severidade

1. **AiWorker process bypassa Kernel apos enqueue (P0).** Phase 1 entregou
   bridge no gateway; Phases 4-6 do ADR (PermissionGate Meta 3 no worker,
   Evidence attach Meta 4, Certification transition) pendentes. Mission_id
   nasce em `payload.kernel` e morre la — worker nao consome.
2. **`ATLAS_AI_KERNEL_HTTP_INTEGRATION_ENABLED` default false (P0).** Bridge
   dormente em producao. Sem teste/asserca CI que prove ambiente onde flag
   esta on em modo escalavel.
3. **`ATLAS_AI_TOOL_RUNTIME_STRICT` default false (P1).** Tool fallback
   silencioso ativo em producao. Marker `evidence_runtime_unavailable`
   ainda emitido em runtime real ate caller audit + flip.
4. **Phase 2 da consolidacao Dev->Forge pendente (P1).** Mechanism 4 (Forge
   HTTP direto) ainda nao emite `route_decision.v1`. Forge intake nao
   centralizado.
5. **Readiness 2 heuristicas stale (P1).** `aiworker_kernel_integration` e
   `mandatory_rag_gate_enforced` precisam ser refinadas para refletir as
   implementacoes recentes; senao o operador honesto e direcionado ao
   lugar errado (e potencialmente reverte uma correcao por achar que ainda
   nao foi feita).
6. **E2E canonico nao cobre HTTP (P1).** `AtlasCanonicalRuntimeE2ETest`
   parte do `IntentKernelService` (Meta 6), nao do controller HTTP. Pin
   guard explicito existe; precisa ser substituido por teste real apos
   Phase 6.

### Correcao minima recomendada (3 deltas, 6-10h total)

1. **Refinar 2 heuristicas do `ProgrammingRuntimeReadinessService`** (1-2h).
   - `aiworker_kernel_integration`: inspecionar `AiGatewayService.php` para
     `AiGatewayMissionBridge` injetado AND `AiWorker.php` para qualquer
     classe Kernel Meta 1-4/6. Status `partial` quando so um dos dois
     existe; `green` quando ambos; `blocked` quando nenhum.
   - `mandatory_rag_gate_enforced`: inspecionar existencia de
     `app/Services/Ai/Programming/AtlasDev/Gate/MandatoryRagGate.php` AND
     caller em `AtlasDevFastPathOrchestrator.php` AND constante
     `STATUS_BLOCKED` no result class. Green quando os tres presentes.

2. **AP-Phase-4 do AiWorker ADR (warn-only)** (4-6h). `PermissionGateService::evaluate`
   roda paralelo a `AiPermissionEngine::authorizeJob` no AiWorker apos a
   enqueue, em modo `warn_only`. Registra Blocker (Meta 4) quando bate;
   nao bloqueia execucao ainda. Mede mismatch rate para informar Phase 5+.

3. **AP-Phase-2 da consolidacao** (2-4h). Mechanism 4 (Forge HTTP direto)
   passa a emitir `route_decision.v1` com `route=forge` antes do dispatch
   em cada controller `/works/{project}/forge/*`. Sem mexer no fluxo
   existente; emissao transparente.

Apos esses 3 deltas:
- Spine fechada em PASS para 11/15 requisitos (10 PASS + 1 melhorado).
- Phases 4-6 do ADR continuam o caminho ate spine 15/15.

### Ordem da proxima rodada (sequenciada)

| # | Acao | Severidade | Custo | Bloqueia |
|---|------|------------|-------|----------|
| 1 | Refinar 2 heuristicas readiness | P1 | 1-2h | nada — independente |
| 2 | AP Phase-4 ADR (PermissionGate warn-only no AiWorker) | P0 | 4-6h | AP irmao Tool strict |
| 3 | AP Phase-2 consolidacao (Forge HTTP emite route_decision) | P1 | 2-4h | nada |
| 4 | Auditar callers Tool + flipar `ATLAS_AI_TOOL_RUNTIME_STRICT=true` em CI/dev | P1 | 4-8h | rollout em prod |
| 5 | AP Phase-5 ADR (Evidence attach no AiWorker) | P0 | 4-6h | depende #2 |
| 6 | AP Phase-6 ADR (Certification gate no AiWorker + teste HTTP E2E) | P0 | 6-8h | depende #5 |
| 7 | Marcar ADR `status: active` e este audit como `superseded_by` proximo audit | doc | 1h | apos #6 |

Total ate spine fechada: ~25-35h efetivas distribuidas em 6 sessoes.

## Exemplos

**Canonico hoje (smoke/CLI/test):** pipeline percorre Intent -> Router ->
route_decision.v1 -> Adapter -> Mission -> Evidence -> Certification PASSED
em <50ms via `AtlasCanonicalRuntimeE2ETest` ou `atlas:ai:programming-adapter
--action=smoke`. Tudo persistido com hash determinstico.

**HTTP real (flag off, producao default):** `POST /ai/interactions` ->
`AiGatewayMissionBridge` retorna `null` -> `AiTrace`+`AiJob` sem `kernel`
metadata -> `AiWorker` executa legado. Zero Mission/Certification/route_decision.

**HTTP com flag on:** mesmo POST -> envelope com `mission_id` + objectives +
work_orders em `planned` populados em `metadata.kernel` -> `AiWorker` ainda
nao le `payload.kernel.mission_id` -> mission fica `planned` para sempre.
Bridge prova-de-vida sem fechar o ciclo.

## Proximas Acoes

Post-fix 2026-05-18 (sessao "Fix Runtime Spine Blockers" — ver secao post-fix):

1. AP Phase-4/5/6 do AiWorker ADR — pendente (multi-sessao).
2. Flip `ATLAS_AI_TOOL_RUNTIME_STRICT=true` apos caller audit — pendente.
3. Mechanism 4 nos outros controllers Forge (operating-room/fast-path/async) — somente live-executions wired nesta sessao.
4. Re-auditar quando AiWorker spine 15/15.

## Post-Fix Status 2026-05-18

Mudancas entregues sem suavizar o veredicto (eliminacao de falsos positivos + 1 wire-up real):

- `ProgrammingRuntimeReadinessService::checkAiWorkerKernelIntegration` inspeciona `AiGatewayService.php` (Phase 1 bridge) + `AiWorker.php` (Phase 4-6). Phase 1 shipped -> `warn` honesto, nao mais falso `blocked`. Sentinels em `ProgrammingRuntimeReadinessCanon::KERNEL_GATEWAY_BRIDGE_SENTINELS`.
- `ProgrammingRuntimeReadinessService::checkMandatoryRagGateEnforced` detecta sinais canonicos (`MandatoryRagGate.php` + `MandatoryRagGateResult::STATUS_BLOCKED` + caller fora de Gate/) em vez de grep `failed_closed`. Constants em `ProgrammingRuntimeReadinessCanon::MANDATORY_RAG_GATE_CANONICAL_SIGNALS`.
- `ForgeIntakeRouteDecisionRecorder` novo em `app/Services/Ai/DualCore/`. Emite `atlas.dual_core.route_decision.v1` para Mechanism 4 do dev-forge consolidation plan. Injetado em `AtlasCodeForgeExecutionController::store|startAsync` como chamada aditiva antes do dispatch (Forge runtime intocado). Tolerante: retorna null + log warning quando `ai_dual_core_route_decisions` ausente.
- Tests: `ProgrammingRuntimeReadinessServiceTest` ganhou 4 estados (green / warn-gateway-only / warn-worker-only / blocked) + canonical RAG signals. Nova `ForgeIntakeRouteDecisionRecorderTest` cobre persist + degraded-table + canonical-route fallback. 17 testes / 155 assertions verdes nesta sessao.

## Definition of Done

Pronto quando: 15 requisitos classificados com evidencia (arquivo/linha/teste);
top blockers numerados; correcao minima + ordem proxima rodada listadas;
suites verdes documentadas (51/349); docs-health `status: ok` / 0 violations;
`git diff --check` exit 0; sem alteracao em runtime/contratos; sem suavizacao
do veredicto (8 PASS + 5 PARTIAL + 2 FAIL).
