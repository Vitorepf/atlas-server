---
id: atlas-decide-meta-learning-loop-closure
type: engineering_knowledge
title: Atlas Decide Meta-Learning Loop Closure
status: active
implementation_state: runtime_available_shadow_gated
blocker: Automatic routing promotion remains blocked; Rivals evidence is consumed as advisory/shadow signal until operator activation and Atlas Decide policy allow it.
category: atlas-decide
priority: 84
summary: Contrato canonico para fechar o loop entre provider performance evidence, advisory decide signals/maps e routing recommendations do Atlas Decide, mantendo shadow-mode e ativacao humana.
tags: [atlas-ai, atlas-decide, provider-routing, meta-learning, governance]
capabilities: [routing_recommendation, rivals_advisory_map, shadow_mode, human_activation, provider_performance_feedback]
decisions:
  - Rivals/provider performance evidence remains advisory until Atlas Decide consumes it under explicit policy.
  - Recommendations start in shadow mode and activation requires operator confirmation.
  - Rivals decide-map can be exposed to Atlas Decide as shadow-only advisory intelligence by task category, difficulty and role.
maintenance:
  - Keep AtlasDecideMetaLearningService, activation commands, routing-table fold, advisory-map contract and tests in sync.
  - Keep external rivals certification blocked and provider routing owned by Atlas Decide.
related_paths:
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
  - docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-industrial-benchmark-suite-v1.md
  - docs/engineering-knowledge-base/atlas-forge-provider-capacity-continuity-v1.md
  - docs/engineering-knowledge-base/system-graph/atlas-decide.md
doc_schema: atlas_canonical_module_doc.v1
owner: atlas-ai
graph_id: atlas-decide-meta-learning-loop-closure
graph_title: Atlas Decide Meta-Learning Loop Closure
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-decide
graph_status: planned
graph_source: repo
repo_paths:
  - docs/engineering-knowledge-base/atlas-decide-meta-learning-loop-closure.md
depends_on: [atlas-ai-model-selection-strategy, atlas-forge-rivals-industrial-benchmark-suite-v1, atlas-forge-provider-capacity-continuity-v1]
flows_to: [atlas-ai-model-selection-strategy]
unlocks: [atlas_decide_meta_learning_recommendations, provider_routing_feedback_loop]
governs: [atlas_decide_shadow_recommendations, routing_activation_receipts]
evidence:
  - docs/engineering-knowledge-base/atlas-decide-meta-learning-loop-closure.md
  - app/Services/Ai/AtlasDecide/AtlasDecideMetaLearningService.php
  - app/Console/Commands/AtlasDecideMetaLearningCommand.php
  - tests/Unit/Ai/AtlasDecide/AtlasDecideMetaLearningServiceTest.php
  - tests/Feature/Ai/AtlasDecide/AtlasDecideMetaLearningCommandTest.php
evidence_refs:
  - symbol: AtlasDecideMetaLearningService
  - command: atlas:atlas-decide:meta-learning
  - test: AtlasDecideMetaLearningServiceTest
required_tests:
  - "php artisan atlas:engineering:knowledge docs-health --json"
  - "php artisan atlas:ai:architecture-validate --json"
  - "php artisan test --filter=AtlasDecideMetaLearning"
next_actions:
  - Expandir a leitura do advisory context nos consumers do Decision Receipt sem alterar topology automaticamente.
  - Manter comandos governados de ativacao/deativacao/reset com confirmacao humana.
  - Provar integracao end-to-end com runs reais repetidos antes de qualquer claim externo.
allowed_changes:
  - Refine the runtime contract, add evidence fields, or tighten activation gates.
forbidden_changes:
  - Claim service, commands, routing table or ACOS scorecard integration are implemented without code and tests.
  - Let Rivals or provider benchmarks change Atlas Decide topology directly.
requires_evidence: true
risk_level: high
line_limit: 520
---

# Atlas Decide — Meta-Learning Loop Closure

> **Status**: active runtime, shadow-gated
> **Authority**: ACOS · Patamar 2 (Cognitive Maturity)
> **Schema**: `atlas.atlas_decide.routing_recommendation.v1`
> **Advisory map schema**: `atlas.atlas_decide.rivals_advisory_map.v1`
> **Service**: `App\Services\Ai\AtlasDecide\AtlasDecideMetaLearningService`
> **Owner**: this doc governs runtime consumption of Rivals evidence by Atlas Decide; automatic promotion remains blocked.

## Resumo

Esta doc governa o meta-learning do Atlas Decide. Ela descreve como evidence
de provider performance pode virar recomendacao de roteamento em shadow mode e
como o `decide-map` do Rivals vira mapa consultivo por categoria, dificuldade e
role, sem deixar Rivals ou benchmarks mudarem provider topology diretamente.

