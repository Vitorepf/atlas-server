---
id: atlas-teos-existing-code-map-part-01
type: engineering_knowledge
title: Atlas TEOS Existing Code Map · Parte 1
status: active
category: programming
priority: 95
summary: Recorte focado do mapa de código TEOS: Existing Components Map ate Não-greenfield (não criar serviço novo!).
tags:
  - atlas-teos
  - code-map
  - anti-duplication
  - split-doc
capabilities:
  - existing_code_inventory
  - anti_duplication_rules
decisions:
  - Este recorte preserva uma parte do inventário TEOS sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
  - Atualizar quando arquivo, classe ou classificação mudar.
related_paths:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
  - docs/engineering-knowledge-base/atlas-long-horizon-intelligence-layer.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-teos-existing-code-map-part-01
graph_title: Atlas TEOS Existing Code Map Parte 1
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-teos-existing-code-map
graph_status: active
graph_source: repo
human_name: Atlas TEOS Existing Code Map Parte 1
canonical_name: Atlas TEOS Existing Code Map Parte 1
technical_name: atlas-teos-existing-code-map-part-01
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md
owner: programming
repo_paths:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map-part-01.md
allowed_changes:
  - Atualizar somente inventário, gaps, testes e alterações recomendadas desta parte.
forbidden_changes:
  - Declarar reuse sem evidência de código ou criar componente paralelo proibido.
depends_on:
  - atlas-teos-existing-code-map
flows_to:
  - atlas-programming-superiority-roadmap
unlocks:
  - teos_i1_implementation_kickoff
governs:
  - atlas_teos_existing_code_reuse
evidence:
  - docs/engineering-knowledge-base/atlas-teos-existing-code-map.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice TEOS e bloquear duplicação de código.
---
# Atlas TEOS Existing Code Map · Parte 1

## Resumo

Este recorte preserva uma parte focada do inventário TEOS: Existing Components Map ate Não-greenfield (não criar serviço novo!).

## Papel no Atlas

Ajuda implementadores e reviewers a reutilizar código existente em vez de criar sistema paralelo.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-teos-existing-code-map.md` e complementa o contrato de Long-Horizon Intelligence.

## Contratos

Não duplica ledger, memory store, compaction engine, Forge state ou benchmark runner.

## Fluxo

Mapa TEOS → recorte de componente → decisão reuse/extend/adapter/greenfield → PR com evidência.

## Regras para IA

Sempre procurar componente existente antes de propor classe nova. Não misturar roadmap com inventário técnico.

## Escopo de Implementacao

Este recorte documenta código existente, gaps, riscos, testes e alteração recomendada.

## Dependencias

Depende do mapa TEOS, Long-Horizon Intelligence e glossário canônico.

## Evidencias

A evidência principal é o próprio caminho de código citado no conteúdo extraído.

## Riscos

Risco principal: duplicação operacional que faz docs/cartografia divergirem do runtime real.

## Exemplos

Os exemplos abaixo são o inventário extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar quando a classificação do componente mudar e rodar docs-health.

## Conteudo Extraido
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
