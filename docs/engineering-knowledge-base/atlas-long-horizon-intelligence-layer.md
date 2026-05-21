---
id: atlas-long-horizon-intelligence-layer
type: engineering_knowledge
title: Atlas Long-Horizon Intelligence Layer
status: active
category: programming
priority: 95
summary: Camada canônica que permite Atlas Dev sustentar contexto de semanas e Atlas Forge operar Obras de meses por compactação auditável, continuation packs, decision/evidence timeline, drift detection, recovery planner e memory promotion. Cita 15+ modelos/serviços JÁ implementados (Forge long-horizon, Dev continuation, evidence ledger) e marca gaps greenfield. benchmark_not_run, sem claim numérico.
tags:
  - atlas-dev
  - atlas-forge
  - long-horizon
  - continuation
  - compaction
  - drift-detection
  - recovery
  - 2026-05-18
capabilities:
  - long_horizon_state_design
  - continuation_pack_contract
  - superior_context_compaction
  - drift_detection_recovery
  - memory_promotion_pipeline
  - operator_review_points
decisions:
  - "long_horizon" entra no glossário canônico — termo já em uso por `AiForgeLongHorizonState` shipped.
  - Schemas novos `atlas.long_horizon.continuation_pack.v1` e `atlas.long_horizon.compaction_receipt.v1` são abstrações canônicas; especializações existentes (`atlas.programming.continuation_packet.v1`, `atlas.forge.long_horizon_state.v1`) mantêm-se.
  - Dev e Forge preservam identidade; continuity layer respeita boundary.
  - Recovery Planner e Context Freshness Gate são greenfield; demais 11 componentes têm base parcial em código.
  - benchmark_not_run — esta camada é pré-requisito para benchmark, não o benchmark.
maintenance:
  - Atualizar quando schemas long_horizon.* shiparem.
  - Sincronizar com `atlas-canonical-glossary-and-naming.md` quando entry `long_horizon` for adicionada.
  - Atualizar quando AtlasMemoryEntry ganhar scopes `obra` / `long_horizon`.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-runtime-spine-completion-audit.md
  - docs/engineering-knowledge-base/atlas-pre-benchmark-readiness-audit.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-forge-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-intelligence-operating-system.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-long-horizon-intelligence-layer
graph_title: Atlas Long-Horizon Intelligence Layer
graph_world: atlas
graph_layer: system
graph_kind: system

graph_parent: atlas-programming-superiority-architecture

graph_status: active

graph_source: repo
human_name: Atlas Long-Horizon Intelligence Layer
canonical_name: Atlas Long-Horizon Intelligence Layer
technical_name: atlas-long-horizon-intelligence-layer
cartography_type: system
canonical_source: docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md

allowed_changes:
  - Adicionar schema/componente quando shipé em código.
  - Promover gap GREENFIELD → EXISTS_PARTIAL → EXISTS_WIRED com receipt.

forbidden_changes:
  - Inventar termo paralelo a "long_horizon" sem entry no glossary canônico.
  - Declarar superioridade numérica sem benchmark methodology executada (M10).
  - Fundir continuity Dev e Forge em runtime único (boundary dual-core preservado).
  - Auto-promover memória long-horizon sem operator review.

depends_on:
  - atlas-programming-superiority-architecture
  - atlas-programming-superiority-contracts
  - atlas-canonical-glossary-and-naming
  - atlas-dual-core-engineering-system
  - atlas-evidence-certification-runtime
  - atlas-compounding-engineering-intelligence

flows_to:
  - atlas-programming-superiority-roadmap
  - atlas-pre-benchmark-readiness-audit

unlocks:
  - dev_multi_week_continuity
  - forge_multi_month_obra_continuity
  - long_horizon_audit_trail
  - context_freshness_enforcement

governs:
  - atlas_long_horizon_continuity

