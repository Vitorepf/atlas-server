---
id: atlas-teos-existing-code-map
type: engineering_knowledge
title: Atlas TEOS Existing Code Map
status: active
category: programming
priority: 95
summary: Mapa técnico do código já implementado que TEOS-I1 deve reutilizar/estender em vez de duplicar. Por componente cita arquivo:classe, função atual, relação com TEOS, classificação (reuse|extend|adapter|deprecate_later|do_not_touch|greenfield), gaps, riscos, teste existente e alteração recomendada. Inclui regras anti-duplicação, ordem recomendada de implementação e mapa de risco. Não implementa código, não roda benchmark.
tags:
  - atlas-teos
  - long-horizon
  - code-map
  - anti-duplication
  - 2026-05-18
capabilities:
  - existing_code_inventory
  - anti_duplication_rules
  - implementation_order
  - test_reuse_matrix
  - risk_map
decisions:
  - TEOS-I1 reutiliza 11 componentes existentes; só 4-5 são realmente greenfield.
  - Compactação Atlas Dev evolui `AiCompactionService` em vez de criar `LongHorizonCompactionEngine` paralelo.
  - Continuation pack unificado é abstração nova ACIMA de `atlas.programming.continuation_packet.v1` e `atlas.forge.long_horizon_state.v1`, sem deprecar nenhum.
  - Decision ledger reutiliza `AtlasLedgerEvent` (append-only enforced) em vez de criar tabela paralela.
  - Memory store reutiliza `AtlasMemoryEntry` com scopes novos `obra`/`long_horizon` em vez de novo store.
  - Benchmark runner é o `BenchmarkReadinessHarness` existente; TEOS nunca cria runner próprio.
maintenance:
  - Atualizar quando um componente promover classificação (greenfield → extend → reuse).
  - Sincronizar com `atlas-long-horizon-intelligence-layer.md` após cada slice TEOS.
  - Re-verificar arquivo:linha quando refactor mover símbolos.
related_paths:
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-teos-existing-code-map
graph_title: Atlas TEOS Existing Code Map
graph_world: atlas
graph_layer: system
graph_kind: index
graph_parent: atlas-long-horizon-intelligence-layer
graph_status: active
graph_source: repo
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
allowed_changes:
  - Promover componente: greenfield → extend → reuse.
  - Adicionar novo componente quando shippado em código.
  - Ajustar arquivo:linha quando refactor move símbolos.
forbidden_changes:
  - Declarar `reuse` sem grep que prove uso em produção.
  - Esconder gap (downgrade fake de greenfield para extend).
  - Aprovar duplicação porque "é mais simples".
depends_on:
  - atlas-long-horizon-intelligence-layer
  - atlas-programming-superiority-architecture
  - atlas-programming-superiority-contracts
  - atlas-canonical-glossary-and-naming
flows_to:
  - atlas-programming-superiority-roadmap
unlocks:
  - teos_i1_implementation_kickoff
governs:
  - atlas_teos_existing_code_reuse
evidence:
  - app/Services/Ai/AiCompactionService.php
  - app/Services/Ai/AiSessionStateService.php
  - app/Services/Ai/Programming/ProgrammingResumeService.php
  - app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php
  - app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php
  - app/Models/AtlasLedgerEvent.php
  - app/Models/AiAuditEvent.php
  - app/Models/AtlasMemoryEntry.php
  - app/Models/AiCodebaseWorldModel.php
  - app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php
  - app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentMemoryIngestionService.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - app/Services/Ai/ProgrammingRuntime/ControlPlane/ProgrammingRuntimeControlPlaneService.php
  - app/Services/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarness.php
  - app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: high
next_actions:
  - Cravar L1 (`long_horizon` glossary entry) antes de qualquer commit TEOS.
  - Materializar L2 (continuation_pack + compaction_receipt tables + models) como primeiro slice greenfield.
  - Migrar `AiCompactionService` para suportar `scope_type ∈ {thread, dev_workstream, forge_obra, work_packet, milestone}` antes de qualquer escrita paralela.
  - Validar que TEOS commands sempre carregam `claim_policy.benchmark_not_run=true` (regra herdada de `ProgrammingConsoleService`).
line_limit: 900
---

# Atlas TEOS Existing Code Map

## Resumo

**Pergunta canônica:** *onde encaixar TEOS-I1 sem criar sistema paralelo?*

**Resposta operacional:** 15 componentes inventariados; **11 são reuse/extend**,
**4 são greenfield real** (LongHorizonContextFreshnessGate,
LongHorizonRecoveryPlannerService, ReplayManifest builder/reader,
ContinuityCertification service) e **1 é greenfield opcional**
(CausalDecisionGraph lite, depende de Decision Ledger ampliado). Compactação,
continuation, long-horizon state, ledger append-only, memory governance,
world model, certification, telemetry, control plane e benchmark readiness
já estão **shipados em produção** com testes próprios.

**Top-3 riscos de duplicação:** (1) criar tabela `ai_long_horizon_compactions`
paralela a `ai_compactions`; (2) criar `LongHorizonDecisionLedger` paralelo a
`AtlasLedgerEvent`; (3) criar `LongHorizonMemoryStore` paralelo a
`AtlasMemoryEntry`. Os três são proibidos por `Anti-Duplication Rules` (§14).

**benchmark_not_run: true** — TEOS é substrate de benchmark, não benchmark
em si. O harness existente já garante esse invariante; TEOS nunca toca o
runner.

## Papel no Atlas

Doc é **mapa técnico de reuse**, não roadmap nem contrato. Existe para
3 audiências:

1. **Implementador TEOS-I1** — saber onde colocar cada feature antes de criar
   classe nova. Cada componente carrega `Alteração recomendada` específica.
2. **Reviewer** — bloquear PR que duplique `AtlasLedgerEvent`,
   `AtlasMemoryEntry`, `AiCompactionService` ou `ForgeLongHorizonStateService`.
3. **Operador** — entender que TEOS não é um produto novo; é uma *extensão
   coordenada* do que já roda.

Complementa `atlas-long-horizon-intelligence-layer.md` (que define schemas e
roadmap). Esta doc cita arquivo:classe.

### Classification Legend

