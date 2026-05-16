---
id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
type: engineering_knowledge
title: Atlas Forge Rivals · Perfect Battery & Adjudicator v1
status: active
category: programming-forge
priority: 88
summary: Bateria única e adjudicator determinístico local para `atlas:forge:rivals`. Encadeia doctor→setup→preflight→dry-run→plan-real→run-real→collect-evidence→replay→adjudicate→report em um comando auditável, com winner/tie/invalid honesto. Nunca destrava external_rivals_certification.
tags:
  - atlas
  - forge
  - rivals
  - run-battery
  - adjudicator
capabilities:
  - forge_rivals_run_battery_v1
  - forge_rivals_local_deterministic_adjudicator
  - forge_rivals_perfect_battery_certification
decisions:
  - Bateria é um único entrypoint humano; aliases não escondem o canon `run-battery`.
  - Adjudicator é local determinístico; nunca delega para provider externo.
  - `external_rivals_certification` permanece BLOCKED por construção independentemente do veredito.
maintenance:
  - Atualizar quando `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsAdjudicatorService` ou cert v1 mudarem de invariantes.
  - Não introduzir alias novo sem aliasing list no command.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-benchmark-strategy-v1.md
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-operator-battery-v2.md
  - docs/engineering-knowledge-base/atlas-forge-rivals-real-battery-operator-harness-v1.md
doc_schema: atlas_canonical_module_doc.v1
owner: programming_rivals
graph_id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_title: Atlas Forge Rivals · Perfect Battery & Adjudicator v1
graph_world: atlas
graph_layer: system
graph_kind: runbook
graph_parent: atlas-forge-rivals-operator-battery-v2
graph_status: active
graph_source: repo
repo_paths:
  - app/Console/Commands/AtlasForgeRivalsCommand.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsRunBatteryService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorService.php
  - app/Services/Ai/Programming/ForgeRivals/AtlasForgeRivalsReportService.php
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
allowed_changes:
  - Adicionar aliases novos ao command com aliasing list explícita.
  - Endurecer invariantes do adjudicator determinístico local.
forbidden_changes:
  - Delegar o veredito do adjudicator para provider externo.
  - Esconder safety strip ou pular confirmações em `fair`/`full_power`.
  - Promover `external_rivals_certification` a partir do veredito da bateria.
depends_on:
  - atlas-forge-rivals-benchmark-strategy-v1
  - atlas-forge-rivals-operator-battery-v2
  - atlas-forge-rivals-real-battery-operator-harness-v1
flows_to:
  - atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2
  - atlas-forge-rivals-provider-arena-core-v1
unlocks:
  - operator_runs_atlas_vs_rival_in_single_auditable_command
governs:
  - forge_rivals_run_battery_pipeline
evidence:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsRunBatteryTest.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorServiceTest.php
required_tests:
  - tests/Feature/Ai/Programming/AtlasForgeRivalsRunBatteryTest.php
  - tests/Unit/Ai/Programming/ForgeRivals/AtlasForgeRivalsAdjudicatorServiceTest.php
requires_evidence: true
risk_level: high
next_actions:
  - Manter aliasing list sincronizada com novos modos.
  - Acompanhar invariantes do adjudicator quando novas categorias forem introduzidas.
---

# Atlas Forge Rivals · Perfect Battery & Adjudicator v1

**Strategy canon:** `atlas-forge-rivals-benchmark-strategy-v1.md`
**Schema:** `atlas.forge.rivals.run_battery.v1`
**Adjudicator schema:** `atlas.forge.rivals.adjudication.v1` (legacy, intact)
**Adjudicator schema v2:** `atlas.forge.rivals.adjudication.v2` (category scoring + triage + ledger projection)
**Batch input schema:** `atlas.forge.rivals.adjudication_batch_input.v1`
**Report schema:** `atlas.forge.rivals.report.v3` (additive over v2; see `atlas-forge-rivals-reporting-v1.md`)
**Certification:** `atlas_forge_rivals_perfect_battery_certification` (v1)
**Entrypoint:** `php artisan atlas:forge:rivals`
**Status:** Slice 7 — Perfect Battery & Adjudicator delivered (2026-05-15)
**v2 Adjudicator layer:** delivered 2026-05-15 (per-category scoring, hard gates,
suspicious triage, confidence ladder, ledger projection — sits on top of v1
without breaking it).

