---
id: atlas-programming-superiority-roadmap
type: engineering_knowledge
title: Atlas Programming Superiority Roadmap
status: active
category: programming
priority: 95
summary: Roadmap implementável da Atlas Programming Superiority Architecture — Atlas Dev target + Atlas Forge target + Router/Dual-Core + PEVR loop + multi-agent + compounding + métricas honestas + 10-phase plan + top 10 missions + DoD. Filho de planejamento do índice estratégico.
tags:
  - atlas-dev
  - atlas-forge
  - roadmap
  - missions
  - metrics
  - 2026-05-18
capabilities:
  - dev_target_architecture
  - forge_target_architecture
  - pevr_loop_spec
  - multi_agent_scheduler_spec
  - compounding_closed_loop_plan
  - honest_metrics_methodology
  - implementation_phase_sequencing
decisions:
  - 10 missions priorizadas por (risco × multiplicador) — não por entusiasmo.
  - Cada missão tem DoD + receipts esperados + métricas + dependências.
  - Multi-agent scheduler primeira encarnação reaproveita `Self-Construction/AgentControlPlaneMultiAgentParallelismPlanner`.
  - "10x/30x/100x" só após benchmark methodology shipping (M10).
maintenance:
  - Atualizar quando missão completar (mover para "shipped").
  - Atualizar quando ordem de missões mudar baseado em evidência.
  - Sincronizar com `-architecture.md` e `-contracts.md`.
related_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-architecture.md
  - docs/engineering-knowledge-base/atlas-programming-superiority-contracts.md
  - docs/engineering-knowledge-base/atlas-dual-core-engineering-system.md
  - docs/engineering-knowledge-base/atlas-dev-efficient-programming-flow-v1.md
  - docs/engineering-knowledge-base/atlas-forge-continuum-os.md
  - docs/engineering-knowledge-base/atlas-compounding-engineering-intelligence.md
  - docs/engineering-knowledge-base/atlas-hyperflow-operation.md
  - docs/engineering-knowledge-base/atlas-autonomous-engineering-operating-system.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-programming-superiority-roadmap

graph_title: Atlas Programming Superiority Roadmap

graph_world: atlas

graph_layer: system

graph_kind: runbook

graph_parent: atlas-programming-superiority-architecture

graph_status: active

graph_source: repo

owner: programming

repo_paths:
  - docs/engineering-knowledge-base/atlas-programming-superiority-roadmap.md

allowed_changes:
  - Adicionar missão M11+ quando trinity atual completar.
  - Mover missão de pending → in_progress → shipped com receipt.

forbidden_changes:
  - Declarar missão shipped sem teste e gate verde.
  - Reordenar missões P0 sem evidência de bloqueio.
  - Reduzir DoD para acelerar entrega.

depends_on:
  - atlas-programming-superiority-architecture
  - atlas-programming-superiority-contracts

flows_to:
  - implementation_missions

unlocks:
  - first_executable_mission_plan

governs:
  - atlas_programming_implementation_sequencing

evidence:
  - app/Services/Ai/Programming/AtlasDev/Pipeline/AtlasDevFastPathOrchestrator.php
  - app/Services/Ai/Programming/AtlasForgeContinuumCertificationService.php
  - app/Services/Ai/Programming/AtlasDev/SeniorLoop/SeniorEngineerLoopAuditor.php
  - app/Services/Ai/Compounding/AtlasCompoundingRuntimeService.php
  - tests/Feature/Ai/DualCore/DualCoreRouteDecisionServiceTest.php

required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"

requires_evidence: true

risk_level: high

next_actions:
  - Iniciar M1 (Wire DualCoreRouteDecisionService).
  - Bloquear M3 (consolidação) até M2 (escalation_packet) shipar.
  - Não iniciar M10 (benchmark methodology) antes de M1-M9 estarem em produção.

---

# Atlas Programming Superiority Roadmap

## Resumo
**10 missões priorizadas** para transformar a arquitetura em runtime real.
Estimativa total: **MVP 60-90h (M1-M5)**, **superioridade básica 150-200h (M1-M9)**,
**superioridade auditavel 250-350h (M1-M10 + benchmarks)**.

