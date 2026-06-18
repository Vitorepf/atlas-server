---
ap: AP-807
title: Loop Preflight + Cycle Firewall and Post-Cycle Auditor
status: proposal
owner: agentic_engineering_os/dev_forge
schema: atlas.software_company_stewardship.loop_cycle_firewall.v1
supersedes: none
related: [atlas-long-horizon-loop-control-plane, AP-790, AP-793, AP-796, AP-798, AP-800, AP-801, AP-805, AP-806, AP-782, AP-783, AP-791]
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.


# AP-807 - Loop Preflight + Cycle Firewall and Post-Cycle Auditor

## Reality Status

This AP is a **contract proposal**, not a runtime completion claim. Its parent
implementation order is `docs/engineering-knowledge-base/atlas-long-horizon-loop-control-plane.md`.

Existing pieces already cover parts of the safety story:

- AP-805 checks ten-cycle readiness before a run.
- AP-806 certifies autonomy, arms the integration-lane envelope, admits
  Self-Construction packets, and tracks slice progression.
- AP-798/AP-801 provide the workcell judge and repair loop.
- AP-782/AP-783 provide the integration lane and promotion model.
- AP-790 provides the reliable 24h loop runner, locks, kill switch, ledger and
  cleanup surfaces.
- AP-791 defines receipt integrity.

AP-807 is the missing **per-cycle immune layer**: a hard pre-provider firewall
and a hard post-cycle auditor. Its job is to make the loop fail closed before it
burns provider calls and fail honestly after every cycle before it counts
anything as success.

## Why

The loop has already exposed the failure modes that matter most for 24h
autonomy:

- duplicate starvation-recovery commits that looked like productivity;
- false merges where a judge said `repair_required` after code had already
  landed;
- cross-system work trying to use the wrong merge target;
- big AAEOS findings being retried without a small executable packet;
- successive packets being validated against `main` instead of the lane head;
- provider calls spent on candidates that should have been blocked before
  execution;
- orphaned worktrees, locks and provider processes after interrupted runs.

The operator's target is not "the loop never sees problems." The target is:

```text
no provider call without admissible work
no merge without judge acceptance
no success count without evidence
no lane mode mutating main
no repeated filler
no hidden dirty state after the cycle
```

AP-807 turns that target into two runtime contracts:

1. **Loop Preflight + Cycle Firewall** - runs before provider execution.
2. **Post-Cycle Auditor** - runs after every cycle, whether merged, blocked or
   failed.

## Non Goals

- AP-807 is not a second loop.
- AP-807 does not replace AP-790, AP-805 or AP-806.
- AP-807 does not invoke providers.
- AP-807 does not judge code quality by itself; it verifies that the right
  quality gates ran and that their verdict was obeyed.
- AP-807 does not promote an integration lane to `main`.
- AP-807 does not make L2 container isolation real; that remains AP-793.

## Placement

AP-807 sits inside the Stewardship / Area Focus Loop:

```text
finding discovery
  -> candidate selection / Self-Construction packet admission
  -> Loop Preflight + Cycle Firewall
  -> provider / owner runtime / multi-agent workcell
  -> judge / merge governor / integration lane
  -> Post-Cycle Auditor
  -> learning / next cycle
```

The firewall is the last gate before cost. The auditor is the first gate after
execution truth.

## Part 1 - Loop Preflight + Cycle Firewall

The firewall answers one question:

> Is this exact next cycle allowed to spend a provider call?

If the answer is not an explicit `allow`, the cycle must stop as `blocked`
before provider invocation.

### Required Inputs

- `area`, `focus`, `scope_profile`, `run_id`, `cycle_index`.
- Current repo state: branch, dirty paths, worktrees, locks, kill/pause files.
- Selected candidate finding or packet.
- Active autonomy envelope, if any.
- Integration lane state, if any.
- Candidate rejection/admission report from AP-806.
- Slice progression state.
- Provider route / budget state when available.
- Current seen-finding / review-lock / quarantine state.

