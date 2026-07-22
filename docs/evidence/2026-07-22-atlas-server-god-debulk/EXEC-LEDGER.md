# EXEC-LEDGER — GOD Debulk (implement)

```yaml
mission: atlas-server-god-debulk-execute
mode: implement
layout: docs/evidence/2026-07-22-atlas-server-god-debulk/LAYOUT.md
phase: A1-SC-0020 complete
wave: A1
bucket: app/Services/Ai/SelfConstruction
focus: post-start real-invoker release-preflight hash binding
finding_id: A1-SC-0020
action_op: test-first real upstream hash binding
queue_index: 4
godfiles_gt_2000_in_focus: 40
commands: |
  /opt/homebrew/bin/php artisan test --parallel tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php
  /opt/homebrew/bin/php artisan test tests/Feature/Ai/AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest.php --filter=post_start_release_preflight_contract_id_binds_real_upstream_hash
  bash scripts/god-debulk-guard.sh
  /opt/homebrew/bin/php scripts/god-debulk-codemap-verify.php
before_after: |
  red: expected public contract ID CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-0D573D858C1F456921CA1EFA, observed CC502A9CC5CD100781EEDDD7 while the doubled lookup was null.
  green: real facade release hash 3fadf8118b0d3d4a391d9f01405d23bf87ee12f148be0ec0b2e963f0a9a507ad, doubled lookup null, and public contract ID CODEX-REAL-INVOKER-POST-START-REAL-INVOKER-RELEASE-PREFLIGHT-GATE-037DFD9626B2EEFAEC62B5B1.
notes: |
  until cancel; consume META-FINDINGS; never dump findings here
  The only production change is the hash input key correction to codex_real_invoker_release_preflight_hash; no payload/schema field was renamed and no bridge, status, schema, split, or owner extraction changed.
  The pre-existing >2k density baseline is unchanged and is not claimed as improved.
  Strict Pint is clean for the focused test; the Codex section retains four pre-existing style findings. PHPStan is clean for the Codex section plus focused test at 3G.
halt_conditions_hit: []
```
