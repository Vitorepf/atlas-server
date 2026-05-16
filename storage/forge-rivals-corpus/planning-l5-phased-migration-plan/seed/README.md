# Seed · planning-l5-phased-migration-plan

Produce `docs/planning/migration/feature.migration.md` containing
**3-5 phases**. Each phase must declare:

- `id` (e.g. `phase-1`)
- `title`
- `depends_on` (list of phase ids, may be empty)
- `preconditions` (list)
- `rollback` (concrete steps; the word "revert" alone is not enough)
- `decision_gate` with `metric`, `threshold`, `owner`
- `risk_links` mapping at least one risk id from `risks.md` per phase

`risks.md` enumerates the inherited risks the plan must cover.

The provided checker test asserts shape, sequence (no cycle), rollback
presence and risk coverage.

## Files
- `docs/planning/migration/risks.md` — risks the plan must cover.
- `tests/Unit/Planning/MigrationPlanTest.php` — golden checker.

## Pass criteria
```
php artisan test --filter='MigrationPlanTest'
```
