# Seed · planning-l3-risk-register

Produce `docs/planning/inbox/feature.risks.md` with **4-8 risks**.
Each risk MUST declare:

- `id` (e.g. `risk-1`)
- `likelihood` ∈ {low, medium, high}
- `impact` ∈ {low, medium, high, critical}
- `mitigation` (one concrete action; the word "monitor" alone is not enough)
- `owner` (role or named team)

At least one risk MUST have `impact=critical` OR `likelihood=high`.
For every risk with `impact ∈ {high, critical}` OR `likelihood=high`,
`mitigation` MUST be present and non-empty.

`feature.md` describes the rollout this register is sized for.

## Files
- `docs/planning/inbox/feature.md` — feature being assessed.
- `tests/Unit/Planning/RiskRegisterTest.php` — golden checker scaffold.

## Pass criteria
```
php artisan test --filter='RiskRegisterTest'
```