This doc is the canonical contract for running Atlas Forge vs a rival in a
single auditable command, with a deterministic local adjudicator that
declares an honest winner / tie / invalid verdict. It supersedes nothing —
it builds on top of `atlas-forge-rivals-operator-battery-v2.md` and
`atlas-forge-rivals-real-battery-operator-harness-v1.md`. Read those first.

> **External rivals canon.** This battery NEVER unlocks
> `external_rivals_certification`. That cert remains operator-approval-gated
> and separately tracked. Every layer (run-battery, adjudicator, report,
> certification, this doc) restates the separation explicitly.

---

## 1. The single button

```bash
php artisan atlas:forge:rivals run-battery \
  --mode=fair --atlas-model=sonnet --rival=claude_sonnet \
  --preset=release \
  --confirm-runbook-reviewed \
  --confirm-provider-cost \
  --confirm-real-provider-call \
  --json --strict
```

This one command chains the entire pipeline:

```
doctor → setup → preflight → dry-run → plan-real → run-real →
collect-evidence → replay → adjudicate → report
```

Stops on the first phase whose status is not `ok`. Returns the partial
pipeline so the operator can see exactly where it broke. Evidence is
preserved at `runs/<run_id>/` regardless.

**Aliases:** `battery`, `run-battery-real`, `battery-run` all resolve to
`run-battery`. `score`, `adjudicator`, `adjudication` all resolve to
`adjudicate`.

---

## 2. Modes, models, presets

### Modes (canonical + alias)

| Mode (canon) | Alias | Requires provider | Allows topology | Notes |
| --- | --- | --- | --- | --- |
| `fair` | — | yes | no | Same model on both arms. Canonical claim mode. |
| `full_power` | `power` | yes | yes | Atlas may declare topology; rival may use codex. |
| `local_fake` | — | no | no | In-process fake; no provider call; for CI smoke. |

### Models

| Arm | Allowed |
| --- | --- |
| `--atlas-model` | `sonnet` · `opus` · `claude_sonnet` · `claude_opus` · `codex` (only in `full_power` after driver wired) · `auto` (only in `full_power`) |
| `--rival` | `claude_sonnet` · `claude_opus` · `codex` (honest blocker if binary missing) · `auto` (only in `full_power`) |

`sonnet` is shorthand for `claude_sonnet`; `opus` is shorthand for
`claude_opus`. `fair` mode rejects mismatched models on the two arms.
`auto` is rejected outside `full_power`.

### Presets

`smoke` · `quick` · `release` · `full`. A preset that resolves to zero
cases is a fatal harness bug (`EmptyPresetIsFatalHarnessBug`).

---

## 3. Three operator confirmations (real-provider only)

For `fair` and `full_power`, the battery refuses to invoke the provider
without **all three** flags:

- `--confirm-runbook-reviewed`
- `--confirm-provider-cost`
- `--confirm-real-provider-call`

Missing any one ⇒ `blocked`, `missing_confirmation:<flag>` blocker,
`external_provider_call=false`, `provider_tokens_spent=false`.
`local_fake` ignores the flags — it never invokes the provider.

The same gate is enforced by `run-real` and by `run-battery` independently
(defense in depth). `AtlasForgeRivalsPerfectBatteryCertification` invariant
`real_provider_requires_three_confirmations` proves both layers reference
all three flags + the missing-confirmation blocker.

---

## 4. Adjudicator (deterministic, local)

`AtlasForgeRivalsAdjudicatorService` produces `evidence/scorecard.json` for a
run. Schema: `atlas.forge.rivals.adjudication.v1`. **No LLM is ever asked to
judge.** Every signal is computed from local artifacts.

### Hard gates

`verdict_comparable` · `provider_exit_zero_{atlas,rival}` ·
`tests_passed_{atlas,rival}` · `replay_passes` · `evidence_complete` ·
`no_out_of_scope_files_{atlas,rival}` · `no_bytecode_artifacts_{atlas,rival}` ·
`dirty_after_run_false` · `patch_diff_present_{atlas,rival}`.

