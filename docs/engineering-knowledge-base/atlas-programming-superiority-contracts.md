---
id: atlas-programming-superiority-contracts
type: engineering_knowledge
title: Atlas Programming Superiority Contracts
status: active
category: programming
priority: 95
summary: Contratos canônicos da Atlas Programming Superiority Architecture — 14 schemas com field shapes, Super RAG Spine, Codebase World Model, Evidence Ledger grammar, tabelas DB e APIs/comandos. Filho operacional do índice estratégico.
tags:
  - atlas-dev
  - atlas-forge
  - contracts
  - rag
  - world-model
  - evidence
  - 2026-05-18
capabilities:
  - canonical_schema_catalog
  - super_rag_spine_spec
  - codebase_world_model_spec
  - evidence_receipts_grammar
  - data_model_inventory
decisions:
  - Reutilizar schemas já implementados quando disponíveis (13 de 14 já existem em código).
  - Schemas novos só quando absolutamente necessários (1: escalation_packet.v1).
  - APIs/comandos devem expor o que existe, não inventar surface paralela.
maintenance:
  - Atualizar quando schema novo for shipado em código.
  - Atualizar quando tabela DB mudar estrutura.
  - Sincronizar com `atlas-programming-superiority-architecture.md` e `-roadmap.md`.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-evidence-certification-runtime.md
  - docs/engineering-knowledge-base/domains/programming-agentic-rag-professional-spec.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-programming-superiority-contracts
graph_title: Atlas Programming Superiority Contracts
graph_world: atlas

graph_layer: system

graph_kind: contract

graph_parent: atlas-programming-superiority-architecture

graph_status: active

graph_source: repo

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md

allowed_changes:
  - Adicionar schema novo quando shipé em código.
  - Atualizar field shapes quando código diverge.

forbidden_changes:
  - Inventar schema sem implementação ou AP.
  - Apagar schema implementado sem migração e deprecation receipt.

depends_on:
  - atlas-programming-superiority-architecture
  - atlas-dual-core-engineering-system
  - atlas-evidence-certification-runtime

flows_to:
  - atlas-programming-superiority-roadmap

unlocks:
  - implementation_via_canonical_schemas

governs:
  - atlas_programming_canonical_contracts

evidence:
  - app/Services/Ai/DualCore/DualCoreRouteDecisionService.php
  - app/Services/Ai/Programming/ProgrammingRetrievalPlanner.php
  - app/Services/Ai/Programming/ProgrammingGraphRagRuntime.php
  - app/Services/Ai/Programming/ProgrammingSemanticCodeGraphService.php
  - app/Services/Ai/Programming/ProgrammingPatchVerifier.php
  - app/Services/Ai/Programming/ProgrammingTestImpactAnalyzer.php
  - app/Services/Ai/Programming/ProgrammingGapCritic.php
  - app/Services/Ai/Programming/ProgrammingRepairExecutor.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - database/migrations/2026_05_18_040000_create_ai_dual_core_route_decisions_table.php

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - Implementar schema 2 (`atlas.dev_to_forge.escalation_packet.v1`).
  - Wire schema 1 (`route_decision.v1`) em `FlowRouterService`.
  - Enforce schema 6 (`context_sufficiency_gate.v1`) na execução de flows strict.
  - Catalogar tabelas atuais vs faltantes em sync com a roadmap doc.

---

# Atlas Programming Superiority Contracts

## Resumo
Catálogo de **14 schemas canônicos** que governam a superioridade programática
do Atlas Dev + Atlas Forge, mais o spec do **Super RAG Spine**, **Codebase
World Model**, **Evidence Ledger grammar** e **inventário de tabelas e APIs**.

13 de 14 schemas estão **implementados em código** (com qualificações);
1 é gap conhecido (Schema 2 `escalation_packet.v1`). Todo schema cita
arquivo:linha de implementação ou declara gap explícito.