evidence:
  - app/Models/AiForgeLongHorizonState.php
  - app/Models/AiForgeWorkPacketExecutionCycle.php
  - app/Models/AiForgeMilestone.php
  - app/Models/AiForgeWorkPacket.php
  - app/Models/AiForgeIntake.php
  - app/Models/AiSessionState.php
  - app/Models/AiCompaction.php
  - app/Models/AtlasDevRunIndex.php
  - app/Models/AtlasProgrammingStageReceipt.php
  - app/Models/AtlasLedgerEvent.php
  - app/Models/AtlasSpec.php
  - app/Models/AtlasSddDriftReport.php
  - app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
  - app/Services/Ai/Programming/Forge/ForgeLongHorizonStateCanon.php
  - app/Services/Ai/Programming/ProgrammingResumeService.php
  - app/Services/Ai/Programming/AtlasDev/Persistence/ReceiptStorage.php
  - app/Services/Ai/AiCompactionService.php
  - app/Services/Ai/AiSessionStateService.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Programming/Sdd/SpecDriftDetector.php
  - app/Services/Ai/SelfConstruction/AgentControlPlaneContinuationSummaryBuilder.php

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - Adicionar entry `long_horizon` ao `atlas-canonical-glossary-and-naming.md`.
  - Implementar `atlas.long_horizon.continuation_pack.v1` (abstração unificada Dev + Forge).
  - Implementar `atlas.long_horizon.compaction_receipt.v1` com must_keep_coverage enforcement.
  - Implementar `LongHorizonRecoveryPlannerService` (greenfield).
  - Implementar `LongHorizonContextFreshnessGate` (greenfield).
  - Adicionar scopes `obra` e `long_horizon` em `AtlasMemoryEntry::SCOPES`.

---
# Atlas Long-Horizon Intelligence Layer

## Resumo
**Tese**: Atlas vence contexto longo transformando **chat ephemeral em estado
operacional durável** via 13 componentes auditáveis (state store, continuation
pack, superior compaction, decision ledger, evidence timeline, SDD evolution
log, work packet history, drift detector, recovery planner, context freshness
gate, memory promotion, operator review points, next best action).

**Veredicto (2026-05-18)**: **não é greenfield**. 7 componentes `WIRED`, 4
`PARTIAL/ADJACENT`, 2 `GREENFIELD` (Recovery Planner, Context Freshness Gate).
Forge long-horizon sólido (`AiForgeLongHorizonState` + `Service` + `Milestone`
+ `WorkPacket` + `WorkPacketExecutionCycle` cycle_position monotônico). Dev
continuity em nível de run via `ProgrammingResumeService::continuationPacket`
+ `ReceiptStorage` atomic monotonic + `AtlasDevRunIndex`.

**Gap principal**: faltam **2 schemas canônicos unificadores** —
`atlas.long_horizon.continuation_pack.v1` e
`atlas.long_horizon.compaction_receipt.v1` — para cross-scope (session → run →
mission → workstream → milestone → obra) homogêneo. **benchmark_not_run: true**.

## Papel no Atlas
Resolve 3 problemas concretos: (1) **Atlas Dev** sustentar contexto de
semanas/1 mês sem operator re-explicar decisões — hoje continuity em receipts
atomic por `run_id` mas **sem agregação workstream** cross-run. (2) **Atlas
Forge** operar **Obras de meses** — hoje `AiForgeLongHorizonState` per-intake
mas **sem timeline queryable de decisões/evidências aggregadas por Obra**.
(3) **Operador pausar/retomar sem perda crítica** — hoje `atlas:cli:continue` /
`atlas:programming:resume` existem mas **sem freshness gate**.

Por que Claude Code/Codex degradam em contexto longo: perda de contexto (zero
state store durável); compactação fraca (chat summary genérico sem `must_keep`
enforcement); esquecimento de decisões (zero ledger queryable cross-session);
tarefas abertas perdidas; docs divergentes (sem drift detector cruzando
`AtlasSpec.version` vs código); testes esquecidos (sem evidence timeline);
drift arquitetural; prompts humanos repetitivos; falta de evidence timeline
append-only.

