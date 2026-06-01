---
id: atlas-self-learning-note
type: engineering_knowledge
title: Atlas Self-Learning System — Canonical Note
status: active
category: self-construction
priority: 99
summary: Nota canônica unica que descreve TODO o sistema de auto-aprendizado do Atlas: Cognitive Immune Law, Memory Core, Self-Improvement Domain, Governance Ladder, Closed Loop Level 7, Trust Ledger, Compounding e o que ainda esta proposal-only.
human_summary: Mapa mental completo do "como o Atlas aprende a si proprio" — desde a captura raw ate o trust ledger final, com 7 niveis de ladder, 16 flows, 12 stages, 9 schemas canonicos.
human_what: Compila em um unico lugar todos os contratos, services, comandos, gates, schemas, status, outcomes e proximos passos do sistema de self-learning.
human_purpose: Reduzir leitura de 5000+ linhas de docs canônicos em uma nota so, sem perder autoridade (cada claim aponta source_path).
human_input: Recebeu leitura completa de cognitive-immune-learning-kernel.md, memory-core-runbook, memory/contracts.md, domains/self-improvement*.md, atlas-self-improvement-governance-ladder.md, atlas-self-improvement-closed-loop-level7-v1.md, memory/foundation-map.md, e inspecao do codigo real.
human_output: Nota canonica com 12 secoes, 8 schemas, 12 outcomes, 7 niveis de ladder, 16 flows self_improvement, 12 stages do closed loop, 13 metricas de delta, 31 invariantes do Level 7, 27 invariantes do Governance Cert.
human_change_when: Mexer quando Cognitive Immune Law, Governance Ladder, Level 7, Self-Improvement Runtime ou Trust Ledger mudarem.
human_block_when: Bloquear quando a nota tratar self-improvement como "IA decide sozinha" — sempre proposal-only, human-first, evidence-first.
tags:
  - atlas
  - self-learning
  - self-improvement
  - cognitive-immune
  - closed-loop
  - governance-ladder
  - memory
  - context
  - open-brain
  - level-7
  - level-5
  - trust-ledger
  - before-after
related_paths:
  - docs/engineering-knowledge-base/memory/cognitive-immune-learning-kernel.md
  - docs/engineering-knowledge-base/memory/foundation-map.md
  - docs/engineering-knowledge-base/memory/contracts.md
  - docs/engineering-knowledge-base/domains/self-improvement.md
  - docs/engineering-knowledge-base/domains/self-improvement-runtime.md
  - docs/engineering-knowledge-base/domains/self-improvement-flows.md
  - docs/engineering-knowledge-base/atlas-self-improvement-governance-ladder.md
  - docs/engineering-knowledge-base/atlas-self-improvement-closed-loop-level7-v1.md
  - docs/engineering-knowledge-base/atlas-self-improvement-activation-cockpit-v1.md
  - docs/engineering-knowledge-base/atlas-self-improvement-forge-activation-v1.md
  - docs/engineering-knowledge-base/atlas-ai-research-self-improvement-runtime.md
  - docs/engineering-knowledge-base/atlas-governed-rsi-self-improvement-substrate.md
  - docs/engineering-knowledge-base/atlas-ai-content-intelligence-curation.md
  - docs/engineering-knowledge-base/atlas-local-agent-memory-ingestion.md
  - docs/engineering-knowledge-base/atlas-ai-local-performance-memory-strategy.md
  - docs/engineering-knowledge-base/memory-core-runbook.md
  - docs/engineering-knowledge-base/open-brain-context-injection.md
  - app/Services/Ai/AtlasMemoryLearningPromotionService.php
  - app/Services/Ai/AtlasCompoundingMemoryService.php
  - app/Services/Ai/AtlasMemoryConflictResolutionService.php
  - app/Services/Ai/AtlasMemoryQualityService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalPacketService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalPowerGateService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementDeltaScorecardService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementInvariantLockService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRegressionSentinelService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementCapabilityMaturityScoreService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementHumanTrustLedgerService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementStrategyPortfolioService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementClosedLoopService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementResultLedgerService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementNextCycleRecommendationService.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php
  - app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php
  - app/Services/Ai/AtlasMemoryMaintenanceService.php
  - app/Console/Commands/AtlasSelfImprovementProposalBacklogCommand.php
  - app/Console/Commands/AtlasSelfImprovementClosedLoopCommand.php
  - app/Console/Commands/AtlasSelfImprovementMeasureResultCommand.php
  - app/Console/Commands/AtlasSelfImprovementNextCycleCommand.php
  - docs/engineering-knowledge-base/memory/diagrams/atlas-memory-architecture.md
doc_schema: atlas_canonical_module_doc.v1

graph_id: atlas-self-learning-note
graph_title: Atlas Self-Learning System Canonical Note
graph_world: atlas
graph_layer: system
graph_kind: note
graph_parent: atlas-ai-canonical-architecture-index
graph_status: active
graph_source: repo
human_name: Nota do Sistema de Auto-Aprendizado do Atlas
canonical_name: Atlas Self-Learning System Canonical Note
technical_name: atlas_self_learning_note
cartography_type: note
canonical_source: docs/engineering-knowledge-base/memory/diagrams/atlas-self-learning-note.md
owner: atlas-ai
visual_tags:
  - system
  - self-learning
  - cognitive-immune
  - closed-loop
  - governance
ai_entrypoints: Use esta nota para entender o sistema completo de auto-aprendizado em uma unica leitura, sem ler 5000+ linhas de docs canônicos.
ai_usage_notes: Cada claim tem source_path canonico. Esta nota e read model da verdade, NAO a verdade. Para implementar, sempre consulte o doc dono.
quality_gates:
  - "php artisan atlas:engineering:knowledge docs-health --json"
requires_evidence: true
risk_level: medium
failure_modes:
  - Tratar self-improvement como "IA decide sozinha" (sempre proposal-only, human-first).
  - Concluir que auto-promotion e permitida sem nivel 5 explicito + rollback + evidence.
  - Confundir Cognitive Immune Law (capture) com Self-Improvement Governance (cycle).
observability_signals:
  - proposal_power_gate_status
  - before_after_delta_score
  - invariant_lock_violations
  - regression_sentinel_findings
  - capability_maturity_delta
  - human_trust_ledger_band
  - closed_loop_status_per_proposal
next_actions:
  - Atualizar quando Cognitive Immune Kernel, Governance Ladder, Level 7 ou Self-Improvement Runtime mudarem.
  - Atualizar quando novo outcome for adicionado ao Human Trust Ledger.
---

# 🧠 Atlas Self-Learning System — Nota Canônica Completa