Sem M10 (benchmark methodology), Atlas **não pode declarar superioridade**.

## Papel no Atlas
Filho de planejamento do índice. Existe para que qualquer IA implementando
saiba em que ordem agir, com qual DoD, qual receipt esperado, qual dependência.

Não é wishlist — é sequência sob restrição (mesmo path, mesma surface).

## Onde Se Encaixa
Layer 0.72 Programming. Sequencia missões para realizar a tese de
`atlas-programming-superiority-architecture.md` usando contratos de
`atlas-programming-superiority-contracts.md`.

## Contratos
Roadmap reusa os 14 schemas da doc de contratos. Receipts esperados por missão:
- M1: emissão de `route_decision.v1` em flow real.
- M2: persistência de `escalation_packet.v1` em DB.
- M3: deprecation receipt dos 3 mecanismos legados.
- M4: log + alarme em fallback ToolPolicy/ToolReceipt.
- M5: certification com `quality_score > threshold` declarado.
- M6: AiWorker emite `mission.created` antes de invocar provider.
- M7: `ProgrammingRagGateException` thrown em flow strict.
- M8: `AiProgrammingMultiAgentAssignment` row criado.
- M9: `rag.feedback.v1` registra utilidade por ref.
- M10: `atlas.programming.benchmark_run.v1` com métricas auditadas.

## Fluxo
### Atlas Dev Target Architecture
**Estado-alvo** (referência `atlas-dev-patamares.md` A0-A7):
- A0 ✅ passado (chat com engine cru).
- A1 🚧 em construção (Foundation Schemas/Discovery/PromptProjection/Telemetry/Persistence canônicos).
- A2 planejado (Plan Visible no Desktop).
- A3 planejado (Provider Verified, scope guard + verification + receipt).
- A4 planejado (Self-Healing repair loop com escalation honesto).
- A5 planejado (Surface Parity — Desktop/CLI/App/API thin adapters).
- A6 futuro (Adaptive Engine via Atlas Decide escolhe engine por categoria).
- A7 futuro distante (Continuous Learning via FastPathErrorLedger → Curator → Inbox).

**Specialist flows** (alvo): plan, code, debug, review, explain, research,
test, refactor, frontend, backend, database, security (quando aplicável).
**Fast path vs deep path**: fast = single-call com scope guard, deep = senior
engineer loop com auditoria 7 capabilities.

**Patch Intelligence** (real, em `ProgrammingPatchVerifier`):
local style detection (via reranker), blast radius (changed_files count),
expected files (allowed_files contract), diff risk (large_diff requires review),
secret guard (`.env` + secrets), rollback plan obrigatório, anti-churn
(action_manifests sem rollback rejeitados), code review self-check
(VerificationGate + CompletionStateGate).

**Debug Intelligence** (real, em `ProgrammingRepairExecutor` + `FailureModeClassifier`):
error fingerprint (FailureSignatureHasher), reproduction (validation_commands
do task contract), hypothesis (RepairPromptComposer), similar failure
retrieval (`failure_hash` lookup), minimal failing test (TestImpactAnalyzer),
logs (test_log evidence refs), repair candidates, confidence scoring,
stop conditions (`same_signature_twice`, `diff_growth`).

**Review Intelligence** (real, em `AtlasDev/Gate/`): bug-focused review via
VerificationGate; severity precedence (blocked > no_patch_needed > failed >
needs_review > passed) em CompletionStateGate; line refs em test_log; test gaps
detectados; architecture drift via ScopeGuard.

**Research Intelligence for Programming** (parcial):
official docs first via `canonical_docs` source; repo docs first via Code
Intelligence; source credibility via reranker bonuses; **gap**: version
verification não automatica; **gap**: web search policy não declarada (Tool
Runtime tem `browser.readonly` mas integração programming-specific pendente).