Infrastructure, evidence, replay, scope, dirty-workspace, and missing-patch hard
gates fail closed to `winner=null` and scores null. A one-sided
`tests_passed_*` failure is reported differently: it may emit
`gate_winner=atlas|rival` and `gate_result.kind=one_sided_test_failure`, but it
still keeps `winner=null`, both scores null, and
`quality_score_available=false`. That is a gate outcome, not a quality score.

### Quality dimensions (only when every hard gate green)

| Dimension | Weight | Measures |
| --- | ---: | --- |
| `objective_alignment` | 15% | Provider exit zero + tests pass, −20 if killed |
| `patch_focus` | 12% | Smaller substantive diff wins (curve) |
| `implementation_complexity` | 8% | Touched-files + big-single-file penalty |
| `test_quality` | 12% | Assertion count + touched-test bonus |
| `maintainability` | 10% | Average diff bytes per touched file |
| `risk_surface` | 10% | Production-touched vs tests-touched balance |
| `scope_discipline` | 15% | Zero out-of-scope + zero bytecode |
| `evidence_quality` | 10% | Required artifacts present + hashed |
| `cost_time_efficiency` | 8% | Wall time + stdout volume proxies |

Weights sum to 1.0. Final score per arm = weighted sum in [0, 100].
**Cost/time can never overturn a non-tie quality outcome** — it only
surfaces as a tiebreaker hint in `winner_reason`.

### Winner / tie / invalid

- Infrastructure/evidence/replay/scope hard fail ⇒ `winner=null`, scores null,
  gates explain failure.
- One-sided test failure ⇒ `gate_winner=atlas|rival`, `winner=null`, scores
  null, `quality_score_available=false`. This says which arm survived the gate;
  it is not a comparable quality result.
- `|atlas-rival| < threshold` (default 5.0) ⇒ `winner=human_review_required_tie`.
- Otherwise ⇒ `winner=atlas|rival`, `claim_ready=true`, structured `winner_reason`.

Adjudicator NEVER calls a provider; fully unit-testable against synthetic receipts.

---

## 4b. Adjudicator v2 (per-category, suspicious triage, confidence ladder)

`AtlasForgeRivalsAdjudicatorV2Service` is the layer on top of v1 that answers
the operator question **"in which categories was Atlas or Claude better?"**.
It NEVER calls a provider, NEVER asks an LLM to judge, NEVER promotes
`external_rivals_certification`. v1's scorecard.json is preserved byte-for-byte;
v2 writes `evidence/scorecard.v2.json` next to it and decorates the CLI
envelope with `scorecard_v2` + `scorecard_v2_path`.

**Schema:** `atlas.forge.rivals.adjudication.v2`
**Batch input schema:** `atlas.forge.rivals.adjudication_batch_input.v1`

### Two consumption modes

```bash
# Single-run (compat): v1 + v2 emitted side by side.
php artisan atlas:forge:rivals adjudicate --run-id=<id> --json --strict

# Batch (multi-case): v2 only, fed by a JSON payload on disk.
php artisan atlas:forge:rivals adjudicate \
  --input=/path/to/adjudication_batch_input.v1.json \
  --output-path=/path/to/scorecard.v2.json --json --strict
```

`score`, `adjudicator`, `adjudication` continue to alias to `adjudicate`.

### v2 hard gates (superset of v1)

v2 keeps every v1 gate AND adds operator-friendly extras (any one ⇒
`score=null`, `winner=null` for the case):

- `missing_provider_receipt:<arm>` · `missing_patch_diff:<arm>` ·
  `missing_test_log:<arm>` · `tests_failed:<arm>`
- `workspace_dirty_before` · `workspace_dirty_after` · `tracked_python_bytecode:<arm>`
- `scope_violation:<arm>` · `forbidden_file_changed:<arm>`
- `replay_manifest_missing` · `replay_failed` · `evidence_pack_incomplete`
- `timeout_without_result:<arm>` · `stalled_runner_no_heartbeat:<arm>`
- `synthetic_score_detected` · `provider_policy_violation:<arm>`
- `atlas_arm_not_forge` · `model_lock_violation`

### Default v2 dimension weights

When a case manifest does not declare `quality_gates.weights`, v2 falls back
to `default_policy` weights (sum 1.0):

