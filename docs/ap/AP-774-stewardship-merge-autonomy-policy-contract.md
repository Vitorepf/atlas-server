# AP-774 · Stewardship Merge Autonomy Policy

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack.
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyService.php`
- **Consumer:** AP-769 `StewardshipBranchMergeGovernorService`
- **Composes:** AP-769 branch merge governor · AP-771 priority engine · AP-772 merge queue · AP-773 branch safety audit.

## Purpose

AP-774 centralizes auto-merge autonomy decisions so the 24/7 stewardship loop
does not hide policy inside a single git governor method. AP-769 still owns git
inspection and fast-forward execution; AP-774 owns the deterministic question:

```text
may this branch auto-merge, or must it wait for operator review?
```

## Inputs

- AP-769 classification (`documentation_only`, `tests_only`, `docs_and_tests`,
  `bugfix`, `cleanup`, `code_or_mixed`, etc.).
- AP-769 validation result.
- Changed files and branch-only commit count.
- AP-769 blockers.
- Operator controls such as `allow_code_auto_merge` and
  `max_auto_merge_files`.

## Decision Schema

`atlas.software_company_stewardship.merge_autonomy_policy.v1`

Required fields:

- `eligible`
- `status`
- `risk_class`
- `reasons[]`
- `merge_mode`
- `rollback_plan`
- `operator_controls`

## Enterprise Policy

- Docs/tests-only can auto-merge only when small, conflict-free, on top of the
  current base and fast-forwardable.
- Code never auto-merges as generic `code_or_mixed`.
- `bugfix` and `cleanup` may auto-merge only with explicit operator flag and
  green validation.
- Any blocker (`stale`, `conflict`, dirty base, already merged) blocks
  auto-merge regardless of class.
- Large branch or high blast radius shifts to operator review.
- Rollback is never `reset`, `rebase`, `force-push` or silent history discard;
  accepted changes are reverted with normal revert commits if needed.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/StewardshipMergeAutonomyPolicyServiceTest.php`

Coverage:

- docs/tests small branches are allowed;
- bugfix requires explicit code auto-merge flag and green validation;
- code without flag/validation is blocked;
- conflict/high-risk blocks even otherwise safe branches.
