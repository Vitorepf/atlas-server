# EXEC-LEDGER — GOD Debulk (implement)

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
layout: docs/evidence/2026-07-22-atlas-server-god-debulk/LAYOUT.md
phase: A1-SC-0096 complete
wave: A1
bucket: app/Services/Ai/SelfConstruction
focus: durable-reservation contract consistency
finding_id: A1-SC-0096
action_op: test-first durable-reservation table and state alignment
queue_index: 6
last_commit: ec53f99e0
godfiles_gt_2000_in_focus: 40
commands: |
  /opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionDurableReservationSectionTest.php
  /opt/homebrew/bin/php -l app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php
  /opt/homebrew/bin/php -l tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionDurableReservationSectionTest.php
  find app/Services/Ai/SelfConstruction/Readiness/ReadinessProjectionDurableReservationSection.php tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionDurableReservationSectionTest.php -name '*.php' -print0 | xargs -0 wc -l | awk '$1 > 2000 {print}'
  vendor/bin/phpstan analyse --memory-limit=3G [section and focused test]
  vendor/bin/pint --test --verbose -- [section and focused test]
  bash scripts/god-debulk-guard.sh
  /opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
  git diff --check
before_after: |
  red: public storage schema table vector was [atlas_self_construction_reservation_events, atlas_self_construction_reservations], omitting atlas_self_construction_packet_snapshots and disagreeing with the ledger plan.
  green: ledger plan, storage schema and migration blueprint all return [atlas_self_construction_reservations, atlas_self_construction_reservation_events, atlas_self_construction_packet_snapshots]; storage schema and both lifecycle projections return [available, claimed, renewed, released, expired, completed, blocked].
stdout: |
  focused_parallel_test: OK (5 tests, 36 assertions)
  php_lint: No syntax errors detected in the durable-reservation section and focused test
  loc_check: no individual PHP file above 2000 lines; section=1972
  guard: GOD_DEBULK_GUARD_OK
  codemap: GOD_DEBULK_CODEMAP_OK targets=3
  diff_check: PASS
notes: |
  until cancel; consume META-FINDINGS; never dump findings here
  The correction is projection-only: no migration, storage write, schema version, status, command surface, compatibility facade or runtime behavior changed. The public regression also proves all available authority flags remain false.
  PHPStan reports 27 pre-existing errors from the section's dynamic __call forwarding; no suppression, facade edit or ownership change was added. Strict Pint reports four pre-existing section style findings (class_attributes_separation, braces_position, no_unused_imports, single_line_empty_body).
  The pre-existing >2k density baseline is unchanged and is not claimed as improved.
  Historical label hold remains open: immutable content commit 52fd8598c has a test(core) subject despite its app diff; the canonical rule requires refactor(core), and all later app-diff cycles must use refactor(core).
halt_conditions_hit:
  - historical_label_mismatch_52fd8598c_test_core_subject_for_app_diff_requires_refactor_core_unresolved
```