| Dimension | Weight |
| --- | ---: |
| `correctness` | 0.18 |
| `test_coverage` | 0.12 |
| `scope_discipline` | 0.15 |
| `minimality` | 0.10 |
| `maintainability` | 0.10 |
| `architecture_fit` | 0.10 |
| `evidence_quality` | 0.10 |
| `cost_time` | 0.05 |
| `ux_quality` | 0.05 |
| `performance` | 0.05 |

When the case manifest declares its own weights they are consumed and
normalized so the sum stays 1.0. `weights_source` is recorded per case
(`case_manifest` | `default_policy`).

### Suspicious result triage

v2 NEVER trusts a numerically clean score blindly. Per case, it emits a
`suspicious_results[]` list with `code`, `arm`, `score`, `context`,
`affects_winner`, `next_action`. Canonical codes:

| Code | Trigger |
| --- | --- |
| `rival_underperformed_unexpectedly` | rival is a strong model (claude/codex/gemini/gpt) AND score < 70 |
| `atlas_won_easy_case_by_huge_margin` | role_focus simple (ui_correctness, minimal_diff, cohesion, clarity) AND atlas margin > 35 AND atlas score > 85 |
| `empty_stdout_with_expected_change` | stdout_bytes=0 AND patch_diff_bytes>0 |
| `too_fast_for_difficulty` | elapsed < 5s on non-trivial task category |
| `score_diverges_from_history` | per-arm score deviates > 30 from supplied historical mean |
| `narrow_win` | margin ∈ (tie_threshold, narrow_win_threshold) |

If any suspicious result has `affects_winner=true`, the category /
overall winner falls back to `no_trusted_winner`.

### Confidence ladder

| Level | Trigger | Winner trust |
| --- | --- | --- |
| `flow_validated` | ≤3 valid cases OR preset=quick | NEVER claim global superiority |
| `directional_signal` | ≥5 valid cases AND ≥2 categories, no suspicious affecting winner | Tendency, not release-grade |
| `trusted_battery` | ≥12 valid cases AND ≥8 categories AND no hard fail AND no suspicious affecting winner | Battery winner declarable |
| `provider_ranking` | ≥25 valid cases AND coverage AND no suspicious | Provider ranking signal |
| `decide_signal` | Ledger entry_count ≥ 25 AND `ledger_fresh=true` | Atlas Decide advisory |

`overall.claim_allowed` is `true` only at `trusted_battery` or above, with a
non-null/non-tie winner and zero suspicious results affecting winner.
`claim_ready` STAYS `false` in every code path — v2 NEVER promotes a
completion claim, NEVER unlocks `external_rivals_certification`.

### Tie / narrow_win semantics (v2)

- `|atlas - rival| < 5.0` ⇒ `winner = tie`, `human_review_required = true`.
- `5.0 ≤ |atlas - rival| < 7.0` ⇒ winner declared, `narrow_win` suspicious
  emitted, `human_review_required = true`.
- `|atlas - rival| ≥ 7.0` ⇒ clear winner.
- Any case with `affects_winner = true` suspicious ⇒ category winner
  collapses to `no_trusted_winner`.

### Category winners

`categories[]` carries per-category aggregates:

```json
{
  "category": "backend_logic",
  "cases_count": 3,
  "valid_cases_count": 3,
  "atlas_score_avg": 84.1,
  "rival_score_avg": 75.4,
  "winner": "atlas",
  "margin": 8.7,
  "confidence": "directional_signal",
  "reasons": ["atlas_leads_category_by_8.70"],
  "suspicious_results": [],
  "recommended_provider_signal": "atlas_forge"
}
```

### Overall block

```json
{
  "winner": "atlas | rival | tie | no_trusted_winner | null",
  "atlas_score": 81.3,
  "rival_score": 76.8,
  "margin": 4.5,
  "confidence": "trusted_battery",
  "winner_reason": ["atlas_leads_aggregate_by_4.50", "categories_won:atlas=5 rival=3 tie=0 no_trusted=0"],
  "caveats": [],
  "claim_allowed": false,
  "human_review_required": false,
  "categories_won": {"atlas": 5, "rival": 3, "tie": 0, "no_trusted_winner": 0}
}
```

### Ledger projection ready