## Onde Se Encaixa
Layer 0.72 Programming. Filho de `atlas-programming-superiority-architecture`.
13 componentes arquiteturais:

| # | Componente | Estado | Evidência |
|---|-----------|--------|-----------|
| 1 | Long-Horizon State Store | WIRED (Forge) | `AiForgeLongHorizonState.php`, `ForgeLongHorizonStateService.php` |
| 2 | Continuation Pack | WIRED (Dev run-level) | `ProgrammingResumeService.php:69` schema `atlas.programming.continuation_packet.v1` |
| 3 | Compaction Engine | PARTIAL (thread) | `AiCompactionService.php`; sem scope obra/milestone |
| 4 | Decision Ledger | PARTIAL | `AiAuditEvent` + `AiRouterDecision` + `AtlasDecisionReceipt`; sem agregador cross-scope |
| 5 | Evidence Timeline | WIRED | `AtlasEvidenceLedger.php` schema `atlas.ledger_event.v1` |
| 6 | SDD Evolution Log | ADJACENT | `AtlasSpec.version`, `AtlasAssumption.resolved_at`, `AtlasSddDriftReport`; sem agregador |
| 7 | Work Packet History | WIRED | `AiForgeWorkPacketExecutionCycle` (cycle_position monotônico) |
| 8 | Drift Detector | PARTIAL (SDD) | `SpecDriftDetector.php`; sem state drift |
| 9 | Recovery Planner | **GREENFIELD** | nenhum código |
| 10 | Context Freshness Gate | **GREENFIELD** | nenhum código |
| 11 | Memory Promotion | PARTIAL | `AiMemoryDeltaProposer`; sem scope `obra/long_horizon` |
| 12 | Operator Review Points | PARTIAL | Self-Construction gates; sem lifecycle long-horizon |
| 13 | Next Best Action | PARTIAL | `ForgeLongHorizonStateCanon::NEXT_ACTION_*` + `AtlasControlPlaneNextActionService`; sem cross-component sequencing |

Boundary dual-core preservado: Dev e Forge têm continuity próprias; long_horizon
abstrai contratos sem fundir runtimes.

## Contratos
### Schemas canônicos propostos (novos)

**`atlas.long_horizon.continuation_pack.v1`** — abstração unificada acima de
`atlas.programming.continuation_packet.v1` (Dev) e
`atlas.forge.long_horizon_state.v1` (Forge):
- `pack_id` (uuid), `schema_version`, `pack_hash` (sha256).
- `scope_type` (dev_session|dev_workstream|forge_obra|mission|work_packet|milestone).
- `scope_id`.
- `objective` (texto canônico imutável).
- `current_phase`, `phase_history[]`.
- `state_summary` (provider-safe).
- `decisions[]` (cada com decision_id, made_at, evidence_refs, rationale).
- `open_tasks[]`, `completed_tasks[]`, `cancelled_tasks[]`.
- `blockers[]` (cada com blocker_id, reason, opened_at, severity).
- `risks[]`.
- `evidence_refs[]`.
- `context_pack_hash` (último context pack válido).
- `summary_hash`.
- `source_receipts[]` (receipt_id list ordenado).
- `stale_after` (ISO8601 com TTL por scope_type).
- `next_best_action`.
- `human_decisions_required[]`.
- `confidence` (0.0-1.0).
- `created_at`, `updated_at`.

**`atlas.long_horizon.compaction_receipt.v1`** — receipt de cada
compactação:
- `receipt_id` (uuid), `schema_version`.
- `source_context_refs[]` (lista de refs antes da compactação).
- `retained_items[]` (com `must_keep:true` flag por item).
- `discarded_items[]`.
- `discarded_reason[]` (paralelo: stale|low_signal|duplicate|out_of_scope|superseded).
- `must_keep_coverage` (% items `must_keep` retidos; **deve ser 1.0**).
- `evidence_refs[]`.
- `summary_hash` (sha256 do output).
- `quality_score` (0.0-1.0).
- `detected_contradictions[]`.
- `stale_risks[]`.
- `created_at`.

