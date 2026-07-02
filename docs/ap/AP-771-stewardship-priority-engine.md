# AP-771 · Stewardship Priority Engine

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineService.php`
- **CLI:** `php artisan atlas:software-company-stewardship priority-rank`
- **Composes:** AP-748 deep scan · AP-756 branch sandbox · AP-769 merge governor · AP-770 branch lifecycle registry.

## Purpose

AP-771 is the ordering brain for the 24/7 stewardship loop. Its rule is:

```text
implement in the order of maximum advancement and robustness,
while minimizing branch conflict, blast radius and unsafe merge risk.
```

It does not mutate code, create branches or merge. It ranks candidate findings,
branch records or work items so the loop spends scarce autonomous cycles on the
highest leverage, most robust work first.

## Scoring Model

Each candidate receives a deterministic `priority_score` from:

- `advancement`: bug/risk/test/gap work outranks cosmetic cleanup;
- `robustness`: tests, safety hardening and docs/tests-only changes gain weight;
- `risk_reduction`: critical/high issues outrank low-severity polish;
- `mergeability`: docs/tests/small changes rank higher for autonomous merge path;
- `confidence`: high-confidence findings beat speculative findings;
- `conflict_penalty`: config/routes/command/core-wide edits are penalized;
- `blast_radius_penalty`: broad file count is penalized.

Priority bands:

- `P0_maximum_advancement`
- `P1_high_advancement`
- `P2_standard`
- `P3_defer`

## Autonomy Hints

AP-771 can emit:

- `auto_merge_candidate_after_ap769_validation`
- `high_priority_operator_review`
- `normal_review_queue`

These are hints, not approvals. AP-769 remains the merge authority, and
irreversible actions still require the existing gates.

## Command

```bash
php artisan atlas:software-company-stewardship priority-rank \
  --priority-file=/path/to/candidates.json \
  --json
```

The file may contain a list of candidates, or an object with `candidates`,
`findings`, `branches`, or `deep_scan_report.findings`.

## Schema

`atlas.software_company_stewardship.priority_engine.v1`

## Enterprise Guarantees

- Deterministic ranking.
- No provider call.
- No branch/worktree creation.
- No merge/deploy/push/secrets.
- Explicit score breakdown for reviewability.
- Designed to feed AP-756/AP-769/AP-770 instead of replacing them.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipPriorityEngineServiceTest.php`

Coverage:

- ranks by maximum advancement and robustness;
- marks docs/tests candidates as AP-769 auto-merge candidates after validation;
- blocks when no candidates are supplied.