### Gate A - Environment State

Hard block when any of these are false:

- no kill switch is active;
- no stale loop lock is held;
- no unrelated dirty worktree state is present;
- no orphaned sandbox branch blocks the selected packet;
- no open merge lease conflicts with the target;
- if target is integration lane, the lane head is resolvable;
- if target is main, the selected work is factory-scoped only;
- provider process leftovers from a prior run are either absent or have a
  cleanup plan that runs before provider invocation.

### Gate B - Candidate Truth

Hard block when the selected candidate is:

- starvation recovery in autonomous `factory_max` mode;
- a routine missing-test task with no strategic value;
- a benchmark/rivals finding masquerading as implementation;
- review-locked without a changed blocker reason;
- quarantined for a non-transient failure;
- a repeated finding with the same `finding_id`, same blocker and same slice;
- missing a source document, evidence reference or canonical reason;
- marked as `planned` while being counted as executable.

Expected block status: `backlog_exhausted`, `admission_blocked`,
`review_locked`, `quarantined`, or `duplicate_blocked`. Never `success`.

### Gate C - Packet and Slice Fitness

For high-value AAEOS work, provider execution is allowed only for a bounded
packet, not for the whole strategic finding.

Hard block unless the candidate packet has:

- `parent_finding_id`;
- `packet_id`;
- `slice_sequence`;
- `active_slice_id`;
- `allowed_files` with a small explicit scope;
- `forbidden_files` / forbidden axes;
- `required_tests`;
- `expected_diff_shape`;
- `stop_conditions`;
- `owner_runtime`;
- claim / lease metadata from Self-Construction;
- dependency state proving prior packets are merged or not required.

The firewall must reject a packet whose text says "implement the whole feature"
instead of "execute only this bounded step."

### Gate D - Authority and Merge Target

Hard block when authority does not match blast radius:

- Cross-system AAEOS work requires an armed envelope.
- Cross-system autonomous work must route to the integration lane, not `main`.
- `main` auto-merge is allowed only for factory-scoped work inside the allowed
  risk ceiling.
- Forge plan-only or fixture paths must not be counted as real execution.
- Risk must be less than or equal to the envelope risk ceiling.
- Provider owner must be a runtime that can actually produce code today.

### Gate E - Cost and Provider Readiness

Hard block before provider invocation when:

- per-run budget is exhausted;
- provider timeout is below the floor;
- provider route is absent when routing is required;
- the exact same candidate already spent a provider call and hit the same
  blocker;
- the run has exceeded `max_blocked_in_row`;
- preflight cannot write a receipt for this cycle.

### Preflight Output

Schema: `atlas.software_company_stewardship.loop_preflight_firewall.v1`

```json
{
  "schema_version": "atlas.software_company_stewardship.loop_preflight_firewall.v1",
  "run_id": "string",
  "cycle_index": 1,
  "area": "agentic_engineering_os",
  "focus": "dev_forge",
  "status": "allow|block",
  "provider_allowed": true,
  "candidate": {
    "finding_id": "string",
    "packet_id": "string|null",
    "active_slice_id": "string|null",
    "source": "canonical_backlog|self_construction_packet|scanner"
  },
  "merge_target": "integration_lane|main|none",
  "blockers": [],
  "warnings": [],
  "gates": {
    "environment": "passed|blocked",
    "candidate_truth": "passed|blocked",
    "packet_fitness": "passed|blocked",
    "authority": "passed|blocked",
    "cost": "passed|blocked"
  },
  "report_hash": "stable_hash"
}
```

## Part 2 - Post-Cycle Auditor

The auditor answers one question:

> What actually happened, and is the loop allowed to count it?

It runs after every cycle, including blocked cycles and crashes.

### Required Inputs

- Preflight receipt for the same `run_id` and `cycle_index`.
- Cycle ledger row from AP-790.
- Owner-flow result.
- Workcell judge result.
- Merge governor result.
- Evidence / inbox receipt.
- Repo state before and after the cycle.
- Worktree / branch / provider process cleanup state.