### Schemas existentes a reutilizar (não inventar paralelos)

- `atlas.forge.long_horizon_state.v1` (`ForgeLongHorizonStateCanon:21`) — Obra-scoped state per intake.
- `atlas.forge.work_packet_execution_cycle.v1` — append-only cycle history.
- `atlas.programming.continuation_packet.v1` (`ProgrammingResumeService:69`) — Atlas Dev resume per run.
- `atlas.ledger_event.v1` (`AtlasEvidenceLedger`) — append-only event ledger.
- `atlas.ai.control_plane.snapshot.v1` (`AtlasControlPlaneSnapshotService`) — point-in-time snapshot.
- `atlas.ai.compounding.{outcome,learning_candidate,memory,heuristic_update}.v1` (11 services em `Compounding/`).
- `atlas.programming.stage_receipt.v1` (`AtlasProgrammingStageReceipt`) — per-stage receipt.

### Persistence / Data Models

**Obrigatório agora (M1):**
- Tabela `ai_long_horizon_continuation_packs` (PK pack_id; indexes scope_type, scope_id, stale_after).
- Tabela `ai_long_horizon_compaction_receipts` (PK receipt_id; FK ai_long_horizon_continuation_packs).
- Adicionar scopes `obra` e `long_horizon` em `AtlasMemoryEntry::SCOPES`.

**Fase 2:**
- Tabela `ai_long_horizon_decision_ledger_entries` (agregador queryable acima de AiAuditEvent/AiRouterDecision/AtlasDecisionReceipt por scope_id).
- Tabela `ai_long_horizon_drift_findings` (estende SpecDriftDetector).
- Tabela `ai_long_horizon_recovery_plans` (greenfield).
- Tabela `ai_long_horizon_evidence_timeline_events` (proxy queryable acima de AtlasLedgerEvent filtrado por scope).
- Tabela `ai_long_horizon_memory_promotion_candidates` (estende AiMemoryDelta).
- Tabela `ai_long_horizon_operator_review_points`.

**Futuro:**
- Tabela `ai_long_horizon_sdd_evolution_snapshots` (agregador AtlasSpec + AtlasAssumption + AtlasSddDriftReport por Obra).
- Tabela `ai_long_horizon_next_action_chains` (multi-step lookahead).

### Commands / APIs canônicos propostos

- `atlas:long-horizon:compact` — emite `compaction_receipt.v1` para scope.
- `atlas:long-horizon:continue` — carrega `continuation_pack.v1`, valida freshness, retorna next_best_action.
- `atlas:long-horizon:status` — relatório agregado por scope.
- `atlas:long-horizon:drift-check` — roda Drift Detector ampliado.
- `atlas:long-horizon:recovery-plan` — emite plano de recovery (greenfield).
- `atlas:long-horizon:certify` — valida invariantes da camada antes de declaração ready.
- `atlas:long-horizon:freshness-gate` — checa stale_after e bloqueia se expired (greenfield).

## Fluxo
### Atlas Dev Long-Context Runtime (semanas)
1. Operador inicia run → `IntentKernelService` → `DomainRouterService` →
   `FlowRouterService` → `DualCoreRouteDecisionService::recordFromFlowRoute`
   (emite `atlas.dual_core.route_decision.v1`).
2. `AtlasDevFastPathOrchestrator` cria/recupera `run_id`; `AtlasDevRunIndex`
   indexa por `thread_id`/`workspace_hash`.
3. `ReceiptStorage` persiste artifacts (`atomic` write + monotonic versioning).
4. **NOVO (M3):** ao final de cada run, `LongHorizonContinuationPackBuilder`
   produz `continuation_pack.v1` com `scope_type=dev_workstream`
   (workstream = união de runs por `thread_id` ou `workspace_hash`).
