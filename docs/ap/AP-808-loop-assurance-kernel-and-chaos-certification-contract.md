---
ap: AP-808
title: Loop Assurance Kernel and Chaos Certification
status: proposal
owner: agentic_engineering_os/dev_forge
schema: atlas.software_company_stewardship.loop_assurance_kernel.v1
supersedes: none
related: [atlas-long-horizon-loop-control-plane, AP-790, AP-793, AP-805, AP-806, AP-807, AP-782, AP-783, AP-791]
---

# AP-808 - Loop Assurance Kernel and Chaos Certification

## Reality Status

This AP is a **contract proposal**, not a runtime completion claim. It is the
next hardening layer after AP-807.

AP-807 protects each real cycle with preflight and post-cycle audit. AP-808
tries to prove, before a long run starts, that the loop survives bad states,
faults, retry pressure, low backlog, provider failures and cleanup hazards
without lying, spinning or dirtying the repo.

## Why

The operator's requirement is stronger than "the loop can run." The real target
is:

```text
the loop can run for long hours,
under faults,
without false success,
without provider waste,
without hidden repo pollution,
and with enough evidence to replay every decision.
```

AP-808 is the crash-test layer. It must make the loop prove its invariants in
simulation and fault injection before anyone spends a 24h provider budget.

## Non Goals

- AP-808 is not a second runner.
- AP-808 does not replace AP-790, AP-805, AP-806 or AP-807.
- AP-808 does not call real providers during deterministic simulation.
- AP-808 does not merge code.
- AP-808 does not hide low output as success.
- AP-808 does not certify weeks/months without AP-793 L2 isolation.

## Position In The Loop

```text
AP-805 readiness
  -> AP-806 autonomy / envelope / backlog depth
  -> AP-807 preflight + post-cycle auditor enabled
  -> AP-808 assurance simulation + chaos certification
  -> short real proof
  -> 10-cycle certification
  -> 24h run
```

AP-808 runs mostly before the long run. It may also run after a long run to
replay the ledger and prove no invariant was violated.

## Part 1 - Deterministic Loop Simulator

The simulator runs many provider-free cycles over modeled candidates, packets,
locks, lanes, blockers and cleanup outcomes.

It must simulate:

- admissible canonical backlog parent;
- Self-Construction packet 1, 2, 3 progression;
- blocked packet with retry policy;
- backlog exhausted;
- recovery candidate trying to enter `factory_max`;
- cross-system candidate without envelope;
- cross-system candidate with lane envelope;
- judge accepted;
- judge repair_required;
- lane merge success;
- main merge forbidden;
- cleanup success/failure.

Required invariant: **no provider call is simulated unless AP-807 preflight would
allow it.**

Output schema: `atlas.software_company_stewardship.loop_simulation_run.v1`

Minimum acceptance before 24h: 1,000 deterministic simulated cycles with zero
critical invariant violations.

## Part 2 - Invariant Test Harness

The invariant harness encodes the rules that must never break.

Mandatory invariants:

```text
provider_invoked => preflight.status == allow
merge_performed => judge.status == accepted_for_merge_governor
merge_target == integration_lane => main_before == main_after
blocked => counts_as_success == false
sandbox_commit_only => counts_as_merge == false
forge_plan_only => counts_as_implementation == false
recovery_selected_in_factory_max => critical_violation
final_clean => locks == 0 && orphan_worktrees == 0 && orphan_processes == 0
same_finding_same_packet_same_blocker_repeated => duplicate_spin_violation
packet_completed => merge_performed == true
```

Invariant failures are fatal. They block 10-cycle and 24h certification.

Output schema: `atlas.software_company_stewardship.loop_invariant_report.v1`

## Part 3 - Chaos / Fault Injection

Chaos certification intentionally creates bad states in controlled simulation or
test fixtures. The loop must either recover or stop honestly.

Faults to inject:

- provider timeout;
- provider process killed mid-cycle;
- stale lock with dead pid;
- live lock owned by another process;
- dirty worktree before cycle;
- orphan sandbox branch;
- lane head missing;
- lane head behind expected packet;
- test command failure;
- judge `repair_required`;
- merge conflict;
- AP-791 receipt write failure;
- disk budget exceeded;
- max blocked-in-row exceeded;
- kill switch during provider execution;
- corrupted ledger tail;
- duplicate packet selected after block.

Pass condition: every injected fault leads to one of:

- preflight block before provider;
- valid blocked cycle with evidence;
- retry bounded by policy;
- cleanup with receipt;
- explicit critical violation that stops the run.

No injected fault may produce a false successful cycle.

Output schema: `atlas.software_company_stewardship.loop_chaos_certification.v1`

## Part 4 - Transactional Cycle Protocol

Every cycle must behave like a transaction.

States:

```text
prepare
  -> preflight
  -> execute
  -> validate
  -> judge
  -> merge_or_block
  -> post_cycle_audit
  -> persist_receipts
  -> cleanup
  -> next_state
```

Rules:

- A cycle cannot enter `execute` without preflight allow.
- A cycle cannot enter `merge_or_block` without validation and judge result.
- A cycle cannot enter `next_state` without post-cycle audit.
- If the process dies, recovery resumes from the last durable state.
- If durable state is ambiguous, stop as `critical_violation`, do not guess.

Output schema: `atlas.software_company_stewardship.transactional_cycle_state.v1`

## Part 5 - Resource Governor

Long runs fail when resource growth is invisible. AP-808 requires explicit
resource ceilings and receipts.

Track:

- provider calls;
- estimated tokens when provider token usage is unavailable;
- wall time per cycle;
- memory peak when observable;
- disk growth under storage/worktrees;
- ledger line count and size;
- provider process count;
- worktree count;
- branch count;
- blocked streak;
- retry count per finding/packet.

Hard stop examples:

- provider calls exceed budget;
- disk growth exceeds threshold;
- ledger cannot be appended;
- provider process count remains non-zero after cleanup;
- worktree count exceeds configured maximum;
- blocked streak exceeds configured maximum.

Output schema: `atlas.software_company_stewardship.loop_resource_governor.v1`

## Part 6 - Flight Recorder

The flight recorder is the black box for long runs. It must answer:

> Why does this commit, block, packet or provider call exist?

For each cycle it records refs to:

- preflight report;
- selected candidate / packet;
- provider session or simulated execution;
- changed files summary;
- validation commands;
- judge verdict;
- merge governor result;
- post-cycle audit;
- cleanup result;
- next candidate state;
- resource governor snapshot.

The recorder must be append-only and replayable. It may store summaries and
hashes, but must keep enough refs to reconstruct the decision chain.

Output schema: `atlas.software_company_stewardship.loop_flight_recorder.v1`

## Part 7 - Long-Run Certification Ladder

No direct jump to 24h.

Required ladder:

1. **10 simulated cycles** - smoke simulation.
2. **1,000 simulated cycles** - deterministic invariant burn-in.
3. **Chaos suite** - injected faults pass or block honestly.
4. **1 real lane cycle** - provider, judge, lane merge, audit.
5. **3 real successive packets** - packet progression.
6. **10 real cycles** - quality floor, no false success.
7. **2h run** - resource governor and cleanup.
8. **8h run** - backlog depth and blocked streak behavior.
9. **24h run** - full AP-807/AP-808 receipts.
10. **7d run** - only after replay/recovery and supervisor are proven.
11. **weeks/months** - only after AP-793 L2 isolation.

Each rung must leave an assurance report. Skipping a rung requires an operator
decision receipt and cannot be treated as normal readiness.

## CLI Surfaces

Expected diagnostic commands:

```bash
php artisan atlas:software-company-stewardship loop-assurance-simulate \
  --area=agentic_engineering_os --focus=dev_forge --cycles=1000 --json

php artisan atlas:software-company-stewardship loop-assurance-chaos \
  --area=agentic_engineering_os --focus=dev_forge --profile=pre_24h --json

php artisan atlas:software-company-stewardship loop-assurance-report \
  --area=agentic_engineering_os --focus=dev_forge --run-id=... --json
```

These commands are diagnostic/certification surfaces. They do not run real
providers unless explicitly documented by a later implementation AP.

## Implementation Plan

Create or wire:

- `LoopDeterministicSimulatorService`
- `LoopInvariantHarnessService`
- `LoopChaosCertificationService`
- `TransactionalCycleStateService`
- `LoopResourceGovernorService`
- `LoopFlightRecorderService`
- `LongRunCertificationLadderService`

Wire points:

- AP-805 readiness consumes latest assurance status.
- AP-806 autonomy cert includes backlog depth and quality readiness.
- AP-807 preflight/auditor emit data for invariant replay.
- AP-790 runner refuses 24h when AP-808 certification is stale or failed.

## Required Tests

- Simulated provider call cannot occur without preflight allow.
- Simulated lane merge cannot mutate main.
- Judge repair_required cannot produce success.
- Recovery in factory_max becomes critical violation.
- Duplicate same packet/blocker becomes spin violation.
- Stale dead-pid lock recovers; live lock blocks.
- Corrupted ledger tail stops as critical violation.
- Provider killed mid-cycle yields blocked/cleanup, not success.
- Resource governor stops when worktree/process/disk ceilings are exceeded.
- Flight recorder can answer why a merge or block happened.

## Definition Of Done

AP-808 is implemented only when:

- 1,000 deterministic simulated cycles pass with zero critical invariant
  violations;
- chaos suite passes for the pre-24h profile;
- AP-790 refuses 24h when AP-808 certification is missing/stale/failed;
- every real cycle can be replayed through the flight recorder;
- resource governor emits a final clean report;
- docs-health and AreaFocusLoop tests pass.

## Attention Points

- AP-808 does not increase output by itself. It increases confidence that output
  is real and safe.
- AP-808 must not become a decorative report after the run. It must gate before
  long runs.
- Simulation must not hide runtime truth. Real-cycle proofs are still required.
- L2 isolation remains a separate AP-793 milestone for weeks/months.