## Papel no Atlas
Filho de `atlas-programming-superiority-architecture` no graph. Existe para
que qualquer IA implementando uma missão da roadmap saiba qual schema reutilizar
(reutilização > criação) e em qual classe persistir.

Não é mestre filo­sófico — é dicionário operacional.

## Onde Se Encaixa
Layer 0.72. Cruza:
- Layer 1 Kernel contratos (envelope, receipt, ledger).
- Layer 0.7 Spec OS / SDD governance.
- Layer 0.6 Research Intelligence (Mandatory RAG gate).
- Layer 2 Master Architecture (Evidence/Certification planes).

## Contratos
### Schema Catalog (14 schemas canônicos)

#### Schema 1 — `atlas.dual_core.route_decision.v1` ✅ SHIPPED 2026-05-18
**Arquivo:** `app/Services/Ai/DualCore/DualCoreRouteDecisionService.php` (310 lines).
**Model:** `App\Models\AiDualCoreRouteDecision` (41 fillables).
**Tabela:** `ai_dual_core_route_decisions` (migration `2026_05_18_040000`).
**Tests:** 11 Feature em `tests/Feature/Ai/DualCore/`.
**Campos canônicos** (per `atlas-dual-core-engineering-system.md:247-258`):
`schema`, `uuid`, `route` (dev|forge|dev_to_forge), `reason`, `intent_summary`,
`ambiguity_level` (low|medium|high), `risk_level` (low|medium|high|critical),
`expected_duration`, `modules_touched_estimate`, `sdd_required`,
`evidence_required[]`, `operator_visible`, `rejected_routes[]`,
`routing_signals{}`, `confidence`, `evidence_refs[]`, `policy_refs[]`,
`policy_snapshot{}`, `decision_hash` (sha256), `mission_id`, `work_order_id`,
`router_decision_id`, `intent_classification_id`, `conversation_id`,
`actor_type` (system|router_runtime_adapter|operator).
**Gap:** 0 callers em produção. Wire em `FlowRouterService::decideFlow` é M1.

#### Schema 2 — `atlas.dev_to_forge.escalation_packet.v1` ❌ GAP
**Status:** 0 matches em `app/`. Implementação é M2.
**Campos canônicos** (per `atlas-dual-core-engineering-system.md:279-301`):
`schema`, `source` (atlas_dev), `target` (atlas_forge), `intent`,
`dev_interpretation`, `why_escalated[]`, `workspace{root,relevant_paths[]}`,
`evidence_refs{plan,senior_loop_audit,senior_loop_execution,verification_receipt,error_ledger,failure_capsules[]}`,
`known_risks[]`, `open_questions[]`, `recommended_forge_mode`
(sdd_intake|obra_intake|architecture_review|long_run).
**Substitui:** `atlas.dev.forge_promotion_preview.v1` (`ForgePromotionPreviewBuilder` legacy).

#### Schema 3 — `atlas.programming.intent_classification.v1` ⚠️ via RouterRuntime
**Implementação:** `App\Models\AiAtlasIntentClassification` + `IntentKernelService`.
**Tabela:** `ai_atlas_intent_classifications` (Meta 6).
**Campos:** `intent_type` (DEV/RESEARCH/EXPLAIN/DEBUG/REVIEW/CONVERSATION/PLAN/FORGE/MIXED/UNSAFE), `composer_mode`, `composer_task`, `workspace_present`, `attachments`, `confidence`, `signals`.

#### Schema 4 — `atlas.programming.context_pack.professional.v1` ✅
**Arquivo:** `ProgrammingRetrievalExecutor:148`.
**Campos canônicos:** `schema_version`, `context_pack_hash` (sha256),
`provider_safe`, `ranked_refs[]` (cada um: `source` enum, `ref`, `score`,
`reason`, `scope`, `hash`, `freshness` enum, `privacy` enum),
`excluded_refs[]` (`ref`, `reason` em duplicate|unsafe|stale|low_relevance|budget_trimmed),
`budget{max_refs,max_chars,used_chars}`, `source_counts{}`.

