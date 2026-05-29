---
id: atlas-forge-rivals-provider-performance-ledger-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Provider Performance Ledger v1
status: active
category: architecture
priority: 80
summary: Append-only local ledger + decide-signal/decide-map projection that turns Rivals scorecards into Atlas-Decide-grade measured evidence by provider, model, role, task category and difficulty.
tags:
  - atlas-forge
  - rivals
  - provider-performance-ledger
  - atlas-decide
  - audit
  - certification
capabilities:
  - provider_performance_ledger
  - decide_signal_projection
  - decide_model_intelligence_map
  - category_difficulty_role_model_aggregate
  - statistical_repeat_readiness
  - cost_quality_frontier
  - fair_vs_full_power_delta
  - atlas_forge_vs_raw_provider_delta
  - aggregated_evidence_for_atlas_decide
decisions:
  - The ledger is read-side intelligence on top of adjudicator scorecards; it never calls a provider.
  - Atlas Decide consumes decide-signal and decide-map as advisory input only — the ledger never claims authority.
  - Hard-failed runs are recorded as invalid negative signal but excluded from rankings and the cost/quality frontier.
  - external_rivals_certification stays blocked forever as far as this ledger is concerned.
maintenance:
  - When the adjudicator schema changes, update the entry mapping and the docs section that lists the canonical scorecard fields.
  - Keep the nine-invariant certification in sync with the service contract; never relax an invariant without a follow-up doc.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-intelligence-ledger-v1.md
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-provider-performance-ledger-v1
graph_title: Atlas Forge Rivals · Provider Performance Ledger v1
graph_world: atlas
graph_layer: system
graph_kind: module
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
human_name: "Atlas Forge Rivals · Provider Performance Ledger v1"
canonical_name: "Atlas Forge Rivals · Provider Performance Ledger v1"
technical_name: atlas-forge-rivals-provider-performance-ledger-v1
cartography_type: module
canonical_source: docs/engineering-knowledge-base/atlas-forge-rivals-provider-performance-ledger-v1.md
owner: programming

repo_paths:
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsDecideSignalProjectionService.php
  - app/Services/Ai/Kernel/Architecture/AtlasForgeRivalsProviderPerformanceLedgerCertification.php

allowed_changes:
  - Atualizar quando o adjudicator emitir novos campos no scorecard ou quando os thresholds de confidence/stale precisarem evoluir.
  - Manter os 9 invariantes em sincronia com o contrato exposto na cert.
forbidden_changes:
  - Permitir que o ledger ou o decide-signal chamem provider externo, gastem token, destravem external_rivals_certification, ou promovam claim_ready=true.
  - Ranquear runs com hard_failures em qualquer agregado ou no cost_quality_frontier.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
  - atlas-forge-rivals-operator-battery-v2
flows_to:
  - atlas-decide
unlocks:
  - provider-performance-ranking-by-task-category-and-role
governs:
  - rivals_provider_performance_intelligence
evidence:
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsProviderPerformanceLedgerServiceTest.php
  - tests/Feature/Ai/Programming/AtlasForgeRivalsProviderPerformanceLedgerCertificationTest.php
  - "php artisan atlas:forge:rivals audit --json"
  - "php artisan atlas:forge:rivals ledger --json"
  - "php artisan atlas:forge:rivals decide-signal --task-category=frontend --role=builder --json"
  - "php artisan atlas:forge:rivals decide-map --json"

required_tests:
  - "php artisan test --filter='ProviderPerformanceLedger'"
  - "php artisan atlas:forge:rivals audit --json"
requires_evidence: true
risk_level: medium
next_actions:
  - Conectar atlas:decide para consumir decide-signal e decide-map como input advisory.
  - Evoluir snapshot para Intelligence Ledger historico segmentado com intervalo de confianca completo.
  - Adicionar retention/compaction ao entries.jsonl quando volume justificar.
  - Surface UI Atlas Code Premium (ranking, provider cards, cost/quality scatter).
---
# Atlas Forge Rivals · Provider Performance Ledger v1

