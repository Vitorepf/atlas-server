# GOD-DEBULK P0 — tooling

Status: complete

Source: `docs/prompts/atlas-server-god-debulk-EXECUTE.md` §6 and the P0
priority in `docs/evidence/2026-07-22-atlas-server-god-debulk/EXEC-DEBTS.md`.

## Task 1: P0 tooling

1. Add a characterization test, first, for the three P0 commands. It must
   execute the actual scripts and verify their stable success markers.
2. Create `scripts/god-debulk-audit.php`. It must scan `app/Services/Ai`,
   output `godfiles_gt_5k`, `godfiles_gt_2k`, `GT5K` entries, and the top
   first-level Ai folders deterministically.
3. Create `scripts/god-debulk-guard.sh`. It must call the audit, keep the
   existing >5k baseline as a warning unless `GOD_DEBULK_ENFORCE=1`, and fail
   a last commit whose subject matches `residual pass <number>`.
4. Create `app/Services/Ai/CODEMAP.md` as a small, truthful navigation
   skeleton. Each initial row must link a concrete change concern to an
   existing `Class::method`, and explicitly say that it is incomplete.
5. Create `scripts/god-debulk-codemap-verify.php`. It must fail if the
   CODEMAP is missing, lacks its incompleteness marker, lacks a navigation
   row, or links a non-existent PHP class/method target.
6. Update the EXEC queue/ledger with the baseline counts and the P0 result.

## Constraints

- Local `main` only; preserve all unrelated WIP.
- Do not touch META findings, META ledger, blueprints, ownership, or legacy
  `DEBTS.md` / `LEDGER.md`.
- No PHP file may be new or edited above 2,000 LOC; no public product surface.
- Record TDD RED and GREEN evidence in the implementer report.
- Commit only the P0 files with a `docs(core): GOD-DEBULK P0 ...` subject.

## Acceptance

```bash
/opt/homebrew/bin/php artisan test --parallel tests/Feature/Scripts/GodDebulkToolingTest.php
/opt/homebrew/bin/php scripts/god-debulk-audit.php
bash scripts/god-debulk-guard.sh
/opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
/opt/homebrew/bin/php -l scripts/god-debulk-audit.php
/opt/homebrew/bin/php -l scripts/god-debulk-codemap-verify.php
find scripts app/Services/Ai tests/Feature/Scripts -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'
```

Rollback: remove only the P0 files from this cycle and restore the two EXEC
cursor files to their pre-cycle contents. No alias is involved.

## Completion evidence

- Test-first RED: the three characterization tests failed because all three P0
  scripts were absent.
- GREEN: `GodDebulkToolingTest` passed with 3 tests and 8 assertions.
- Baseline from the deterministic audit: `godfiles_gt_5k=14` and
  `godfiles_gt_2k=40`; the guard reports the existing >5k count as a warning
  unless `GOD_DEBULK_ENFORCE=1`.