5. Quando contexto exceder budget (definido por `AiCompaction` rules),
   `LongHorizonCompactionEngine` compacta: emite `compaction_receipt.v1`
   com `must_keep_coverage=1.0` (decisions e blockers nunca descartáveis).
6. `ProgrammingResumeService` em `atlas:cli:continue` carrega
   `continuation_pack` + valida `stale_after` via Freshness Gate (M5).
7. Se stale → bloqueia resume, exige operator decision ou re-retrieval.
8. Se workstream cresce → `EscalationDecisionEngine` pode escalar para Forge
   via `atlas.dev_to_forge.escalation_packet.v1`.

### Atlas Forge Multi-Month Obra Runtime
1. Operador cria intake → `AiForgeIntake` row.
2. `ForgeLongHorizonStateService::initializeForIntake()` cria
   `AiForgeLongHorizonState` (status=active).
3. `ForgeMilestonePlanner` cria `AiForgeMilestone` rows (position monotônico).
4. `AiForgeWorkPacket` rows criados por milestone com
   `expected_files/dependencies/acceptance_criteria/required_evidence`.
5. Cada execução de work packet emite `AiForgeWorkPacketExecutionCycle`
   (cycle_position monotônico, schema `atlas.forge.work_packet_execution_cycle.v1`).
6. `ForgeMilestoneGateRunner` aplica gates; `MissionCertificationService`
   certifica milestone.
7. **NOVO (M4):** `LongHorizonContinuationPackBuilder` produz `continuation_pack.v1`
   com `scope_type=forge_obra` semanalmente (ou ao final de milestone).
8. `ForgeLongHorizonStateService::recordCycle()` atualiza `cycle_count` e
   `last_cycle_summary`.
9. Compactação obra-scoped emite `compaction_receipt.v1`.
10. Drift Detector (M5) compara `AtlasSpec.version[t]` vs `version[t-Δ]`
    + work packet completion vs DoD declarado.
11. Operator Review Points (M7): a cada milestone, operator confirm via
    `AtlasOperatorDecision`.
12. `MissionLifecycleService::transition(completed)` exige `evidence_refs ≥1` +
    `certification.status=passed` per invariant existing.

### Superior Context Compaction Engine — pseudoalgoritmo
```
function compact(scope_id, scope_type, source_refs[]):
  must_keep = filter(refs, tag ∈ [decision, blocker, dod, risk_critical, operator_constraint])
  may_discard = filter(refs, tag ∈ [stale_query, low_score_ref, duplicate])
  // classificar undecided em must_keep | may_discard
  retained = must_keep ∪ select_high_signal(may_discard, budget)
  discarded = refs \ retained
  must_keep_coverage = |retained ∩ must_keep| / |must_keep|
  assert must_keep_coverage == 1.0  // invariante absoluta
  emit compaction_receipt.v1(retained, discarded, discarded_reason[],
                             contradictions, stale_risks, summary_hash,
                             quality_score, evidence_refs)
  return (summary, receipt_id, summary_hash)
```

### Recovery Planner — fluxo
1. `LongHorizonRecoveryPlannerService::plan(scope_id)`.
2. Carrega `continuation_pack` mais recente.
3. Valida freshness (M5 gate).
4. Compara repo state (HEAD vs `pack.context_pack_hash`).
5. Identifica missing context (refs que mudaram entre pack e HEAD).
6. Retrieva missing context via `ProgrammingRetrievalPlanner`.
7. Re-roda gates relevantes (`ProgrammingGapCritic`, `RagGate`).
8. Gera `next_best_action`.
9. Se stale ou unsafe → bloqueia retomada com `blocker_reason`.

## Regras para IA
1. **Nunca declarar "continua"** sem freshness check (`stale_after` em pack).
2. **Compactação sem receipt** = bug; abortar.
3. **must_keep_coverage < 1.0** = bug crítico; abortar e escalar.
4. **Memory promotion** exige operator review se scope ∈ {obra, long_horizon}.
5. **`completed` sem certification** = throw (invariante já em
   `MissionLifecycleService:121-131`).