Strategy canon: `atlas-forge-rivals-benchmark-strategy-v1.md`
Status: **available** (delivered 2026-05-15)
Schema: `atlas.forge.rivals.provider_performance_ledger.v1`
Entry schema: `atlas.forge.rivals.provider_performance_ledger_entry.v1`
Decide signal schema: `atlas.forge.rivals.decide_signal.v1`
Decide map schema: `atlas.forge.rivals.decide_model_intelligence_map.v1`
Certification: `atlas_forge_rivals_provider_performance_ledger_certification`
Canonical commands:

```bash
php artisan atlas:forge:rivals ledger --json --strict
php artisan atlas:forge:rivals ledger-record --run-id=<id> --task-category=<cat> --role=<role> --json --strict
php artisan atlas:forge:rivals decide-signal --task-category=<cat> --role=<role> --difficulty=L5 --json --strict
php artisan atlas:forge:rivals decide-map --json --strict
php artisan atlas:forge:rivals audit --json --strict
```

## Motivation

A naive global "winner" ranking across Claude Code, Codex, Gemini, and Atlas
Forge is misleading: each provider is strong in different regimes (frontend
vs bugfix, builder vs reviewer, fair vs full_power, framework X vs Y). The
Provider Performance Ledger is the canonical substrate that lets the
**Atlas Decide** layer learn which provider/model/role/strategy is best for
**a specific situation** based on **real** local evidence — without ever
calling a provider itself.

The ledger is **read-side intelligence** on top of the existing
adjudicator's scorecards. The Rivals pipeline already produces:

- `evidence/manifest.json`
- `evidence/scorecard.json` (`atlas.forge.rivals.adjudication.v1`)
- `evidence/evidence_pack.json`
- `evidence/atlas_receipt.json` / `evidence/rival_receipt.json`

The ledger absorbs these into an append-only feed and computes aggregates
that downstream `atlas:decide` can consume as **advisory** signal.

## Hard safety contract

The ledger NEVER:

- calls an external provider (zero token spend);
- unlocks `external_rivals_certification`;
- promotes a completion claim (`claim_ready` always `false`);
- masks a hard-failed run as a win;
- overwrites prior entries (append-only by `entry_id`);
- ranks invalid entries (hard failures excluded from aggregates and the
  `cost_quality_frontier`);
- decides anything as final authority — `decide-signal` and `decide-map` are
  **advisory only**.

## Architecture

```
+----------------------+        +--------------------------+
|  atlas:forge:rivals  | -----> |  ProviderPerformance      |
|  ledger-record       |        |  LedgerService            |
|  ledger              |        |  - record(scorecard)      |
|  decide-signal       |        |  - snapshot(+filters)     |
|  decide-map          |        |  - segment intelligence   |
+----------+-----------+        |  - loadEntries()          |
           |                    +-------------+------------+
           |                                  |
           v                                  v
+----------------------+        +--------------------------+
|  DecideSignal         | <---- |  ledger/entries.jsonl    |
|  ProjectionService    |       |  ledger/entries/<id>.json|
|  - project(filters)   |       |  (append-only)           |
|  - map(filters)       |       |                          |
+----------------------+        +--------------------------+
```

### Services

- `App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsProviderPerformanceLedgerService`
- `App\Services\Ai\Programming\ForgeRivals\AtlasForgeRivalsDecideSignalProjectionService`

### Certification

- `App\Services\Ai\Kernel\Architecture\AtlasForgeRivalsProviderPerformanceLedgerCertification`
  exposed in the `atlas:forge:rivals audit --json` payload alongside the
  existing Operator Battery, Perfect Battery, and Provider Arena Core
  certifications.

### Storage layout

By default the ledger persists to
`storage/app/rivals-forge-ledger/` with this layout:

```
storage/app/rivals-forge-ledger/
├── entries.jsonl              # append-only one-line-per-entry index
└── entries/
    ├── <run_id>-<arm>-<short_evidence_hash>-<micro>.json
    └── ...
```

The root is overridable via the Laravel config key
`atlas_rivals.ledger_root` (string path), e.g. for tests:

