# 📝 Nota — Sistema de Auto-Aprendizado do Atlas

> **TL;DR.** O Atlas aprende capturando muito, acreditando em pouco. Tudo nasce em quarentena cognitiva (raw capture nunca é memória/contexto/decisão), só vira canônico via 9 promotion gates + Cognitive Immune Law. Memória canônica tem 11 tipos / 6 escopos / 8 estados e passa por Composer determinístico antes de virar Context Pack provider-safe. Self-Improvement Domain roda 16 flows que **proõem** mudanças (nunca aplicam); 7 níveis de ladder decidem se a proposta pode virar Obra; Level 7 fecha o loop com 12 stages, 13 métricas de delta e 31 invariantes. **Humano é o último gate, sempre.** Nada acontece sem review.

---

## 1. Lei Mestra (se esquecer de tudo, lembre disto)

```
Raw Capture ≠ Evidence ≠ Learning Signal ≠ Memory ≠ Context ≠ Decision
```

Toda entrada nasce com **flags fechadas** (`memory_eligible=false`, `context_eligible=false`, `embedding_allowed=false`, `promotion_status=unclassified`) e só sobe de camada passando gates **G0..G8** explícitos.

---

## 2. O Loop (5 Estações)

```
CAPTURE (Cognitive Immune)        →  filtra ruido, aplica 9 gates, grava immune_audit v1
   ↓
MEMORY CORE (Postgres)            →  11 tipos × 6 escopos × 8 estados; 3 fontes (registry/verbatim/semantic)
   ↓                                Composer determinístico: score = priority + importance*10 + confidence*10
                                      + hybrid_score*30 + scopeWeight + typeWeight
RETRIEVAL & OPEN BRAIN            →  AiContextPackBuilder + OpenBrainContextInjectionService (1262 linhas)
   ↓                                5 status: injected | skipped | degraded | failed_open | failed_closed
                                     3 policy modes: off | auto | required
SELF-IMPROVEMENT DOMAIN (16 flows) →  Proposes only. Nunca aplica. Nunca chama provider externo.
   ↓                                night_review, weekly_architecture_audit, capability_gap_scan,
                                     benchmark_review, memory_quality_review, tool_runtime_review,
                                     repair_loop_review, kernel_pipeline_review, domain_learning_review,
                                     docs_drift_review, provider_release_review, provider_performance_review,
                                     agent_behavior_review, voice_realtime_review, failure_pattern_review,
                                     improvement_proposal_generation
CLOSED LOOP LEVEL 7                →  12 stages: proposal_captured → power_gate_evaluated → human_approved
                                     → activation_created → obra_created → forge_executed → evidence_collected
                                     → human_reviewed → delta_measured → trust_updated → learning_recorded
                                     → next_cycle_recommended
```

---

## 3. Números-Chave (memorize esses)

| O quê | Quanto | Onde |
|---|---|---|
| **Promotion gates** | **9** (G0..G8) | `cognitive-immune-learning-kernel.md` |
| **Input classes** | 11 | `cognitive-immune-learning-kernel.md` |
| **Memory types** | 11 canônicos | `atlas_memory_entries.memory_type` |
| **Memory scopes** | 6 (global/project/task/run/workspace/user/session) | `atlas_memory_entries.scope_type` |
| **Memory states** | 8 (candidate → trusted → conflicted → stale → deprecated → archived → blocked_private → tombstoned) | Cognitive Immune Law |
| **Conflict verbs** | 6 (related, compatible, scoped, conflicts_with, supersedes, not_conflict) | `AtlasMemoryConflictResolutionService` |
| **Quality scores** | 9 (não usar só `confidence`) | Cognitive Immune Law |
| **Self-Improvement flows** | **16** | `atlas:ai:self-improve --list-flows` |
| **Governance Ladder níveis** | **7** (observação → estratégia) | `atlas-self-improvement-governance-ladder.md` |
| **Delta métricas** | **13** (pesos somam 100) | `AtlasSelfImprovementDeltaScorecardService` |
| **Closed Loop stages** | **12** | `atlas-self-improvement-closed-loop-level7-v1.md` |
| **Governance Cert invariantes** | **27** | `atlas.self_improvement.governance_certification.v1` |
| **Level 7 Cert invariantes** | **31** | `atlas.self_improvement.closed_loop_level7_certification.v1` |
| **Trust Ledger outcomes** | 10 + 5 self_improvement | `AtlasSelfImprovementHumanTrustLedgerService` |
| **Memory quality snapshots** | tabelas + scorecard longitudinal | `atlas_memory_quality_snapshots` |
| **Tables principais** | 8 (atlas_memory_entries, atlas_verbatim_memories, atlas_memory_entry_usages, atlas_memory_entry_relations, atlas_open_brain_access_logs, atlas_memory_quality_snapshots, ai_memory_deltas, atlas_memory_provider_projection_audits) | migrations `2026_05_*` |
| **Services PHP principais** | 17+ em `app/Services/Ai/` | ver glossário no diagrama anterior |