Each case yields one v2 ledger-projection row per arm so the existing
`atlas:forge:rivals ledger-record` pipeline can absorb the v2 result
without further work:

```json
{
  "arm": "atlas",
  "arm_id": "atlas",
  "runner_type": "atlas_forge",
  "provider": "anthropic_claude",
  "model": "claude_sonnet",
  "role": "builder",
  "task_category": "backend_logic",
  "mode": "fair",
  "preset": "release",
  "score": 86.5,
  "confidence": "trusted_battery",
  "valid": true,
  "hard_failure_reason": null,
  "freshness": "2026-05-15T...",
  "claim_ready": false,
  "separated_from_external_rivals_certification": true
}
```

Hard-failed runs are recorded with `valid=false` and `hard_failure_reason`
set so the ledger continues to exclude them from ranking automatically.

### Why a low Claude / Codex / Opus score is NEVER accepted as truth

Canon `atlas-forge-rivals-benchmark-strategy-v1.md` §Triage de resultado
suspeito states that anomalously low scores from strong rivals must run
triage before becoming a trusted result. v2 enforces this in code: any
strong-rival score below 70 surfaces as `rival_underperformed_unexpectedly`
with `affects_winner=true`, the case stops being eligible for
`trusted_battery`, the category winner collapses to `no_trusted_winner`,
and `overall.claim_allowed` flips to `false`. The operator must investigate
prompt / receipt / replay / scope / fixture / case fairness before
re-running. We refuse to publish a winner that we can't trust.

### Deterministic adjudication_hash

The v2 envelope includes `adjudication_hash` = SHA-256 of the canonical
JSON form of the payload with `adjudication_hash` and `generated_at`
stripped. Same input ⇒ same hash. Auditors can re-run the adjudicator on
an evidence pack and verify the hash matches the persisted scorecard.

## 4c. Truth Guard v1 — Calibration & Score Separation

The Truth Guard layer (`AtlasForgeRivalsAdjudicatorCalibrationService`)
makes the v2 adjudicator refuse to publish absurd scores like
"Atlas 100 × 0 Claude" without a verifiable structural cause. It owns the
canonical tables that everything downstream reads:

### Difficulty ladder (L1..L5)

Every real case declares `difficulty_level`, `difficulty_score`,
`difficulty_reason`, `planning_weight`, `execution_weight`,
`ambiguity_level`, `risk_level`.

| Level | Multiplier | Default plan / exec | Where it applies |
| --- | ---: | --- | --- |
| `L1` Mechanical | 1.00 | 0.10 / 0.90 | local bug, mechanical refactor |
| `L2` Local Reasoning | 1.20 | 0.20 / 0.80 | few files, correct + tested |
| `L3` Product/Integration | 1.50 | 0.35 / 0.65 | rule of business + state + edge |
| `L4` Architectural | 2.00 | 0.55 / 0.45 | boundary, schema, fail-closed |
| `L5` Strategic Planning | 2.50 | 0.70 / 0.30 | decomposition + risk + arch choice |

Difficulty **changes weight but NEVER masks failure.** Hard fail still
forces `score=null`. `difficulty_weighted_score` is bounded at 100 so a
multiplier cannot manufacture a >100 claim.

### Score separation (per arm)

`case.atlas_breakdown` / `case.rival_breakdown` expose four fields plus an
explanation array:

| Field | Meaning |
| --- | --- |
| `hard_fail` | bool — case had at least one hard failure for this arm |
| `quality_score` | 0..100 — raw weighted aggregate (null on hard fail) |
| `confidence_score` | 0..1 — derived from confidence ladder |
| `difficulty_weighted_score` | quality_score × difficulty_multiplier, capped at 100 |
| `score_explanation[]` | bullets: top-loss dimension, contribution breakdown, "why not 100" |

### Per-category default weights

