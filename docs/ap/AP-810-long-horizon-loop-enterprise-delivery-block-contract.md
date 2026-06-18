---
ap: AP-810
title: Long-Horizon Loop Enterprise Delivery Block
status: proposal
owner: agentic_engineering_os/dev_forge
schema: atlas.software_company_stewardship.long_horizon_loop_delivery_block.v1
supersedes: none
related: [atlas-long-horizon-loop-control-plane, AP-790, AP-793, AP-805, AP-806, AP-807, AP-808, AP-809]
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.


# AP-810 - Long-Horizon Loop Enterprise Delivery Block

## Reality Status

This AP is a **delivery-structure contract**, not a runtime completion claim.

AP-807 defines per-cycle safety. AP-808 defines assurance and chaos
certification. AP-809 defines months-scale reliability. AP-810 turns those APs
into one enterprise delivery block with ordered slices, each independently
implementable, testable and certifiable.

## Why

The loop hardening work is now too important to be implemented as scattered
fixes. The operator's target is a software factory that can run for 24h, then
days, then months. That requires a professional delivery program:

- one final block of value;
- small slices;
- no broad "implement all reliability" prompts;
- explicit dependencies;
- runtime evidence per slice;
- promotion gates between maturity levels;
- a clean answer to "what is done, what is next, and what is still unsafe?"

## Final Block Definition

Name: `Long-Horizon Loop Enterprise Reliability Block`.

Mission:

```text
Make the Atlas Stewardship / Area Focus Loop safe, auditable, valuable and
recoverable enough to run AAEOS-focused work for long horizons:
10 real cycles -> 24h -> 3d -> 7d -> 30d -> months.
```

The block is complete only when every slice below is implemented, tested,
documented and evidenced.

## Delivery Rules

- Implement exactly one slice at a time unless a later slice is purely
  documentation.
- Every slice must produce a runtime artifact, test, command/report or evidence
  receipt.
- No slice may call providers unless its own preflight says it is allowed.
- No slice may weaken judge, lane, merge or cleanup invariants.
- No slice may count blocked work as success.
- No slice may turn docs Factory/evolution into broad provider prompts.
- Every slice updates the slice ledger with status: `planned`, `building`,
  `implemented`, `certified`, or `blocked`.

## Slice Schema

`atlas.software_company_stewardship.long_horizon_loop_delivery_slice.v1`

```json
{
  "slice_id": "LHL-01",
  "name": "Loop Preflight Firewall",
  "owner_ap": "AP-807",
  "status": "planned|building|implemented|certified|blocked",
  "depends_on": [],
  "unblocks": [],
  "runtime_artifacts": [],
  "commands": [],
  "tests": [],
  "evidence_refs": [],
  "hard_gates": [],
  "rollback_plan": "string",
  "operator_visible": true
}
```

## Enterprise Slice Plan

### LHL-00 - Reality Baseline and Delivery Ledger

Owner: AP-810.

Goal: create the delivery ledger and capture the exact starting state of `main`,
lane, locks, worktrees, provider processes, AP-805 readiness, AP-806 autonomy,
backlog depth and current doc status.

Depends on: none.

DoD:

- baseline command/report exists;
- records `main`, lane, locks, worktrees and processes;
- records current AP-807/AP-808/AP-809 implementation status;
- writes delivery slice ledger;
- no provider call.

### LHL-01 - Loop Preflight Firewall

Owner: AP-807.

Goal: block inadmissible work before provider execution.

Blocks:

- recovery/filler in autonomous `factory_max`;
- routine low-value missing-test candidates in high-power certification mode;
- cross-system without envelope;
- cross-system to `main`;
- packet without scope, tests, active slice or stop conditions;
- duplicate same packet/blocker;
- provider call without budget/timeout/route.

DoD:

- provider mock is not invoked when preflight blocks;
- preflight receipt exists for every attempted cycle;
- AP-790 can refuse a cycle before provider cost.

### LHL-02 - Post-Cycle Auditor

Owner: AP-807.

Goal: verify execution truth after every cycle.

DoD:

- detects merge with `judge=repair_required`;
- detects sandbox-only commit counted as merge;
- verifies lane merge leaves main unchanged;
- verifies cleanup final state;
- every AP-790 cycle ledger row links a post-cycle audit receipt.

### LHL-03 - Flight Recorder v0

Owner: AP-808/AP-809.

Goal: bind preflight, provider, validation, judge, merge, evidence, cleanup and
next-state references into one replayable cycle chain.