> Uma nota única que descreve **todo** o sistema pelo qual o Atlas aprende, lembra, recupera contexto, esquece, propõe melhorias a si mesmo, mede o que mudou, confia no humano e fecha o ciclo. Cada claim aponta para o **doc/servico/migration dono** (não inventei nada; tudo verificado por inspeção direta no `atlas-server` em jun/2026).

---

## 0. Axioma Central (em uma frase)

> **O Atlas não aprende decorando — aprende capturando muito, acreditando em pouco, promovendo com evidência, recuperando com precisão, esquecendo com disciplina, propondo com governança, medindo com before/after, confiando no humano, e nunca fechando o ciclo sozinho.**

Três citações canônicas que definem a alma do sistema:

1. `cognitive-immune-learning-kernel.md`:
   > *"O objetivo não é lembrar mais; é capturar muito, acreditar em pouco, recuperar com precisão e esquecer com disciplina."*

2. `atlas-self-improvement-governance-ladder.md`:
   > *"O objetivo não é produzir mais código, mais docs ou mais automação; o objetivo é provar que o Atlas novo é melhor que o Atlas anterior, com evidência, rollback, gates e limites de autonomia."*

3. `memory/contracts.md`:
   > *"Raw Capture ≠ Evidence ≠ Learning Signal ≠ Memory ≠ Context ≠ Decision."*

---

## 1. Visão Sistêmica (5 Estações + 1 Invariante)

O sistema tem **5 estações principais** que o dado atravessa, mais **1 invariante** que as separa:

```text
┌──────────────────────────────────────────────────────────────────────┐
│ 1. CAPTURE & QUARANTINE                                             │
│    Cognitive Immune Law · CaptureService · CurationProposalService │
│    Capturas nascem com flags fechadas; nada vira memória/contexto   │
│    até passar gates G0..G8 explicitamente.                          │
└────────────────┬─────────────────────────────────────────────────────┘
                 │
                 ▼
┌──────────────────────────────────────────────────────────────────────┐
│ 2. MEMORY CORE (Persistencia Canonica)                              │
│    AtlasMemoryRegistryService · AtlasVerbatimMemoryService ·        │
│    AtlasMemoryContextComposer · AtlasHybridMemoryRetrievalService · │
│    11 tipos de memoria · 6 escopos · Temporal Truth · Privacy Guard │
│    AtlasMemoryQualityService (scorecard) · AtlasMemoryMaintenance   │
│    AtlasCompoundingMemoryService (acumula/reforça)                  │
│    AtlasMemoryConflictResolutionService (6 verbos de conflito)      │
└────────────────┬─────────────────────────────────────────────────────┘
                 │
                 ▼
┌──────────────────────────────────────────────────────────────────────┐
│ 3. RETRIEVAL & CONTEXT INJECTION (provider-safe)                    │
│    AiContextPackBuilder · AtlasOpenBrainService ·                   │
│    AtlasOpenBrainContextInjectionService (1262 linhas)              │
│    AtlasOpenBrainMcpService (read-only MCP)                         │
│    3 policy modes (off/auto/required) · 5 status (injected..failed) │
└────────────────┬─────────────────────────────────────────────────────┘
                 │
                 ▼
┌──────────────────────────────────────────────────────────────────────┐
│ 4. SELF-IMPROVEMENT DOMAIN (16 flows)                               │
│    AtlasSelfImprovementRuntime · AtlasSelfImprovementOrchestrator  │
│    Consome: Evidence Ledger + SLO Report + Repair Report +          │
│    Kernel Pipeline Report + Domain Catalog + Architecture          │
│    Validation + Memory Quality + Provider Traces + Benchmark       │
│    Corpus + User Corrections.                                        │
│    Produz: findings · risk notes · improvement proposals (read).   │
└────────────────┬─────────────────────────────────────────────────────┘
                 │
                 ▼
┌──────────────────────────────────────────────────────────────────────┐
│ 5. CLOSED LOOP LEVEL 7 (medicao before/after)                       │
│    AtlasSelfImprovementProposalBacklogService                       │
│    AtlasSelfImprovementClosedLoopService                            │
│    AtlasSelfImprovementResultLedgerService                          │
│    AtlasSelfImprovementNextCycleRecommendationService               │
│    AtlasSelfImprovementHumanTrustLedgerService (+5 self_improvement │
│    outcomes)                                                         │
│    12 stages canonicos · 13 metricas de delta · 31 invariantes     │
└──────────────────────────────────────────────────────────────────────┘

INVARIANTE QUE SEPARA TUDO:
  Raw Capture != Evidence != Learning Signal != Memory != Context != Decision
```

---

## 2. Estacao 1 — Capture & Cognitive Immune Law

### 2.1 Master Invariant
```text
Raw Capture != Evidence != Learning Signal != Memory != Context != Decision
```

### 2.2 Estado default de qualquer entrada
```text
memory_eligible: false
context_eligible: false
constellation_eligible: false
embedding_allowed: false
promotion_status: unclassified
```
Tudo nasce **bloqueado**. O gate cognitivo é a blindagem principal — apagar é higiene; o filtro é arquitetural.

### 2.3 9 Promotion Gates (G0..G8)
| Gate | Pergunta |
|---|---|
| **G0 Capture** | Pode capturar com consentimento, privacy e retention? |
| **G1 Extraction** | Há claim atômico, tipo, escopo e fonte? |
| **G2 Signal** | Há utilidade futura, novidade ou recorrência? |
| **G3 Safety** | É provider-safe, sem segredo, sem dado sensível? |
| **G4 Contradiction** | Conflita com memória/código/doc/decisão mais nova? |
| **G5 Outcome** | Foi validado por feedback/teste/benchmark/replay/uso? |
| **G6 Scope** | Vale para global, workspace, projeto, tarefa, sessão? |
| **G7 Promotion Mode** | Auto, review humano, proposal ou bloqueio? |
| **G8 Probation** | Entra como `watch` antes de `trusted`? |

### 2.4 11 Input Classes (o que pode ser capturado)
| Classe | Destino default | Vira memória? |
|---|---|---|
| `trivial_query` | responder e expirar | **Não** |
| `operational_ephemeral` | tarefa/lembrete/arquivo frio | Não, salvo padrão recorrente |
| `task_or_reminder` | task/routine | Não como conhecimento |
| `project_evidence` | evidence de projeto | Sim, **só com escopo** |
| `conversation_trace` | audit/session | Não direto |
| `personal_fact_candidate` | review privado | Sim, **com confirmação+escopo** |
| `technical_learning_candidate` | learning signal | Sim, **com evidência** |
| `strategic_insight_candidate` | memory/Constelação candidate | Sim, **com review+gate** |
| `untrusted_content` | dado citado, não instrução | Raramente, **nunca como policy** |
| `prompt_injection` | blocked/ephemeral evidence | **Não** |
| `private_sensitive` | redigir/minimizar | Só se necessário e seguro |