### Atlas Forge Target Architecture
**Estado-alvo** (referência `atlas-forge-continuum-os.md`):
- **Obras** persistidas (AtlasProject model).
- **SDD** completo: Spec → Plan → Tasks via `AtlasSddPipeline`.
- **Milestones**: derivados de Tasks com gates.
- **Work packets**: `AtlasCodeWorkPacket` com `allowed_files`,
  `forbidden_files`, `acceptance_criteria`, `verification_commands`,
  `evidence_required`, `risk_band`, `role_slot`.
- **Long-horizon memory**: Compounding memory + Memory Registry.
- **QA gates**: Continuum certification 37 invariants + Runtime certification
  (`AtlasForgeContinuumCertificationService`, `AtlasForgeRuntimeCertificationService`).
- **Multi-agent execution**: hoje ad-hoc; M8 entrega scheduler dedicado.
- **Repair and regression loops**: `AtlasForgeLiveExecutionService` 11 stages
  com repair stage.
- **Artifact management**: AtlasCodeWorkPacket + EvidencePack.
- **Certification**: Continuum + Runtime + (alvo) Hyperflow 100x.
- **Release readiness**: `forge_runtime_dispatch` + `forge_review` gates.
- **Handoff**: `AtlasForgeHandoffAdapter::promote()` via Meta 2.
- **Relation with Dev**: zero import; opera independente; pode receber
  escalation_packet via M2/M3.

### Router + Dual-Core Decision System
**route_decision.v1 wiring** (estado-alvo, M1):
- HTTP/CLI entry → `IntentKernelService::classify`.
- `DomainRouterService::route` → `primary_domain`.
- `FlowRouterService::decideFlow` → emite `AiAtlasFlowRoute`.
- **NOVO (M1):** `FlowRouterService` chama `DualCoreRouteDecisionService::recordFromFlowRoute`.
- `DualCoreRouteDecisionService` emite `route_decision.v1` persistido.
- Resultado: 1 query única em `ai_dual_core_route_decisions` responde "por que essa rota?".

**Thresholds canônicos** (`atlas-dev-policy.md` + EscalationDecisionEngine):
- `target=forge` se score≥7 OU risk≥R4.
- `target=obra_candidate` se 4≤score<7.
- `target=null` (Dev permanece) se score<4 E risk<R4.
- Sinais: `file_count>5-6`, `layers≥3`, `contexto>40k chars`, `thread≥24 msgs`,
  keywords (security/auth/billing/migration), falha recorrente ≥2.

**Refusal/clarification**: ambiguity_level=high + risk_level=high → Router
deve preferir clarification em vez de execução; hoje route fallback é
`atlas_conversation` (suficiente para perguntar de volta).

### Plan-Execute-Verify-Repair Loop
**Spec operacional** (todas as classes existem):

| Fase | Classe responsável | Output | Schema |
|------|---|---|---|
| Plan | `AtlasDevFastPathOrchestrator::planOnly` | PlanOnlyResult | `atlas.dev.plan_only_result.v1` |
| Execute | `AtlasDevFastPathOrchestrator::run` | ProviderCallResult | inline |
| Verify | `ProgrammingPatchVerifier` + `VerificationGate` + `ProgrammingTestImpactAnalyzer` | patch_verifier.report.v1 + test_impact.receipt.v1 | ✅ |
| Repair | `ProgrammingRepairExecutor` + `FailureModeClassifier` + `RepairOrchestrator` | repair_capsule.v1 + failure_capsule.v1 | ✅ |
| Certify | `MissionCertificationService` + `CompletionStateGate` | certification.v1 | ⚠️ shape-only hoje |
| Update memory | `AtlasCompoundingOutcomeEvaluator` + `AtlasLearningDistiller` | outcome.v1 + learning_candidate.v1 | ✅ |

**Stop conditions** (canônicas):
- `same_signature_twice` → block + escalate.
- `max_attempts_reached` → block + escalate.
- `human_review_required` → pause + waiting_approval.
- `certification_failed` → repairing OR failed.

### Multi-Agent Work Scheduler
**Estado atual:** sem classe dedicada. Self-Construction tem
`AgentControlPlaneMultiAgentParallelismPlanner` (orquestra agentes para
self-construction work).

**Spec alvo (M8):**
- **Quando spawnar**: WorkItem com `parallelism_estimate > 1` (heurística:
  modules_touched ≥3 e independentes).