## Papel no Atlas

O papel e fechar o loop entre evidence, signal projection e Atlas Decide sem
criar uma fonte paralela de decisao. Atlas Decide continua dono de provider,
modelo, fallback e receipts; este modulo apenas propoe recomendacoes governadas
para revisao/ativacao humana.

## Onde Se Encaixa

```text
Provider Performance Ledger
  -> Decide Signal Projection
  -> Decide Map Projection
  -> Atlas Decide Meta-Learning recommendation proposal
  -> Atlas Decide routing policy after operator activation
```

## Contratos

- `atlas.atlas_decide.routing_recommendation.v1`
- `atlas.atlas_decide.rivals_advisory_map.v1`
- `atlas.atlas_decide.routing_table.v1`
- `atlas.atlas_decide.routing_activation.v1`

## Fluxo

1. Ler evidence existente de provider performance.
2. Projetar recomendacao por `task_category`, `role` e `framework`.
3. Projetar mapa consultivo por `task_category`, `difficulty_level` e `role`.
4. Anexar `rivals_advisory_context` ao Decision Receipt v2 como evidencia auditavel.
5. Emitir recomendacao em `shadow` por padrao.
6. Ativar somente com operador, confirmacao e receipt.
7. Atlas Decide consome apenas entries ativas e faz fallback para policy nativa.

## Regras para IA

- Nao declarar `AtlasDecideMetaLearningService` implementado sem codigo, testes e comando real.
- Nao usar esta proposta para mudar provider topology automaticamente.
- Nao promover evidence de Rivals para autoridade de roteamento.
- Nao remover `external_rivals_certification` de blocked por causa desta doc.

## Escopo de Implementacao

Escopo implementado: service read-only de recomendacoes, advisory map
segmentado, comandos de list/inspect, comandos activate/deactivate/reset com
confirmacao, routing table derivada de receipts JSONL e testes de stale
evidence, tie, confidence, human review e invariantes advisory-only.

## Dependencias

Depende de Atlas Decide, provider performance ledger, Rivals advisory evidence,
model selection strategy, Evidence Ledger e politica de ativacao humana.

## Evidencias

Evidencia atual: service, commands, tests focados, architecture validate e
docs-health. Evidencia ainda necessaria para claims externos: runs reais
repetidos, replay/matrix verdes e certificacao humana.

## Riscos

- Confundir recomendacao com decisao de provider.
- Transformar benchmark em roteador.
- Ativar provider/model globalmente em vez de granular por tarefa.
- Declarar ACOS/scorecard pronto sem runtime.

## Exemplos

Uma categoria `frontend/builder/react` pode receber recomendacao `shadow` se a
evidence for recente e suficiente. Ela so vira routing ativo depois de comando
confirmado pelo operador e receipt de ativacao.

## Proximas Acoes

1. Implementar service read-only de recomendacoes.
2. Adicionar comandos list/inspect/activate/deactivate/reset.
3. Cobrir stale, tie, low confidence e human review em testes.
4. Integrar Atlas Decide somente depois de receipts e gates verdes.

## 1. Why this exists (the gap closed)

Atlas had three pieces in place:

1. **Provider Performance Ledger** — append-only record of every Forge Rivals/Arena run.
2. **Decide Signal Projection** — read-model emitting **advisory** signals (winner, runner-up, full_power vs fair delta).
3. **Atlas Decide** — chooses provider/model/role per task. Today it ignores the projection.

The loop was open: evidence accumulated, signal was projected, but the routing layer didn't consume it. Antifragility was **inert**.

This proposed subsystem closes the loop by turning the advisory signal into a governed routing recommendation that Atlas Decide can adopt under explicit safety gates, with shadow-mode default and operator activation.

## 2. Hard invariants (never relax)

- **No claim of benchmark / rivals / superiority** — the recommendation says "measured-best for this category", not "objectively best".
- **`external_rivals_certification` stays BLOCKED**.
- **No provider call** — service only reads existing ledger entries.
- **`auto_activate=false` by default** — every recommendation starts in `shadow` mode; operator runs `--activate` per (task_category, role) pair.
- **Confidence floor** — recommendations below `medium` confidence are emitted but flagged `not_actionable`.
- **Stale data veto** — if `latest_age_days > 14`, recommendation is marked `stale_evidence`.
- **Tie / human-review** — any signal returning `human_review_required` produces a recommendation with `requires_human_review=true` and no routing change.
- **Per-task-category granularity** — routing is per `(task_category, role)` tuple; never global override.

