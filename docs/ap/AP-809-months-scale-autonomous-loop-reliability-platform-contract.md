---
ap: AP-809
title: Months-Scale Autonomous Loop Reliability Platform
status: proposal
owner: agentic_engineering_os/dev_forge
schema: atlas.software_company_stewardship.months_scale_loop_reliability.v1
supersedes: none
related: [atlas-long-horizon-loop-control-plane, AP-790, AP-793, AP-805, AP-806, AP-807, AP-808, AP-782, AP-783, AP-791]
---

# AP-809 - Months-Scale Autonomous Loop Reliability Platform

## Reality Status

This AP is a **contract proposal**, not a runtime completion claim.

AP-807 protects each cycle. AP-808 crash-tests the loop before long runs. AP-809
turns the loop into an always-on reliability platform capable of running for
weeks and months without silently degrading, exhausting resources, corrupting
state or depending on the operator to babysit every failure.

## Why

A loop that survives 24h can still fail over months because of accumulation:

- ledger and evidence growth;
- provider quota drift;
- stale worktrees and branches;
- backlog exhaustion;
- repeated low-value cycles;
- quality degradation;
- hidden locks;
- corrupted storage;
- dependency changes;
- model behavior changes;
- operator context loss.

AP-809 exists so "months" means **production-grade autonomous operation**, not a
very long script.

## Non Goals

- AP-809 is not a new loop.
- AP-809 does not replace AP-790/AP-805/AP-806/AP-807/AP-808.
- AP-809 does not grant autonomous destructive authority.
- AP-809 does not promote integration lanes to `main`.
- AP-809 does not bypass AP-793 isolation.
- AP-809 does not treat high output as success when quality drifts down.

## Operational Target

For months-scale operation the loop must satisfy:

```text
MTBOI (mean time between operator intervention) >= 7 days
false_merge_count == 0
recovery_filler_count == 0
unclassified_dirty_state_count == 0
orphan_process_count_final == 0
ledger_replay_available == true
provider_waste_rate <= configured ceiling
valid_cycle_quality_score_p50 >= configured floor
backlog_depth_regenerates == true
```

These are SLOs, not vibes.

## Part 1 - Always-On Supervisor

The supervisor owns long-lived operation outside a single run.

Responsibilities:

- start/stop/pause/resume the AP-790 runner;
- renew and audit leases;
- monitor heartbeat and stall windows;
- restart after safe crashes;
- stop on critical violations;
- kill orphaned provider processes;
- run cleanup finalizers;
- emit operator-visible status;
- refuse restart when AP-807/AP-808 certification is stale or failed.

Output schema: `atlas.software_company_stewardship.always_on_loop_supervisor.v1`

Hard rule: the supervisor may restart the loop, but it may not mutate source code,
merge branches or fabricate success.

## Part 2 - Durable Loop State Machine

The loop state must survive process death.

Durable states:

```text
run_planned
run_ready
cycle_planned
cycle_preflighted
cycle_executing
cycle_validated
cycle_judged
cycle_merged_or_blocked
cycle_audited
cycle_cleaned
run_paused
run_stopped
run_recovered
run_failed_closed
```

Rules:

- every state transition is append-only;
- every transition has `from`, `to`, `reason`, `actor`, `receipt_hash`;
- recovery resumes only from a known durable state;
- ambiguous state stops as `run_failed_closed`;
- state machine cannot skip AP-807 preflight/audit.

Output schema: `atlas.software_company_stewardship.durable_loop_state.v1`

## Part 3 - Ledger Compaction and Archival

Months of operation produce too much raw JSONL/evidence. AP-809 requires
compaction without losing replay.

Compaction outputs:

- raw segment sealed with hash;
- compact cycle index;
- commit/blocker/provider summary;
- evidence refs preserved;
- replay manifest;
- retention policy;
- archive location;
- integrity proof.

Rules:

- raw evidence is never deleted until sealed and indexed;
- compaction never changes cycle truth;
- replay must work across archived segments;
- failed compaction pauses the loop before disk exhaustion.

Output schema: `atlas.software_company_stewardship.loop_ledger_archive.v1`

## Part 4 - Provider Reliability Layer

Provider behavior changes over weeks. The loop must not assume one provider will
stay healthy.

Track:

- timeout rate;
- transient vs permanent failures;
- cost / call;
- approximate token usage when exact usage is unavailable;
- model quality by lane;
- rate-limit windows;
- fallback availability;
- provider circuit breaker state.

Required behavior:

- transient provider failure does not permanently quarantine a finding;
- repeated provider failure opens a circuit breaker;
- fallback route requires an auditable decision;
- degraded provider mode reduces concurrency and risk ceiling;
- provider spend above budget pauses the loop.

Output schema: `atlas.software_company_stewardship.provider_reliability_state.v1`

## Part 5 - Backlog Regeneration Engine

Months require continuous high-value work discovery. A fixed backlog will run out.

Allowed sources:

- AAEOS Runtime Gap Matrix;
- AP-806 canonical backlog;
- AP-807/AP-808 blocker reports;
- post-cycle quality score trends;
- docs Factory/evolution when present and promoted to canonical findings;
- failed tests / repair capsules;
- runtime telemetry;
- context/memory/retrieval gaps;
- stale docs vs runtime evidence drift.

Rules:

- generated backlog must become canonical finding objects;
- broad findings must become Self-Construction packets before provider execution;
- proposals do not execute directly;
- backlog regeneration must rank by multiplier, safety and executability;
- low-value filler cannot satisfy depth.

Output schema: `atlas.software_company_stewardship.backlog_regeneration.v1`

## Part 6 - Quality Drift Detector

Months-scale autonomy fails if code slowly gets worse while tests stay green.

Signals:

- quality score p50/p90;
- repair_required rate;
- blocked rate;
- test duration trend;
- changed-file churn;
- repeated touch of same subsystem;
- complexity / duplication trend when available;
- rollback/revert count;
- documentation drift;
- evidence completeness;
- operator intervention frequency.

Actions:

- reduce autonomy tier;
- pause high-risk packets;
- request Architecture/Security review;
- switch to maintenance window;
- generate backlog item for quality repair;
- stop long-run certification if drift crosses threshold.

Output schema: `atlas.software_company_stewardship.loop_quality_drift.v1`

## Part 7 - Self-Healing Maintenance Windows

The loop must periodically stop producing features and maintain itself.

Maintenance tasks:

- compact ledger;
- archive evidence;
- clean worktrees/branches;
- verify locks;
- run AP-807 audit replay sample;
- run AP-808 mini chaos suite;
- refresh provider health;
- recalculate backlog depth;
- update quality drift report;
- emit operator summary.

Default cadence:

- lightweight maintenance every few hours;
- full maintenance every 24h;
- deep maintenance before 7d/30d promotion.

Output schema: `atlas.software_company_stewardship.self_healing_maintenance_window.v1`

## Part 8 - Disaster Recovery

AP-809 must define recovery for damaged state.

Recovery scenarios:

- ledger tail corrupt;
- lane branch missing;
- sandbox branch dirty;
- lock owner dead;
- provider process orphaned;
- archive incomplete;
- evidence receipt missing;
- main/lane divergence unexpected;
- disk threshold exceeded;
- repeated critical violation.

Recovery policy:

- never guess;
- prefer pause + report over mutation;
- rebuild read models from sealed receipts when possible;
- preserve raw evidence before cleanup;
- require operator decision for irreversible repair;
- emit a disaster recovery packet.

Output schema: `atlas.software_company_stewardship.loop_disaster_recovery.v1`

## Part 9 - Autonomy SLOs

Months-scale claims require explicit service-level objectives.

Minimum SLO set:

| SLO | Target |
|---|---|
| false_merge_count | 0 |
| recovery_filler_count | 0 |
| unclassified_dirty_state_count | 0 |
| orphan_process_count_final | 0 |
| replay_coverage | 100% counted cycles |
| provider_waste_rate | below configured ceiling |
| quality_score_p50 | above configured floor |
| MTBOI | >= 7 days before months claim |
| backlog_regeneration_interval | within configured cadence |
| disaster_recovery_drill_passed | true before 30d claim |

Output schema: `atlas.software_company_stewardship.loop_autonomy_slo.v1`

## Part 10 - Monthly Certification Ladder

No jump from 24h to months.

Required ladder:

```text
24h clean
  -> 3d clean
  -> 7d clean
  -> 14d clean
  -> 30d clean
  -> monthly recurring certification
```

Each rung requires:

- AP-807 receipts for every cycle;
- AP-808 assurance report;
- supervisor report;
- resource report;
- quality drift report;
- backlog regeneration report;
- maintenance windows;
- disaster recovery readiness;
- operator-readable summary.

## CLI Surfaces

Expected command surfaces:

```bash
php artisan atlas:software-company-stewardship loop-months-readiness \
  --area=agentic_engineering_os --focus=dev_forge --horizon=30d --json

php artisan atlas:software-company-stewardship loop-supervisor-status \
  --area=agentic_engineering_os --focus=dev_forge --json

php artisan atlas:software-company-stewardship loop-maintenance-window \
  --area=agentic_engineering_os --focus=dev_forge --profile=full --json

php artisan atlas:software-company-stewardship loop-disaster-recovery-preflight \
  --area=agentic_engineering_os --focus=dev_forge --json
```

## Implementation Plan

Create or wire:

- `AlwaysOnLoopSupervisorService`
- `DurableLoopStateMachineService`
- `LoopLedgerArchiveService`
- `ProviderReliabilityLayerService`
- `BacklogRegenerationEngineService`
- `LoopQualityDriftDetectorService`
- `SelfHealingMaintenanceWindowService`
- `LoopDisasterRecoveryService`
- `AutonomySloMonitorService`
- `MonthlyCertificationLadderService`

Wire points:

- AP-790 reports to supervisor and state machine.
- AP-807 receipts feed state/audit truth.
- AP-808 reports gate long-run promotion.
- AP-806 backlog feeds regeneration and depth.
- AP-793 isolation status gates weeks/months.

## Required Tests

- Supervisor refuses restart when AP-808 is failed/stale.
- Durable state resumes from known state after simulated crash.
- Ambiguous state fails closed.
- Ledger compaction preserves replay manifest.
- Provider circuit breaker opens after repeated failures.
- Backlog regeneration refuses proposal-only broad work.
- Quality drift pauses autonomy tier.
- Maintenance window cleans controlled artifacts and preserves evidence.
- Disaster recovery preflight blocks irreversible repair without operator receipt.
- SLO monitor blocks 30d claim when any hard target fails.

## Definition Of Done

AP-809 is implemented only when:

- 24h, 3d and 7d rungs can be certified with reports;
- supervisor can recover from safe crashes;
- ledger compaction/archive passes replay checks;
- provider reliability state affects routing/budget;
- backlog regeneration replenishes high-value packets;
- quality drift detector can reduce autonomy tier;
- disaster recovery drill passes;
- months-readiness blocks honestly until every hard SLO is met.

## Attention Points

- AP-809 is required for months. AP-807/AP-808 alone are not enough.
- Months-scale autonomy without ledger compaction becomes disk/ledger risk.
- Months-scale autonomy without backlog regeneration becomes filler risk.
- Months-scale autonomy without quality drift detection becomes slow decay.
- Months-scale autonomy without L2 isolation remains unsafe for unattended claims.

