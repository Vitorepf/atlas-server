# Atlas Forge Rivals Industrial Execution Suite v1

Status: canonical local execution readiness.

Canonical phrase: “Rivals emits measured evidence; Atlas Decide decides model routing.”

## Purpose

`atlas-forge-rivals-industrial-execution-suite-v1` turns the industrial benchmark specs into an executable local harness, starting with `industrial-50`.

It is not a provider run. It does not spend tokens. It does not unlock `external_rivals_certification`. It proves that fixtures, evidence paths, replay gates and matrix/report prerequisites can execute locally before an operator authorizes any real provider benchmark.

## Scope

Implemented scope:

- `industrial-50` readiness with exactly 50 executable cases.
- Deterministic local fixtures under `storage/forge-rivals-corpus/<case_id>/seed`.
- Fixture payloads stage into `storage/forge-rivals-industrial/<case_id>/...` inside isolated worktrees.
- Per-case metadata remains canonical: `case_id`, category, difficulty L1-L5, task type, scope, acceptance criteria, evidence requirements, invalid-if rules, expected changed files, scoring dimensions, oracle metadata.
- `local_fake` execution remains provider-free and token-free.
- Strong benchmark claims remain blocked until real execution evidence, replay, scorecard, adjudicator, matrix and confidence gates are complete.

Out of scope:

- Starting Claude/Codex/Gemini or any real provider.
- Spending provider tokens.
- Promoting external marketing claims.
- Changing Atlas Decide model topology.

## Commands

Readiness:

```bash
php artisan atlas:forge:rivals industrial-execution --case-set=industrial-50 --json
```

Dry-run plan:

```bash
php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --dry-run --json
```

Local fake execution:

```bash
php artisan atlas:forge:rivals run-battery --preset=industrial-50 --mode=local_fake --json
```

Case listing:

```bash
php artisan atlas:forge:rivals cases --case-set=industrial-50 --json
```

## Readiness Contract

The readiness JSON must expose:

- `total_cases`
- `executable_cases`
- `missing_fixtures`
- `empty_seed_cases`
- `missing_tests`
- `missing_expected_changed_files`
- `oracle_metadata_status`
- `evidence_requirements_status`
- `claim_status`
- `external_provider_call=false`
- `provider_tokens_spent=false`

Any fixture contamination blocks execution with explicit blockers instead of ambiguous failures.

## Claim Policy

`local_fake` is a harness proof, not a real benchmark claim. A strong claim requires all of:

- minimum 50 valid executable cases;
- complete evidence pack;
- green replay;
- scorecard per case;
- green adjudicator;
- green matrix report;
- statistical repetitions when required;
- explicit confidence;
- human approval for external certification.

`external_rivals_certification` remains `blocked_requires_human_approval`.

## Statistical Repeat

`statistical-repeat` readiness is present, but confidence remains blocked until real repeated executions exist for the required repetition groups. The suite must report `confidence_ready=false` and `statistical_repetitions_missing` until then.

## Atlas Decide Boundary

All machine-readable outputs remain advisory-only:

```json
{
  "advisory_only": true,
  "should_update_provider_topology": false,
  "never_changes_atlas_decide_topology": true,
  "owner_of_model_routing": "atlas_decide",
  "routing_effect": "none"
}
```

Rivals measures evidence. Atlas Decide owns model routing.