- **Ownership por arquivos/módulos**: cada agente recebe `allowed_files`
  disjuntos; rules em AtlasCodeWorkPacket.
- **Non-overlap**: enforcement em `MultiAgentAssignmentService` (novo).
- **Merge/integration**: `merge_strategy` em packet (sequential|parallel_then_merge).
- **Agent receipts**: cada agente emite `agent_receipt.v1` com decisões.
- **Conflict detection**: hash de patch overlap; bloqueia merge.
- **Verifier agents**: separados de worker agents; verificam após cada merge.
- **Planner vs worker split**: planner agent emite WorkPacket; workers consomem.
- **Long-horizon (Forge)**: scheduler persiste estado entre sessões via
  Obra/WorkItem rows.

Reutilizar `AgentControlPlaneMultiAgentParallelismPlanner` como base; estender
para programming-specific WorkPackets.

### Compounding Loop
**Estado real** (11 services confirmados em `app/Services/Ai/Compounding/`):
```
Execution Receipts
→ AtlasCompoundingOutcomeEvaluator.evaluate() [outcome.v1]
→ AtlasLearningDistiller.distill() [learning_candidate.v1, decision=promote|hold]
→ (if promote) AtlasCompoundingMemoryService.promote() [memory.v1, requires evidence_refs ≠ [] AND confidence ≥ 70]
→ AtlasRagFeedbackService.record() [rag.feedback.v1 — included/used/noise/missed]
→ AtlasBenchmarkGeneratorService.fromOutcome() [benchmark_case.v1 — se outcome ruim]
→ AtlasHeuristicEvolutionService.propose() [heuristic_update.v1 — status=proposed]
→ AtlasTemporalCertificationService.certify() [temporal delta]
→ AtlasCompoundingRuntimeService.recordExecution() [runtime_record.v1]
```

**Gap crítico (M9):** loop completo de feedback retrieval→uso→repromote
não fechado. RagFeedback registra signals, mas não retroage no reranker.

**Promotion gates** (Cognitive Immune G0-G8 + Programming-specific):
- ProgrammingLearningPromotionGate exige `human_reviewed=true`,
  `evidence_refs ≠ []`, `status='candidate_ready_for_review'`.
- TTL 30 dias em `ProgrammingLearningCandidateStore`.
- Dedupe por `candidate_hash`.

## Regras para IA
1. **Não pular missão por entusiasmo** — ordem M1→M10 é por dependência, não preferência.
2. **Cada missão exige DoD verde antes de "shipped"** (tests + readiness + smoke).
3. **Atalhos em M5 (Certification quality-aware)** comprometem todo o sistema
   downstream — não simplificar.
4. **M10 (benchmark methodology) NUNCA antes** de M1-M9 — benchmark sem
   substrato produz métricas inválidas.
5. **Paralelização** só onde arquivos não se cruzam (ver tabela de missões).
6. **Cada missão deve emitir receipt canônico** — sem receipt, não shipa.

## Escopo de Implementacao
**Cobre:** spec implementável de Dev target + Forge target + loops + scheduler +
10 missões com DoD detalhado.
**Não cobre:** código, AP individual por missão (cada missão exige sua AP
quando começar).

## Dependencias
- Trinity: `atlas-programming-superiority-architecture.md` + `-contracts.md`.
- `atlas-dual-core-engineering-system.md` (lei).
- `atlas-dev-efficient-programming-flow-v1.md` (Dev impl).
- `atlas-forge-continuum-os.md` (Forge impl).
- `atlas-compounding-engineering-intelligence.md` (Compounding lei).
- `atlas-hyperflow-operation.md` (operational layer).
- `atlas-autonomous-engineering-operating-system.md` (meta loop).
- Meta 1-9 backend (Mission/Domain/Policy/Tool/Evidence/Router/Control Plane).

## Evidencias
### Honest Metrics Methodology

