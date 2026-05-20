---
id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-02
type: engineering_knowledge
title: Atlas Forge Rivals Perfect Battery & Adjudicator v1 · Parte 2
status: active
category: programming-forge
priority: 88
summary: Recorte focado de Atlas Forge Rivals Perfect Battery & Adjudicator v1: 4b. Adjudicator v2 (per-category, suspicious triage, confidence ladder) ate 4c. Truth Guard v1 — Calibration & Score Separation.
tags:
  - atlas
  - forge
  - rivals
  - split-doc
capabilities:
  - forge_rivals_documentation_split
decisions:
  - Este recorte preserva detalhe operacional Rivals sem ampliar responsabilidade do índice canônico.
maintenance:
  - Manter abaixo de 520 linhas.
related_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
  - docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md
doc_schema: atlas_canonical_module_doc.v1
graph_id: atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-02
graph_title: Atlas Forge Rivals Perfect Battery & Adjudicator v1 Parte 2
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-forge-rivals-perfect-battery-and-adjudicator-v1
graph_status: active
graph_source: repo
owner: programming_rivals
repo_paths:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1-part-02.md
allowed_changes:
  - Atualizar somente o detalhe operacional desta parte.
forbidden_changes:
  - Transformar Rivals em routing, provider decision ou feature de produto.
depends_on:
  - atlas-forge-rivals-perfect-battery-and-adjudicator-v1
flows_to:
  - atlas-forge-rivals-operator-battery-v2
unlocks:
  - forge_rivals_readable_cartography
governs:
  - forge_rivals_run_battery_pipeline
evidence:
  - docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md
required_tests:
  - php artisan atlas:engineering:knowledge docs-health --json
requires_evidence: true
risk_level: high
line_limit: 520
next_actions:
  - Manter este recorte alinhado ao índice canônico.
---
# Atlas Forge Rivals Perfect Battery & Adjudicator v1 · Parte 2

## Resumo

Este recorte preserva uma parte focada de Atlas Forge Rivals Perfect Battery & Adjudicator v1: 4b. Adjudicator v2 (per-category, suspicious triage, confidence ladder) ate 4c. Truth Guard v1 — Calibration & Score Separation.

## Papel no Atlas

Mantém detalhe operacional Rivals fora do índice principal para que a cartografia continue legível.

## Onde Se Encaixa

É filho canônico de `docs/engineering-knowledge-base/atlas-forge-rivals-perfect-battery-and-adjudicator-v1.md`.

## Contratos

Rivals mede desempenho e evidência. Atlas Decide continua dono de routing/modelo.

## Fluxo

Índice Rivals → recorte operacional → comando/evidência/report correspondente.

## Regras para IA

Não transformar medição em decisão de provider. Não promover claim sem evidência, replay e gates.

## Escopo de Implementacao

Este arquivo guarda apenas o detalhe extraído do documento maior.

## Dependencias

Depende do índice canônico Rivals e do glossário.

## Evidencias

A evidência de origem é o documento principal e docs-health verde.

## Riscos

Risco principal: confundir harness/medição com decisão operacional do Atlas.

## Exemplos

Os exemplos abaixo são o conteúdo extraído, preservado sem perda semântica.

## Proximas Acoes

Atualizar quando o contrato correspondente mudar e rodar docs-health.

## Conteudo Extraido
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
  "measured_provider_signal": "atlas_forge",
  "routing_effect": "none"
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