DoD:

- "why does this commit/block/provider call exist?" can be answered by command;
- counted cycles have full evidence refs;
- blocked cycles keep blocker and retry policy;
- no chat context required to explain a cycle.

### LHL-04 - Deterministic Loop Simulator

Owner: AP-808.

Goal: simulate the loop decision machine without providers.

Scenarios:

- admissible packet progression;
- cross-system without envelope;
- lane envelope;
- judge accepted;
- judge repair_required;
- backlog exhausted;
- recovery trying to enter `factory_max`;
- duplicate blocker spin.

DoD:

- 1,000 simulated cycles pass with zero critical invariant violations;
- simulation emits stable report hash;
- AP-805 can read latest simulation status.

### LHL-05 - Invariant Harness

Owner: AP-808.

Goal: encode non-negotiable rules as executable assertions.

Core invariants:

- `provider_invoked => preflight_allow`;
- `merge_performed => judge_accept`;
- `lane_mode => main_unchanged`;
- `blocked => not_success`;
- `sandbox_commit_only => not_merge`;
- `recovery_in_factory_max => critical_violation`;
- `final_clean => zero_locks + zero_orphans`.

DoD:

- invariant report blocks long-run readiness on violation;
- historical false-success fixtures fail the harness;
- clean lane packet fixtures pass.

### LHL-06 - Chaos and Fault Injection

Owner: AP-808.

Goal: prove the loop stops or recovers under controlled failure.

Faults:

- provider timeout / killed process;
- stale lock / live lock;
- dirty worktree;
- branch conflict;
- lane head missing;
- corrupted ledger tail;
- receipt write failure;
- kill switch during execution.

DoD:

- every injected fault produces block, bounded retry, cleanup or critical stop;
- no fault produces false success;
- chaos report gates 24h.

### LHL-07 - Transactional Cycle State

Owner: AP-808/AP-809.

Goal: persist cycle state transitions so crashes resume safely.

States:

`planned -> preflighted -> executing -> validated -> judged -> merged_or_blocked -> audited -> cleaned`.

DoD:

- every transition is append-only;
- ambiguous state fails closed;
- recovery resumes only from known durable state;
- AP-790 refuses to continue from corrupt state.

### LHL-08 - Resource Governor

Owner: AP-808/AP-809.

Goal: cap RAM, disk, provider calls, approximate token spend, ledger growth,
processes, worktrees, branches and blocked streak.

DoD:

- resource ceilings are configurable;
- exceeding ceiling pauses/stops with receipt;
- final run report includes resource summary;
- provider usage without exact tokens is still approximated honestly.

### LHL-09 - Backlog Depth Governor

Owner: AP-806/AP-809.

Goal: prove enough high-value executable work exists before 10 cycles/24h.

Inputs:

- AAEOS Runtime Gap Matrix;
- canonical backlog;
- Self-Construction packets;
- AP-807/AP-808 blockers;
- Factory/evolution proposals only after canonical promotion.

DoD:

- reports parents, packets, risk distribution and locked/quarantined counts;
- blocks 24h when packet depth is below floor;
- excludes filler/recovery from depth.

### LHL-10 - Backlog Regeneration Engine

Owner: AP-809.

Goal: regenerate high-value work over weeks/months so the loop does not starve.

Sources:

- runtime gaps;
- failed/blocked cycles;
- quality drift;
- evidence gaps;
- context/memory/retrieval gaps;
- docs Factory/evolution when present and promoted.

DoD:

- regenerated work becomes canonical finding objects;
- broad work becomes Self-Construction packets;
- ranking uses multiplier, safety and executability;
- low-value filler cannot satisfy regeneration.

### LHL-11 - Cycle Quality Score

Owner: AP-807/AP-809.

Goal: distinguish "valid merge" from "valuable engineering progress."

Signals:

- blocker reduced;
- packet progression advanced;
- AAEOS/Factory/evolution relevance;
- test/evidence quality;
- code churn;
- absence of filler;
- effect on context, memory, quality, agents, speed or robustness.

DoD:

- 10-cycle certification requires a quality floor;
- low-value valid merges do not count as "salto";
- Product Mode can show value accumulated.

### LHL-12 - Quality Drift Detector

Owner: AP-809.

Goal: detect gradual degradation during days/weeks.

Signals:

- repair_required rate;
- blocked rate;
- test duration;
- repeated subsystem churn;
- revert/rollback count;
- complexity/duplication trend when available;
- evidence completeness;
- operator intervention frequency.