**Métricas operacionais reais** (medíveis hoje):
- `time_to_first_correct_patch` (segundos do prompt até patch_verifier.report.status=passed).
- `num_user_turns_until_done` (count de mensagens do operador).
- `pct_tasks_completed_without_clarification` (no_blocker_no_clarification / total).
- `bug_recurrence_30d` (mesma signature voltando em 30 dias).
- `test_pass_rate` (tests passando após patch / total testados).
- `repair_success_rate` (repair attempts que viraram passed / total attempts).
- `retrieval_precision_proxy` (1.0 - context_waste_ratio de retrieval_eval.v1).
- `retrieval_recall_proxy` (% required_sources presentes em ranked_refs).
- `context_sufficiency_rate` (% planos com gate=passed).
- `evidence_completeness` (% certifications com missing_requirements=[]).
- `human_intervention_count` (operator_decisions / total runs).
- `forge_long_horizon_completion_rate` (Obras concluídas / Obras iniciadas em 30+ dias).

**Como medir Nx sem fraude (M10):**
1. Definir suite de N tasks idênticas — mix de bug fix pequeno (40%),
   feature média (30%), refactor multi-file (15%), debug profundo (10%),
   Obra (5%).
2. Executar suite em Atlas E em provider direto (Claude Code / Codex / Cursor)
   sob mesma workload, mesmo workspace, mesmo dia/clima de CI.
3. Coletar métricas acima.
4. Calcular Nx = atlas_metric / provider_metric **per métrica**.
5. Reportar **vetor de Nx**, não escalar único.
6. Atestation receipt via `AtlasTemporalCertificationService`.

**Gap atual:** sem golden set + sem Rivals real-execution + sem CI suite
comparável. M10 endereça.

### Benchmark Readiness Harness (prep para M10)

Slice de preparação **sem execução**: `app/Services/Ai/Programming/BenchmarkReadiness/`
expõe `BenchmarkReadinessHarness` (suite/manifest/validate JSON) +
`BenchmarkReadinessCaseCatalog` com 8 casos canônicos (bug_fix, feature,
debug, review, research, forge_obra, long_horizon_continuation, repair_loop),
rubric `atlas.programming.benchmark_scoring_rubric.v1` (8 dimensions) +
provider_slots `[atlas, dev, forge, rival_placeholder]` com placeholder
`unbound_no_run`. Toda saída carrega `benchmark_status=benchmark_not_run`,
`human_authorization_required=true`, `rival_provider_invoked=false`.

`BenchmarkReadinessHarness::run()` **sempre** lança
`BenchmarkReadinessAuthorizationException`: sem autorização canon
(`missing_authorization`) OU mesmo com shape válido
(`not_run_runtime_disabled_in_readiness_only_slice`). CLI exposta:
`atlas:programming:benchmark-readiness {readiness|manifest|suite|validate}` —
sem `run`. Quando M10 chegar, o runtime real plugará nesta superfície sem
mudar manifest, rubric ou provider_slots.

### 10-Phase Implementation Roadmap

| Fase | Nome | Entregáveis principais | Riscos |
|------|------|------------------------|--------|
| 1 | Cleanup | Doc status reconciliation (Forge OS `future`→`active`), consolidação 4 mecanismos escalação | baixo |
| 2 | Contracts | M1+M2 (Schemas 1+2 wired) | médio (mudança em RouterRuntime) |
| 3 | Enforcement | M3+M4 (consolidação + strict mode bridges) | alto (toca path crítico) |
| 4 | Quality | M5 (Certification quality-aware) | médio |
| 5 | Production wire | M6 (AiWorker → Kernel) | crítico |
| 6 | RAG Gate enforcement | M7 (Mandatory gate throws) | alto |
| 7 | Multi-agent | M8 (scheduler dedicado) | médio |
| 8 | Compounding closed loop | M9 (retrieval feedback retroage no reranker) | médio |
| 9 | Productization | promote Atlas Dev `--efficient` em produção; desktop cert | médio |
| 10 | Benchmark | M10 (suite + métricas auditadas + atestation) | alto |

### Top 10 Implementation Missions