| Category | Dominant dimensions |
| --- | --- |
| `frontend_ui` | ux_quality 0.25, correctness 0.20, scope 0.15 |
| `backend_logic` | correctness 0.25, test_coverage 0.20, scope 0.15 |
| `realistic_bugfix` | correctness 0.30, minimality 0.25, test_coverage 0.20 |
| `refactor` | maintainability 0.30, architecture_fit 0.20, scope 0.20 |
| `test_design` | test_coverage 0.40, correctness 0.20, maintainability 0.15 |
| `architecture` | architecture_fit 0.30, correctness 0.20, scope 0.15 |
| `integration` | correctness 0.25, architecture_fit 0.20, test_coverage 0.20 |
| `performance_edge_case` | performance 0.35, correctness 0.20, test_coverage 0.15 |
| `planning` | architecture_fit 0.30, correctness 0.20, evidence_quality 0.20 |
| `docs` | evidence_quality 0.30, scope 0.20, maintainability 0.20 |
| `security` | correctness 0.30, scope 0.20, test_coverage 0.15 |

Case manifests can declare their own `quality_gates.weights`; the
adjudicator normalises to sum 1.0 and records `weights_source =
case_manifest` (otherwise `default_policy`). Dimensions the manifest does
NOT declare are weighted **zero** — never falling back to the policy
table — so the aggregate cannot exceed 100.

### Truth Guard suspicious codes (additive)

In addition to the canonical 6 codes from §4b, the Truth Guard layer adds:

| Code | Trigger | Affects winner |
| --- | --- | --- |
| `score_100_vs_0` | atlas≥99 AND rival≤1 · OR · margin≥95 · OR · margin≥60 AND max≥95 | yes |
| `score_blowout_without_cause` | atlas≥90 AND rival≤10 AND no timeout/kill | yes |
| `atlas_patch_almost_empty` | atlas patch_diff_bytes ∈ (0, 64] | when atlas wins |
| `rival_patch_almost_empty` | rival patch_diff_bytes ∈ (0, 64] | when rival wins |
| `evidence_incomplete_suspicious` | < 80% required artifacts present | yes |
| `provider_timeout_mistaken_for_low_quality` | timeout=true AND score < 50 | when other arm wins |

Suspicious with `affects_winner=true` flips case `outcome` to
`needs_triage` — the case does NOT become a claim. Operator must
triage before any winner is published.

### Outcome labels (per case)

`case.outcome` ∈ {`winner`, `loser`, `tie`, `no_trusted_winner`,
`needs_triage`, `invalid`}. `needs_triage` always preempts `winner` when
a suspicious code with `affects_winner=true` is present.

### Calibration snapshot

`AtlasForgeRivalsAdjudicatorCalibrationService::tableSnapshot()` returns
the full canonical tables (multipliers, plan/exec composition, per-category
weights, ambiguity/risk vocab, Truth Guard thresholds) — schema version
`atlas.forge.rivals.adjudicator_calibration.v1`. Used by reporting / audit
surfaces.

## 5. Premium report (`report.md` + JSON)

`AtlasForgeRivalsReportService` (schema `atlas.forge.rivals.report.v2`)
reads manifest + replay + scorecard and renders:

- **Executive summary** with run id, mode, models, preset, score line,
  replay_passes, claim_ready, declared_why.
- **Why this outcome** — bullets from `scorecard.winner_reason`.
- **Hard gates** table (one row per gate).
- **Quality dimensions** table (atlas vs rival, weighted contribution).
- **Patch comparison** (bytes, changed files, out-of-scope, bytecode,
  diff hashes).
- **Test comparison** (command, exit codes, log paths, log hashes).
- **Cost / time / provider usage** (wall time, stdout/stderr bytes,
  tokens_used, token_cost).
- **Evidence integrity** (manifest, receipts, workspace hashes,
  scorecard).
- **Replay status** (passes, mismatches, event_count).
- **Human review checklist** — adapts to outcome (invalid / replay-failed /
  hard-fail / tie / clear winner). Tie always triggers a 5-item checklist.
- **Artifacts list** — `events.jsonl`, `manifest.json`, receipts, patches,
  test logs, scorecard, report.
- **Canon footer** — restates separation from `external_rivals_certification`.

### Report JSON envelope