| Tag | Significado | Quando usar |
|-----|-------------|-------------|
| `reuse` | Componente serve TEOS as-is; usar sem modificar. | Schema/serviço estável com testes próprios. |
| `extend` | Adicionar campo/método sem quebrar callers existentes. | Coluna nova, scope novo, parâmetro opcional. |
| `adapter` | TEOS escreve um wrapper read-only acima. | Não modificar o original; expor via projection. |
| `deprecate_later` | Funciona, mas TEOS-I2/I3 absorverá; manter compat. | Surface legacy ainda em uso. |
| `do_not_touch` | Estável e fora do escopo TEOS. | Camadas Kernel/runtime crítico. |
| `greenfield` | Não existe; precisa nascer dentro do escopo TEOS. | Sem código matching, sem AP equivalente. |

## Onde Se Encaixa

Layer 0.72 Programming, filho de `atlas-long-horizon-intelligence-layer`.
TEOS-I1 vive na intersecção de 4 camadas existentes:

```text
                +-----------------------------+
                |  Atlas TEOS / Long-Horizon  |   <- esta camada
                +-----------------------------+
                  |        |        |        |
       +----------+ +------+ +------+ +------+----------+
       |            |        |        |                 |
   Compaction   Resume    Forge    Evidence/         Memory /
   (Dev/Forge)  /Stage    LH State Ledger            World Model
       |            |        |        |                 |
   AiCompaction ProgrammingResume Forge*  AtlasLedger  AtlasMemoryEntry
   AiSessionState                                       AiCodebaseWorldModel*
```

TEOS não substitui essas camadas — sequência-as via `continuation_pack.v1`
unificado e `compaction_receipt.v1` cross-scope. Boundary dual-core
preservado: Dev e Forge mantêm runtimes próprios.

## Contratos

### Schemas existentes a reutilizar (não duplicar)

| Schema | Fonte | Classificação |
|--------|-------|---------------|
| `atlas.programming.continuation_packet.v1` | `ProgrammingResumeService:69` | `reuse` (Dev run-level) |
| `atlas.forge.long_horizon_state.v1` | `ForgeLongHorizonStateCanon:21` | `reuse` (Obra-scoped) |
| `atlas.forge.work_packet_execution_cycle.v1` | `ForgeWorkPacketExecutionCycleCanon:29` | `reuse` (cycle history) |
| `atlas.ledger_event.v1` | `AtlasEvidenceLedger::SCHEMA_VERSION` | `reuse` (append-only) |
| `atlas.programming.stage_receipt.v1` | `AtlasProgrammingStageReceipt` | `reuse` (Dev stage timeline) |
| `atlas.ai.local_agent_ingestion.{run,source,candidate,receipt}.v1` | `LocalAgentMemoryIngestionCanon:21-27` | `reuse` (memory ingestion) |
| `atlas.programming.codebase_world_model.ranking.v1` | `WorldModelGraphRanker::SCHEMA` | `reuse` (graph ranking) |
| `atlas.programming.benchmark_suite.readiness.v1` | `BenchmarkReadinessCanon::SUITE_SCHEMA_VERSION` | `reuse` (suite manifest) |
| `atlas.programming.runtime_control_plane.v1` | `ProgrammingRuntimeControlPlaneCanon` | `reuse` (read model) |
| `atlas.programming.runtime_telemetry.aggregate.v1` | `ProgrammingRuntimeTelemetryCanon` | `reuse` (telemetry) |
| `atlas.programming.console.v1` | `ProgrammingConsoleCanon::SCHEMA_VERSION` | `reuse` (canonical envelope) |
| `atlas.ai.compounding.{outcome,memory,heuristic_update}.v1` | `app/Services/Ai/Compounding/` (11 services) | `reuse` (compounding) |

### Schemas novos canônicos TEOS-I1 (greenfield)

| Schema | Aterrissa em | Classificação |
|--------|--------------|---------------|
| `atlas.long_horizon.continuation_pack.v1` | tabela nova `ai_long_horizon_continuation_packs` | `greenfield` |
| `atlas.long_horizon.compaction_receipt.v1` | tabela nova `ai_long_horizon_compaction_receipts` | `greenfield` |
| `atlas.long_horizon.context_freshness_gate.v1` | output do `LongHorizonContextFreshnessGate` | `greenfield` |
| `atlas.long_horizon.recovery_plan.v1` | output do `LongHorizonRecoveryPlannerService` | `greenfield` |
| `atlas.long_horizon.replay_manifest.v1` | output do ReplayManifest builder/reader | `greenfield` |
| `atlas.long_horizon.continuity_certification.v1` | output do ContinuityCertification service | `greenfield` |

## Fluxo

### Existing Components Map

Cada linha: **arquivo/classe** · **função atual** · **relação TEOS** ·
**classificação** · **gap** · **risco** · **teste existente** · **alteração
recomendada**.

#### Compaction Layer

**`app/Models/AiCompaction.php`** · linha 9. Fillables: `thread_id`,
`session_id`, `reason`, `source_position_start/end`, `source_message_count`,
`summary`, `structured_state` (JSON), `token_estimate_before/after`,
`quality_gate_status`, `provider`, `model`, `metadata`. **TEOS:** *é* a tabela
de compaction. **Classificação:** `extend`. **Gap:** sem `scope_type` (só
thread/session); sem `must_keep_coverage`; sem ligação a
`continuation_pack_id`. **Risco:** se TEOS criar
`ai_long_horizon_compactions` paralela, dois caminhos divergem em produção.
**Teste:** `tests/Unit/AiSessionManagerTest.php` (compaction integration).
**Alteração:** migration aditiva — colunas `scope_type`, `scope_id`,
`must_keep_coverage` (decimal 5,4), `continuation_pack_id` (uuid nullable),
`detected_contradictions` (json), `stale_risks` (json), `quality_score`
(decimal 4,3). FK opcional para `ai_long_horizon_continuation_packs`. Mesma
classe model; `reason` enum aceita novos valores (`long_horizon_obra`,
`long_horizon_workstream`, `dry_run`).

