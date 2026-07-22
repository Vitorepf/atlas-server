# RuntimeExecution F0 receipt-hygiene report

Docs-only correction commit: `65e41832f` (`docs(core): GOD-DEBULK correct
RuntimeExecution F0 receipt hygiene`).

## Delivered correction

- Preserved the initial RuntimeExecution F0 report and ledger receipt as
  historical provenance while marking both **SUPERSEDED** by the canonical
  review-correction receipt `b3c02f4e7`.
- States the precise invalid claim: the initial same-logical-`goal_record_id`
  relationship between a legacy AVER certification and an absent canonical
  certification cannot hold, because the current AVER execution schema does
  not persist `goal_record_id`.
- Restored `EXEC-DEBTS.md` to `claimed_paths: []`. The stale committed paths
  (including the nonexistent RuntimeExecution F0 target) are no longer
  presented as active coordination state; Blackboard claims remain separate.

## Verification

```text
git diff --check
PASS
```

No tests, application code, migrations, provider calls, or external effects
were changed by this documentation-only task.