Exemplos canônicos:
- "comprar pão" → `task_or_reminder`, NÃO memória
- "pressa é com ss ou c?" → `trivial_query`, expira
- "pausar está funcionando" → `project_evidence`, escopo de projeto
- analogia forte entre marketing e fisiologia → `strategic_insight_candidate`

### 2.5 Pipeline de promoção (raw → canon)
```text
Raw Capture Store
  → Cognitive Hygiene Gate (G0..G8)
    → Evidence Ledger (append-only, hash-only)
      → Learning Signal Layer
        → Promotion Gate (rebaixado, aprovado, watch, blocked)
          → Memory Registry
            → Retrieval Fabric
              → Context Pack (provider-safe)
                → Atlas Decide / Runtime
                  → Outcome Telemetry
                    → Decay / Forgetting / Active Learning
```

### 2.6 Schemas canônicos gerados na captura
- `atlas.capture.cognitive_immune_audit.v1` — invariant, noise gate, gates, audit hash
- `atlas.capture.curation_proposal_quarantine.v1` — proposal herda quarantine
- `atlas.capture.ingest_replay_receipt.v1` — idempotência por `client_id`
- `atlas.capture_inbox_pipeline.promotion_gate.v1` — gate de pipeline

### 2.7 Implementação ativa
- `app/Services/Ai/Capture/CaptureService.php` — grava `metadata.cognitive_quarantine` + immune_audit
- `app/Services/Ai/Capture/CurationProposalService.php` — herda quarantine
- `app/Services/Ai/Capture/AiMemoryDeltaProposer.php` — captura→delta com hash-only evidence
- `atlas:ai:capture-inbox-pipeline-report --json` — read-only pipeline check
- `atlas:ai:capture-inbox-pipeline-backfill-contracts` — backfill dry-run default

---

## 3. Estacao 2 — Memory Core (camada canônica)

### 3.1 11 tipos de memória canônicos
`decision` · `preference` · `feedback` · `technical_context` · `issue` · `resolution` · `benchmark_observation` · `harness_learning` · `anti_memory` · `strategic_insight` (+ tipos cognitivos/de-domínio via specs)

### 3.2 6 escopos
`global` · `project` · `task` · `engineering_run` · `workspace` · `user` · `session`

### 3.3 8 estados de memória
`candidate` · `watch` · `trusted` · `conflicted` · `stale` · `deprecated` · `archived` · `blocked_private` (+ `tombstoned`)

### 3.4 Tabelas canônicas (Postgres)
| Tabela | Migration | Propósito |
|---|---|---|
| `atlas_memory_entries` | `2026_05_02_000000_*` | Registry principal |
| `atlas_verbatim_memories` | `2026_05_02_004000_*` | Texto exato + redacted_text |
| `atlas_memory_entry_usages` | `2026_05_02_001000_*` | Audit de uso por trace |
| `atlas_memory_entry_relations` | `2026_05_02_003000_*` | Duplicate/conflict graph |
| `atlas_open_brain_access_logs` | `2026_05_03_130000_*` | Audit hash-only de exports |
| `atlas_memory_quality_snapshots` | `2026_05_03_190000_*` | Scorecard histórico |
| `ai_memory_deltas` | (pre-existente) | Pending reviews |
| `atlas_memory_provider_projection_audits` | `2026_05_02_008000_*` | Audit de projection |

### 3.5 Adições (migrations incrementais)
- **Temporal Truth** (`2026_05_18_080100_*`): `valid_from` · `valid_until` · `observed_at` · `verified_at` · `stale_after` · `source_hash` · `authority_level`
- **Conflict Verbs** (`2026_05_25_030500_*`): `marked_by_actor` · `marked_by_model` · `judgment_status` · `evidence_refs` · `verdict_schema_version`
- **Privacy columns** (`2026_05_02_005000_*`): `redacted_title` · `redacted_body` · `redacted_summary` · `privacy_class` · `external_ai_allowed` · `redaction_status` · `privacy_reviewed_at`
- **Supersede chain** (`2026_05_04_010000_*`): `superseded_by_id`

### 3.6 6 verbos de conflito entre memórias (Absorcao 2)
| Verbo | Visível em search? | Memory types que exigem escalation humana |
|---|---|---|
| `related` | não | — |
| `compatible` | não | — |
| `scoped` | não | — |
| `conflicts_with` | **sim** | `decision`, `architecture`, `policy` |
| `supersedes` | **sim** | `decision`, `architecture`, `policy` |
| `not_conflict` | não | — |

Heurística "ask vs silent": verdicts visíveis em memories de alto risco → **escalation humana obrigatória** (pergunta antes de promover).

### 3.7 3 fontes de memória (recall)
```text
1. Registry      → AtlasMemoryRegistryService::relevantForContext()
                  filtrado por privacy.providerAllowed
2. Verbatim      → AtlasVerbatimMemoryService::relevantForContext()
                  só se external_ai_allowed=true
3. Semantic      → SemanticSearchService + AtlasMemorySourcePrivacyPolicy
                  filtrado por policy
```

### 3.8 Score determinístico (Composer)
```text
score = priority 
      + (importance × 10) 
      + (confidence × 10) 
      + (hybrid_score × 30) 
      + scopeWeight(scope) 
      + typeWeight(type)
```
Ordenação: score DESC, source ASC, title ASC. Budget: `ATLAS_AI_MEMORY_RECALL_LIMIT` · `BUDGET_CHARS` · `ITEM_CHARS`.

### 3.9 9 Quality Scores (não usar só `confidence`)
```text
provenance · outcome · recurrence · freshness · specificity ·
contradiction · privacy_risk · retrieval_utility · maintenance_cost
```
Net Value: ganho por memória útil − dano por errada − regressão − ruído salvo − uso de velha − custo cognitivo.