```php
config(['atlas_rivals.ledger_root' => $tmp]);
```

## Entry schema (`atlas.forge.rivals.provider_performance_ledger_entry.v1`)

Each run produces **two** entries (one per arm — `atlas` and `rival`). The
canonical fields:

```jsonc
{
  "schema_version": "atlas.forge.rivals.provider_performance_ledger_entry.v1",
  "entry_id": "<run_id>-<arm>-<evidence_hash_12>-<microsuffix>",
  "recorded_at": "2026-05-15T12:00:00+00:00",
  "run_id": "<run_id>",
  "battery_id": "<battery_id|run_id>",
  "arena_run_id": "<arena_run_id|run_id>",
  "case_id": "<case_id>",
  "task_id": "<task_id>",
  "case_source": "release|deepswe|quick|...",
  "arm": "atlas|rival",
  "arm_id": "atlas:anthropic_claude:claude_sonnet:fair",
  "runner_type": "atlas_forge|raw_provider",
  "provider": "anthropic_claude|openai_codex|openai_gpt|google_gemini|atlas_decide|unknown",
  "model": "claude_sonnet|claude_opus|codex|auto|...",
  "task_category": "frontend|backend|bugfix|refactor|feature|test|docs|devops|security|unknown",
  "difficulty_level": "L1|L2|L3|L4|L5|null",
  "difficulty_weight": 2.5,
  "role": "builder|reviewer|repair_agent|context_scout|test_generator|architect|docs",
  "framework": "react|laravel|...|null",
  "mode": "fair|full_power|diagnostic|replay_only|local_fake|...",
  "run_family": "<battery|experiment|external_round>",
  "prompt_mode": "spec-perfect|human-normal|messy-real|enterprise-change|null",
  "preset": "smoke|quick|release|full",
  "score_total": 78.42,                // null when hard-failed
  "scores_by_dimension": {              // per adjudicator dimension, this arm only
    "patch_focus": 92.0,
    "scope_discipline": 100.0,
    "...": 0.0
  },
  "winner": "atlas|rival|human_review_required_tie|null",
  "outcome": "winner|loser|human_review_required|invalid",
  "human_review_required": false,
  "hard_failures": [],                  // populated from scorecard
  "tests_passed": true,
  "replay_passed": true,
  "scope_violations": 0,
  "intervention_count": 0,
  "duration_ms": 60000,
  "cost_estimate": 0.01,                // from receipt token_cost (may be null)
  "tokens_used": 100,
  "evidence_pack_hash": "<sha256>",
  "adjudication_hash": "<sha256>",
  "valid_for_ranking": true,            // false when hard_failures || score null
  "atlas_decide_learning_eligible": true,
  "atlas_decide_learning_blockers": [],
  "claim_ready": false,                 // always false (ledger never claims)
  "separated_from_external_rivals_certification": true,
  "external_provider_call": false,
  "provider_tokens_spent": false
}
```

The `evidence_pack_hash` is the `sha256` of
`manifest|atlas_receipt|rival_receipt|workspace_hashes` SHAs joined with `|`.
Records lacking this hash are rejected with `evidence_hash_required`.

`valid_for_ranking` and `atlas_decide_learning_eligible` are deliberately
separate. A replayed score can remain in the ledger/ranking while still being
blocked for Atlas Decide learning if it lacks dimensions required to answer
"which model is better for what": provider, model, task category, difficulty
L1-L5 and role. Missing dimensions populate
`atlas_decide_learning_blockers`; the snapshot aggregate
`atlas_decide_learning_eligibility` counts eligible/ineligible entries and
keeps the output advisory-only with `routing_effect=none`.

The Decide projection may still surface unresolved rows in repair views, but
unresolved provider/model rows must not become model profiles, shadow policy
candidates or preference candidates. They remain metadata repair work until a
trusted provider receipt, manifest or model registry alias proves the real
provider/model.

## Roles taxonomy

```
builder | reviewer | repair_agent | context_scout |
test_generator | architect | docs
```