```json
{
  "status": "ok",
  "schema_version": "atlas.forge.rivals.report.v2",
  "run_id": "...",
  "verdict": "comparable|invalid_*",
  "winner": "atlas|rival|human_review_required_tie|null",
  "gate_winner": "atlas|rival|null",
  "gate_result": null,
  "winner_reason": [],
  "atlas_score": 86.5,
  "rival_score": 79.2,
  "score_source": "quality_dimensions|gate_outcome",
  "quality_score_available": true,
  "quality_score_reason": null,
  "threshold": 5.0,
  "hard_failures": [],
  "human_review_required": false,
  "claim_ready": true,
  "replay_passes": true,
  "declared_why": "quality_winner:atlas",
  "quality_dimensions": { },
  "hard_gates": [],
  "report_path": "runs/<id>/evidence/report.md",
  "scorecard_path": "runs/<id>/evidence/scorecard.json",
  "artifacts": [],
  "external_provider_call": false,
  "separated_from_external_rivals_certification": true,
  "unlocks_external_rivals_certification": false
}
```

---

## 6. Codex blocker (honest)

Asking for `--rival=codex` (or `--atlas-model=codex` in `full_power`)
without the `codex` binary installed yields:

```
blocker: rival_driver_not_configured:codex
hint:    install codex CLI (which codex) or pick a different --rival
```

Both `run-battery` (via doctor probe) and `run-real` (via direct
`which codex` check) emit this blocker. No silent "pretend support".
`AtlasForgeRivalsPerfectBatteryCertification` invariant
`codex_blocker_honest_or_supported` proves both layers emit the blocker.

---

## 7. Worktree isolation

Every run lives in `/Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/`
with `atlas/` and `rival/` worktrees provisioned by `setup`. `run-real`
refuses to start when those worktrees are missing (`worktrees_missing`
blocker) and never operates on the source repo. The source repo can be
dirty without affecting any run.

---

## 8. Certification (audit read-model)

`php artisan atlas:forge:rivals audit --json` evaluates two certifications
side by side:

- `atlas_forge_rivals_operator_battery_certification` (the 18-invariant
  v2 contract — backwards compatibility)
- `atlas_forge_rivals_perfect_battery_certification` (this v1 — 12
  invariants)

### v1 invariants

1. `one_button_battery_available` — `run-battery` is in `ACTIONS`, dispatcher
   routes it, service class exists.
2. `adjudicator_available` — `adjudicate` action wired, service exists,
   schema constant present.
3. `replay_required_before_winner` — adjudicator and report both reference
   `replay_passes` in their gate logic.
4. `evidence_required_before_winner` — adjudicator references
   `evidence_complete` and `missing_evidence`.
5. `hard_fail_score_null` — adjudicator emits null scores and `WINNER_NONE`
   on the hard-fail branch.
6. `no_external_rivals_unlock` — every layer asserts separation from
   `external_rivals_certification`.
7. `real_provider_requires_three_confirmations` — battery and run-real
   both reference all three flags + the missing-confirmation blocker.
8. `worktree_isolation_required` — run-real references `worktrees_missing`
   and `.git` checks.
9. `sonnet_supported` — `MODEL_CLAUDE_SONNET` in `MODELS`.
10. `opus_supported` — `MODEL_CLAUDE_OPUS` in `MODELS`.
11. `codex_blocker_honest_or_supported` — battery and run-real both emit
    `rival_driver_not_configured:codex`.
12. `report_has_winner_reason` — report JSON and adjudicator both expose
    `winner_reason`; adjudicator has `buildWinnerReason`.

Status semantics:

- `available` — every invariant green, every artifact present.
- `missing_artifacts` — an artifact required to evaluate is missing.
- `blocked` — at least one invariant evaluates to false.

---

## 9. CLI ergonomics & interpretation

Every action returns the canonical envelope
(`atlas.forge.rivals.action_response.v1`). `--strict` causes
non-`ok`/`completed` statuses to exit non-zero. Headline commands:
`doctor --json`, `run-battery ... --json`, `report --run-id=<id> --json`,
`replay --run-id=<id> --json`, `adjudicate --run-id=<id> --json`,
`audit --json` (dual certification read-model).

