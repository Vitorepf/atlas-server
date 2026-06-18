---
ap: AP-806
title: Loop Autonomy Certification + Integration-Lane Autonomy Envelope (slice spec)
status: building
owner: agentic_engineering_os/dev_forge
schema: atlas.software_company_stewardship.loop_autonomy_certification.v1
supersedes: none
related: [AP-790, AP-795, AP-796, AP-797, AP-798, AP-799, AP-800, AP-801, AP-804, AP-805, AP-782, AP-783]
---

> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.


# AP-806 — Loop Autonomy Certification

## Why

The operator's goal is a stewardship loop that, **turned on once**, runs for a
week (then a month) implementing the AAEOS documentation backlog with a full
quality cycle, merging to a safe target, reviewed at the end of the week.

The blocker to that goal is not "more read models" — it is knowing, honestly,
**which steps of the loop are already autonomous and which still depend on a
human or on a runtime that does not exist yet**, and turning that knowledge into
the next concrete unblock. Certifying autonomy is the map; **eliminating
per-cycle dependency is the mission.**

`LoopAutonomyCertificationService` is the ruler. It is read-only and composes
AP-805 (`TenCycleReadinessGovernorService`) — it never duplicates AP-805's
probes, never runs the loop, never invokes a provider, never merges.

## What it certifies

For a target operating mode it classifies every loop stage as exactly one of:

- `autonomous` — the loop already does it, no human, no missing runtime.
- `policy_pre_authorized` — autonomous once a one-time standing envelope is set.
- `requires_operator_once` — one-time operator action (e.g. configure provider).
- `requires_operator_per_cycle` — human approval every cycle (anti-autonomy).
- `requires_claude_manual` — currently depends on Claude doing it (a crutch).
- `not_implemented` — the runtime does not exist yet.
- `unsafe` — would be autonomous but is unsafe without a gate.

Stages (full cycle, in order): finding_discovery, priority, scope_admission,
slice_planner, spec_architecture, route_dev_vs_forge, forge_intake_obra,
provider_topology_decide, budget, sandbox, execution, judge_repair, evidence,
inbox_product_mode, merge_target, learning_compounding, continuation_recovery.

