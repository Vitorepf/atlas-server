# Seed · testdesign-l5-mutation-baseline

Establish a mutation-testing baseline for `CaptureMarkdownParser` using
Infection PHP. Without running the real Infection toolchain (the case is
offline), the arm must:

1. Write `tests/Mutation/baseline.json` with `{msi, killed, escaped,
   tool, generated_at, sample_size}`.
2. Author `docs/quality/mutation-survivors-decision.md` listing each
   `escaped` mutant from the baseline with a decision row:
   `kill_in_next_pr | accepted | false_positive`, plus a one-line
   justification.
3. Provide an `infection.json.dist` template wiring Infection against
   `app/Domain/Captures`.

Production code stays untouched.

## Files
- `tests/Mutation/baseline.example.json` — shape the arm must mirror.
- `tests/Mutation/MutationBaselineTest.php` — golden checker.
- `docs/quality/mutation-survivors-template.md` — section scaffold.

## Pass criteria
```
php artisan test --filter='MutationBaselineTest'
```