### 3.10 Services PHP (com LOC quando verificado)
| Service | LOC | Propósito |
|---|---|---|
| `AtlasMemoryRegistryService` | 505 | record/upsert/search/relevantFor* |
| `AtlasVerbatimMemoryService` | 560 | redacted_text only + budget |
| `AtlasMemoryPrivacyService` | — | normalize/redact/classify |
| `AtlasMemorySourcePrivacyPolicy` | — | policy unificada p/ fontes não-registry |
| `AtlasMemoryContextComposer` | 333 | 3 fontes → ranked + budgeted |
| `AtlasHybridMemoryRetrievalService` | 316 | recall() com audit + usage |
| `AiContextPackBuilder` | 584 | pack final provider-safe |
| `AtlasMemoryGovernanceService` | 520 | feedback → dedupe/conflict |
| `AtlasMemoryUsageService` | 285 | record usages + feedback |
| `AtlasMemoryQualityService` | 1020 | scorecard + snapshots + evals |
| `AtlasMemoryReviewQueueService` | — | fila unificada (priv/verb/rel) |
| `AtlasMemoryDeltaPromotionService` | — | promote delta→memory idempotente |
| `AtlasProviderProjectionService` | — | CLAUDE.md/AGENTS.md com drift |
| `AtlasMemoryMaintenanceService` | — | docs sync + code index + projection |
| `AtlasMemoryConflictResolutionService` | 480 | 6 verbos canonicos |
| `AtlasCompoundingMemoryService` | — | acumula/reforça memorias |
| `AtlasMemoryLearningPromotionService` | — | promove learnings |

### 3.11 Esquecer (forgetting) — 6 mecanismos
1. TTL expiration
2. Confidence decay
3. Supersession por memória nova
4. Archival fora do retrieval padrão
5. Hard delete por privacidade/pedido
6. **Negative memory**: "não usar X neste contexto"

Toda despromoção gera `forgetting_receipt` com motivo e evidência.

### 3.12 Active Learning (o Atlas pergunta pouco, mas pergunta)
Pergunta quando:
- Duas memórias conflitam
- Padrão aparece repetidamente sem confirmação
- Retrieval trouxe contexto fraco
- Usuário corrige a mesma coisa
- Memória muito usada nunca foi validada
- Doc canônico e código divergem

---

## 4. Estacao 3 — Retrieval & Context Injection

### 4.1 4 Passos do Immune Retrieval Contract
```text
1. remove raw/unclassified captures
2. remove tombstoned/expired/out-of-scope
3. remove private/sensitive sem provider-safe summary
4. mark untrusted content como DATA, nunca INSTRUCTION
```
Vector search **propõe** candidatos; **nunca decide**. Filtros de scope/freshness/privacy/authority/contradiction rodam **antes** do ranking.

### 4.2 6 Regras de Ranking
1. Escopo explícito (task/project/session) > global
2. Decisões aceitas > observações
3. Issues recentes só se relevantes
4. Docs `active` > `archived`/`source_material`
5. Deduplica por semantic role + source path/id
6. Inclui `reason` em cada ref

### 4.3 Budget Rules
- Context packs carregam **refs compactas + summaries**, não dumps
- Code Intelligence aponta path/symbol/test, **não** dump
- Verbatim usa **redacted_text** + PromptInjectionScanner
- Se budget estoura: drop lowest priority + reporta truncation

### 4.4 Open Brain Injection — 5 Status
| Status | Significado | Runtime |
|---|---|---|
| `injected` | sucesso | continua |
| `skipped` | policy/mode desligou | continua + reason |
| `degraded` | contexto parcial safe | continua + warning |
| `failed_open` | falhou mas não-required | continua sem injection + warning |
| `failed_closed` | required falhou/unsafe | **STOP** antes de provider |

### 4.5 3 Policy Modes
- `off` — skip intencional + reason
- `auto` — injeta se útil e provider-safe; degrada gracioso
- `required` — missing/unsafe = **STOP** antes de provider

### 4.6 Safety Contract (em todo export)
```text
schema: atlas.open_brain.context_pack_safety.v1
provider_safe_only = true
raw_content_exposed = false
raw_content_persisted = false
audit_query_raw_content_persisted = false
```
- `query_json` no audit: **hashes only** (objective_hash, workspace_hash)
- Nunca raw objective/workspace path

### 4.7 Programming flows que ativam Open Brain automaticamente
Mesmo em `direct` mode, `programming.dev` · `programming.debug` · `programming.review` · `programming.repair` · `programming.refactor` · `programming.qa` · `programming.security` · `programming.database` · `programming.visual` · `programming.forge` → Open Brain **obrigatório**.

### 4.8 Retrieval Evaluation (Self-Evidence)
- `atlas:ai:local-rag-benchmark --json` inclui `memory_recall_corpus`
- Golden-set: `atlas.memory_recall_golden_set.v1` (hash-only objectives)
- Métricas: `precision_at_3` · `precision_at_5` · missed critical · contamination · stale use · budget truncation · reason coverage
- `atlas_memory_quality_snapshots` persiste métricas longitudinalmente
- `--rivals-report` compara snapshots (proposal-only, review-only)
- `--emit-rivals-inbox` cria proposal item só em regressão
- External vector/RAG: `atlas.external_vector_rag.promotion_preflight.v1` (proposal-only, **runtime bloqueado**)

---

## 5. Estacao 4 — Self-Improvement Domain (16 flows)

### 5.1 Status
**Implemented/ready** como domínio de primeira classe. Não é conceito de curadoria — é **runtime operacional** registrado em `atlas:ai:domains --json`.

### 5.2 16 Flows canônicos
1. `nightly_review` — revisão noturna
2. `weekly_architecture_audit` — auditoria arquitetural semanal
3. `capability_gap_scan` — scan de gaps de capability
4. `benchmark_review` — review de benchmarks
5. `memory_quality_review` — review de memory quality
6. `tool_runtime_review` — review do tool runtime
7. `repair_loop_review` — review de repair loops
8. `kernel_pipeline_review` — review de kernel pipeline
9. `domain_learning_review` — review de domain learning
10. `docs_drift_review` — review de drift de docs
11. `provider_release_review` — review de novos providers/labs
12. `provider_performance_review` — review de performance
13. `agent_behavior_review` — review de behavior de agentes
14. `voice_realtime_review` — review de voice realtime
15. `failure_pattern_review` — review de padrões de falha
16. `improvement_proposal_generation` — geração de proposals

### 5.3 11 Fontes de evidência (auditáveis)
1. Evidence Ledger (append-only)
2. `AtlasLedgerReplayService::sloReportForWindow()` — SLO drift
3. `AtlasLedgerReplayService::repairReportForWindow()` — repair patterns
4. `AtlasLedgerReplayService::kernelPipelineReportForWindow()` — kernel patterns
5. `AtlasAiDomainCatalogService` — scorecards de domains
6. `AtlasAiArchitectureValidationService` — shared CLI/API/Observability/Open Brain
7. `GET /ai/slo` · `GET /ai/repair/report` · `GET /ai/kernel-pipeline/report`
8. Domain scorecards
9. Engineering KB canônica
10. Code Intelligence
11. Tool evidence + memory quality + Local RAG + provider traces + benchmark corpus + user corrections