Modes: `factory_scoped_self_improvement`, `aaeos_dev_integration_lane`
(the operator's first real autonomy target), `aaeos_forge_full`.

## Output (operational, not just informative)

`autonomy_score`, `autonomy_band`, `verdict`, per-stage `state`+`remediation`,
`blockers_by_impact` (ordered), `per_cycle_dependencies_to_eliminate`,
`next_executable_slice`, `most_autonomous_command_now` (honest about its limit),
`horizon_gaps` (24h / 7d / 30d), and a deterministic `report_hash`.

CLI: `php artisan atlas:software-company-stewardship loop-autonomy-certify
--area=agentic_engineering_os --focus=dev_forge --target-mode=aaeos_dev_integration_lane --json`

## Honest verdict at landing (2026-05-28)

- `factory_scoped_self_improvement`: **0.938** — closest to autonomous. The loop
  already detects→executes (real provider)→judges→auto-merges factory-scoped
  work with no human (proven by prior autonomous merges). Open gaps:
  `learning_compounding` not wired back into selection; finding engine emits
  strategic roadmap + vague seeds, not concrete factory tasks (quality, not
  mechanism).
- `aaeos_dev_integration_lane`: **0.776 — NOT autonomous.** Blockers by impact:
  `merge_target` (not_implemented), `scope_admission` (not_implemented),
  `learning_compounding` (not_implemented).
- `aaeos_forge_full`: **0.641** — `execution` is `not_implemented`: Forge runtime
  is plan-only (`owner_flow_forge_planned`) or fixture
  (`AtlasForgeLiveExecutionService`). **Forge does not generate real code today.**
  It is reported as a blocker, never dressed as ready.

## Next unblock — Integration-Lane Autonomy Envelope (SPEC, NOT YET IMPLEMENTED)

The single highest-impact unblock toward the week-long AAEOS loop, with no new
Forge runtime and no per-cycle human:

A one-time **standing envelope** the operator configures once (area, duration,
budget, allowed providers, risk ceiling, forbidden actions, **merge target =
integration lane**, quality criteria), after which the loop runs alone.

Sliced for safety (admission and merge-target are coupled — admitting
cross-system work without forcing the safe merge target would be unsafe):

1. **`StewardshipAutonomyEnvelope` value object** — immutable, validated standing
   policy. Pure primitive, no behavior change. (factory-scoped, safe)
2. **Scope-admission profile `aaeos_dev_integration_lane`** — in
   `candidateRejectionReason`, admit cross-system **atlas_dev** findings ONLY when
   an envelope with `merge_target=integration_lane` is present; factory_max
   behavior unchanged when no envelope is set.
3. **Merge-target enforcement** — admitted cross-system work resolves its merge
   target to the governed integration lane (AP-782/783), **never main**;
   cross-system auto-merge to main stays forbidden (`unsafe`).
4. Operator reviews + promotes the integration lane to main at week's end
   (AP-783).

Acceptance: under the envelope profile a cross-system atlas_dev finding is
ADMITTED and its merge target resolves to the integration lane; with no envelope,
factory_max is byte-identical; cross-system → main remains forbidden.

This is the path to "turn it on, it implements AAEOS for a week, I review the
commits" — without a crutch and without faking Forge.

## Slice 1 LANDED (2026-05-28) — envelope mechanism, gated & safe

Implemented and tested, but **NOT yet armed for a live autonomous run** (honest):

- `StewardshipAutonomyEnvelope` value object: immutable standing policy. SAFETY
  INVARIANT enforced — a cross-system envelope with `merge_target=main` throws;
  `forge` is dropped from allowed owners (plan-only/fixture today).
- Scope admission: `candidateRejectionReason` admits cross-system **atlas_dev**
  findings (with a real runtime source, within risk ceiling) ONLY under an
  envelope routing to the integration lane. **Byte-identical** to prior
  factory_max when no envelope is present (full AreaFocusLoop suite green).
- Merge routing: `governedMergeForCycle` routes a cycle's merge through AP-782
  `integrate()` (which by construction NEVER mutates main) when the envelope
  routes to the lane; otherwise the existing ff-only merge into main. AP-782 now
  honors `allow_code_auto_merge`/validation so the lane respects the same policy
  as main.

## Slice 2 LANDED (2026-05-28) — the envelope is now ARMABLE (operator arms it)

- `StewardshipAutonomyEnvelopeService`: arm / show / disarm a standing policy
  with `area, focus, duration, budget (max_cycles/max_merges), allowed_providers,
  risk_ceiling, forbidden_actions (safe defaults always applied), merge_target =
  integration_lane, quality_criteria, operator_actor, policy_hash`. Invalid /
  unsafe (no operator actor, cross-system→main) is BLOCKED, never armed.
- The loop loads the armed envelope automatically (`current()`) so a one-time
  arming applies to every cycle with NO per-cycle approval. Nothing armed →
  byte-identical.
- Lane-based sandbox branches: when the envelope routes to the lane and the lane
  exists, the AP-756 materializer bases the sandbox branch on the lane ref (not
  main) so cycle #2 fast-forwards the lane instead of blocking.
- CLI: `atlas:software-company-stewardship loop-autonomy-envelope
  --envelope-action=arm|show|disarm --operator-actor=… --admit-cross-system …`.
- Product Mode visibility: arming emits an `autonomy_envelope_armed` event.

The certifier now scores `aaeos_dev_integration_lane` ≈ **0.91 (high)** with
`scope_admission` and `merge_target` = `policy_pre_authorized`. **I did NOT
auto-arm a policy — the operator arms it.**

### Remaining (NOT done — do not fake)

1. **Operator arms** a standing envelope (one-time CLI), then turns on the loop.
2. **Live-run proof**: a real cross-system AAEOS cycle landing on the lane has
   not been run yet (integrate eligibility for code + senior loop on cross-system
   must hold in practice). `learning_compounding` is still not wired back into
   selection (the remaining cert blocker).
3. **7-day stability proof** (lease renewal, pollution control, backlog depth).

Forge real execution stays `not_implemented` throughout.

## Follow-up: workcell judge is now a HARD pre-merge gate (false-success closure)

A read-only certification of the loop found the #1 safety blocker for unattended
24h autonomy: the AP-801 multi-agent workcell judge was a **post-hoc projection**
attached *after* `runCycle` had already merged — it had no veto. The AP-790
ledger proved the failure mode concretely: cycles **251–254 and 259 merged real
commits to `main` while the workcell judge said `repair_required`
(`merge_eligible=false`)** — exactly the false-success the operator was burned by.

Fix (`AutonomousEvolutionSessionService`):

- `workcellMergeGate()` runs the workcell judge on the **executed + committed**
  cycle **before** the merge. Reaching the merge means `ownerFlow.merge_allowed
  === true` (owner runtime verified), so the judge gets a real `validation=passed`
  and independently gates scope / evidence / reviewer / diff-shape / risk.
- In `runOwnerFlowCycle`, when the workcell is engaged and the judge does **not**
  accept (`repair_required` / `rejected` / `operator_review` / `blocked`), the
  merge is **blocked** (`workcell_judge_not_accept:<status>`); evidence/inbox are
  still emitted. On any workcell error the gate **fails closed** (no merge).
- The gate is **provider-free** (it judges an already-executed result), so it adds
  zero provider cost. The post-loop projection reuses the gate's verdict instead
  of re-running the lanes.

Proven (`WorkcellMergeGateTest`, 4 tests): a clean validated in-scope cycle is
ACCEPTED (`accepted_for_merge_governor`, `merge_eligible=true`) so the gate does
not starve legitimate merges; a validation-failed cycle and a scope-violating
cycle are both refused; the gate is inert when the flag is off. Regression: 662
AreaFocusLoop + AgentExecution tests green.

## Follow-up: slice-progression (big findings complete, never stuck at step 1)

`firstSemanticSlice` always returned sequence 1 with no completed-slice tracking,
so a decomposed big finding got sliced-then-stuck (contract merged; skeleton /
first_behavior never ran → half-features). Fix (`AutonomousEvolutionSessionService`
+ `Reliable24hLoopRunnerService`):

- `findingKeys`: a finding narrowed to a slice locks/tracks ONLY that slice
  (`active_slice_id`), so completing slice N never review-locks the parent.
- `completedSemanticSliceIds`: merged slice_ids from the durable session record
  (only `cycle_completed` counts; a failed slice stays pending → retried, so
  depends_on order is preserved).
- `firstSemanticSlice(plan, completed)` returns the first PENDING slice; once every
  slice merged, `runCycle` finalizes (parent review-locked) WITHOUT another
  provider call. `file_group` plans (no semantic steps) proceed normally.
- runner `findingKey` prefers `active_slice_id` so progression never trips the
  duplicate-finding stop.

Pinned by `AutonomousEvolutionSliceExecutionTest` (+4) — advance to next slice,
all-done → null, slice-scoped keys, durable record-scan. Requires `--record`.

## Structural backlog (Gap Matrix consumption)

The loop ALREADY consumes a deep structural backlog: `factoryMaxSeedCandidates()`
seeds ~13 high-impact factory targets (forge topology, owner runtime, scheduler,
merge governor, provider routing, sandbox, deep scan, …) pointed at real
`AreaFocusLoop/` runtime + focused tests — these ARE the Runtime Gap Matrix's
structural backlog (Proxima Acao #2), factory-scoped + judge-gated. Deriving the
seed set DYNAMICALLY from the live Gap Matrix doc is deferred: adding/removing
static seeds shifts the `factory_max` candidate-exhaustion + starvation-recovery
state machine that ~21 tests deliberately pin, so it needs its own contract pass —
not a rushed addition (kept the 24h loop stable instead).

## Claim policy

Read-only certification. The envelope mechanism never auto-merges cross-system
work to main (routed through the lane, which cannot touch main). Forge real
execution is `not_implemented` today. False autonomy is never claimed.