| Outcome | Meaning | Operator action |
| --- | --- | --- |
| `winner: atlas` / `rival` | All hard gates green, diff ≥ threshold | Inspect diff; legitimate win — does NOT unlock external rivals |
| `winner: human_review_required_tie` | Diff < threshold | Read checklist; do NOT force a winner |
| `gate_winner: atlas` / `rival`, `winner: null` | Exactly one arm failed tests while replay/evidence gates stayed valid | Gate outcome only; quality score N/A; fix failed arm or run another case |
| `winner: null` + `hard_failures` | A hard gate failed | Fix gates and re-run |
| `verdict: invalid_*` | Run invalidated (dirty, timeout, no diff) | ZERO claim — investigate cause |
| `replay_passes: false` | Hash mismatch | Evidence untrustworthy — re-run |

## 10. Safety rules (non-negotiable)

- NEVER unlocks `external_rivals_certification`.
- Real provider call requires three operator confirmations simultaneously.
- `local_fake` mode never invokes a provider, even with confirmations.
- `rm` is never used against worktree roots; reset is git-driven.
- Workspace dirty after run / tracked .pyc / out-of-scope files ⇒ hard fail, ZERO claim.
- Empty preset ⇒ `EmptyPresetIsFatalHarnessBug` (not a silent no-op).

## 11. Related docs

- `atlas-forge-rivals-operator-battery-v2.md`
- `atlas-forge-rivals-real-battery-operator-harness-v1.md`
- `atlas-forge-rivals-reliability-lockdown-v1.md`
- `atlas-rivals-evidence-pack-replay-manifest-v1.md`
- `atlas-rivals-one-shot-enterprise-evaluation-v1.md`
- `atlas-forge-native-rivals-protocol-v1.md`

## Resumo

Slice 7 do Forge Rivals: bateria única `atlas:forge:rivals run-battery` que orquestra todo o pipeline de comparação Atlas vs rival com adjudicator determinístico local e cert v1. Aliases consolidados (`battery`, `run-battery-real`, `score`, `adjudicator`). `external_rivals_certification` continua BLOCKED.

## Papel no Atlas

Cabine humana do Forge Rivals. Substitui a sequência manual de 9 comandos por uma única chamada com `--strict`, retornando o pipeline parcial até o ponto de falha e preservando evidência em `runs/<run_id>/`.

## Onde Se Encaixa

Acima de `atlas-forge-rivals-operator-battery-v2.md` e `atlas-forge-rivals-real-battery-operator-harness-v1.md`. Companheiro direto de `atlas-forge-rivals-evidence-replay-adjudicator-hardening-v2.md`.

## Contratos

Schemas: `atlas.forge.rivals.run_battery.v1` (envelope), `atlas.forge.rivals.adjudication.v1` (adjudicator determinístico local), `atlas.forge.rivals.report.v2` (report). Cert: `atlas_forge_rivals_perfect_battery_certification` (v1). Invariantes: adjudicator nunca delega para provider externo; safety strip nunca é escondida; 3 confirmações para `fair`/`full_power`; `external_rivals_certification` permanece BLOCKED por construção.

## Fluxo

`doctor → setup → preflight → dry-run → plan-real → run-real → collect-evidence → replay → adjudicate → report`, parando na primeira fase com status diferente de `ok`.

## Regras para IA

Não esconder safety strip. Não pular as três confirmações em modos `fair`/`full_power`. Não promover `external_rivals_certification` a partir do veredito.

## Escopo de Implementacao

`AtlasForgeRivalsCommand`, `AtlasForgeRivalsRunBatteryService`, `AtlasForgeRivalsAdjudicatorService`, `AtlasForgeRivalsReportService`, `AtlasForgeRivalsCollectEvidenceService`, `AtlasForgeRivalsReplayService`.

## Dependencias

Operator battery v2, real battery operator harness v1, evidence pack v2 hardening, perfect battery certification v1.

## Evidencias

Cert v1 `atlas_forge_rivals_perfect_battery_certification` e 186 testes Forge Rivals verdes (Slice 7 delivered 2026-05-15).

## Riscos

Operador interpretar `winner` como completion claim. Alias novo escapar do controle do command. Promoção indevida de `external_rivals_certification`.

## Exemplos

`php artisan atlas:forge:rivals run-battery --mode=fair --atlas-model=sonnet --rival=claude_sonnet --preset=release --confirm-runbook-reviewed --confirm-provider-cost --confirm-real-provider-call --json --strict`.

## Proximas Acoes

Acompanhar futuros polish em adjudicator e report. Mantersuit de testes sincronizada com mudanças de invariantes.