---

## 4. Os 7 Níveis (resumo de uma linha cada)

| N | Nome | Pergunta | Pode mudar código? |
|---|---|---|---|
| 0 | Auto-Observação | O que está acontecendo? | Não |
| 1 | Auto-Diagnóstico | Por que importa? | Não |
| 2 | Auto-Proposta | O que merece virar Obra? | Não |
| 3 | Auto-Implementação Governada | Consigo construir com segurança? | Sim, em worktree/sandbox |
| 4 | Auto-Verificação / Rivals | Melhorou de verdade? | Não promove |
| 5 | Auto-Promoção Controlada | Pode incorporar ao Atlas? | Sim, **se policy permitir** |
| 6 | Auto-Estratégia | Para onde evoluir? | Cria portfolio, **não bypassa gates** |

**Cumulativos:** N5 não existe sem 0-4 completos. N6 não é permissão para alterar regras sagradas.

---

## 5. O Que Está Proposal-Only (intencional, **não** tentar ligar)

🔒 ChromaDB · 🔒 Graph RAG/Python · 🔒 Streamable HTTP/SSE · 🔒 Multiuser sync · 🔒 Provider-owned memory merge · 🔒 Autonomous memory promotion · 🔒 External embeddings · 🔒 Synthetic scores como evidence · 🔒 Backlog criar Obra silenciosa · 🔒 Next-cycle persistir proposta · 🔒 External rivals certification.

---

## 6. As 8 Regras Invioláveis

1. Raw capture **nunca** entra direto no Context Builder
2. Chat transcript **nunca** vira memória silenciosamente
3. Archive **não** significa memory-approved
4. Delete propaga para memória, embeddings, caches e Constelação
5. Toda memória tem escopo, fonte, estado, motivo
6. Retrieval sem `reason` é bug
7. Learning **não** altera comportamento crítico sem proposal/review
8. **Preferir contexto insuficiente a recuperar lixo**

---

## 7. CLIs Essenciais (cole no seu `bin/atlas` muscle memory)

```bash
# Loop fechado (Level 7)
atlas:self-improvement:proposal-backlog    # CRUD backlog (12 status, 8 sources)
atlas:self-improvement:closed-loop         # projection 12 stages
atlas:self-improvement:measure-result      # before/after + learning + trust
atlas:self-improvement:next-cycle          # recomendação draft

# Governance Ladder
atlas:self-improvement:proposal-gate       # Power Gate (10 hard fails)
atlas:self-improvement:before-after        # Delta Scorecard 13 metrics
atlas:self-improvement:invariant-lock      # 8 regras sagradas
atlas:self-improvement:regression-sentinel # regressões ocultas
atlas:self-improvement:maturity-score      # 0..10 capability ladder
atlas:self-improvement:trust-ledger        # 10 outcomes

# Self-Improvement Runtime
atlas:ai:self-improve                      # 16 flows
atlas:ai:self-improve --list-flows --json
atlas:ai:self-improve --schedule-plan --json
atlas:ai:self-improve --flow=... --plan-only --json

# Memory
atlas:memory:recall                       # hybrid recall 3 fontes
atlas:memory:quality                       # scorecard + retrieval_eval
atlas:memory:maintain                      # sync + index + projection + open-brain health

# Local RAG
atlas:ai:local-rag-benchmark               # memory_recall_corpus + golden-set + rivals
atlas:ai:local-rag-benchmark --record-memory-quality

# Capture pipeline
atlas:ai:capture-inbox-pipeline-report                 # read-only
atlas:ai:capture-inbox-pipeline-backfill-contracts     # dry-run default
```

---

## 8. North Star (em 1 frase cada)

- **Memória**: "capturar muito, acreditar em pouco, promover com evidência, recuperar com precisão, esquecer com disciplina"
- **Self-Improvement**: "provar que o Atlas novo é melhor que o anterior, com evidência, rollback, gates e limites de autonomia"
- **Trust Ledger**: "reduzir intervenção humana sem reduzir confiança"
- **Resultado final**: **nada acontece sem intervenção humana explícita**

---

## 9. Onde Aprofundar (em ordem de leitura)

1. `atlas-self-improvement-governance-ladder.md` — **lei** (priority 102)
2. `memory/cognitive-immune-learning-kernel.md` — lei cognitiva
3. `atlas-self-improvement-closed-loop-level7-v1.md` — loop fechado
4. `memory/foundation-map.md` — estado atual de tudo
5. `domains/self-improvement.md` — domínio operacional
6. Diagrama visual: `memory/diagrams/atlas-memory-architecture.md`/`.html`
7. Nota completa (3.100 linhas condensadas): `memory/diagrams/atlas-self-learning-note.md`

---

**Próxima ação governada que vale saber**: Strategy Portfolio ainda aceita lista in-memory de Proposal Packets; a próxima iteração conecta a coleção real persistida. **Hoje:** tudo é humano-first, proposta-first, evidência-first, gate-first.
