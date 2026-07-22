# RuntimeExecution F0 report

Primary test commit: `5a954a59b4cc1133d006b08ff00d639c84570f58` (`test(core): GOD-DEBULK RuntimeExecution F0 characterization`).

## Delivered characterization

- Executed all nine safe public `AtlasVerifiedExecutionRuntimeService` APIs. `executeFixtureCycle` remains the only tenth public API, statically inventoried but deliberately not called because it creates a temporary workspace and spawns a process.
- Created a legacy `AtlasAverCertifiedExecution` through `certify()` from safe, materialized ledger receipts; the corresponding `AiRealExecutionCertification` is absent for the same logical `goal_record_id`. The present AVER execution table has no `goal_record_id` column, which is the F4a additive-migration gap.
- Asserted the incompatible representations directly: AVER `diff_hash` is a canonical payload hash; RealExecution's comparable representation is `hash('sha256', $rawDiff)`. They are not equal and must not be used as correlation keys.
- Frozen the byte-level `atlas.ai.runtime_release_gate.v1` JSON output at `2026-07-22T12:34:56+00:00`, with a concrete readiness spy proving exactly one upstream `report()` call.
- Classified `AiToolRuntime::availableTools()` statically: 19 total, 9 read-only and 10 gate-required. No catalog tool was invoked by the F0 test.

## Verification

```text
/opt/homebrew/bin/php artisan test tests/Feature/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeServiceTest.php tests/Feature/Ai/RuntimeReleaseGate/AtlasAiRuntimeReleaseGateServiceTest.php tests/Unit/AiToolRuntimeTest.php --filter=F0
PASS 4 tests, 29 assertions

/opt/homebrew/bin/php -l <each changed test>
PASS

vendor/bin/pint --test <three changed tests>
PASS

git diff --check
PASS
```

## Boundaries and concern

This is characterization only: no application code, migrations, production data, provider calls, catalog-tool executions, or fixture-cycle process execution changed. The unsafe gap is intentionally retained until a separately governed test strategy can cover `executeFixtureCycle` without violating F0's no-external-effect boundary.