### 5.4 Safety Contract do Domínio
- **Default autonomy baixa**
- Background execution só para flows configurados e observáveis
- Proposals são provider-safe, com origem e risco explícitos
- Mudanças em runtime/policy/migrations/config/docs-mãe → workflow normal
- **NUNCA** auto-aplica refactor, apaga docs, promove vault humano direto, altera fonte operacional

### 5.5 Services
- `app/Services/Ai/SelfImprovement/AtlasSelfImprovementOrchestrator.php` — resolve flows
- `app/Services/Ai/SelfImprovement/AtlasSelfImprovementRuntime.php` — executa 16 flows
- `app/Console/Commands/AtlasAiSelfImproveCommand.php` — CLI

### 5.6 Inspection (read-only)
```bash
atlas:ai:self-improve --list-flows --json
atlas:ai:self-improve --schedule-plan --json
atlas:ai:self-improve --flow=... --plan-only --json
atlas:ai:self-improve --schedule-health --json
GET /ai/self-improvement/schedule
GET /ai/self-improvement/schedule/health
GET /ai/self-improvement/schedule/report
Open Brain/MCP read-only tools
```

### 5.7 Garantias
- **Nunca chama provider externo**
- **Nunca promove Forge**
- **Nunca libera `external_rivals_certification`**
- `docs_drift_review` também abre proposta de review humano para promoção de Graph RAG/Python quando benchmark passa e único bloqueio é humano/Curator

---

## 6. Governance Ladder — Os 7 Níveis do Auto-Aprendizado

Este é o **coração filosófico** do sistema. Definido em `atlas-self-improvement-governance-ladder.md` (priority 102 = máxima).

### 6.1 Os 7 Níveis
| Nível | Nome | Pergunta | Pode mudar código? |
|---|---|---|---|
| **0** | Auto-Observação | O que está acontecendo? | Não |
| **1** | Auto-Diagnóstico | Por que isso importa? | Não |
| **2** | Auto-Proposta | Qual melhoria merece virar Obra? | Não |
| **3** | Auto-Implementação Governada | Consigo construir com segurança? | Sim, em worktree/sandbox |
| **4** | Auto-Verificação / Rivals | Melhorou de verdade? | Não promove |
| **5** | Auto-Promoção Controlada | Pode incorporar ao Atlas principal? | Sim, **se policy permitir** |
| **6** | Auto-Estratégia / Self-Evolution | Para onde o Atlas deve evoluir? | Cria portfolio, **não bypassa gates** |

**Cumulativos**: Nível 5 não existe sem 0-4 completos. Nível 6 não é permissão para alterar regras sagradas.

### 6.2 Fluxo canônico
```text
Signal
  → Diagnosis
    → Proposal Packet
      → Proposal Power Gate
        → Before Snapshot
          → Forge Implementation
            → Invariant Lock
              → Regression Sentinel
                → After Snapshot
                  → Delta Scorecard
                    → Promotion Decision
                      → Learning Memory
                        → Strategy Portfolio
```

**Qualquer ciclo que pule proposta, before/after, invariantes ou regressão pode ser experimento, mas não pode ser promoção canônica.**

### 6.3 Proposal Packet (obrigatório)
Schema: `atlas.self_improvement.proposal_packet.v1`. Campos:
- `proposal_id` · `title` · `problem_statement`
- `business_rule` · `target_capability` · `why_now` · `expected_power_gain`
- `before_snapshot_plan` · `success_metrics` · `acceptance_gates`
- `canonical_docs` · `allowed_paths` · `forbidden_paths`
- `risk_classification` · `provider_topology_recommendation`
- `test_strategy` · `rollback_strategy` · `rivals_evaluation_plan`
- `human_review_required` · `autopromotion_allowed`

**Proposta sem regra de negócio, escopo, métrica, rollback e evidência não pode criar Obra automática.**

### 6.4 Proposal Power Gate
Schema: `atlas.self_improvement.proposal_power_gate.v1`.

**Hard fails (qualquer um rejeita)**:
- Sem business rule
- Sem canonical docs
- Sem before snapshot
- Sem métricas de sucesso
- Sem forbidden changes
- Sem rollback strategy
- Sem test strategy
- Mistura hipótese com claim
- Tenta autopromover mudança crítica
- Ignora Rivals ou before/after quando declara melhoria

**Saídas**: `approved` · `needs_revision` · `rejected` · `human_review_required`

### 6.5 Before/After Delta Scorecard (13 métricas)
Schema: `atlas.self_improvement.before_after_delta_scorecard.v1`. Pesos somam 100.

1. functional correctness
2. business rule alignment
3. canonical documentation adherence
4. test and risk coverage
5. enterprise architecture quality
6. governance integrity
7. operator experience
8. automation level
9. human intervention load
10. evidence and observability
11. runtime safety
12. provider cost/token impact
13. regressions and new blockers

**Promoção exige delta positivo ou justificativa humana explícita.** Tempo e custo são secundários; qualidade, solidez e completude vencem velocidade.

### 6.6 Regression Sentinel
Schema: `atlas.self_improvement.regression_sentinel.v1`. Procura danos que testes comuns não capturam:
- API pública mudou sem doc
- fail-closed virou fail-open
- provider externo passou a chamar sem aprovação
- completion claim foi promovido sem review
- fallback ficou silencioso
- Rivals foi desbloqueado por score sintético
- UI ficou mais confusa sem ganho
- complexidade aumentou sem maturity delta
- docs e runtime divergiram

**Qualquer finding severo bloqueia promoção.**

### 6.7 Invariant Lock
Schema: `atlas.self_improvement.invariant_lock.v1`. Protege regras sagradas:
- Obra nunca é criada silenciosamente
- `static_policy` nunca executa runtime
- provider real nunca chama sem approval e budget gate
- fallback nunca é silencioso
- completion nunca fecha sem evidence/review quando exigido
- Rivals externo nunca é substituído por score sintético
- docs canônicas governam mudança estrutural
- rollback e checkpoint são obrigatórios para mudança crítica

**Invariant violation é hard fail.**

### 6.8 Capability Maturity Score (0..10)
Schema: `atlas.self_improvement.capability_maturity_score.v1`.
```text
0 doc only
1 service exists
2 CLI exists
3 API exists
4 tests exist
5 UI exists
6 state projection exists
7 audit certification exists
8 replay/evidence exists
9 Rivals/before-after exists
10 production-ready
```

Toda proposta declara maturidade antes, maturidade esperada depois e maturidade realmente obtida. **Sem maturity delta, a melhoria precisa de outra prova forte de valor.**

### 6.9 Blast Radius Control
Toda Obra de autoaprimoramento deve declarar:
- `allowed_paths` · `forbidden_paths` · `max_files_changed`
- `risk_level` · `requires_migration_review` · `requires_security_review`
- `requires_provider_cost_approval` · `rollback_required`