#### Schema 5 — `atlas.programming.agentic_rag.professional_plan.v1` ✅
**Arquivo:** `ProgrammingRetrievalPlanner:104`.
**Campos canônicos:** `schema_version`, `plan_id`, `flow` (ex programming.repair),
`objective_hash`, `retrieval_strategy` (hybrid_graph_semantic),
`required_sources[]`, `source_queries[]`, `iterations[]` (`step`, `goal`,
`sources[]`, `status`, `gap_after_step[]`),
`context_sufficiency_gate{status,reasons[]}`, `retrieval_receipt_id`.

#### Schema 6 — `atlas.programming.context_sufficiency_gate.v1` ⚠️ COMPUTADO, NÃO ENFORCED
**Arquivo:** `ProgrammingRetrievalPlanner:137-141`.
**Gap:** status é retornado mas caller deve checar; sem throw automático. Enforcement é M7.

#### Schema 7 — `atlas.programming.graph_rag_runtime.v1` ✅
**Arquivo:** `ProgrammingGraphRagRuntime:44`.
**Boundary explícito (AP-683):** `execution_policy.local_only=true`, `provider_calls_allowed=false`, `network_calls_allowed=false`, `writes_allowed=false`, `surface_direct_invocation_allowed=false`, `planner_mediated_only=true`.

#### Schema 8 — `atlas.programming.semantic_code_graph.context.v1` ✅
**Arquivo:** `ProgrammingSemanticCodeGraphService:81`.
**Campos:** `node_count`, `edge_count`, `nodes`, `edges`, `related_tests`, `related_docs`, `source` (`filesystem_scan|engineering_code_intelligence`), `complete`.

#### Schema 9 — `atlas.programming.test_impact.receipt.v1` ✅
**Arquivo:** `ProgrammingTestImpactAnalyzer:40-56`.
**Sinais:** `changed_files`, `code_graph.related_tests`, `risk` level.
**Output:** `selection_reason`, `minimum_policy`, `requires_no_test_reason`.

#### Schema 10 — `atlas.programming.patch_verifier.report.v1` ✅
**Arquivo:** `ProgrammingPatchVerifier:11-96`.
**Verificações:**
- max 12 `changed_files` sem human review (P9 patch policy).
- `action_manifests` cobrem cada changed_file.
- `secret_files` (`.env`, `secrets`) detectados → block.
- `rollback` disponível para write actions.
**Status:** `passed | advisory_with_reason | blocked`.

#### Schema 11 — `atlas.programming.agentic_rag.gap_critic.v1` ✅
**Arquivo:** `ProgrammingGapCritic:13-46`.
**Detecta:** `missing_required_sources`, `docs_present_but_code_graph_incomplete`.
**Status:** `passed | degraded | blocked`.

#### Schema 12 — `atlas.programming.repair_capsule.v1` ✅
**Arquivo:** `ProgrammingRepairExecutor:12-35`.
**Status:** `planned | blocked_no_progress | blocked_max_attempts`.
**Detection:** `same_signature_twice` (failure_hash repetido) → blocked.
**Output:** `next_action: patch_repair_then_retest | human_review`.

#### Schema 13 — `atlas.programming.retrieval_eval.v1` ✅
**Arquivo:** `ProgrammingRetrievalEvaluator:26`.
**Métricas:** `recall_proxy`, `precision_proxy`, `context_waste_ratio`.
**Estado:** `professional_promotion_allowed: false` (até golden set benchmark passar).

