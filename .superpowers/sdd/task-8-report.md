# RuntimeExecution F0 review-correction report

Primary test commit: `af717ce0c29d54d60d2bf19d711859815d6fa2d9` (`test(core): correct RuntimeExecution F0 characterization`).

## Corrections delivered

- Removed the false same-`goal_record_id` relationship and the synthetic canonical table. The AVER legacy certification is now explicitly uncorrelatable until F4a persists that key.
- Bound the raw-diff SHA-256 characterization to the actual `AtlasRealEngineeringExecutionKernelService` producer expression through a source-contract assertion; the mutative producer is not invoked.
- Reframed 19/9/10 as the F3-pre seed inventory and added non-executing `PermissionRequest` coverage for the command-dependent `shell.run` modes.
- Added focused command stdout to the ledger receipt.

## Verification

```text
/opt/homebrew/bin/php artisan test tests/Feature/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeServiceTest.php tests/Feature/Ai/RuntimeReleaseGate/AtlasAiRuntimeReleaseGateServiceTest.php tests/Unit/AiToolRuntimeTest.php --filter=F0
PASS 5 tests, 32 assertions

/opt/homebrew/bin/php -l <both changed tests>
PASS

vendor/bin/pint --test <both changed tests>
PASS

git diff --check
PASS
```

## Residual concern

`executeFixtureCycle` remains intentionally uncalled because F0 forbids its temporary-workspace and child-process effects. Its public API inventory is still asserted; a separately governed strategy must cover that path.
