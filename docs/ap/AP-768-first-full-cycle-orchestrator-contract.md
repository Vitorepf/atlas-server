# AP-768 · Area Focus Loop · First Full Cycle Orchestrator

- **Status:** building
- **Stack:** Atlas Software Company Stewardship Stack (a stack/capability family inside the Atlas Autonomous Software Company Runtime, **not** a new OS).
- **Owner service:** `app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FirstFullCycleOrchestratorService.php`
- **CLI:** `php artisan atlas:software-company-stewardship first-full-cycle` (+ `first-full-cycles`, `first-full-cycle-replay`)
- **Composes (does not re-implement):** AP-766 runner · AP-748 deep scan · AP-771 priority engine · AP-718 spec bridge → Self-Directed Evolution · AP-756 branch sandbox · AP-767 Dev/Forge runtime execution bridge · AP-765 evidence/Product Mode/inbox result bridge · AP-769 branch merge governor · AP-780 branch review packet.

## Purpose

Stitch the already-shipped components into the **first complete, conservative, auditable 24h stewardship cycle** for one `area_id`/`focus`:

```
Runner (AP-766) → Deep Scan (AP-748) → Priority Rank (AP-771) → selected Finding → Spec/Proposal seed (AP-718)
  → Branch Sandbox (AP-756) → Dev/Forge Bridge (AP-767) → Evidence/Product Mode/Inbox (AP-765)
  → Branch Merge Governance (AP-769) → Branch Review Packet (AP-780) → final cycle receipt
```

Every stage either **RUNS** (delegated to its owner) or is **DEFERRED with a precise, machine-readable contract** describing exactly what the operator must supply to advance it. Nothing is hidden.

## Modes

- `--mode=dry-run` (default): read-only. Runner gate snapshot, deep scan, finding selection, proposal-only spec draft, **simulated** isolated sandbox descriptor (no disk), AP-767 plan, AP-765 deferred-with-contract. `final_status = dry_run_complete`.
- `--mode=execute`: advances to the gate-permitted point. AP-756 worktree creation runs **only** when the operator passes a real `--preflight-file` + `--sandbox-receipt-file` **and** `--materialize-sandbox`. Otherwise the sandbox stays simulated and AP-767 stops safely. AP-769 runs only after a real branch/result exists. `final_status = executed_to_gate`, or `cycle_closed` once a real `execution_result` flows into AP-765.

## Branch Merge Governance

AP-768 never treats a provider patch as complete merely because code changed. When a materialized cycle branch exists, the orchestrator calls AP-769 to produce:

- GitKraken-visible branch/base/commit metadata;
- conflict preflight;
- docs/tests/code classification;
- `review_required`, `auto_merge_eligible`, `merged`, or `blocked`;
- append-only governance record when requested.

Merge remains off by default. `merges=true` may appear in `claim_policy` only when the operator supplies AP-769 merge flags, the branch is conflict-free, the strategy is fast-forward only, and the AP-769 policy explicitly allows the merge.

After AP-769, AP-768 calls AP-780 to produce one GitKraken/Product Mode review
packet for the same branch. The packet carries branch/base refs, commits,
changed files, traceability, risk summary and safe decision options. If AP-769
is deferred, AP-780 is also deferred with `branch_governance_report_required`.

## Hard invariants (claim_policy)

`provider_invoked=false`, `mutates_main=false`, `deploys=false`, `pushes_external=false`, `touches_secrets=false`, `destructive_change=false`, `auto_approval=false`, `auto_implementation=false`, `creates_new_os=false`, `parallel_runtime_created=false`, `operator_review_required=true`. `branch_created`/`worktree_created`/`owner_command_executed_by_bridge` are only ever `true` via the explicit operator-gated AP-756/AP-767 flags. `merges=false` by default and can become `true` only through AP-769 fast-forward merge governance with explicit operator flags.

## Final receipt (required surface)

`cycle_id`, `area_id`, `focus`, `runner_receipt`, `scan_id`, `selected_finding`, `spec_proposal_seed`, `sandbox_receipt`, `dev_forge_execution_result`, `evidence_pack`, `inbox_item`, `product_mode_event`, `branch_merge_governance`, `branch_review_packet`, `tests`, `final_status`, `next_operator_action` (+ `stages`, `blockers`, `claim_policy`, `cycle_hash`). Schema `atlas.software_company_stewardship.first_full_cycle.v1`; append-only JSONL record (idempotent on `cycle_id`) when `--record`.

## Finding selection

Conservative boundary remains first: only `kind ∈ {test, doc, gap}` and `severity ∈ {low, medium}` are auto-selectable. Inside that safe set, AP-771 ranks by advancement, robustness, risk reduction, mergeability, confidence and conflict/blast-radius penalties. Critical/high risks are never auto-selected. `--selected-finding` (service input) overrides.

## Tests

`tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/FirstFullCycleOrchestratorServiceTest.php` (12 tests): dry-run full receipt; smallest-safe selection; blocked when no safe finding; simulated+isolated sandbox; AP-767 called; AP-765 deferred-with-contract; cycle closes on injected execution_result; AP-769 branch merge governance on materialized branch; AP-780 branch review packet from AP-769 governance; no dangerous action in either mode; proposal-only spec; record idempotency; replay.