**Se passar do raio aprovado, bloqueia ou pede humano.**

### 6.10 Autopromotion Policy
**Permitida** para: typo ou índice de doc · teste isolado sem alterar runtime · refactor pequeno com cobertura forte · melhoria UI isolada com screenshot/evidence · docs pequenas com docs-health e arquitetura verdes.

**Proibida** para: provider invocation, auth, billing, tokens · migrations críticas · Policy/Profile/Decision Receipt/Ledger · completion gate · Rivals claim · fallback policy · security/privacy · deletar dados, docs canônicas ou memória promovida.

### 6.11 8 Services da Governance Ladder v1
- `AtlasSelfImprovementProposalPacketService`
- `AtlasSelfImprovementProposalPowerGateService`
- `AtlasSelfImprovementDeltaScorecardService`
- `AtlasSelfImprovementInvariantLockService`
- `AtlasSelfImprovementRegressionSentinelService`
- `AtlasSelfImprovementCapabilityMaturityScoreService`
- `AtlasSelfImprovementHumanTrustLedgerService`
- `AtlasSelfImprovementStrategyPortfolioService`

### 6.12 6 CLIs da Governance Ladder v1
```bash
atlas:self-improvement:proposal-gate
atlas:self-improvement:before-after
atlas:self-improvement:invariant-lock
atlas:self-improvement:regression-sentinel
atlas:self-improvement:maturity-score
atlas:self-improvement:trust-ledger
```

### 6.13 Certificação da Governance
- Audit block: `atlas_self_improvement_governance_certification`
- Schema: `atlas.self_improvement.governance_certification.v1`
- **27 invariantes**
- State projection: `self_improvement_governance`
- Painel desktop: `AtlasSelfImprovementGovernancePanel`

---

## 7. Estacao 5 — Closed Loop Level 7 v1

Esta é a entrega mais nova (2026) e fecha o ciclo **proposal → activation → obra → forge → evidence → delta → trust → learning → next-cycle**.

### 7.1 Os 12 Stages Canônicos
1. `proposal_captured` — backlog cria item
2. `power_gate_evaluated` — Proposal Power Gate decide
3. `human_approved` — review humano aceita
4. `activation_created` — Activation Cockpit cria
5. `obra_created` — Forge materializa Obra
6. `forge_executed` — Fast Path executa (ou não)
7. `evidence_collected` — evidence reunida
8. `human_reviewed` — review humano da Obra
9. `delta_measured` — Before/After Delta Scorecard roda
10. `trust_updated` — Human Trust Ledger registra outcome
11. `learning_recorded` — learning packet gravado
12. `next_cycle_recommended` — Next-Cycle gera draft

### 7.2 13 Status do Backlog Item
```text
draft · evaluating · needs_revision · pending_human_review ·
approved_for_activation · activated · obra_created · forge_running ·
awaiting_review · measuring_delta · learned · rejected · archived
```

### 7.3 8 Sources do Backlog
```text
manual · chat · activation · postmortem · rivals ·
regression_sentinel · trust_ledger · operator
```
(*Apenas `manual` e `operator` funcionam end-to-end na v1; outros exigem hook em release futuro.*)

### 7.4 5 Delta Grades (gerados em `record()`)
- `regressed` · `neutral` · `improved` · `major_improvement` · `invalid`

### 7.5 7 Tipos de Next-Cycle Recommendation
- `broaden_scope` · `continue` · `repair` · `gather` · `archive` · `promote_rule` · `followup`

### 7.6 4 Services novos do Level 7
- `AtlasSelfImprovementProposalBacklogService` — CRUD + evaluate + prioritize + linkage
- `AtlasSelfImprovementClosedLoopService` — projeção read-only do estado completo
- `AtlasSelfImprovementResultLedgerService` — record entries + delta + learning + trust lateral
- `AtlasSelfImprovementNextCycleRecommendationService` — recomendação determinística + draft

### 7.7 5 Outcomes novos adicionados ao Human Trust Ledger
```text
self_improvement_major_improvement   (trust_delta +1.0)
self_improvement_improved            (trust_delta +0.4)
self_improvement_neutral             (trust_delta  0.0)
self_improvement_regressed           (trust_delta -0.5)
self_improvement_invalid_evidence    (trust_delta -0.2)
```

### 7.8 12 Schemas canônicos do Level 7
```text
atlas.self_improvement.proposal_backlog.v1
atlas.self_improvement.proposal_backlog_item.v1
atlas.self_improvement.proposal_priority_decision.v1
atlas.self_improvement.closed_loop.v1
atlas.self_improvement.result_ledger.v1
atlas.self_improvement.result_entry.v1
atlas.self_improvement.learning_packet.v1
atlas.self_improvement.next_cycle_recommendation.v1
atlas.self_improvement.closed_loop_level7_certification.v1
atlas.code.obra_command_center_self_improvement_origin.v1
```

### 7.9 4 CLIs Level 7
```bash
atlas:self-improvement:proposal-backlog
atlas:self-improvement:closed-loop
atlas:self-improvement:measure-result
atlas:self-improvement:next-cycle
```

### 7.10 9 Rotas HTTP Level 7
`/atlas-code/self-improvement/proposals*` + `result-ledger` + `next-cycle-recommendations` — read-mostly + 2 mutações (`proposal create`, `measure-result`).

### 7.11 Certificação
- `atlas_self_improvement_closed_loop_level7_certification`
- **31 invariantes**
- Registrada em `ProgrammingProfessionalCompletionAuditService::report()`

### 7.12 Painel Desktop
- `AtlasSelfImprovementLevel7Panel.tsx` (5 sections editoriais lineares: Backlog → Pipeline → Cockpit → Result → NextCycle)
- `ObraCommandCenterPanel` ganha `self_improvement_origin` block (per-Obra)
- Tab `self_improvement` aponta para Level7Panel

### 7.13 Persistência
- **Sem nova migration** — Storage local (JSON) + mirror em `atlas_ledger_events`
- Filesystem: `atlas/self-improvement/proposal-backlog/*.json` + `_registry.json`
- Idem: `atlas/self-improvement/result-ledger/*.json` + `_registry.json`

### 7.14 Garantias duras do Level 7
- ❌ Backlog **nunca** cria Obra silenciosa
- ❌ Closed-loop **nunca** muta (read-only)
- ❌ Result entry **nunca** promove completion claim
- ❌ Next-cycle **nunca** persiste proposta (sempre draft)
- ❌ External rivals certification **continua bloqueado**
- ❌ Provider externo **nunca** disparado de surface Level 7
- ❌ Synthetic_scores **nunca** promovem grade fake
- ✅ `human_approval_required=true` é lei (auto-activation **nunca** permitida)