| # | Pri | Missão | Esforço | Paralelizável | Bloqueia |
|---|-----|--------|---------|---------------|----------|
| M1 | P0 | Wire `DualCoreRouteDecisionService::recordFromFlowRoute` em `FlowRouterService::decideFlow` | 4-6h | não | M2, M6 |
| M2 | P0 | Implementar `atlas.dev_to_forge.escalation_packet.v1` + service + table + tests + adapter | 6-8h | não (mesmo path Router) | M3 |
| M3 | P1 | Consolidar 4 mecanismos paralelos de escalação em path único via `AtlasForgeHandoffAdapter` Meta 2 | 12-18h | não (mesma área Kernel) | M9 |
| M4 | P1 | Endurecer `ToolPolicyBridgeService` + `ToolReceiptService` para strict mode (remover fallback silencioso) | 8-12h | não (toca production runtime) | M5 |
| M5 | P1 | Enriquecer `MissionCertificationService::runChecks` com checks quality-aware (quality_score, source_verification, claim_verification) | 10-16h | não | M6, M9 |
| M6 | P1 | Wire AiWorker → MissionFactoryService + FlowRouterService (substituir path legacy direto-para-provider) | 16-24h | não (path produção crítico) | M7, M9 |
| M7 | P1 | Enforce mandatory RAG gate em flows strict (repair/forge/frontend/security/database) — `ProgrammingRagGateException` throw em `failed_closed` | 6-10h | paralelo a M4 (área diferente) | — |
| M8 | P2 | Multi-agent scheduler dedicado para programming WorkPackets reusando `AgentControlPlaneMultiAgentParallelismPlanner` | 16-24h | paralelo a M6 (área diferente) | Forge multi-agent |
| M9 | P2 | Compounding feedback loop closed: `AtlasRagFeedbackService` retroage no reranker via `AtlasHeuristicEvolutionService` | 16-24h | paralelo a M8 | benchmark precision |
| M10 | P2 | Benchmark methodology shipping: golden set + Rivals real-execution suite + auditavel metrics + atestation receipt | 24-40h | depende M1-M9 | declaração de superioridade |

**Paralelização segura:**
- M1+M2 só sequencial.
- M3 só após M2.
- M4 paralelo a M7 (área diferente).
- M6 paralelo a M8 (área diferente).
- M5 sequencial entre M4 e M6.
- M9 paralelo a M10 fase de preparação.

**Estimativa total**: 118-182h apenas missões; +30-50h para APs, docs,
revisão, integration testing. Total realista: **150-230h** para superioridade
auditavel.

### Safety / Governance Gates

**User approval obrigatório:**
- Promotion Dev → Forge (Attention queue, hoje implementado).
- Habilitar driver real Claude/Codex/Gemini CLI (3-approval em `AtlasForgeProviderInvocationService`).
- Aplicar `heuristic_update` (status `proposed` → `applied`).
- Promoção de memory candidate (G6-G8 Cognitive Immune).
- Publish marketing / spend orçamentado.
- Live trade (HARD BLOCKED por default).
- Cyber offensive (RoE escrita exigida).

**Destructive actions** (sempre exigem confirmation token):
- File delete via Tool Runtime.
- DB migration rollback.
- Force push (legitimately blocked em git hooks).
- Worktree clean.

**Secrets:**
- ProgrammingPatchVerifier bloqueia `.env`, `secrets in filename`.
- Local Agent Memory Ingestion redact + receipt.
- Provider projection redact via privacy class.

**External tools (browser, web fetch):** todas com `policy.evaluate` strict
mode (M4 endereça).

**Sandbox:** `ProgrammingSandboxManager` para mudanças risk≥R3.

**Audit:** todo receipt persiste com sha256 hash; AuditEvent append-only.

## Riscos
1. **M6 (AiWorker integration) é a missão mais arriscada** — toca o path de
   produção que serve usuários hoje. Necessita: feature flag, rollback plan,
   canary deployment.
2. **M3 (consolidação) pode quebrar callers desconhecidos** — `rg` pode não
   pegar callers dinâmicos (string-based class loading). Mitigação: deprecation
   por 1 sprint antes de remoção.
3. **M5 (quality-aware certification) pode rejeitar runs que hoje passam**.
   Mitigação: lançar em modo `warn_only` por 2 semanas; coletar dados; depois
   `enforce`.