#### Schema 14 — Compounding family ✅ (11 services)
Conjunto: `atlas.ai.compounding.{outcome,learning_candidate,memory,heuristic_update,benchmark_case,rag.feedback,runtime_record}.v1`.
**Implementações** (todas em `app/Services/Ai/Compounding/`):
- `AtlasCompoundingOutcomeEvaluator` → outcome.v1 (mede flow/retrieval/execution/evidence quality).
- `AtlasLearningDistiller` → learning_candidate.v1 (decide promote/hold).
- `AtlasCompoundingMemoryService` → memory.v1 (promove se evidence_refs≠[] + confidence≥70).
- `AtlasRagFeedbackService` → rag.feedback.v1 (included/used/noise/missed sources).
- `AtlasBenchmarkGeneratorService` → benchmark_case.v1 (cria cases de outcomes ruins).
- `AtlasHeuristicEvolutionService` → heuristic_update.v1 (`proposed|applied|rolled_back`).
- `AtlasTemporalCertificationService` → comparação por período/flow/rival.
- `AtlasCompoundingRuntimeService` → orquestrador `runtime_record.v1`.
- `AtlasCompoundingEngineeringIntelligenceService` → readiness wrapper.
- `AtlasCompoundingReadinessService` → validações de presença.
- `CompoundingHash` → hash utility.

### Super RAG Spine
**Definição operacional:** spine = pipeline mandatório
`intent → source_plan → retrieval (graph + vector) → reranker → context_pack →
gap_critic → eval → gate(strict|degraded|failed_closed)`.

**Sources canônicos** (`ProgrammingRetrievalPlanner:158-174`):
- `code_symbols` (todos os flows).
- `canonical_docs` (todos).
- `prior_decisions` (todos).
- `stage_receipts` (repair/forge).
- `known_failures` (repair/forge).
- `related_tests` (repair/forge/frontend/security/database/qa).
- `visual_evidence` + `asset_provenance` (frontend).
- `failure_packet`, `failed_test_output`, `changed_files`, `prior_receipts` (repair).
- `diff`, `owners`, `risk_rules` (review).
- `attack_surface`, `secrets_policy`, `auth_dataflow_docs`, `threat_refs` (security).
- `migrations`, `models`, `queries`, `indexes`, `rollback_path`, `data_risk_docs` (database).

**Mandatory RAG gate rules** (`programming-agentic-rag-professional-spec.md:195-203`):
- Não chamar "Agentic RAG" se for só lexical search.
- Não declarar contexto suficiente sem source gate.
- Não incluir file sem reason + hash.
- Não usar embeddings externos sem classificação provider-safe.
- Não promover learning sem review.
- Não comparar providers com contexto diferente.

**Local Vector Index** (`ProgrammingLocalVectorIndex`):
- **Algoritmo:** deterministic token-based cosine (NÃO neural embeddings).
- **Stop words:** removidos antes de count.
- **Score:** dot product / (norm_a × norm_b).
- **Path boost:** até 1.25 (token match + exact symbol match).
- **Threshold:** 0.08 mínimo.

**Professional Reranker** (`ProgrammingProfessionalReranker`):
- Base = input score normalizado.
- Bonus: `+0.12` se source em required; `+0.08` se stage_receipts e flow repair/forge; `+0.22` se retrieval_channel=local_semantic_vector; `+0.35` se audited_empty_source.
- Bonus path: `+0.22` se em programming-relevant paths.
- Penalty: `-0.45` em self-construction, migrations, CaptureInbox, ProviderRelease.
- Dedup por `(source|ref)`; mantém highest, merge reasons.
- Budget trim com `reason='budget_trimmed'`.

**Context Pack persistence** (`ProgrammingContextPackStore` table `atlas_programming_context_packs`):
- Unique key: `context_pack_hash`.
- Colunas: `plan_id`, `parent_plan_id`, `schema_version`, `status`, `provider_safe`, `retrieval_strategy`, `ranked_refs_json`, `excluded_refs_json`, `source_counts_json`, `metrics_json`, `budget_json`, `payload_json`.
- Replay determinístico por hash.