6. **Forge sem milestone ledger** = configuração inválida.
7. **Atlas Dev tentando carregar Obra de meses** = escalar para Forge,
   não consumir.
8. **Drift detected** = criar recovery plan automaticamente; nunca silencioso.
9. **Operator review points em Forge** são mandatórios por milestone; pular
   = violação de governança.
10. **Decision Ledger é append-only**; nunca update/delete de decision.
11. **benchmark_not_run: true** até `atlas-pre-benchmark-readiness-audit.md`
    P0/P1 verdes E M1-M8 desta camada shipped.
12. **Resume com `stale_after < now`** = bloqueia; exige re-retrieval.

### Quality Gates
- No `continuation_pack` without `evidence_refs ≥ 1`.
- No `compact()` without emitting `compaction_receipt`.
- No `resume` if `stale_after < now` (Freshness Gate, M5).
- No `mission.transition(completed)` without certification (existente).
- No memory_promotion to scope `obra|long_horizon` without operator_decision.
- No `next_best_action` execution without operator confirmation (Forge).
- No `recovery_plan` without `drift_finding_id` ou explicit recovery trigger.

## Escopo de Implementacao
Cobre design + contratos + roadmap. **Não** implementa código nem benchmark.

**Cobre:**
- 2 schemas novos canônicos (continuation_pack, compaction_receipt).
- Reuse explícito de 7 schemas existentes.
- 13 componentes arquiteturais com estado classificado.
- 9 tabelas (3 obrigatórias agora, 6 fase 2).
- 7 comandos CLI canônicos.
- 8 quality gates.
- 8 testes obrigatórios.
- 8 métricas operacionais.
- 8-fase roadmap.
- 10 missões priorizadas.

**Não cobre:**
- Driver real de provider (decisão de produto separada).
- Benchmark execution (`atlas-pre-benchmark-readiness-audit.md` é gate).
- UX Desktop (out of scope deste layer; integração via existing Cockpit).
- Multi-agent scheduler dedicado (M8 da `superiority-roadmap.md`).

## Dependencias
Canon dependencies: `atlas-canonical-glossary-and-naming.md` (entry
`long_horizon` a adicionar), `atlas-dual-core-engineering-system.md`
(boundary preservado), `atlas-evidence-certification-runtime.md`,
`atlas-compounding-engineering-intelligence.md`, trinity superiority
(`-architecture/-contracts/-roadmap.md`), `atlas-runtime-spine-completion-audit.md`
(spine 60% closed), `atlas-pre-benchmark-readiness-audit.md`,
`atlas-local-agent-memory-ingestion.md`. Backend: Meta 1 (Mission) + Meta 4
(Evidence) + Meta 6 (Router) + Meta 9 (Control Plane) live.

## Evidencias
### Testes obrigatórios
1. **Dev session continuation**: criar workstream com 3 runs em 3 dias diferentes; `continue` retoma com `must_keep_coverage=1.0` e `stale_after` válido.
2. **Forge Obra continuation**: pausar Obra em milestone 2/5; resume após 7 dias preserva milestone state, work packets ativos, evidence_refs.
3. **Compaction preserves must_keep**: compactar contexto 200 refs com 12 must_keep → retidos ≥ 12, `must_keep_coverage == 1.0`.
4. **Discarded items recorded**: cada descarte tem `discarded_reason` enum válido.
5. **Stale context blocks resume**: `continuation_pack` com `stale_after = now - 1h` → resume retorna `status=blocked, reason=stale_context`.
6. **Drift finding creates recovery plan**: criar `AtlasSddDriftReport` → trigger `recovery-plan` command → emite plano com next_action.
7. **Continuation pack JSON stable**: serializar mesmo pack 2x → sha256 idêntico (deterministic).
8. **Certification blocks missing evidence**: pack sem `evidence_refs[]` → `certify` retorna `failed` com `missing_requirements=['evidence_refs_exist']`.

