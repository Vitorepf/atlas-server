# EXEC-LEDGER — GOD Debulk (implement)

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
layout: docs/evidence/2026-07-22-atlas-server-god-debulk/LAYOUT.md
phase: A1-SC-0106 complete
wave: A1
bucket: app/Services/Ai/SelfConstruction
focus: fail-closed task dependency serving
finding_id: A1-SC-0106
action_op: test-first dependency-order authorization repair
queue_index: 6
last_commit: c23319df3
godfiles_gt_2000_in_focus: 40
commands: |
  /opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasTaskServingDependencyOrderTest.php tests/Feature/Ai/AgentControlPlaneTaskDependencyClassifierTest.php tests/Unit/Ai/SelfConstruction/TaskQueue/AgentControlPlaneTaskDependencyClassifierTest.php
  /opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/TaskQueue/AgentControlPlaneTaskDependencyClassifier.php
  /opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
  vendor/bin/phpstan analyse --memory-limit=512M [classifier, orchestrator, focused tests]
  vendor/bin/pint --test [classifier, orchestrator, focused tests]
  bash scripts/god-debulk-guard.sh
  git diff --check
before_after: |
  red: 11 tests failed. The real AtlasTaskServingService::next path returned served for a packet with an absent, cancelled, or cyclic prerequisite; both classifier suites also returned met.
  green: only completed_dry_run is satisfied. Missing, cancelled, and cyclic prerequisites return blocked; the real serving path returns no_claimable_task.
stdout: |
  focused_test: OK (57 tests, 77 assertions)
  php_lint: No syntax errors detected in both production files and all focused tests
  loc_check: orchestrator=1933; dependency_classifier=375
  guard: GOD_DEBULK_GUARD_OK
  diff_check: PASS
notes: |
  until cancel; consume META-FINDINGS; never dump findings here
  The correction changes serving authorization: a dependent task now requires recorded prerequisite completion. It adds no storage schema, command, or provider side effect.
  Strict Pint reports pre-existing formatting findings in all five focused hosts. PHPStan reports 11 findings: seven existing orchestrator issues plus four existing test-closure return-type issues; no PHPStan error is emitted for the edited classifier. No suppression or unrelated reformatting was added.
  The pre-existing >2k density baseline remains 40; the edited production files are below 2k.
  Historical label hold remains open: immutable content commit 52fd8598c has a test(core) subject despite its app diff; the canonical rule requires refactor(core), and all later app-diff cycles must use refactor(core).
halt_conditions_hit:
  - historical_label_mismatch_52fd8598c_test_core_subject_for_app_diff_requires_refactor_core_unresolved
```

## RuntimeExecution F0 receipt — 2026-07-22 (SUPERSEDED)

This initial receipt is retained for provenance but superseded by the
review-correction receipt `b3c02f4e7`. Its claim that a legacy AVER
certification and an absent canonical certification were related by the same
logical `goal_record_id` is invalid: the current AVER execution schema does
not persist `goal_record_id`, so that correlation cannot be claimed before
F4a.

```yaml
primary_commit: 5a954a59b4cc1133d006b08ff00d639c84570f58
subject: test(core): GOD-DEBULK RuntimeExecution F0 characterization
scope:
  - tests/Feature/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeServiceTest.php
  - tests/Feature/Ai/RuntimeReleaseGate/AtlasAiRuntimeReleaseGateServiceTest.php
  - tests/Unit/AiToolRuntimeTest.php
production_changes: false
external_effects: false
acceptance:
  aver_safe_public_apis: 9
  aver_static_public_inventory: 10
  aver_unsafe_gap: executeFixtureCycle creates a temporary workspace and spawns a process; it was not called
  lineage: "SUPERSEDED/INVALID — this initial assertion that legacy AtlasAverCertifiedExecution and an absent AiRealExecutionCertification share the same logical goal_record_id is invalid. The current AVER schema has no persisted goal_record_id, so no such correlation can be claimed before F4a; see canonical correction b3c02f4e7."
  hash_representations: AVER diff_hash hashes its payload and is asserted non-equivalent to RealExecution SHA-256 of the raw diff
  release_gate: frozen-time byte JSON snapshot for atlas.ai.runtime_release_gate.v1 and upstream report spy count equals 1
  tool_catalog: 19 static tools classified as 9 read-only and 10 gate-required without execute calls
verification:
  command: /opt/homebrew/bin/php artisan test tests/Feature/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeServiceTest.php tests/Feature/Ai/RuntimeReleaseGate/AtlasAiRuntimeReleaseGateServiceTest.php tests/Unit/AiToolRuntimeTest.php --filter=F0
  result: PASS 4 tests, 29 assertions
  lint: /opt/homebrew/bin/php -l on all three changed tests PASS; vendor/bin/pint --test on all three changed tests PASS
  diff_check: git diff --check PASS
```

## RuntimeExecution F0 review-correction receipt — 2026-07-22

```yaml
primary_commit: af717ce0c29d54d60d2bf19d711859815d6fa2d9
subject: test(core): correct RuntimeExecution F0 characterization
production_changes: false
external_effects: false
corrections:
  lineage: AVER certification is characterized as legacy and uncorrelatable; atlas_aver_executions has no goal_record_id, so no same-goal or absent-canonical-certification claim is made before F4a.
  real_diff_hash: test source-links AtlasRealEngineeringExecutionKernelService's "diff_hash => hash('sha256', $diff)" producer expression without running its mutative kernel.
  f3_pre_catalog: 19/9/10 remains a declared seed inventory, not today's permission classifier; non-executing PermissionRequest coverage proves shell.run can be read/low, write/medium, or danger/high by command.
verification:
  command: /opt/homebrew/bin/php artisan test tests/Feature/Ai/VerifiedExecution/AtlasVerifiedExecutionRuntimeServiceTest.php tests/Feature/Ai/RuntimeReleaseGate/AtlasAiRuntimeReleaseGateServiceTest.php tests/Unit/AiToolRuntimeTest.php --filter=F0
  stdout: |
    PASS Tests\\Feature\\Ai\\VerifiedExecution\\AtlasVerifiedExecutionRuntimeServiceTest
    PASS Tests\\Feature\\Ai\\RuntimeReleaseGate\\AtlasAiRuntimeReleaseGateServiceTest
    PASS Tests\\Unit\\AiToolRuntimeTest
    Tests: 5 passed (32 assertions)
  lint: /opt/homebrew/bin/php -l on both changed tests PASS
  pint: vendor/bin/pint --test on both changed tests PASS
  diff_check: git diff --check PASS
```