### Audit A - Execution Truth

The auditor must verify:

- if preflight blocked, provider was not invoked;
- if provider was invoked, preflight had `status=allow`;
- provider invocation belongs to the selected packet, not a different finding;
- no blocked cycle is counted as a successful cycle;
- no cycle without provider execution is counted as a real implementation cycle.

### Audit B - Judge and Repair Truth

The auditor must verify:

- a merge is allowed only when the workcell judge status is accepted;
- `repair_required`, `rejected`, `operator_review` and `blocked` all block
  merge;
- repair attempts are tied to the same candidate and not a new hidden finding;
- judge evidence includes validation result, changed files, scope result and
  reviewer / judge verdict.

### Audit C - Merge Truth

The auditor must verify:

- `merge_performed=true` implies a real merge hash exists;
- `merge_performed=true` implies the target ref advanced;
- when target is `main`, `main_before != main_after`;
- when target is `integration_lane`, lane head advanced and `main` stayed
  unchanged;
- when target is `none`, no branch was advanced;
- a sandbox commit is not counted as a merge;
- a plan-only Forge output is not counted as implementation.

### Audit D - Evidence Truth

The auditor must verify:

- AP-791 receipt exists and is linked to the cycle;
- the receipt references the selected candidate / packet;
- changed files are recorded;
- required tests are recorded with pass/fail/block status;
- lane/main hashes are recorded;
- operator-visible evidence is available for the cockpit / Product Mode.

### Audit E - Cleanup Truth

The auditor must verify:

- cycle sandbox worktree is removed or intentionally retained with reason;
- sandbox branches are cleaned when merged / abandoned;
- loop lock is released or renewed correctly;
- no orphaned provider process remains;
- kill switch is respected;
- dirty worktree after the cycle is either absent or classified.

### Audit F - Progression and Learning Truth

The auditor must verify:

- a packet is marked completed only when it merged;
- a blocked packet records the exact blocker and retry policy;
- the next packet depends on the lane head when prior packets merged to lane;
- the same blocked packet is not selected forever;
- canonical backlog / learning feedback is updated with useful blockers;
- backlog exhaustion is reported honestly instead of selecting recovery.

### Post-Cycle Output

Schema: `atlas.software_company_stewardship.loop_post_cycle_audit.v1`

```json
{
  "schema_version": "atlas.software_company_stewardship.loop_post_cycle_audit.v1",
  "run_id": "string",
  "cycle_index": 1,
  "status": "valid_success|valid_block|invalid_cycle|critical_violation",
  "counts_as_real_cycle": false,
  "counts_as_success": false,
  "provider_invoked": false,
  "merge_performed": false,
  "merge_target": "integration_lane|main|none",
  "main_before": "sha|null",
  "main_after": "sha|null",
  "lane_before": "sha|null",
  "lane_after": "sha|null",
  "judge_status": "accepted_for_merge_governor|repair_required|blocked|unknown",
  "violations": [],
  "cleanup": {
    "worktrees_remaining": 0,
    "orphan_branches": 0,
    "locks_remaining": 0,
    "provider_processes_remaining": 0
  },
  "next_action": "continue|repair|stop_backlog_exhausted|stop_critical_violation",
  "report_hash": "stable_hash"
}
```

## Hard Invariants

These invariants are non-negotiable:

1. No provider call before preflight `allow`.
2. No recovery execution in autonomous `factory_max`.
3. No cross-system autonomous merge directly to `main`.
4. No merge unless the judge accepts before merge.
5. No success count without merge truth.
6. No real implementation count without provider invocation.
7. No sandbox commit counted as a merge.
8. No plan-only Forge path counted as implementation.
9. No repeated same finding / same packet / same blocker loop.
10. No final run status of clean while locks, worktrees, orphan branches or
    provider processes remain.

## Implementation Plan

### Services

Create or wire:

- `LoopPreflightCycleFirewallService`
- `LoopPostCycleAuditorService`
- `LoopCycleInvariantRegistry`

The services should be pure or mostly read-only. The only permitted writes are
receipts / ledger records and explicit cleanup evidence already owned by AP-790.

### Wire Points

- Before provider execution in AP-790 / owner-flow cycle dispatch.
- After candidate selection and Self-Construction packet admission in AP-806.
- Before AP-801 workcell execution spends provider calls.
- After AP-798 judge and before AP-782/AP-783 merge accounting.
- After merge governor result and before cycle success counting.
- During final run cleanup before reporting run completion.

### CLI

Expected command surfaces:

```bash
php artisan atlas:software-company-stewardship loop-cycle-firewall \
  --area=agentic_engineering_os --focus=dev_forge --run-id=... --cycle-index=... --json

php artisan atlas:software-company-stewardship loop-post-cycle-audit \
  --area=agentic_engineering_os --focus=dev_forge --run-id=... --cycle-index=... --json
```

These commands are diagnostic by default. They must not run providers or merge.

## Required Tests

Preflight tests:

- blocks starvation recovery in autonomous `factory_max`;
- blocks routine missing-test filler in high-power certification mode;
- blocks benchmark/rivals work as implementation;
- blocks cross-system work without envelope;
- blocks cross-system work to `main`;
- allows a bounded Self-Construction packet under a lane envelope;
- blocks provider invocation when preflight status is `block`;
- emits a stable report hash.

Post-cycle tests:

- detects historical false success: merge happened but judge was
  `repair_required`;
- accepts lane merge only when lane advanced and main did not;
- rejects sandbox-only commit as success;
- rejects provider call without preflight allow;
- detects orphan worktree / branch / lock / provider process;
- marks packet complete only after merge;
- records backlog exhaustion as valid block, not failure;
- emits a stable report hash.

Integration tests:

- one admitted canonical AAEOS parent becomes packets;
- packet 1 merges to lane;
- packet 2 validates against lane head;
- post-cycle auditor confirms both;
- cleanup leaves one worktree, zero orphan branches, zero locks and zero
  provider processes.

## 24h Certification Policy

A 24h run is not eligible to start unless:

- AP-805 readiness is `ready`;
- AP-806 autonomy cert for `aaeos_dev_integration_lane` is high enough for the
  configured threshold;
- AP-807 preflight passes for the first candidate;
- backlog depth report shows enough admissible packets for the intended run;
- post-cycle auditor is enabled and configured to fail closed.

A 24h run is not allowed to report success unless:

- 100 percent of cycles have preflight receipts;
- 100 percent of cycles have post-cycle audit receipts;
- zero false merges;
- zero unapproved main mutations in lane mode;
- zero recovery executions;
- zero unclassified dirty state at final cleanup;
- blocked cycles are counted separately from successful cycles.

## Attention Points

- AP-807 does not guarantee high output. It guarantees that low-output or blocked
  states are honest.
- AP-807 prevents provider waste only for known bad candidates. It still allows
  provider calls for candidates that pass the contract and then fail honestly.
- AP-807 must not become a new paperwork layer that providers can ignore. It
  must sit in the runtime path before cost and after result.
- AP-807 must keep `main` and `integration_lane` semantics visibly separate.
- AP-807 should surface "backlog depth is too low" as the next strategic blocker
  instead of letting the loop invent filler.

## Definition of Done

AP-807 is implemented only when:

- the firewall blocks provider execution for inadmissible candidates;
- the auditor can invalidate a cycle that looks successful but violates judge,
  merge, evidence or cleanup truth;
- every AP-790 cycle ledger row references both preflight and post-cycle audit
  receipts;
- a dry-run proves zero provider calls for blocked candidates;
- a lane run proves a valid packet merge is counted and a repair-required packet
  is not counted;
- final cleanup evidence is part of the run report;
- docs-health and the AreaFocusLoop test suite pass.