### Métricas operacionais (sem claim numérico vs rivais)
- `context_retention_score` = % `must_keep` itens preservados across N compactions.
- `compaction_loss_rate` = items descartados com `discarded_reason ∈ stale|low_signal` / total descartados.
- `stale_recovery_rate` = % runs bloqueados por freshness gate.
- `human_re_explanation_count` = mensagens operator re-explicando decisões já em pack.
- `continuation_success_rate` = resumes que produzem `next_best_action` válido / total resumes.
- `missed_decision_rate` = decisões em `decision_ledger` ausentes em compaction summary.
- `drift_detection_precision` = drift findings true_positive / total findings.
- `long_horizon_completion_rate` = Obras com `status=completed` / Obras iniciadas em 30+ dias.

**benchmark_not_run: true** — métricas medem só Atlas; comparação vs rivais
está em `atlas-pre-benchmark-readiness-audit.md` M10.

## Riscos
### Anti-patterns proibidos + riscos arquiteturais
1. Summary solto sem receipt; compactar só texto final descartando decisions/blockers; esconder descartes (`discarded_items=[]` mentiroso).
2. "Continua" sem freshness check; memória sem evidência; Forge sem milestone ledger.
3. Atlas Dev sustentar workstream >1 mês (deveria escalar para Forge); benchmark antes M1-M8 shipped.
4. Naming proliferation (`WorkstreamState` paralelo a `AiForgeLongHorizonState` em vez de estender).
5. Compaction loss silenciosa (sem `must_keep_coverage=1.0` enforce).
6. Drift false-positives (heurística ruim) → fadiga operator; recovery loop infinito (plan sempre falha freshness).
7. Memory promotion contaminada (hipóteses fracas como facts).
8. Forge HTTP path ainda não canônico (Mechanism 4 P1 pendente) — long-horizon recovery via HTTP pode bypassar gates.
9. Operator fatigue (review points excessivos → carimba sem ler); scope creep (`long_horizon` virar guarda-chuva).

## Exemplos
**Trace Dev workstream (alvo M3+M5):** Day 1 → 3 runs sobre feature X →
`ReceiptStorage` 3 pastas + `AtlasDevRunIndex` agrega por `thread_id`.
End-of-day → `LongHorizonContinuationPackBuilder` emite `continuation_pack.v1`
(`scope_type=dev_workstream`, decisions=[d1-d3], open=[t4,t5],
`stale_after=now+7d`). Day 4 → `atlas:cli:continue --thread=X` → Freshness Gate ✅
→ Recovery Planner compara HEAD vs `pack.context_pack_hash` → 2 files mudaram →
retrieva delta → `next_best_action="implementar t5 considerando file A"`.

**Trace Forge Obra (real, `ForgeLongHorizonStateService`):** Intake →
`AiForgeIntake` + `AiForgeLongHorizonState` (status=active). Milestone 1: 3
work packets via `AiForgeWorkPacketExecutionCycle` (cycle_position 1-3) →
`recordCycle()` → `advanceMilestone()`. Semana 2 pausa; semana 4 retoma.
**NOVO (M4)** Builder produz `continuation_pack.v1` scope=forge_obra; Freshness
Gate (M5) valida; Recovery Planner identifica drift via SpecDriftDetector
ampliado; Obra continua milestone 2/5.

## Proximas Acoes
### 8-Phase Implementation Roadmap

| Fase | Nome | Entregáveis | Esforço |
|------|------|-------------|---------|
| M1 | Contracts + receipts | Schemas continuation_pack + compaction_receipt + 3 tabelas + glossary entry | 8-12h |
| M2 | Compaction engine | LongHorizonCompactionEngine + must_keep_coverage enforce + 4 tests | 12-16h |
| M3 | Dev continuity | LongHorizonContinuationPackBuilder cross-run + cmd `atlas:long-horizon:continue` Dev | 16-20h |
| M4 | Forge continuity | Builder cross-milestone + integração `ForgeLongHorizonStateService` | 12-16h |
| M5 | Drift + Recovery | RecoveryPlannerService + FreshnessGate (greenfield) + drift detector ampliado | 24-32h |
| M6 | Memory promotion | Scopes `obra`/`long_horizon` em AtlasMemoryEntry + promotion gate operator-reviewed | 10-14h |
| M7 | Certification + Control Plane | long_horizon invariants em MissionCertificationService + snapshotHistory | 14-18h |
| M8 | Benchmark readiness | (não executa benchmark) — só prepara substrate; aguarda M10 da superiority-roadmap | depende |