**`app/Services/Ai/AiCompactionService.php`** · 294 lines. Método principal
`maybeAutoCompact(AiThread, AiSession)` + `compact(...)` com lock
transacional. Hoje opera só thread-level (mensagens AiMessage). **TEOS:**
deve ganhar overload `compactForScope(scope_type, scope_id, refs[],
must_keep_tags[])` que delega para `LongHorizonCompactionEngine` mas mantém
o mesmo lock pattern. **Classificação:** `extend`. **Gap:** zero noção de
scope obra/workstream; sem enforcement `must_keep_coverage == 1.0`; sem
emissão de `compaction_receipt.v1`. **Risco:** branch paralelo que escapa do
`DB::transaction` causa double-compaction em concurrent runs.
**Teste:** `tests/Unit/AiSessionManagerTest.php`. **Alteração:** novo método
público + reuse do `compactLocked` privado para garantir um único caminho de
escrita.

**`app/Models/AiSessionState.php`** · linha 9. Fillables: `version`,
`active`, `objective`, `current_phase`, `current_topic`, `decisions`,
`open_loops`, `next_steps`, `relevant_artifacts`, `constraints`,
`pending_steer`, `provider_context`, `quality_notes`. **TEOS:** já é o
"continuation pack thread-level". **Classificação:** `reuse` (modelo) +
`adapter` (projection). **Gap:** sem `pack_hash`, `summary_hash`,
`stale_after`. **Risco:** nenhum se TEOS for adapter read-only; perigoso se
TEOS reescrever fillables.
**Teste:** `tests/Unit/AiSessionStateServicePendingSteerTest.php`.
**Alteração:** `LongHorizonContinuationPackBuilder::fromSessionState($state)`
projeta `AiSessionState` em `continuation_pack.v1`. Zero mudança na model.

**`app/Services/Ai/AiSessionStateService.php`** · 362 lines. Persistência +
diff merge + pending_steer. **TEOS:** fonte de dados Dev session-level.
**Classificação:** `reuse`. **Gap:** sem callback para emitir
continuation_pack ao final. **Risco:** se TEOS escrever em `AiSessionState`
direto sem passar pelo service, branch concurrency.
**Teste:** `AiSessionStateServicePendingSteerTest`. **Alteração:** event
listener post-save (Laravel observer) que dispara
`LongHorizonContinuationPackBuilder::onSessionStateUpdated($state)`.

#### Continuation / Resume Layer

**`app/Services/Ai/Programming/ProgrammingResumeService.php`** · 118 lines.
Método `state($planId, $parentPlanId, $previousReceipts)` retorna
`atlas.programming.resume_state.v1` + nested `atlas.programming.continuation_packet.v1`
em `continuationPacket()`. **TEOS:** *é* o continuation pack Dev run-level.
**Classificação:** `reuse` + `extend`. **Gap:** sem `stale_after`, sem
`pack_hash` sha256, sem `must_keep_coverage`. **Risco:** se TEOS criar
`ProgrammingContinuationPackBuilder` paralelo, dois caminhos para
`atlas:cli:continue`. **Teste:**
`tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php`.
**Alteração:** estender output com chaves `pack_hash`, `stale_after`,
`scope_type='dev_run'`, `scope_id=plan_id`. Não criar serviço novo.

**`app/Models/AtlasProgrammingStageReceipt.php`** · stage receipt store
schema. **TEOS:** fonte canônica do timeline de stages (plan/review/patch/
test/repair). **Classificação:** `reuse`. **Teste:** existente em
`ProgrammingStageReceiptStore` callers. **Alteração:** nenhuma; ler via
`ProgrammingStageReceiptStore::timeline($planId)` que já é estável.

**`app/Models/AtlasDevRunIndex.php`** + **`AtlasDevRunIndexRepository`** ·
indexa runs por `thread_id`, `workspace_hash`. **TEOS:** essencial para
agregação `dev_workstream`. **Classificação:** `reuse`. **Gap:** sem método
`forWorkstream($threadId)` retornando lista ordenada com pack_hash; hoje
expõe iteração por `thread_id`. **Alteração:** método novo no
`AtlasDevRunIndexRepository`, sem mudar a tabela.

**`app/Console/Commands/AtlasCliContinueCommand.php`** + **`AtlasProgrammingResumeCommand.php`** ·
operator entry-points para retomar. **TEOS:** carregar
`continuation_pack.v1` + Freshness Gate antes de prosseguir.
**Classificação:** `extend`. **Gap:** sem chamada a Freshness Gate;
hoje retoma sem validar staleness. **Risco:** retomada cega após X dias
herda contexto stale. **Alteração:** wrapper que invoca
`LongHorizonContextFreshnessGate::evaluate($packId)` e bloqueia se
`status=blocked`.

#### Forge Long-Horizon Layer

**`app/Models/AiForgeIntake.php`** · 80 lines. Obra root: `obra_title`,
`recommended_forge_mode`, `risk_band`, `definition_of_done`,
`required_evidence`, `sdd_spec`, `status`, `intake_hash`. Relations:
`workPackets()`, `milestones()`. **TEOS:** Obra identity. **Classificação:**
`do_not_touch`. **Teste:** `ForgeIntakeServiceTest`. **Alteração:** nenhuma.

**`app/Models/AiForgeMilestone.php`** · `expected_artifacts`,
`required_gates`, `required_evidence`, `status`, `milestone_hash`. **TEOS:**
gate units do Obra. **Classificação:** `do_not_touch`. **Teste:**
`ForgeLongHorizonStateServiceTest`. **Alteração:** nenhuma.

**`app/Models/AiForgeWorkPacket.php`** · 87 lines. Packet contract:
`expected_files`, `dependencies`, `risks`, `acceptance_criteria`,
`required_evidence`, `suggested_tests`, `status`, `role_slot`, `risk_band`,
`packet_hash`. **TEOS:** unit de execução. **Classificação:**
`do_not_touch`. **Alteração:** nenhuma.

**`app/Models/AiForgeLongHorizonState.php`** · 88 lines. Per-Obra state:
`current_milestone`, `milestone_progress`, `active_work_packets`,
`completed_work_packets`, `blockers`, `evidence_refs`, `next_action`,
`last_cycle_summary`, `cycle_count`, `continuation_context_hash`,
`state_hash`. **TEOS:** *é* o long-horizon state Obra-scoped.
**Classificação:** `reuse`. **Gap:** `continuation_context_hash` existe mas
não está casado com `pack_hash` do TEOS unificado. **Alteração:** método
`toCanonicalContinuationPackPayload()` que projeta para o schema
`atlas.long_horizon.continuation_pack.v1`. Zero mudança no schema da tabela.

