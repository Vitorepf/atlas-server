# EXEC-LEDGER — GOD Debulk (implement)

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
layout: docs/evidence/2026-07-22-atlas-server-god-debulk/LAYOUT.md
phase: A1-SC-0155 complete
wave: A1
bucket: app/Services/Ai/SelfConstruction
focus: terminal-loop completion evidence integrity
finding_id: A1-SC-0155
action_op: test-first evidence and receipt revalidation repair
queue_index: 6
last_commit: a69a6f50e
godfiles_gt_2000_in_focus: 40
commands: |
  /opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalLoopHealthDigestTest.php --filter='test_fleet_evidence_rollup_(summarizes_completed_dry_run_evidence|rejects_an_internally_inconsistent_completion_receipt)'
  /opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionAgentControlPlaneTerminalLoopHealthDigestTest.php tests/Unit/Ai/SelfConstruction/AgentControlPlaneTerminalLoopHealthDigestServiceTest.php
  /opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/AgentControlPlaneTaskQueueOrchestrator.php
  /opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/ControlPlane/AgentControlPlaneTerminalLoopHealthDigestService.php
  vendor/bin/phpstan analyse --memory-limit=512M [orchestrator, digest, focused test]
  vendor/bin/pint --test [orchestrator, digest, focused test]
  bash scripts/god-debulk-guard.sh
  git diff --check
before_after: |
  red: a later dry_run_completion_recorded receipt with self-declared valid fields and four shaped 64-hex values made the real digest fleet_evidence_rollup_green.
  green: completion evidence is persisted by completeDryRun; the digest recomputes completion evidence validation, evidence digest, evidence validation hash and repository receipt hash against its task/lease/agent/scope binding. The same forged receipt produces fleet_evidence_rollup_attention_required.
stdout: |
  focused_test: OK (2 tests, 19 assertions)
  php_lint: No syntax errors detected in both production files and focused test
  loc_check: orchestrator=1934; terminal_loop_health_digest=1627
  guard: GOD_DEBULK_GUARD_OK
  diff_check: PASS
notes: |
  until cancel; consume META-FINDINGS; never dump findings here
  The correction changes only read-only digest eligibility and persists the already-validated completion evidence required for independent integrity revalidation; it adds no command, provider or token side effect.
  The broad digest package has 24 passed and 6 pre-existing failures in supply/tag/claim scenarios, all outside this diff. The two A1-SC-0155 acceptance tests pass.
  Strict Pint reports pre-existing host formatting findings. PHPStan reports 13 existing findings across orchestrator and digest hosts; no PHPStan error is emitted for the edited lines. No suppression or unrelated reformatting was added.
  The pre-existing >2k density baseline remains 40; both edited production files are below 2k.
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