**Total M1-M7:** 96-128h. M8 é gate, não trabalho.

### Top 10 Implementation Missions

| # | Pri | Missão | Esforço | Paralelizável |
|---|-----|--------|---------|---------------|
| L1 | P0 | Adicionar entry `long_horizon` em `atlas-canonical-glossary-and-naming.md` + 2 schemas em contracts doc | 2-3h | não (fundação) |
| L2 | P0 | Implementar tabelas `ai_long_horizon_continuation_packs` + `ai_long_horizon_compaction_receipts` + models | 4-6h | não |
| L3 | P1 | `LongHorizonContinuationPackBuilder` (abstração cross-Dev/Forge) | 10-14h | paralelo L4 |
| L4 | P1 | `LongHorizonCompactionEngine` com must_keep_coverage invariant | 12-16h | paralelo L3 |
| L5 | P1 | Integrar Dev: builder cross-run via `AtlasDevRunIndex` + cmd `atlas:long-horizon:continue` | 8-12h | depende L3+L4 |
| L6 | P1 | Integrar Forge: builder cross-milestone via `ForgeLongHorizonStateService` | 8-12h | paralelo L5 (área diferente) |
| L7 | P1 | `LongHorizonContextFreshnessGate` (greenfield) + cmd `freshness-gate` | 6-10h | depende L2 |
| L8 | P2 | `LongHorizonRecoveryPlannerService` (greenfield) + cmd `recovery-plan` | 16-24h | depende L7 |
| L9 | P2 | Estender drift detector além SDD (state staleness, decision-evidence mismatch) | 10-14h | paralelo L8 (mesmo arquivo, sequencial) |
| L10 | P2 | Adicionar scopes `obra`/`long_horizon` em `AtlasMemoryEntry::SCOPES` + promotion gate operator-reviewed | 6-8h | paralelo L8/L9 |

**Paralelização segura:** L3+L4 (diferentes services); L5+L6 (Dev vs Forge);
L8+L10 (greenfield diferentes).

### Definition of Done — Atlas Long-Horizon Intelligence Layer

A camada é **implementada** quando TODOS verdes simultaneamente:
(1) Entry `long_horizon` no glossário canônico.
(2) Schemas `continuation_pack.v1` + `compaction_receipt.v1` shipped (tabelas + models + tests).
(3) `LongHorizonContinuationPackBuilder` cross-Dev e cross-Forge em E2E.
(4) `LongHorizonCompactionEngine` enforça `must_keep_coverage == 1.0` (test).
(5) `LongHorizonContextFreshnessGate` bloqueia resume com pack stale.
(6) `LongHorizonRecoveryPlannerService` emite plano via drift trigger.
(7) Drift detector detecta state staleness + decision-evidence mismatch.
(8) `AtlasMemoryEntry::SCOPES` inclui `obra`/`long_horizon`; promotion operator-reviewed.
(9) Operator Review Points em milestone Forge mandatórios (test enforça).
(10) 8 testes obrigatórios + 8 métricas via `atlas:long-horizon:status`.
(11) Trinity superiority sincronizada. (12) **benchmark_not_run: true** até M10
da superiority-roadmap shipar.

### Gates de fechamento desta entrega (doc)
- `php artisan atlas:engineering:knowledge docs-health --json` → 0 violations, sob line_limit 520; `git diff --check` → limpo; nenhuma doc canônica alterada; **benchmark_not_run: confirmado** — esta doc é design, não execução.