**`app/Services/Ai/Programming/Forge/ForgeLongHorizonStateService.php`** ·
808 lines. `initializeForIntake`, `recordCycle`, `advanceMilestone`,
`completeObra`. Lock + state_hash recompute + next_action compute. **TEOS:**
fonte canônica do estado Obra. **Classificação:** `reuse` + `extend`.
**Gap:** sem hook para emitir `continuation_pack.v1` periódico ou em
milestone-end. **Risco:** se TEOS escrever em
`ai_forge_long_horizon_states` por fora do serviço, divergência de hash.
**Teste:** `tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php`
(13 tests). **Alteração:** método novo `emitContinuationPack()` que delega
para `LongHorizonContinuationPackBuilder`; chamado em
`recordCycle`/`advanceMilestone`/`completeObra`.

**`app/Models/AiForgeWorkPacketExecutionCycle.php`** · 99 lines.
`schema_version=atlas.forge.work_packet_execution_cycle.v1`,
`cycle_position` (monotônico), `execution_mode`, `outcome_status`,
`repair_hook`, `evidence_refs`, `cycle_hash`. **TEOS:** work packet history
append-only. **Classificação:** `reuse`. **Alteração:** nenhuma.

**`app/Services/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleService.php`** ·
versão canônica monotônica de cycle_position (`max+1`). **TEOS:** fonte do
cycle history. **Classificação:** `reuse`. **Teste:**
`ForgeWorkPacketExecutionCycleServiceTest`.

**`app/Services/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycle.php`** ·
camada mais nova do execution cycle (com `select_packet → execution_plan →
gate → repair_hook`). **TEOS:** reuso direto via `WorkPacketExecutionCycle`
para o componente "Work Packet History". **Classificação:** `reuse`.
**Teste:** `tests/Feature/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycleTest.php`.

#### Ledger / Event Layer

**`app/Models/AtlasLedgerEvent.php`** · 60 lines.
**Append-only enforcement** via `save()` que lança `LogicException` em
update; `delete()` proibido. Fillables: `event_id`, `schema_version`,
`tenant_id`, `operator_id`, `envelope_id`, `receipt_id`, `trace_id`,
`correlation_id`, `causation_id`, `event_type`, `payload`, `payload_hash`,
`occurred_at`. **TEOS:** *é* o ledger canônico. **Classificação:** `reuse`.
**Gap:** zero — model já tem `causation_id` para Causal Decision Graph.
**Risco crítico:** criar `ai_long_horizon_decision_ledger_entries` paralela
viola §14 regra 1. **Teste:** `tests/Unit/Ai/Kernel/EvidenceLedgerTest.php`.
**Alteração:** nenhuma. TEOS escreve eventos novos com `event_type` em
`long_horizon.*`.

**`app/Services/Ai/Kernel/Evidence/AtlasEvidenceLedger.php`** · `record()`
emite `AtlasLedgerEvent` com `schema_version=atlas.ledger_event.v1`,
correlation/causation enforced. **TEOS:** entrypoint canônico de gravação.
**Classificação:** `reuse`. **Alteração:** definir `LedgerEventType` enum
novos (`long_horizon.compaction_emitted`, `long_horizon.pack_built`,
`long_horizon.freshness_blocked`, `long_horizon.recovery_planned`,
`long_horizon.continuity_certified`).

**`app/Models/AiAuditEvent.php`** · 34 lines. Fillables: `event_type`,
`target_type`, `target_id`, `actor_type`, `payload`, `event_hash`,
`mission_id`. **TEOS:** decision/audit complementar ao ledger.
**Classificação:** `reuse`. **Gap:** sem agregador queryable cross-scope —
TEOS-I2 pode adicionar projection. **Alteração:** nenhuma em I1.

**`app/Models/AtlasDecisionReceipt.php`** + **`AiRouterDecision.php`** ·
canônicos de decisão por run. **TEOS:** input para Decision Ledger
agregado. **Classificação:** `reuse`. **Alteração:** nenhuma.

#### Memory Layer

**`app/Models/AtlasMemoryEntry.php`** · 199 lines. Tipos:
`decision|preference|feedback|technical_context|issue|resolution|benchmark_observation|harness_learning|anti_memory|strategic_insight`.
**Scopes atuais:** `global|project|task|engineering_run|workspace|user|session`.
**Privacy:** `normal|private|sensitive|secret`. Soft-delete + governance
fields. **TEOS:** *é* o memory store. **Classificação:** `extend`. **Gap:**
`obra` e `long_horizon` ausentes da const `SCOPES`. **Risco:** criar
`AtlasLongHorizonMemoryEntry` viola §14 regra 4. **Alteração:** adicionar 2
valores em `SCOPES` + opcionalmente coluna `superseded_reason` (já existe
`superseded_by_id`).

**`app/Services/Ai/AiMemoryDeltaProposer.php`** + **`AtlasMemoryDeltaPromotionService.php`** ·
propõe e promove memory deltas. **TEOS:** pipeline de memory promotion.
**Classificação:** `extend`. **Gap:** sem operator_review_required quando
scope ∈ `{obra, long_horizon}`. **Alteração:** invariante novo na
promotion: refusal automático para scopes long-horizon sem
`AtlasOperatorDecision`.

**`app/Services/Ai/Memory/LocalAgentIngestion/LocalAgentMemoryIngestionService.php`** +
**`LocalAgentMemoryIngestionCanon.php`** · ingest de memory candidates de
sources locais (4 schemas: run/source/candidate/receipt). **TEOS:** fonte
de candidates para memory promotion. **Classificação:** `reuse`. **Teste:**
existente em `LocalAgentMemoryIngestion*Test.php`. **Alteração:** nenhuma.

**`app/Models/AtlasVerbatimMemory.php`** · raw store. **TEOS:** evidência
audit-grade para memory_promotion. **Classificação:** `reuse`.

#### World Model Layer

**`app/Models/AiCodebaseWorldModel.php`** + **`AiCodebaseWorldModelNode.php`** +
**`AiCodebaseWorldModelEdge.php`** · 3 models JSON-cast,
`HasUuids`. Populated por `AtlasAutonomousEngineeringService::buildWorldModel`.
**TEOS:** modelo de mundo para recovery (`Recovery Planner` consulta
"qual file mudou desde pack.context_pack_hash"). **Classificação:**
`reuse`. **Teste:**
`tests/Unit/Ai/AutonomousEngineering/AtlasAutonomousEngineeringServiceTest.php`.