DoD:

- drift can reduce autonomy tier;
- drift can pause high-risk packets;
- drift can generate backlog repair items.

### LHL-13 - Always-On Supervisor

Owner: AP-809.

Goal: operate the loop as a long-lived service, not a script.

DoD:

- heartbeat monitored;
- safe restart supported;
- orphan provider processes killed;
- stale locks handled;
- AP-807/AP-808/AP-809 stale certifications block restart;
- operator-visible status emitted.

### LHL-14 - Ledger Compaction, Archival and Replay

Owner: AP-809.

Goal: keep months of evidence replayable without unbounded disk/ledger growth.

DoD:

- raw segments sealed with hashes;
- compact index generated;
- replay works across archive segments;
- failed compaction pauses before disk exhaustion.

### LHL-15 - Self-Healing Maintenance Windows

Owner: AP-809.

Goal: schedule regular non-feature maintenance.

Tasks:

- compact ledger;
- archive evidence;
- clean worktrees/branches;
- verify locks;
- replay sample cycles;
- run mini chaos suite;
- refresh provider health;
- recalculate backlog depth;
- emit operator summary.

DoD:

- lightweight maintenance runs during long horizon;
- full maintenance runs before 24h/3d/7d promotion;
- maintenance preserves evidence before cleanup.

### LHL-16 - Provider Reliability and Circuit Breakers

Owner: AP-809.

Goal: handle provider drift, failures and cost over weeks.

DoD:

- transient failures do not permanently quarantine findings;
- repeated failures open circuit breaker;
- fallback route requires auditable decision;
- degraded provider mode lowers risk/concurrency;
- budget breach pauses the loop.

### LHL-17 - Disaster Recovery

Owner: AP-809.

Goal: recover or stop safely from damaged state.

Scenarios:

- corrupt ledger tail;
- missing lane branch;
- dirty sandbox;
- lock owner dead;
- missing evidence receipt;
- unexpected main/lane divergence;
- disk threshold exceeded.

DoD:

- irreversible repair requires operator receipt;
- raw evidence preserved before cleanup;
- disaster recovery report emitted;
- ambiguous state fails closed.

### LHL-18 - L2 Process Isolation Integration

Owner: AP-793/AP-809.

Goal: connect Sandcastle-class process/container isolation to the loop.

DoD:

- provider runs behind `sandbox_provider`;
- filesystem/env/network limits are explicit;
- secrets are not visible by default;
- AP-807/AP-808/AP-809 reports include isolation level;
- weeks/months claims block without L2.

### LHL-19 - Certification Ladder Automation

Owner: AP-808/AP-809.

Goal: automate promotion from small proof to months.

Rungs:

`10 simulated -> 1000 simulated -> chaos -> 1 real -> 3 packets -> 10 real -> 2h -> 8h -> 24h -> 3d -> 7d -> 14d -> 30d`.

DoD:

- each rung has report and promotion gate;
- skipping a rung requires operator decision receipt;
- failure at any rung records next blocker and stops promotion.

## Release Bundles

### Bundle A - Ten-Cycle Truth

Slices: LHL-00 through LHL-08.

Outcome: the loop can prove cycle-level truth, simulation invariants, chaos
behavior and resource ceilings before attempting 10 real cycles.

### Bundle B - 24h Production Readiness

Slices: LHL-09 through LHL-13.

Outcome: the loop has enough backlog, measures value, detects drift and has a
supervisor.

### Bundle C - 7d/30d Reliability

Slices: LHL-14 through LHL-19.

Outcome: the loop has archival, maintenance, provider resilience, disaster
recovery, L2 isolation and certification ladder automation.

## Promotion Gates

| Promotion | Required bundles |
|---|---|
| Try 10 real cycles | Bundle A |
| Try 24h | Bundle A + Bundle B |
| Try 7d | Bundle A + Bundle B + LHL-14..LHL-17 |
| Claim months readiness | Bundle A + Bundle B + Bundle C |

## Enterprise Acceptance

The full block is done only when:

- all slices are `certified`;
- docs-health and architecture-validate pass;
- AreaFocusLoop tests pass;
- 1,000 simulation cycles pass;
- chaos suite passes;
- 10 real cycles pass with quality floor;
- 24h passes with zero false success and clean final state;
- 7d passes with maintenance/archival/replay;
- months-readiness blocks or passes based on SLOs, never vibes.