4. **M10 (benchmark) tentação de declarar Nx prematuramente**. Mitigação:
   forbidden_changes proíbe declaração sem atestation receipt.
5. **Paralelização errada** — duas missões em mesma área se atropelam.
   Mitigação: tabela explícita de paralelização nesta doc.
6. **M8 (multi-agent) sem M5 (quality cert)** — agentes podem certificar
   trabalho ruim. Sequência mandatória.
7. **Driver real configurado fora da ordem**. Mitigação: NÃO listado nas
   10 missões; é decisão de produto separada.
8. **Métricas vanity** — % completion alto mas com qualidade ruim. Mitigação:
   M10 exige métricas combinadas (vetor, não escalar).

## Exemplos
**Sequência ideal de execução (3 sprints):**

**Sprint 1 — Foundation (M1+M2+M3):**
- M1 (4-6h) → M2 (6-8h) → M3 (12-18h).
- Total: ~25-32h.
- Receipt: 4 mecanismos paralelos viram 1; schemas 1+2 wired.
- Gate: docs-health + git diff + 11+8+N tests verdes.

**Sprint 2 — Production wire (M4+M5+M6 sequencial; M7 paralelo):**
- M4 (8-12h) → M5 (10-16h) → M6 (16-24h).
- Em paralelo: M7 (6-10h).
- Total: ~40-62h.
- Receipt: AiWorker emite mission.created; ToolPolicy/Evidence strict; cert quality-aware.

**Sprint 3 — Closed loop + benchmark (M8 || M9 || M10 prep):**
- M8 (16-24h) || M9 (16-24h) paralelo (áreas diferentes).
- M10 prep (golden set creation, ~10h).
- Total: ~42-58h.
- Receipt: scheduler ativo; compounding closed; benchmark ready.

## Proximas Acoes
### Definition of Done — Atlas Programming Superiority

A arquitetura é declarada **implementada** quando TODOS os critérios abaixo
forem verdes simultaneamente:

1. **Schemas 1 + 2 emitidos em flow real de produção** (não apenas testes).
2. **Mecanismo único de escalação Dev→Forge** com 3 outros deprecados.
3. **ToolPolicy + ToolReceipt em strict mode** sem fallback silencioso em produção.
4. **Mission Certification quality-aware** com pelo menos 3 checks beyond shape.
5. **AiWorker invoca Mission/Router/Policy canônicos** para 100% dos prompts programming.
6. **Mandatory RAG gate enforce** em flows strict (throw em failed_closed).
7. **Multi-agent scheduler ativo** com `AiProgrammingMultiAgentAssignment` rows criados.
8. **Compounding feedback loop fechado** — RagFeedback retroage no reranker via HeuristicUpdate.
9. **Benchmark methodology shipped** com golden set + Rivals real + atestation.
10. **Métricas auditadas em rolling 30-day window** mostrando vantagem em pelo menos 8 das 12 métricas vs Claude Code/Codex baseline.
11. **Trinity de docs sincronizada** com runtime real (sem `status: future` órfão; sem schema sem caller).
12. **Senior Engineer Loop 7 capabilities** passando em 100% dos runs produtivos.

**Gates de fechamento desta entrega (trinity de 3 docs):**
- `php artisan atlas:engineering:knowledge docs-health --json` → 0 violations.
- `git diff --check` → limpo.
- 3 docs com line_limit 520 respeitado.
- Cross-link integrity manual verificado.

### Critérios de declaração de superioridade

**NÃO declarar:** "Atlas é 10x/30x/100x Claude Code/Codex".

**PODE declarar (quando M10 terminar):**
- "Atlas tem evidência auditada de N% mais context_sufficiency em flow X
  vs Claude Code/Codex em suite Y, medido por Z dias rolling."
- "Atlas reduz bug_recurrence_30d em P% vs baseline."
- "Atlas Forge completa Obras de 30+ dias com Q% completion rate vs N/A
  (Claude Code/Codex não tem long-horizon)."

Cada declaração com vetor de métricas + atestation receipt assinado por
`AtlasTemporalCertificationService` + período observado declarado.