These map to the seven operator roles a provider can fulfil. A provider can
be measured separately per role so we learn, e.g., that Claude Opus is a
strong `reviewer` even when it ranks mid as a `builder`.

## Task categories taxonomy

```
frontend | backend | bugfix | refactor | feature |
test | docs | devops | security | unknown
```

Any unknown label is normalized to `unknown` rather than rejected, so the
ledger can absorb future categories without breaking persistence.

## Aggregates

`atlas:forge:rivals ledger --json` returns the live ledger plus aggregates:

- `by_task_category`: category summary with score, sample, confidence,
  freshness, provider/model set and latest run ids.
- `by_task_category_difficulty_role_model`: ranked summary per
  category+difficulty+role+provider+model bucket. This is the core external
  benchmark view for answering "which model is better for this kind of task?"
  without collapsing L1-L5 or roles into one global winner.
- `by_run_family_prompt_task_category_difficulty_role_model`: strict readiness
  segment; does not mix benchmark families or prompt modes.
- `by_role`: same structure keyed by role.
- `by_provider_model`: same structure keyed by `provider:model`.
- `by_framework`: only entries that surfaced a `framework`.
- `atlas_forge_vs_raw_provider_delta`: average score delta between
  `runner_type=atlas_forge` and `runner_type=raw_provider`.
- `fair_vs_full_power_delta`: average score delta between `full_power` and
  `fair` modes.
- `cost_quality_frontier`: per-(provider, model, mode) average cost vs
  average score, sorted score desc / cost asc. **Invalid entries are
  excluded** so a hard-failed run can never appear as a cost win.
- `statistical_repeat_readiness`: fail-closed strong-confidence gate. A bucket
  needs three valid entries and stable variance for the same
  run_family+prompt_mode+category+difficulty+role+provider+model segment.

### Confidence model

| evidence_count | confidence              |
|----------------|-------------------------|
| 0              | `insufficient_evidence` |
| 1–2            | `low`                   |
| 3–5            | `medium`                |
| ≥ 6            | `high`                  |

`stale_data` is `true` when the latest recorded entry in the bucket is
older than 14 days. Both signals drive the `should_explore_alternative`
flag in the projection.
Segment rows also expose median, stddev, 95% interval, stability, avg cost/tokens/duration and cost per score point.

## Decide signal (`atlas.forge.rivals.decide_signal.v1`)

`atlas:forge:rivals decide-signal --task-category=X --role=Y [--framework=Z] [--difficulty=L5] [--prompt-mode=human-normal] [--run-family=<id>]`
returns an **advisory measured signal**. It never chooses a route or updates provider topology; Atlas Decide remains the owner of model routing.

```jsonc
{
  "schema_version": "atlas.forge.rivals.decide_signal.v1",
  "signal": "ok|insufficient_evidence|human_review_required",
  "task_category": "frontend",
  "role": "builder",
  "framework": null,
  "difficulty_level": "L5",
  "top_measured_provider": "anthropic_claude",
  "top_measured_model": "claude_sonnet",
  "top_measured_average_score": 78.4,
  "evidence_count": 12,
  "confidence": "high",
  "alternative_measured_candidate": {
    "provider": "openai_codex",
    "model": "codex",
    "average_score": 75.9,
    "sample_size": 8,
    "confidence": "high"
  },
  "latest_run_ids": ["<id>", "..."],
  "latest_recorded_at": "2026-05-15T12:00:00+00:00",
  "latest_age_days": 1,
  "stale_data": false,
  "invalid_entries_seen": 0,
  "tie_entries_seen": 0,
  "should_explore_alternative": false,
  "should_use_full_power": true,
  "should_require_human_review": false,
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none",
  "note": "Rivals emits measured evidence; Atlas Decide decides model routing.",
  "separated_from_external_rivals_certification": true
}
```

Decision rules:

- `signal=insufficient_evidence` when no valid entry matches
  (task_category, role) — `top_measured_*` are `null`.
- `signal=human_review_required` when invalid or tie entries exist AND
  evidence_count is below `CONFIDENCE_MEDIUM_THRESHOLD`.