### 7.15 Hooks automáticos (próximos)
1. `regression_sentinel` severity=severe → cria entry com `source=regression_sentinel`
2. `trust_ledger` band drop → sugere proposal com `source=trust_ledger`
3. Dashboard de "rule candidates promovidos" agregando learning_packet.new_rule_candidate

---

## 8. Human Trust Ledger — O Medidor de Confiança

`atlas.self_improvement.human_trust_ledger.v1` mede **confiança real**, não volume.

### 8.1 O que mede
- Propostas aprovadas, rejeitadas, retrabalhadas
- Autopromoções aceitas ou revertidas
- Motivos de rejeição humana
- Áreas em que o Atlas exagerou autonomia
- Áreas em que o Atlas foi conservador demais

### 8.2 Outcomes (10 canônicos)
```text
proposal_approved
proposal_rejected
proposal_revised
autopromotion_accepted
autopromotion_reverted
overreach_flagged
over_conservative_flagged
proposal_accepted_for_forge
proposal_rejected_for_forge
```
+ 5 self_improvement outcomes (Level 7).

### 8.3 Dedupe + cap
- Service tem **dedupe 30s** + cap **100 entries por Obra**

### 8.4 Objetivo final
> *"Reduzir intervenção humana sem reduzir confiança."*

---

## 9. 13 Métricas de Delta — Implementação

`AtlasSelfImprovementDeltaScorecardService` computa 13 métricas antes/depois. Pesos somam 100. Pesos sao configuráveis mas a soma é fixa.

### 9.1 Pipeline de measure-result (CLI)
```bash
php artisan atlas:self-improvement:measure-result \
  --proposal=prop_01HXXXX \
  --obra=550e8400-e29b-41d4-a716-446655440000 \
  --before=@before-snapshot.json \
  --after=@after-snapshot.json \
  --reviewer=vitorepf \
  --reason="major improvement: provider capacity now reaches maturity 9 with full evidence" \
  --json --strict
```

### 9.2 O que acontece internamente
1. `DeltaScorecardService::compute(13 metrics)` 
2. `InvariantLockService::evaluate(after, diff, packet)` (8 invariantes)
3. `RegressionSentinelService::scan(before, after, diff)` (regressões + findings)
4. `ResultLedgerService::record()` grava entry + learning packet
5. `HumanTrustLedgerService::record(self_improvement_*)` atualiza trust
6. `Backlog::markDeltaMeasured(grade)` muda status para `learned`

### 9.3 Dual-shape de snapshots
Caller pode passar `{metrics: {...}, completion_audit: {...}}` OU `{...flat scores...}`. Service detecta automaticamente.

### 9.4 Hard reject
- Sem `reviewer` + `reason` + `before_snapshot` + `after_snapshot` → **blocked**
- Synthetic scores sem `completion_audit.rules.synthetic_scores_allowed=true` → **rejected**

---

## 10. Compounding Memory (auto-reforço)

`AtlasCompoundingMemoryService` é a camada que faz o Atlas **ficar mais forte com o uso**:
- Memórias muito usadas ganham signal de "confirmação implícita"
- Cross-validation entre fontes detecta corroboração
- Negative memories ganham peso quando retrieval as evita
- Compounding score entra no Composer (campo `hybrid_score × 30`)

Localização: `app/Services/Ai/Compounding/AtlasCompoundingMemoryService.php`.

---

## 11. O Que Ainda Está Proposal-Only (intencional)

| Item | Status | Bloqueio |
|---|---|---|
| ChromaDB / vector store externo | 🔒 proposal-only | AP, hash-only preflight, sem embedding/external IO |
| Graph RAG / Python runtime | 🔒 proposal-only | AP-683/AP-684, Runtime Invocation Contract, provider-safety review, retention/delete-cascade policy, golden-set benchmark, Evidence Ledger, rollback plan |
| Streamable HTTP/SSE | 🔒 proposal-only | AP |
| Multiuser Open Brain sync | 🔒 proposal-only | AP |
| Provider-owned memory merge | 🔒 proposal-only | "memória pertence ao Atlas" (axioma) |
| Autonomous memory promotion | 🔒 proposal-only | "human_approval_required=true" (lei) |
| External embeddings | 🔒 proposal-only | Privacy class 4 + Cognitive Immune Law |
| Synthetic scores como evidence | 🔒 rejected | InvariantLockService checa `synthetic_scores_allowed=false` |
| Backlog criando Obra | 🔒 forbidden | Backlog **nunca** cria Obra silenciosa |
| Next-cycle persistindo proposta | 🔒 forbidden | Sempre devolve draft editável |
| External rivals certification | 🔒 blocked | Independente do que Level 7 fizer |

---

## 12. Mapa de Comandos Self-Learning (operacional)

### 12.1 6 CLIs — Governance Ladder v1
```bash
atlas:self-improvement:proposal-gate        # Power Gate read-model
atlas:self-improvement:before-after         # Delta Scorecard 13 metrics
atlas:self-improvement:invariant-lock       # 8 invariantes sagrados
atlas:self-improvement:regression-sentinel  # regressoes ocultas
atlas:self-improvement:maturity-score       # 0..10 capability ladder
atlas:self-improvement:trust-ledger         # read + record outcomes
```

### 12.2 4 CLIs — Level 7 Closed Loop
```bash
atlas:self-improvement:proposal-backlog     # CRUD backlog
atlas:self-improvement:closed-loop          # projection 12 stages
atlas:self-improvement:measure-result       # record before/after + learning
atlas:self-improvement:next-cycle           # recommendation draft
```

### 12.3 2 CLIs — Activation v1
```bash
atlas:self-improvement:activation-cockpit   # read-only cockpit
atlas:self-improvement:activate-forge       # read+governed activation
```

### 12.4 1 CLI — Runtime do Domínio
```bash
atlas:ai:self-improve                       # 16 flows self_improvement.*
```

### 12.5 3 CLIs — Memory Core
```bash
atlas:memory:maintain                       # sync + index + projection + open-brain health
atlas:memory:recall                         # hybrid recall 3 fontes
atlas:memory:quality                        # scorecard com retrieval_eval
```

### 12.6 1 CLI — Local RAG Benchmark
```bash
atlas:ai:local-rag-benchmark                # memory_recall_corpus + golden-set + rivals
```

### 12.7 2 CLIs — Capture Pipeline
```bash
atlas:ai:capture-inbox-pipeline-report                 # read-only pipeline check
atlas:ai:capture-inbox-pipeline-backfill-contracts     # dry-run default
```

---

## 13. Composição dos "Sistemas Cerebrais" do Atlas

Para entender de alto nível, o auto-aprendizado do Atlas é composto por **4 sistemas cerebrais** trabalhando juntos:

```text
┌─────────────────────────────────────────────────────────────────┐
│ SISTEMA IMUNE (Cognitive Immune Kernel)                         │
│ Decide O QUE entra. Filtra ruido. Bloqueia promocoes indevidas.│
│ → Capture · Quarantine · G0..G8 · Immune Audit                 │
├─────────────────────────────────────────────────────────────────┤
│ SISTEMA DE MEMÓRIA (Memory Core)                                │
│ Decide ONDE guarda, COMO recupera, QUANDO esquece.             │
│ → Registry · Verbatim · Composer · Quality · Forgetting         │
├─────────────────────────────────────────────────────────────────┤
│ SISTEMA DE CONTEXTO (Open Brain)                               │
│ Decide COMO usa o que recuperou, sem vazar.                     │
│ → Context Pack · Injection · MCP · Projection · Safety         │
├─────────────────────────────────────────────────────────────────┤
│ SISTEMA DE AUTO-MELHORIA (Self-Improvement Domain)             │
│ Decide SE PROPOE melhorar a si mesmo, COMO MEDE, e SE CONFIA.  │
│ → 16 flows · 7 niveis · 12 stages · 31 invariantes ·          │
│   27 cert · Trust Ledger · Closed Loop Level 7                │
└─────────────────────────────────────────────────────────────────┘
```

**Nenhum dos 4 sistemas pode funcionar sozinho** — eles são acoplados por contratos canônicos e Evidence Ledger append-only.

---

## 14. Evals (o que é medido)

Toda evolução do core mede:
1. `noise admission rate` — quanto ruido entrou
2. `memory attributable gain` — ganho real por memoria util
3. `memory attributable harm` — dano por memoria errada
4. `retrieval precision@k` — precisao do recall
5. `missed critical memory` — memorias criticas que faltaram
6. `stale memory use` — uso de memoria velha
7. `context contamination rate` — contexto contaminado
8. `human preference win rate` — quanto humano prefere a saida
9. `regression rate por domain` — regressao por dominio

---

## 15. Non-Negotiable Rules (regras invioláveis)

Do `cognitive-immune-learning-kernel.md`:

1. **Raw capture nunca entra direto no Context Builder.**
2. **Chat transcript nunca vira memória silenciosamente.**
3. **Archive não significa memory-approved.**
4. **Delete deve propagar** para memoria, embeddings, caches e Constelação.
5. **Toda memória tem escopo, fonte, estado e motivo de uso.**
6. **Retrieval sem reason é bug.**
7. **Learning não altera comportamento crítico sem proposal/review.**
8. **Preferir contexto insuficiente a recuperar lixo.**

---

## 16. Riscos Centrais

| Risco | Mitigação |
|---|---|
| Autoaprimoramento virar crescimento sem qualidade | Delta Scorecard + antes/depois real |
| Propostas fracas consumirem Forge | Proposal Power Gate com 10 hard fails |
| Autopromoção quebrar regras sagradas | Invariant Lock + 8 regras duras |
| Comparação before/after medir o fácil, não o importante | 13 métricas balanceadas + human review |
| Strategy Portfolio priorizar quick wins e ignorar gargalos | 8 buckets canônicos + balanceamento |
| Memória útil virar lixo por ruído | Cognitive Immune Law + 9 Quality Scores |
| Retrieval trazer contexto fraco | Active Learning pergunta + 9 quality scores |
| Provider externo vazando segredo | Privacy Guard + 4 classes + hash-only audit |
| Completion claim promovido sem review | ResultLedger **nunca** promove; só diagnostica |
| Atlas ficar "maior mas não melhor" | Maturity Delta obrigatório para promoção |

---

## 17. Resumo em uma frase (executivo)

> O sistema de auto-aprendizado do Atlas é um **ciclo fechado de 5 estações** (Capture → Memory Core → Retrieval/Safety → Self-Improvement Domain → Closed Loop Level 7), governado por uma **lei cognitiva imune** (raw capture nunca é memória/contexto/decisão), uma **escada de 7 níveis** (observação → diagnóstico → proposta → implementação governada → verificação → promoção controlada → estratégia), **31 invariantes sagradas**, **27 invariantes de cert**, **9 contratos canônicos** de schemas, **10 outcomes** de trust ledger, **13 métricas** de delta, **16 flows** self-improvement, **12 stages** de closed loop, e **lei dura** de que **nada acontece sem intervenção humana explícita** — humano é o último gate, a primeira confiança, e a única coisa que o Atlas nunca substitui.

---

## 18. Próximas Ações Governadas (do roadmap atual)

1. **Strategy Portfolio** conectar a coleção real de Proposal Packets persistida (hoje aceita lista in-memory)
2. **Decision Receipt** do Atlas Decide nos `provider_topology_recommendation` quando o dispatcher real emitir
3. **Workflow human-review com signoff persistido** após `power_gate.outcome=human_review_required`
4. **Hook automático**: regression sentinel → `createProposal` com `source=regression_sentinel`
5. **Hook automático**: trust ledger band drop → sugere proposal `source=trust_ledger`
6. **Dashboard de rule candidates promovidos** agregando `learning_packet.new_rule_candidate`
7. **Tauri commands nativos** para mutações Level 7 (HTTP fallback continua válido)
8. **Integração Cartografia**: drill-down de `evidence_refs[]` para abrir doc/ledger event

---

## 19. Leitura Mínima para uma Sessão Nova

1. Esta nota (você está aqui)
2. `atlas-self-improvement-governance-ladder.md` (priority 102 = lei)
3. `memory/cognitive-immune-learning-kernel.md` (lei cognitiva)
4. `atlas-self-improvement-closed-loop-level7-v1.md` (loop fechado)
5. `memory/foundation-map.md` (estado atual de tudo)
6. `domains/self-improvement.md` (domínio operacional)
7. Doc dono do assunto específico quando tocar runtime

---

## 20. Cross-references (este é parte do tecido)

Esta nota substitui leitura de:

- `memory/cognitive-immune-learning-kernel.md` (393 linhas) → §2, §3
- `memory/foundation-map.md` (267 linhas) → §3
- `memory/contracts.md` (429 linhas) → §3
- `memory-core-runbook.md` (308 linhas) → §4
- `open-brain-context-injection.md` (397 linhas) → §4
- `domains/self-improvement.md` (322 linhas) → §5
- `atlas-self-improvement-governance-ladder.md` (483 linhas) → §6
- `atlas-self-improvement-closed-loop-level7-v1.md` (484 linhas) → §7

**Total condensado**: ~3.100 linhas em 1 nota de ~600 linhas, sem perder autoridade (cada claim aponta source_path).