## 3. Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│  Provider Performance Ledger (append-only, local)                │
│    entries by (provider, model, role, task_category, mode)       │
└────────────────────┬─────────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│  Decide Signal Projection (advisory)                             │
│    signal: ok | insufficient | human_review                      │
│    top_measured_provider/model · confidence · delta              │
└────────────────────┬─────────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│  AtlasDecideMetaLearningService  ← PROPOSED SUBSYSTEM            │
│    recommendations[]                                             │
│      task_category · role · recommended_provider · model         │
│      mode (shadow|active) · confidence · stale_evidence          │
│      requires_human_review · reason[]                            │
│    routing_table  (only entries with mode=active)                │
└────────────────────┬─────────────────────────────────────────────┘
                     │
                     ▼
┌──────────────────────────────────────────────────────────────────┐
│  Atlas Decide (consumer — separate subsystem)                    │
│    consults routing_table per (task_category, role)              │
│    falls back to its own policy when entry missing or shadow     │
└──────────────────────────────────────────────────────────────────┘
```

## 4. Schemas

### 4.1 `atlas.atlas_decide.routing_recommendation.v1`

```json
{
  "schema_version": "atlas.atlas_decide.routing_recommendation.v1",
  "generated_at": "ISO-8601",
  "scope": {
    "task_category": "frontend",
    "role": "builder",
    "framework": "react|laravel|null"
  },
  "signal": "ok | insufficient_evidence | human_review_required",
  "confidence": "high | medium | low | insufficient_evidence",
  "evidence_count": 12,
  "stale_evidence": false,
  "latest_age_days": 3,
  "recommended_provider": "claude_code",
  "recommended_model": "claude-opus-4-7",
  "runner_up_provider": "codex_cli",
  "runner_up_model": "gpt-5-codex",
  "delta": 6.2,
  "use_full_power": true,
  "mode": "shadow | active",
  "actionable": true,
  "requires_human_review": false,
  "reason": ["evidence_strong", "delta_above_threshold"],
  "rationale": "free-text human summary",
  "recommendation_hash": "sha256:..."
}
```

### 4.2 `atlas.atlas_decide.routing_table.v1`

### 4.2 `atlas.atlas_decide.rivals_advisory_map.v1`

Read-only map emitted by `AtlasDecideMetaLearningService::rivalsAdvisoryMap()`
and `atlas:atlas-decide:meta-learning --map --json`. It is the bulk advisory
view Atlas Decide can inspect before issuing its own Decision Receipt.

```json
{
  "schema_version": "atlas.atlas_decide.rivals_advisory_map.v1",
  "source_schema_version": "atlas.forge.rivals.decide_model_intelligence_map.v1",
  "segment_count": 2,
  "segments": [
    {
      "scope": {
        "task_category": "backend",
        "difficulty_level": "L5",
        "role": "builder"
      },
      "recommended_provider": "anthropic_claude",
      "recommended_model": "claude_opus",
      "average_score": 92.0,
      "confidence": "low|medium|high",
      "decision_readiness": "directional_signal|explore_before_prefer|strong_directional_signal",
      "activation_mode": "shadow",
      "actionable_for_auto_routing": false,
      "advisory_only": true,
      "should_update_provider_topology": false,
      "never_changes_atlas_decide_topology": true,
      "owner_of_model_routing": "atlas_decide",
      "routing_effect": "none"
    }
  ],
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "canonical_phrase": "Rivals emits measured evidence; Atlas Decide decides model routing.",
  "advisory_map_hash": "sha256:..."
}
```

This map never activates a route. It gives Atlas Decide evidence-rich context
for "qual modelo e melhor para que" while keeping topology, fallback and final
model routing inside Atlas Decide.

### 4.3 `atlas.decide.rivals_advisory_context.v1`

Decision Receipt v2 embeds a compact `rivals_advisory_context` in both
`provider_selection.selection_explanation.rivals_advisory` and
`metadata.rivals_advisory_context`. This keeps the measured evidence close to
the provider/model decision without letting it mutate the decision.
Persisted `AiDecisionResource` responses also expose the same context as
`rivals_advisory_context` for snake_case clients and `rivalsAdvisoryContext`
for Atlas Code/Desktop consumers.

Required invariants:

- `advisory_only=true`
- `selection_changed_by_rivals=false`
- `actionable_for_auto_routing=false`
- `activation_mode=shadow`
- `should_update_provider_topology=false`
- `never_changes_atlas_decide_topology=true`
- `owner_of_model_routing=atlas_decide`
- `routing_effect=none`
- `external_provider_call=false`
- `provider_tokens_spent=false`

Canonical phrase: **Rivals emits measured evidence; Atlas Decide decides model routing.**

### 4.4 `atlas.atlas_decide.routing_table.v1`

```json
{
  "schema_version": "atlas.atlas_decide.routing_table.v1",
  "generated_at": "ISO-8601",
  "active_entries": 3,
  "shadow_entries": 14,
  "entries": [
    {
      "task_category": "frontend",
      "role": "builder",
      "framework": null,
      "provider": "claude_code",
      "model": "claude-opus-4-7",
      "mode": "active",
      "activated_at": "ISO-8601",
      "activated_by": "operator"
    }
  ],
  "table_hash": "sha256:..."
}
```

### 4.5 `atlas.atlas_decide.routing_activation.v1` (audit receipt)

```json
{
  "schema_version": "atlas.atlas_decide.routing_activation.v1",
  "action": "activate | deactivate | reset",
  "task_category": "...",
  "role": "...",
  "framework": "...",
  "previous_mode": "shadow",
  "new_mode": "active",
  "actor": "operator",
  "at": "ISO-8601",
  "recommendation_hash_at_activation": "sha256:..."
}
```

## 5. Persistence

Routing table is stored as **append-only JSONL receipts** under
`storage/atlas/atlas_decide/routing_activations.jsonl`. The runtime table is the
**deterministic fold** of those receipts (latest action per scope wins). No
database table required — keeps it local-first and audit-able.

Recommendations are **derived on-demand** from the ledger; they are not
persisted, but every consultation hash is recorded in the activation receipt
for traceability.

## 6. Confidence model

Confidence inherits from `AtlasForgeRivalsProviderPerformanceLedgerService::confidenceFor()`:

| evidence_count | confidence | actionable |
|---|---|---|
| ≥ 6 | high | yes |
| 3–5 | medium | yes |
| 1–2 | low | no (shadow only) |
| 0 | insufficient_evidence | no |

Additional gates:
- `latest_age_days > 14` → `stale_evidence=true`, `actionable=false`.
- `delta < 3.0` (close-race) → `runner_up_in_play=true`, `mode=shadow` (no auto-activate).
- Any `human_review_required` signal → `requires_human_review=true`, `actionable=false`.

## 7. Operator workflow

```bash
# 1. See current recommendations across all known (task_category, role) pairs
php artisan atlas:atlas-decide:meta-learning --json