- `should_explore_alternative=true` when the runner-up gap is below
  `CLOSE_RACE_GAP` (4.0), sample is low/stale/unstable, or runner-up is much
  cheaper without a material score gap; `decision_readiness` remains advisory.
- `should_use_full_power=true` when both `fair` and `full_power` samples
  exist AND the full_power average exceeds the fair average by at least
  `FULL_POWER_WORTH_IT_DELTA` (3.0).

The Decide signal is **never** authoritative — Atlas Decide may choose to
consume it, ignore it, or escalate to a human. The projection only emits
measured evidence and always reports `routing_effect=none`.

## Decide map (`atlas.forge.rivals.decide_model_intelligence_map.v1`)

`atlas:forge:rivals decide-map --json` returns the broad model intelligence
map for Atlas Decide. It groups valid ledger aggregates by `task_category`,
`difficulty_level` and `role`, then ranks provider/model candidates inside
each segment by measured average score, sample count, cost, duration, tokens,
score stability and statistical-repeat readiness.

The map answers: "for this task category, difficulty and role, which measured
provider/model currently looks best?" It still never chooses a route or updates
provider topology. Every payload and segment preserves:

```json
{
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none",
  "claim_ready": false,
  "external_claim_allowed": false,
  "external_provider_call": false,
  "provider_tokens_spent": false,
  "canonical_phrase": "Rivals emits measured evidence; Atlas Decide decides model routing."
}
```

`decide-map` excludes invalid entries from ranking and emits
`signal=insufficient_evidence` when no valid segment exists. It is the preferred
bulk advisory input for Atlas Decide; `decide-signal` remains the focused query
for one specific `(task_category, role, difficulty)` situation.

If a segment's top row has `provider=unknown` or `model=unknown`, the map keeps
the evidence auditable but marks the segment
`candidate_resolution_status=blocked_unresolved_provider_model` and
`next_action=repair_provider_model_metadata`. That segment is intentionally
absent from `model_profiles`, `model_usage_playbook`,
`shadow_policy_candidates` and `preference_candidates`.

## Recording an entry

```bash
php artisan atlas:forge:rivals ledger-record \
  --run-id=<id> \
  --task-category=frontend \
  --role=builder \
  --framework=react \
  --json --strict
```

The recorder:

1. Resolves the run's canonical paths via `AtlasForgeRivalsRunPathResolver`.
2. Reads `manifest.json`, `scorecard.json`, `evidence_pack.json`.
3. Rejects missing artifacts with `manifest_missing`, `scorecard_missing` or
   `evidence_pack_missing`.
4. Resolves `task_category`/`role` from CLI override → manifest → scorecard;
   missing values trigger `task_category_required` or `role_required`.
5. Computes the evidence pack hash. Missing hash → `evidence_hash_required`.
6. Rejects `replay_passes!=true`, missing evidence/artifacts or hash drift.
7. Emits one entry per arm with `valid_for_ranking` true ⇔ replay passed, no
   hard failures and score is non-null.
8. Persists each entry to `entries/<entry_id>.json` AND appends to
   `entries.jsonl`. **Nothing is overwritten** — re-recording the same
   run yields fresh entries that preserve history.

## Audit invariants (9 total)

The certification verifies every invariant by introspecting source files
and class existence — same pattern as Perfect Battery v1:

| # | Invariant                                | What it proves                                                                                  |
|---|------------------------------------------|-------------------------------------------------------------------------------------------------|
| 1 | `ledger_available`                       | `ledger` + `ledger-record` actions wired + service class loads                                  |
| 2 | `no_synthetic_score_as_claim`            | Ledger entries are always `claim_ready=false`, never `true`                                     |
| 3 | `invalid_runs_do_not_rank`               | `valid_for_ranking` + `hard_failures` + `invalid_entries_excluded_from_ranking=true` declared   |
| 4 | `decide_signal_is_advisory_only`         | Projection always emits `advisory_only=true`                                                    |
| 5 | `evidence_hash_required`                 | Recorder rejects without an evidence pack hash                                                  |
| 6 | `task_category_required`                 | Recorder rejects without a task_category                                                        |
| 7 | `role_required`                          | Recorder rejects without a role; ROLES catalogue is declared                                    |
| 8 | `external_rivals_remains_blocked`        | Ledger + signal + cert + doc all assert separation from `external_rivals_certification`         |
| 9 | `provider_tokens_not_spent_by_ledger`    | Ledger + signal always emit `external_provider_call=false` and `provider_tokens_spent=false`    |