### Codebase World Model
**Models** (3, todos em `App\Models\`):
- `AiCodebaseWorldModel` (goal_record_id, schema_version, model_id, scope, status, capabilities[], risks[], receipt{}, model_hash).
- `AiCodebaseWorldModelNode` (world_model_id, node_id, node_type, path, flow_id, capabilities[], risks[], metadata{}).
- `AiCodebaseWorldModelEdge` (world_model_id, from_node_id, to_node_id, edge_type, metadata{}).

**Node types** (materializados por `ProgrammingSemanticCodeGraphService`):
- `file`, `symbol`, `module`, `test`, `doc`, `route`, `dependency`.

**Edge types:**
- `defines` (file→symbol).
- `depends_on` (file→dependency, symbol→symbol).
- `contains_symbol` (module→symbol).
- `documented_by` (module/symbol→doc).
- `tests` (test→symbol) — *inferência: documentar quando shipé*.

**Refresh strategy atual:** on-demand por plan (sem builder background).
**Refresh strategy alvo:** background job semanal + invalidação por commit/push.

**Queries úteis** (estado-alvo):
- "Quais tests cobrem symbol X?" → traverse edge `tests`.
- "Quais docs documentam module Y?" → traverse `documented_by`.
- "Quais files dependem de symbol Z?" → reverse `depends_on`.
- "Quais symbols em path P estão sem teste?" → módulos sem edge `tests`.

**Gap:** Reranker não usa edge weights ainda. Integration é M9 da roadmap.

### Evidence Ledger + Receipts Grammar
**Família de 11 contratos canônicos** (per `atlas-evidence-certification-runtime.md:267-371`):

| Concept | Schema | Implementado |
|---------|--------|--------------|
| EvidencePack | `atlas.ai.evidence_pack.v1` | ✅ `EvidencePackService` |
| Receipt | `atlas.ai.receipt.v1` | ✅ `ReceiptService` |
| Claim | `atlas.ai.claim.v1` | ✅ `ClaimVerificationService` |
| Artifact | `atlas.ai.artifact.v1` | ✅ `ArtifactRegistryService` |
| SourceRef | `atlas.ai.source_ref.v1` | ✅ `SourceRefService` |
| GateRun | `atlas.ai.gate_run.v1` | ✅ `GateRunService` |
| TestResult | `atlas.ai.test_result.v1` | ✅ `TestResultService` |
| OperatorDecision | `atlas.ai.operator_decision.v1` | ✅ `OperatorDecisionService` |
| Certification | `atlas.ai.certification.v1` | ✅ `CertificationRuntimeService` |
| Blocker | `atlas.ai.blocker.v1` | ✅ `BlockerService` |
| AuditEvent | `atlas.ai.audit_event.v1` | ✅ `AuditEventService` |

**Receipts canônicos** (programming-specific, supplementing acima):
- `route_receipt` = `DualCoreRouteDecisionService::record()` output.
- `retrieval_receipt` = `ProgrammingRetrievalPlanner` output (`retrieval_receipt.v1`).
- `patch_receipt` = `ProgrammingPatchVerifier` report.
- `test_receipt` = `ProgrammingTestImpactAnalyzer` receipt.
- `repair_receipt` = `ProgrammingRepairExecutor` capsule.
- `senior_loop_audit_receipt` = `SeniorEngineerLoopAuditor` output.
- `verification_receipt` = `VerificationGate` output.
- `completion_receipt` = `CompletionStateGate` output.
- `handoff_receipt` = `AtlasForgeHandoffAdapter::promote()` → `AiDomainHandoff`.
- `memory_candidate_receipt` = `AtlasLearningDistiller` output.
- `compounding_runtime_record` = `AtlasCompoundingRuntimeService::recordExecution()`.

**Invariante de certificação** (`MissionLifecycleService:121-131`):
```
completed requires:
  evidenceRefs.count() ≥ 1
  AND latestCertification.status == 'passed'
```
**Gap:** checks são shape-only hoje (`MissionCertificationService:94-120`):
`objectives_exist`, `work_orders_exist`, `evidence_refs_exist`, `dod_has_criteria`,
`work_orders_have_receipt_hash`. **Não verifica qualidade da evidência.** M5 da roadmap
endereça.

## Fluxo
Fluxo de produção de evidence (alvo):
```
1. DualCoreRouteDecisionService.record() → route_decision.v1 + decision_hash
2. ProgrammingRetrievalPlanner.plan() → professional_plan.v1 + context_pack.v1 (hashed)
3. ProgrammingGapCritic.criticize() → gap_critic.v1 (passed|degraded|blocked)
4. ProgrammingRetrievalEvaluator.evaluate() → retrieval_eval.v1
5. (gate strict: bloqueia se failed_closed)
6. SeniorEngineerLoopAuditor.audit() → 7 capabilities check
7. Provider invoked (com context pack provider-safe)
8. ProgrammingPatchVerifier.verify() → patch_verifier.report.v1
9. ProgrammingTestImpactAnalyzer + VerificationGate → test results + honesty flags
10. CompletionStateGate.decide() → final status
11. MissionEvidenceService.attach() → atacha receipts em EvidencePack
12. MissionCertificationService.certify() → passed|failed|blocked
13. MissionLifecycleService.transition(completed) (se passed)
14. AtlasCompoundingOutcomeEvaluator → outcome.v1
15. AtlasLearningDistiller → learning_candidate.v1
16. AtlasRagFeedbackService.record() → rag.feedback.v1
```

## Regras para IA
1. **Reutilizar schema existente** antes de inventar novo.
2. **Schema novo exige AP** + spec + tests + migration.
3. **`context_pack_hash` é deterministic** — mesmas refs/scores/source_counts produzem mesmo hash.
4. **Receipt sem `evidence_refs` não é receipt** — é log; rejeitar.
5. **Mandatory RAG gate strict flows** (repair/forge/frontend/security/database):
   `context_sufficiency_gate.status==failed_closed` → throw, não return.
6. **Patch sem teste E sem `requires_no_test_reason`** → `ProgrammingPatchVerifier` retorna `blocked`.
7. **Repair attempt #N com `failure_hash[N] == failure_hash[N-1]`** → blocked, escalate.
8. **Promotion gate G3 (safety)** — toda memória precisa passar `provider_safe=true` + zero secrets.
9. **Compounding heuristic update** nasce `status='proposed'`; nunca `applied` automático.
10. **Certification.status='passed'** exige `missing_requirements=[]` E `evidence_refs ≠ []`.

## Escopo de Implementacao
Esta doc cobre **catálogo + field shapes + grammar**. Cobertura:
- 14 schemas com 13 implementações + 1 gap (Schema 2).
- Super RAG Spine (sources, gate rules, vector index, reranker, persistence).
- Codebase World Model (3 models, node/edge types, queries, refresh strategy).
- Evidence Ledger 11 receipts + 11 programming-specific receipts.

**Fora de escopo:** roadmap (em `-roadmap.md`), missions, metrics, DoD.

## Dependencias
- `atlas-programming-superiority-architecture.md` (índice mestre).
- `atlas-evidence-certification-runtime.md` (gramatica Evidence).
- `programming-agentic-rag-professional-spec.md` (lei RAG).
- `atlas-compounding-engineering-intelligence.md` (lei Compounding).
- `memory/cognitive-immune-learning-kernel.md` (G0–G8 promotion gates).

## Evidencias
### Tabelas (existing / missing / optional / future)

**Existing (verified):**
- `ai_dual_core_route_decisions` ✅ (migration 2026_05_18_040000)
- `ai_atlas_intent_classifications` ✅ (Meta 6)
- `ai_atlas_router_decisions` ✅ (Meta 6)
- `ai_atlas_flow_routes` ✅ (Meta 6)
- `ai_atlas_runtime_dispatches` ✅ (Meta 6)
- `ai_atlas_decision_receipts` ✅ (Meta 6)
- `ai_missions`, `ai_objectives`, `ai_work_orders`, `ai_mission_events`, `ai_mission_certifications` ✅ (Meta 1)
- `ai_policy_*` (7 tabelas) ✅ (Meta 3)
- `ai_evidence_*` (11 tabelas) ✅ (Meta 4)
- `ai_tool_*` (7 tabelas) ✅ (Meta 5)
- `ai_research_*` (4 tabelas) ✅ (Meta 8A)
- `atlas_programming_*` (8 tabelas: stage_receipts, action_manifests, learning_candidates, context_packs, work_items, gate_runs, reviews) ✅
- `atlas_memory_*` (7 tabelas) ✅
- `ai_codebase_world_models`, `ai_codebase_world_model_nodes`, `ai_codebase_world_model_edges` ✅
- `ai_run_outcomes`, `ai_learning_candidates`, `ai_compounding_memories`, `ai_heuristic_updates`, `ai_benchmark_cases`, `ai_rag_feedback_events` ✅ (Compounding)

**Missing (gap):**
- `ai_dev_to_forge_escalation_packets` (para Schema 2).
- `ai_programming_grounded_patch_evidence` (para tracking patch→refs cited).
- `ai_programming_retrieval_utility_feedback` (loop retrieval→uso→repromote).

**Optional:**
- `ai_codebase_world_model_refresh_jobs` (background builder).
- `ai_programming_multi_agent_assignments` (multi-agent scheduler dedicado).

**Future (AP required):**
- ChromaDB / pgvector backing para `ai_programming_local_vector_embeddings`.
- `ai_local_agent_ingestion_runs` (Local Agent Memory Ingestion desbloqueado).

### APIs / Comandos canônicos

**Comandos Artisan atualmente vivos:**
- `atlas:ai:mission-foundation` (readiness, smoke, snapshot, certify)
- `atlas:ai:domain-runtime`, `atlas:ai:domains`
- `atlas:ai:policy`, `atlas:ai:decide`
- `atlas:ai:evidence` (pack, verify, smoke)
- `atlas:ai:tool-runtime`
- `atlas:ai:router-runtime`
- `atlas:ai:control-plane` (readiness, snapshot, mission, blockers, next-actions, smoke)
- `atlas:ai:research-domain`
- `atlas:ai:self-improve`
- `atlas:ai:compounding` (readiness, certify, simulate, run, temporal)
- `atlas:programming:{intake,spec,plan,receipt,verify,complete,status}`
- `atlas:cli:dev [--efficient]`, `atlas:cli:fix`, `atlas:cli:continue`, `atlas:cli:final`
- `atlas:dev:senior-loop:audit`, `atlas:dev:senior-loop:run`
- `atlas:dev:readiness`, `atlas:dev:smoke`
- `atlas:dev:desktop:{enable,acceptance,certification,evidence}`
- `atlas:forge:*` (provider invocation, runtime dispatch, capacity, topology, certification, rivals battery — 20+ comandos)
- `atlas:programming:console {status|dev:plan|dev:summary|forge:intake|forge:summary|blockers|next-actions|evidence|certification|telemetry|smoke}` — unified canonical envelope `atlas.programming.console.v1` over readiness/control-plane/telemetry services. Read-only por default; `dev:plan` é preview (sem provider/sem DB); `forge:intake` cria a row via `ForgeIntakeService::intakeFromPrompt` (sem provider) com `--dry-run` opcional. `claim_policy.benchmark_not_run=true` em todo envelope.
- `atlas:programming:benchmark-readiness {readiness|manifest|suite|validate}` — harness do M10, sempre `benchmark_status=benchmark_not_run`.

**APIs HTTP atualmente expostas (parcial):**
- `/ai/interactions/atlas-dev/{readiness,plan,run,runs,runs/{id},runs/{id}/stream,runs/{id}/cancel}` ✅
- `/works/{project}/state` ✅ (master read-model com 25+ projeções Forge)
- `/works/{project}/forge/{fast-path,live-executions,reviews,runtime-dispatch-plans,provider-topology,provider-invocations}` ✅
- `/ai/policies/{profiles,preview,domains/{domain},flows/{flow}}` ✅
- `/works/{project}/evidence`, `/tasks/{task}/engineering/evidence` ✅
- `/tools/authority/policies/{group}`, `/tools/evidence` ✅
- `/dev-to-forge/threads/{thread}/promote` ⚠️ (legacy path, M3 deprecar)
- `/atlas/ai/control-plane/{readiness,snapshot,blockers,next-actions,missions/{uuid}}` ✅

**APIs/Comandos sugeridos (gaps):**
- `atlas:dual-core:route-decision:smoke` (smoke test Schema 1 wiring).
- `atlas:dual-core:escalation-packet:emit` (Schema 2 quando shipé).
- `atlas:programming:rag-gate:enforce` (enforce mandatory RAG gate em flows strict).
- `atlas:programming:world-model:refresh` (build/refresh background).
- `atlas:programming:benchmark:rivals` (executar suite com métricas auditadas — depende de AP).

## Riscos
1. **Schema sem caller:** repetir o erro do Schema 1 (shipped, 0 callers). Sempre wire ANTES de declarar shipped.
2. **Field drift:** field shape em doc diverge do código. Mitigação: cite arquivo:linha; regenere doc quando código mudar.
3. **14 schemas é muito:** risco de inventar 15º. Mitigação: `forbidden_changes` proíbe novo sem AP.
4. **Reranker formula hardcoded:** bonus/penalty números mágicos. Mitigação: extrair para config seedada (futuro AP).
5. **Local vector NÃO é semantic:** é token cosine. Vendor lock-in não é risco, mas qualidade limitada. AP ChromaDB endereça (futuro).

## Exemplos
**Exemplo de uso de Schema 1 (estado-alvo):**
```php
$routeDecision = $dualCore->recordFromFlowRoute(
    flowRoute: $flowRoute,
    routerDecision: $routerDecision,
    intent: $intent,
    extras: ['actor_type' => 'router_runtime_adapter'],
);
// $routeDecision->route === 'dev' | 'forge' | 'dev_to_forge'
// $routeDecision->decision_hash === sha256 estável
```

**Exemplo de Mandatory RAG gate (estado-alvo, M7):**
```php
$plan = $planner->plan($planId, $workspace, $objective, $flow);
if ($flow in strict_flows && $plan['context_sufficiency_gate']['status'] === 'failed_closed') {
    throw new ProgrammingRagGateException(
        reason: $plan['context_sufficiency_gate']['reasons'],
        plan_id: $planId,
    );
}
```

## Proximas Acoes
Detalhes em `atlas-programming-superiority-roadmap.md`. Resumo:

1. Implementar Schema 2 (`escalation_packet.v1`) — tabela `ai_dev_to_forge_escalation_packets` + service + tests. (6-8h)
2. Wire Schema 1 em `FlowRouterService::decideFlow` — adicionar caller para `DualCoreRouteDecisionService::recordFromFlowRoute`. (4-6h)
3. Enforce Schema 6 (Mandatory RAG gate) em flows strict — adicionar `ProgrammingRagGateException` + throw em `ProgrammingRetrievalPlanner` quando `failed_closed`. (4-6h)
4. Adicionar tabela `ai_programming_grounded_patch_evidence` para tracking de quais refs do context pack foram efetivamente citados no patch. (6-10h)
5. Adicionar tabela `ai_programming_retrieval_utility_feedback` para loop retrieval→uso→repromote. (8-12h)

**Gates desta entrega:** docs-health 0 violations, sob 520 linhas, git diff --check limpo.