**`app/Services/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRanker.php`** ·
ranking por grafo (entregue 2026-05-18). **TEOS:** input para Recovery
Planner — "quais nodes governam o seed file que mudou?". **Classificação:**
`reuse`. **Teste:**
`tests/Unit/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRankerTest.php`.

#### Certification / Readiness Layer

**`app/Services/Ai/Mission/MissionCertificationService.php`** · runChecks
shape-only hoje (per `superiority-architecture.md` Gap #6). **TEOS:**
`completed` exige certification — invariante em
`MissionLifecycleService:121-131`. **Classificação:** `reuse` +
`deprecate_later` (quality-aware é M5 da `superiority-roadmap`).
**Alteração:** TEOS-I1 NÃO mexe; TEOS-I2 ou superiority-M5 endereça.

**`app/Services/Ai/Mission/MissionLifecycleService.php`** · transition
state machine + completed invariant. **TEOS:** *é* o gate canônico de
completion. **Classificação:** `do_not_touch`. **Alteração:** zero.

**`app/Services/Ai/Mission/MissionEvidenceService.php`** · attach receipts.
**TEOS:** continuity certification attach. **Classificação:** `reuse`.

**`app/Services/Ai/ProgrammingRuntime/ProgrammingRuntimeReadinessService.php`** ·
10 checks. **TEOS:** input para Continuity Certification (greenfield).
**Classificação:** `reuse`. **Alteração:** `LongHorizonContinuityCertification`
adiciona invariants próprios (must_keep_coverage, stale_after, pack_hash),
não modifica este service.

#### Control Plane / Telemetry Layer

**`app/Services/Ai/ProgrammingRuntime/ControlPlane/ProgrammingRuntimeControlPlaneService.php`** ·
623 lines. Snapshot read model (missions, dev_runs, forge_obras,
work_packets, rag_gates, repair_loops, telemetry, blockers, evidence,
certification, next_actions, benchmark_status). **TEOS:** read model
canônico. **Classificação:** `reuse`. **Gap:** sem `long_horizon`
projection. **Alteração:** TEOS adiciona key `long_horizon_summary` no
snapshot via método novo, mantendo benchmark invariants (já enforced).

**`app/Services/Ai/ProgrammingRuntime/Telemetry/ProgrammingRuntimeTelemetryAggregator.php`** ·
13 canonical event names + `claim_policy.benchmark_not_run=true`. **TEOS:**
fonte de telemetry. **Classificação:** `reuse`. **Alteração:** adicionar
event names long-horizon (`pack_emitted`, `compaction_emitted`,
`freshness_blocked`, `recovery_planned`) em
`ProgrammingRuntimeTelemetryCanon::CANONICAL_EVENT_NAMES`.

**`app/Services/Ai/ControlPlane/AtlasControlPlaneSnapshotService.php`** +
**`AtlasControlPlaneNextActionService.php`** · snapshot + next-action canon.
**TEOS:** complementar ao Programming Runtime control plane.
**Classificação:** `reuse`.

**`app/Services/Ai/Programming/Console/ProgrammingConsoleService.php`** ·
11 actions com envelope `atlas.programming.console.v1`. **TEOS:** entrypoint
operator. **Classificação:** `extend`. **Alteração:** adicionar 4 actions
TEOS (`long-horizon:compact`, `long-horizon:continue`, `long-horizon:status`,
`long-horizon:freshness-gate`) no Canon, reusando o envelope.

#### Benchmark Readiness Layer

**`app/Services/Ai/Programming/BenchmarkReadiness/`** · 4 arquivos
(Canon/Catalog/Harness/Exception). Suite com 8 casos canon,
`benchmark_status=benchmark_not_run`, `human_authorization_required=true`,
`run()` sempre throws. **TEOS:** *é* o harness; TEOS nunca cria runner.
**Classificação:** `reuse` + `do_not_touch`. **Risco crítico:** criar
"TEOS benchmark runner" viola §14 regra 5. **Teste:**
`tests/Unit/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarnessTest.php`
(14 tests / 391 assertions). **Alteração:** zero em I1. Eventualmente
adicionar `case_type='long_horizon_continuation'` (já existe!) é o único
ponto de contato.

### Gaps Greenfield (componentes que precisam nascer)

1. **`LongHorizonContextFreshnessGate`** · namespace
   `app/Services/Ai/Programming/LongHorizon/` (novo). Input:
   `continuation_pack_id`; checa `stale_after < now`, mismatch
   `repo_HEAD_hash` vs `pack.context_pack_hash`, drift detection. Output:
   `atlas.long_horizon.context_freshness_gate.v1` com `status=passed|stale|
   drifted|blocked`. **Razão de greenfield:** zero código matching no repo
   (confirmado via grep).
2. **`LongHorizonRecoveryPlannerService`** · namespace
   `app/Services/Ai/Programming/LongHorizon/`. Input: `scope_id`,
   `drift_finding_id` (opcional). Carrega pack, identifica missing context
   via `WorldModelGraphRanker`, gera plano `next_best_action`. Output:
   `atlas.long_horizon.recovery_plan.v1`. **Razão:** Gap #11/#12 da
   `long-horizon-intelligence-layer.md` declara explicit greenfield.
3. **`ReplayManifest` builder/reader** · namespace
   `app/Services/Ai/Programming/LongHorizon/Replay/`. Builder produz
   `atlas.long_horizon.replay_manifest.v1` agregando refs por scope
   (continuation_pack + compaction_receipts + ledger events ordenados).
   Reader rehidrata pack a partir do manifest. **Razão:** sem código
   matching; conceitualmente similar ao `BenchmarkReadinessCaseCatalog` mas
   para replay long-horizon.
4. **`ContinuityCertification` service** · namespace
   `app/Services/Ai/Programming/LongHorizon/Certification/`. Valida
   invariantes da camada antes de declarar ready: must_keep_coverage=1.0,
   pack_hash determinístico, freshness válido, decision evidence presente,
   memory governance OK. Output: `atlas.long_horizon.continuity_certification.v1`.
   **Razão:** sem código matching; complementa `MissionCertificationService`
   (que é shape-only hoje).
5. **`CausalDecisionGraph` lite read model** · namespace
   `app/Services/Ai/Programming/LongHorizon/CausalGraph/`. Read-only
   projection sobre `AtlasLedgerEvent` filtrado por scope, usando
   `causation_id`/`correlation_id` para reconstruir DAG de decisões.
   **Razão:** opcional para I1; faz sentido se Decision Ledger agregado
   (Tabela `ai_long_horizon_decision_ledger_entries`) shippar. Marcar como
   `greenfield_opcional` em I1, `greenfield` em I2.

### Não-greenfield (não criar serviço novo!)

- **`LongHorizonCompactionEngine`** — NÃO criar. Estender
  `AiCompactionService` (§14 regra 2).
- **`LongHorizonDecisionLedger`** — NÃO criar. Reusar `AtlasLedgerEvent` +
  `AtlasEvidenceLedger::record()` com event_types novos (§14 regra 1).
- **`LongHorizonMemoryStore`** — NÃO criar. Adicionar scopes em
  `AtlasMemoryEntry::SCOPES` (§14 regra 4).
- **`LongHorizonBenchmarkRunner`** — NÃO criar (§14 regra 5).
- **`LongHorizonForgeState`** — NÃO criar. Estender
  `ForgeLongHorizonStateService` com `emitContinuationPack()` (§14 regra 3).

## Regras para IA

### Anti-Duplication Rules

1. **Não criar tabela paralela ao `atlas_ledger_events`.** TEOS escreve
   eventos com `event_type ∈ long_horizon.*`; append-only enforcement é
   herdado de `AtlasLedgerEvent::save()`.
2. **Não criar `LongHorizonCompactionEngine`.** Estender `AiCompactionService`
   com `compactForScope()` + migration aditiva em `ai_compactions`.
   Compactação tem que passar pelo mesmo lock transaction.
3. **Não criar `LongHorizonForgeState`.** `ForgeLongHorizonStateService`
   serve. Adicionar `emitContinuationPack()` que delega ao builder novo.
4. **Não criar `AtlasLongHorizonMemoryEntry` ou store paralelo.**
   Estender `AtlasMemoryEntry::SCOPES` com `obra` e `long_horizon`.
   Promotion gate ganha invariante operator_review_required.
5. **Não criar `LongHorizonBenchmarkRunner` ou `LongHorizonRivalsHarness`.**
   `BenchmarkReadinessHarness` é a única superfície; TEOS nunca toca runner.
6. **Não criar comando paralelo a `atlas:programming:console`.** Adicionar
   actions `long-horizon:*` no Canon existente (`ProgrammingConsoleCanon`).
7. **Não criar `ProgrammingResumeServiceV2`.** Estender `state()` +
   `continuationPacket()` com chaves novas; manter backwards-compat.
8. **Não criar service paralelo a `AtlasEvidenceLedger`.** Usar `record()`
   com `LedgerEventType` novos.
9. **Não criar tabela `ai_long_horizon_certifications`.** Usar
   `ai_mission_certifications` com `kind=long_horizon_continuity`.
10. **Não introduzir naming proliferation.** "TemporalState",
    "ContinuityEngine", "WorkstreamMachine" — todos proibidos. Usar
    `long_horizon.*` ou nada.

### Quality Gates herdados

- `must_keep_coverage == 1.0` obrigatório em qualquer
  `compaction_receipt.v1` emitido (greenfield invariante).
- `claim_policy.benchmark_not_run=true` em todo envelope do console e
  control plane (já enforced).
- `completed` exige certification (já enforced em `MissionLifecycleService`).
- Promotion memory scope `obra|long_horizon` exige operator review
  (greenfield invariante).

## Escopo de Implementacao

### Recommended Implementation Order

Baseado no código real e em paralelização segura:

| # | Slice | Toca | Classificação | Paralelizável | Dep |
|---|-------|------|---------------|---------------|-----|
| S1 | Glossary entry `long_horizon` em `atlas-canonical-glossary-and-naming.md` | doc | doc-only | não | — |
| S2 | Migration `ai_long_horizon_continuation_packs` + model | DB | greenfield | não | S1 |
| S3 | Migration `ai_long_horizon_compaction_receipts` + model | DB | greenfield | paralelo S2 | S1 |
| S4 | `LongHorizonContinuationPackBuilder` (Dev side via `AtlasDevRunIndex`) | service | greenfield | depende S2 | S2 |
| S5 | Extend `AiCompactionService::compactForScope()` + migration aditiva em `ai_compactions` | service+DB | extend | paralelo S4 | S3 |
| S6 | `ForgeLongHorizonStateService::emitContinuationPack()` hook | service | extend | paralelo S5 | S2+S4 |
| S7 | Extend `ProgrammingResumeService::state()` com `pack_hash`/`stale_after` | service | extend | paralelo S6 | S2 |
| S8 | Console actions `long-horizon:{compact,continue,status}` em `ProgrammingConsoleCanon` | service | extend | depende S5+S7 | S5+S7 |
| S9 | `LongHorizonContextFreshnessGate` (greenfield) + console action | service | greenfield | depende S2 | S2 |
| S10 | Extend `AtlasMemoryEntry::SCOPES` + promotion gate operator-reviewed | model+service | extend | paralelo S9 | — |
| S11 | `LongHorizonRecoveryPlannerService` (greenfield) | service | greenfield | depende S9 | S9 |
| S12 | `ContinuityCertification` service (greenfield) + 8 invariants | service | greenfield | depende S5+S9+S11 | S5+S9+S11 |
| S13 | `ReplayManifest` builder/reader (greenfield) | service | greenfield | paralelo S12 | S2+S3 |
| S14 | Control Plane projection `long_horizon_summary` + telemetry event names | service | extend | depende S8 | S8 |

**Total greenfield slices:** S2, S3, S4, S9, S11, S12, S13 (7).
**Total extend slices:** S5, S6, S7, S8, S10, S14 (6).
**Doc-only:** S1.
**Zero deprecation, zero rewrite.**

## Dependencias

- `atlas-long-horizon-intelligence-layer.md` (lei + schemas).
- `atlas-programming-superiority-architecture.md` + `-contracts.md` +
  `-roadmap.md` (trinity).
- `atlas-canonical-glossary-and-naming.md` (entry `long_horizon` em S1).
- `atlas-evidence-certification-runtime.md` (ledger + certification).
- `atlas-compounding-engineering-intelligence.md` (memory + outcome
  feedback).
- Backend Meta 1 (Mission), Meta 4 (Evidence), Meta 6 (Router), Meta 9
  (Control Plane) — todos `live`.

## Evidencias

### Tests To Reuse (preservar)

Cada componente classificado `reuse|extend|do_not_touch` carrega teste
existente que TEOS NÃO pode quebrar. Cobertura herdada:

| Componente | Teste obrigatório (PRESERVE) |
|------------|------------------------------|
| AiCompactionService | `tests/Unit/AiSessionManagerTest.php` |
| AiSessionStateService | `tests/Unit/AiSessionStateServicePendingSteerTest.php` |
| ProgrammingResumeService | `tests/Unit/Ai/Programming/ProgrammingEnterpriseRuntimeTest.php` |
| ForgeIntakeService | `tests/Feature/Ai/Programming/Forge/ForgeIntakeServiceTest.php` |
| ForgeLongHorizonStateService | `tests/Feature/Ai/Programming/Forge/ForgeLongHorizonStateServiceTest.php` |
| ForgeWorkPacketExecutionCycle (v1 service) | `tests/Feature/Ai/Programming/Forge/ForgeWorkPacketExecutionCycleServiceTest.php` |
| ForgeWorkPacketExecutionCycle (Execution/ new) | `tests/Feature/Ai/Programming/Forge/Execution/ForgeWorkPacketExecutionCycleTest.php` |
| AtlasEvidenceLedger | `tests/Unit/Ai/Kernel/EvidenceLedgerTest.php` |
| AtlasLedgerEvent (append-only) | herdado de tests/Unit/Ai/Kernel/EvidenceLedgerTest.php |
| AtlasMemoryEntry | `tests/Feature/Ai/AtlasMemoryEntry*Test.php` (vários) |
| AiCodebaseWorldModel* | `tests/Unit/Ai/AutonomousEngineering/AtlasAutonomousEngineeringServiceTest.php` |
| WorldModelGraphRanker | `tests/Unit/Ai/AutonomousEngineering/WorldModel/WorldModelGraphRankerTest.php` |
| LocalAgentMemoryIngestion | `tests/Feature/Ai/Memory/LocalAgentIngestion/*Test.php` |
| Compounding family (11 services) | `tests/Feature/Ai/AtlasCompoundingEngineeringIntelligenceTest.php` |
| ProgrammingRuntimeControlPlane | `tests/Feature/Ai/ProgrammingRuntime/ControlPlane/*Test.php` |
| ProgrammingConsole | `tests/Feature/Ai/Programming/Console/ProgrammingConsoleCommandTest.php` |
| BenchmarkReadinessHarness | `tests/Unit/Ai/Programming/BenchmarkReadiness/BenchmarkReadinessHarnessTest.php` |

### Tests novos requeridos (greenfield)

Cada slice greenfield TEM que carregar test:

1. **S2/S3** — model + table smoke; insert/read; `pack_hash`/`receipt_hash`
   determinístico.
2. **S4** — Dev workstream em 3 runs → pack agrega decisions, blockers;
   `pack_hash` estável.
3. **S5** — `compactForScope(scope_type=forge_obra)` emite
   `compaction_receipt` com `must_keep_coverage==1.0`; throw em coverage
   < 1.0.
4. **S6** — Forge milestone-end → continuation_pack emitido sem
   alterar `state_hash` do `AiForgeLongHorizonState`.
5. **S7** — `ProgrammingResumeService::state()` retorna `pack_hash`,
   `stale_after`; backwards-compat com callers atuais.
6. **S8** — 3 console actions long-horizon emitem envelope canon com
   `claim_policy.benchmark_not_run=true`.
7. **S9** — Freshness Gate: `stale_after = now-1h` → status `blocked`.
8. **S10** — Memory promotion scope `obra` sem operator review → refusal.
9. **S11** — Recovery Planner: drift trigger → recovery plan com
   `next_best_action`.
10. **S12** — Continuity Certification: 8 invariants verdes em pack canon;
    falha quando `must_keep_coverage<1.0`.
11. **S13** — ReplayManifest builder + reader: round-trip determinístico.
12. **S14** — Control plane snapshot inclui `long_horizon_summary`;
    telemetry events `long_horizon.*` reconhecidos.

## Riscos

### Risk Map (onde mudanças podem quebrar runtime)

1. **`AiCompactionService` lock contention** (S5): adicionar
   `compactForScope` precisa reusar o mesmo `DB::transaction` +
   `lockForUpdate` para não causar double-compaction. **Mitigação:** novo
   método público delega ao `compactLocked` privado existente.
2. **`AtlasLedgerEvent` append-only invariant** (S8+): qualquer attempt de
   update lança `LogicException`. Bugs de TEOS que tentem corrigir evento
   gravado vão crashar em produção (correto, mas operacionalmente
   visível). **Mitigação:** TEOS sempre emite evento novo
   `long_horizon.pack_corrected` em vez de tentar update.
3. **`ForgeLongHorizonStateService::recordCycle` lock** (S6): emitir
   continuation_pack dentro de recordCycle aumenta tempo do lock.
   **Mitigação:** emit asíncrono via event listener post-save.
4. **`AtlasMemoryEntry::SCOPES` enum bypass** (S10): callers que validem
   scope contra const SCOPES vão rejeitar `obra`/`long_horizon` até PR
   shipped em todos os lugares. **Mitigação:** grep agressivo antes do
   merge.
5. **`ProgrammingResumeService` backwards-compat** (S7): callers em
   `AtlasProgrammingResumeCommand` e `AtlasCliContinueCommand` consomem
   `continuation_packet`. Adicionar keys é seguro; remover ou renomear
   quebra. **Mitigação:** sempre aditivo.
6. **Control plane snapshot determinism** (S14): adicionar
   `long_horizon_summary` muda o `snapshot.sha256` se algum test depende
   disso. **Mitigação:** verificar
   `ProgrammingRuntimeControlPlaneServiceTest::snapshot_is_stable_for_identical_state`.
7. **Telemetry canon event names** (S14): adicionar nomes em
   `CANONICAL_EVENT_NAMES` muda o array. Tests que comparam contagem podem
   falhar. **Mitigação:** validar tests com `count(CANONICAL_EVENT_NAMES)`
   antes do merge.
8. **Freshness Gate false positives** (S9): heurística agressiva bloqueia
   resumes legítimos. **Mitigação:** primeiros 30 dias rodar em modo
   `advisory_only`; bloqueio só em modo `enforce` ativado por flag.
9. **Recovery Planner loop infinito** (S11): plan que sempre falha
   freshness gate cria loop. **Mitigação:** `max_recovery_attempts=3` +
   escalate para operator.
10. **Naming proliferation acidental** — alguém cria `WorkstreamPack` ou
    `TemporalCertification`. **Mitigação:** §14 enforce em code review;
    `atlas-canonical-glossary-and-naming.md` é gate explícito.

### Anti-pattern proibido

- **Criar `LongHorizonForgeState` em vez de estender o serviço existente.**
- **Criar `ai_long_horizon_audit_events`** quando `atlas_ledger_events` +
  `event_type=long_horizon.*` resolve.
- **Reescrever `ProgrammingResumeService` "para limpeza"** — extend, não
  rewrite.
- **Aprovar PR TEOS que adicione `migrations/2026_xx_xx_create_temporal_*`**
  fora da família `ai_long_horizon_*` declarada.
- **Declarar `benchmark_status=running` em qualquer envelope TEOS** —
  proibido por contrato do harness.

## Exemplos

### Reuse correto: Dev workstream resume

S4 + S7 combinados:

```text
operator runs `atlas:cli:continue --thread=feat-router-x` ->
  AtlasCliContinueCommand resolve thread -> ProgrammingResumeService::state()
    (extended) carrega timeline via ProgrammingStageReceiptStore +
    chama LongHorizonContextFreshnessGate::evaluate(pack_id)
  -> Freshness Gate consulta `ai_long_horizon_continuation_packs` (S2),
     `stale_after >= now` -> passed -> resume_command devolvido com
     `pack_hash`. Zero criação de classe nova além de Builder + Gate;
     `ProgrammingStageReceiptStore` intocado.
```

### Reuse correto: Forge Obra continuation

S6:

```text
ForgeLongHorizonStateService::recordCycle() (intocado) executa cycle ->
  event listener post-save dispara LongHorizonContinuationPackBuilder
  (S4 generalizado) -> pack persiste em `ai_long_horizon_continuation_packs`
  -> AtlasEvidenceLedger::record(LedgerEventType::LONG_HORIZON_PACK_BUILT)
     escreve em `atlas_ledger_events` (append-only enforced).
  Zero migration nova em `ai_forge_long_horizon_states`. Zero classe nova
  além de Builder.
```

### Anti-pattern bloqueado

```text
git PR: "feat: add LongHorizonCompactionEngine + ai_long_horizon_compactions
table"
review: REJEITADO. AntiDup §2: estender AiCompactionService::compactForScope
+ migration aditiva em ai_compactions. Re-submit como S5.
```

## Proximas Acoes

### Sequência canônica TEOS-I1 (14 slices em 4 sprints)

**Sprint 1 — Foundation (S1+S2+S3):**
- S1 (2h): glossary entry `long_horizon`.
- S2 (4-6h): tabela + model `ai_long_horizon_continuation_packs`.
- S3 (4-6h): tabela + model `ai_long_horizon_compaction_receipts`.
- Receipt: 2 schemas novos + 2 models + tests smoke.

**Sprint 2 — Builders (S4+S5+S6+S7):**
- S4 (10-14h): `LongHorizonContinuationPackBuilder` Dev side.
- S5 (12-16h): `AiCompactionService::compactForScope()` extend.
- S6 (8-12h): Forge hook `emitContinuationPack()`.
- S7 (4-6h): `ProgrammingResumeService::state()` extend.
- Receipt: 1 builder novo + 3 services estendidos + 4 tests novos.

**Sprint 3 — Gates + Recovery (S8+S9+S10+S11):**
- S8 (6-8h): Console actions `long-horizon:*`.
- S9 (6-10h): `LongHorizonContextFreshnessGate` greenfield.
- S10 (6-8h): `AtlasMemoryEntry::SCOPES` extend + promotion gate.
- S11 (16-24h): `LongHorizonRecoveryPlannerService` greenfield.
- Receipt: 1 Canon estendido + 2 services greenfield + 1 model extend +
  operator-review gate.

**Sprint 4 — Certification + Manifest (S12+S13+S14):**
- S12 (14-18h): `ContinuityCertification` service greenfield.
- S13 (10-14h): `ReplayManifest` builder/reader greenfield.
- S14 (6-8h): Control plane + telemetry projection extend.
- Receipt: 2 services greenfield + 2 services estendidos + 12 testes
  novos cumulativos.

**Total esforço I1**: ~110-160h. **Zero deprecação**, **zero rewrite**,
**zero benchmark run**, **zero rival call**.

### Definition of Done — Atlas TEOS-I1

A integração TEOS-I1 está **shipped** quando:

(1) `long_horizon` é entry no glossary canônico.
(2) Schemas `continuation_pack.v1` + `compaction_receipt.v1` shipped com
    tabelas/models/tests.
(3) `AiCompactionService::compactForScope()` enforce
    `must_keep_coverage==1.0`.
(4) `ProgrammingResumeService` retorna `pack_hash`/`stale_after`.
(5) `ForgeLongHorizonStateService` emite continuation_pack em
    milestone-end.
(6) `LongHorizonContextFreshnessGate` bloqueia resume stale.
(7) `LongHorizonRecoveryPlannerService` produz plano por drift trigger.
(8) `AtlasMemoryEntry::SCOPES` inclui `obra|long_horizon`; promotion gate
    operator-reviewed.
(9) `ContinuityCertification` enforce 8 invariants long-horizon.
(10) Console expõe `long-horizon:{compact,continue,status,freshness-gate}`.
(11) ReplayManifest builder+reader round-trip determinístico.
(12) Control plane snapshot carrega `long_horizon_summary`; telemetry
     reconhece event_names long-horizon.
(13) Tests novos (12) verdes; tests existentes (17) preservados verdes.
(14) `claim_policy.benchmark_not_run=true` em todos envelopes.

### Gates de fechamento desta doc

- `php artisan atlas:engineering:knowledge docs-health --json` → 0
  violations; sob `line_limit: 900`.
- `git diff --check` → limpo.
- **benchmark_not_run: confirmado** — esta doc é mapa de reuse, não
  execução.