Audit output is exposed under `provider_performance_ledger_certification` in
`atlas:forge:rivals audit --json` and under the canonical
`atlas_forge_rivals_provider_performance_ledger_certification` key in the
`certifications` map.

## Future UI surface (Atlas Code premium)

The ledger feed is shaped for a future enterprise panel:

- **Ranking by category**: `by_task_category` aggregates → grouped bars.
- **Provider cards**: `by_provider_model` rows → confidence chip + sample
  size + latest evidence + age + stale flag.
- **Cost/quality frontier**: `cost_quality_frontier` → scatter (cost vs
  score).
- **Atlas Decide signal**: `decide-signal --task-category=...` →
  reasoning bullets, alternative recommendation, explore/full_power hints.

No UI ships in this slice; the JSON contract is the durable interface.

## Resumo

Ledger append-only que transforma scorecards do Rivals em inteligência para
Atlas Decide, sem chamar provider, gastar token ou destravar
`external_rivals_certification`.

## Papel no Atlas

Camada entre Rivals (mede) e Atlas Decide (decide). O `decide-signal` é
advisory para escolher provider/model/role/modo em runs futuros.

## Onde Se Encaixa

Encaixa entre `atlas:forge:rivals adjudicate` e `atlas:decide`. Lê evidência
local já produzida pela pipeline Rivals.

## Contratos

- Snapshot: `atlas.forge.rivals.provider_performance_ledger.v1`.
- Entry: `atlas.forge.rivals.provider_performance_ledger_entry.v1`.
- Decide signal: `atlas.forge.rivals.decide_signal.v1`.
- Cert: `atlas_forge_rivals_provider_performance_ledger_certification`.

## Fluxo

```
scorecard.json ──► ledger-record ──► entries.jsonl + entries/<id>.json
                                          │
                                          ▼
                                       snapshot
                                          │
                                          ▼
                                    decide-signal (advisory)
                                          │
                                          ▼
                                     Atlas Decide
```

## Regras para IA

- Nunca chamar provider externo a partir do ledger ou projeção.
- Nunca promover claim; entradas têm `claim_ready=false`.
- Nunca rankear hard failures nem score com replay/evidence/hash quebrado.
- Nunca destravar `external_rivals_certification`.
- Faltou `task_category`, `role` ou `evidence_pack_hash`: bloquear.

## Escopo de Implementacao

- `AtlasForgeRivalsProviderPerformanceLedgerService`.
- `AtlasForgeRivalsDecideSignalProjectionService`.
- `AtlasForgeRivalsProviderPerformanceLedgerCertification`.
- Actions: `ledger`, `ledger-record`, `decide-signal`.
- Storage local: `storage/app/rivals-forge-ledger/`.

## Dependencias

- `AtlasForgeRivalsRunPathResolver` para localizar manifest/scorecard.
- Scorecards produzidos pelo `AtlasForgeRivalsAdjudicatorService`.
- `AtlasForgeRivalsResponseBuilder` para expor a cert no `audit`.

## Evidencias

- Tests unit do ledger service e feature da certification.
- `php artisan atlas:forge:rivals audit --json`.

## Riscos

- Scorecards sem hashes viram sinal inválido, não ranking.
- `entries.jsonl` ainda não tem rotation; planejar retention futura.

## Exemplos
`php artisan atlas:forge:rivals decide-signal --task-category=frontend --role=builder --difficulty=L5 --json --strict`

## Proximas Acoes
Conectar Decide, retention e UI quando houver volume real suficiente.