# 2. Inspect a specific scope
php artisan atlas:atlas-decide:meta-learning \
  --task-category=frontend --role=builder --framework=react

# 3. Activate one recommendation (Doctor 3-Tier: --confirm + --check)
php artisan atlas:atlas-decide:meta-learning:activate \
  --task-category=frontend --role=builder \
  --check=<code> --confirm

# 4. View the active routing table
php artisan atlas:atlas-decide:routing-table --json

# 5. Deactivate (rollback to Atlas Decide native policy)
php artisan atlas:atlas-decide:meta-learning:deactivate \
  --task-category=frontend --role=builder \
  --check=<code> --confirm
```

## 8. Safety: rollback path

A single operator command resets the entire routing table back to native Atlas
Decide policy:

```bash
php artisan atlas:atlas-decide:meta-learning:reset --check=<code> --confirm
```

This appends a `reset` receipt; existing ledger and recommendations are
untouched.

## 9. What this subsystem does NOT do

- Does NOT call providers.
- Does NOT modify Atlas Decide's internal policy code.
- Does NOT auto-activate anything.
- Does NOT cross task-category boundaries (no global "best provider").
- Does NOT touch `external_rivals_certification`.
- Does NOT score or rank — it consumes pre-scored ledger entries.

## 10. Test coverage requirements

- Unit: recommendation construction, confidence inheritance, stale veto, tie veto, human-review propagation, hash determinism.
- Feature: artisan list, single-scope inspect, activate/deactivate/reset round-trip, routing table fold determinism.
- Real-fixture: tests use real `AtlasForgeRivalsProviderPerformanceLedgerService` (in-memory ledger path), no provider mocks.

## 11. ACOS scorecard integration

When implemented, this subsystem should be registered in
`AtlasCognitionScoreCardService::SUBSYSTEMS` as:

```
['ADML', 'Atlas Decide Meta-Learning', 'atlas_decide',
 AtlasDecideMetaLearningService::class, 'ready', 'ready', '—']
```

Do not update ACOS subsystem count until code and tests prove the runtime.

## 12. Future evolution hooks

- **Per-domain weights** — multiply confidence by domain criticality (cyber > marketing).
- **Decay function** — exponential weight decay on evidence age beyond 14 days.
- **Counterfactual integration** — when TEOS-I3 ships, compare "what would have happened if we kept native routing" as audit signal.
- **Auto-activate proposal** — service can propose activations for operator review (still requires --confirm).
