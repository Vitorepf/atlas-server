---
name: brain-circulation-coupling-spec
status: living
owner: loop (AutonomousEvolution)
created: 2026-06-27
summary: Canonical spec for the brain CIRCULATION obra — making the external brain's built organs load-bearing on the live decide/gate/originate paths (not dead JSON). Captures the verified runtime map, the S213–S216 arc, and the remaining coupling slices with exact seams.
graph_parent: brain-meta-improvement-engine-spec
---

# Brain Circulation & Coupling — obra spec

## Why this exists
A multi-day campaign built ~45 read-only Brain organs (perception / compounding / pattern /
adversarial-critique / simulation-twin). Honest audit (operator: "substrato sem circulação"):
the organs were a **library nobody consumed** — the live runtime (origination + gates) did not call
them, so they changed no behavior. This obra is **circulation**: wire the built capability into the
live decide/gate/originate paths so it flips real outcomes (which task is originated, accept/refuse),
flag-gated, author≠judge preserved, ZERO proxy.

Method of record: every big slice is **projected by a multi-agent Workflow** (map runtime → design
by lens → adversarial verdict → synthesis) BEFORE implementation. Two projection runs are captured
below; their adversarial verdicts killed the weak/proxy designs honestly.

## Verified runtime map (the live seams — grounded, do not re-derive)
- **Origination (decide → spec):** `AtlasBrainNextCommand::decide` builds the task via
  `AtlasLoopOriginationPipeline::produce()` (command call ~`:104`), BEFORE
  `scopeSignalsFor()`/`PortfolioRouter::route()` (~`:258`). `route()`'s `recommended_path` only
  reaches emitted JSON + a `LeverageBrief` action_hint consumed by an OUT-OF-PROCESS worker — it is
  NOT the decision seam. The real authoring seam is `produce()` →
  `leverageFirstMaterialTarget()` (pipeline ~`:121`), gated by
  `atlas.loop.leverage_first_origination_enabled` (default OFF). Candidate ranking comes from
  `AtlasLoopCrossTypeLeverageSelector::rankedForModel` — provider-reorder only when
  `leverage_selection_enabled` is armed; else producer deterministic order. **Discovery/ imports ZERO
  Brain namespaces** — origination was structurally blind to the brain's perception.
- **Second produce() caller:** `AtlasLoopCampaignSupervisor::maybeOriginate` (~`:893`) — L4 starvation
  origination, keyed by scope-ROOT + campaign, NOT the brain scope-slug. Different keying ⇒ brain
  per-target memory does not transfer cleanly here.
- **Gate (keep/drop):** `AtlasLoopSemanticImplementationCertifier::certify()` — the live in-process
  keep/drop the grinder runs (`AtlasLoopTaskGrinder` keeps iff `certified`). `certified = ($reasons === [])`.
  Appending a reason flips keep→drop. `$changedFiles` available at `:62`. The self-refereed judge
  synthesis is at certify() ~`:382` (grinder never supplies `options.judge_verdicts`, so the cert
  fabricates judges from its OWN engines).
- **Pétreo floor:** `config/atlas.php` + every `Brain/` organ + the certifier are in
  `AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS`. Switches: `ATLAS_LOOP_MASTER_ENABLED` (muscle),
  `AtlasBrainMasterSwitch`/`ATLAS_BRAIN_MASTER_ENABLED` (brain, fail-closed, .env-direct).
- **Anti-Goodhart landmine:** ranking origination candidates by a COMPUTED leverage scalar is the
  cyclomatic-proxy reborn (explicit warning in `AtlasLoopCrossTypeLeverageSelector` docblock +
  `loop-not-proxy-cleanup-feedback`). Use FACTUAL signals (refusal history, outcomes), never a scalar.

## Delivered arc (S213–S216) — author≠judge complete + origination coupling opened
- **S213 (default-ON):** author≠judge becomes a runtime CERT predicate. `AtlasBrainAuthorJudgeOverlapCheck`
  wired into `certify()` — a diff touching any FORBIDDEN_SELF_TARGETS path is REFUSED (not just
  edit-blocked). Violation decided by the guard's `isForbiddenSelfTarget` str_contains contract
  (NOT the organ's exact array_intersect — repo-relative paths vs path substrings). Closes the hole the
  12/06 autopsy caught LIVE (soak certified a proposal editing its own judge panel).
- **S214 (armed-ready):** verdict-side twin. `AtlasLoopJudgeConsensusGate` GATE 1b — a SOURCE-CLASS
  independence floor (`in_process|external`, curated enum the author can't fake) refuses a consensus
  carried only by cert-internal judges. Certifier stamps fabricated judges `in_process`, refuters
  `external`. Default `min_distinct_source_classes=0` (inert).
- **S215 (armed-ready):** Discovery→Brain coupling slice 1. `AtlasLoopOriginationPipeline::produce()`
  +`$refusalCounts`; `refusalAwarePick()` (pure public-static) DEMOTES (never excludes) targets the
  brain refused ≥N times below fresh ones. `AtlasBrainNextCommand::buildRefusalCounts` reads
  target-intrinsic refusals (`forbidden_target`/`prepare_blocked`) from the done-set ledger. Flags:
  `origination_refusal_memory_enabled` + `_min` (default OFF/2). Only the brain-next caller wired
  (supervisor keying differs).
- **S216 (arms S214):** `AtlasLoopTaskGrinder::withIndependentJudge` (pure static) appends
  `atlas.loop.independent_judge_cmd` to the semantic refuters → its verdict enters judge_verdicts as
  `external`, satisfying the S214 floor with a real second source. Empty default ⇒ byte-identical.

All flag-gated, default byte-identical, real behavior change when armed. Tests frozen + pint + phpstan
green per slice. Journal: `docs/loop-evolution-journal/brain-24h.md` L213–L216.

## Remaining slices (projected, NOT yet built)
1. **PerceptionBundle empty-starvation-map fix** (`AtlasBrainPerceptionBundle.php:85` passes `[]` as
   `starvationCyclesByPath` to `priorityRank->rank()` ⇒ starvation bonus is structurally 0). LOW
   leverage as-is (bundle is a report surface with no in-process decision consumer) — only worth doing
   if the bundle's path_priority_rank is first wired into a real decision. Pétreo file.
2. **Supervisor refusal memory** (`AtlasLoopCampaignSupervisor::maybeOriginate:893`) — needs a
   scope-root→scope-slug map before the brain's per-target memory can steer the L4 caller.
3. **Origination leverage made real (the big swing):** thread brain FACTUAL signals (refusal history,
   per-file outcome memory — NEVER a scalar) deeper into candidate selection so the brain originates
   the highest-real-leverage target, not the producer's head. Must defeat the anti-Goodhart landmine.
4. **Arming policy:** once a real soak validates origination quality, decide whether
   `leverage_first_origination_enabled` + `origination_refusal_memory_enabled` become the armed default
   (operator decision — changes default origination behavior).

## Invariants for every future slice (the régua)
- Project with a Workflow + adversarial verdict BEFORE building. Kill proxy/cosmetic/forced-fit designs.
- Real behavior change (flip an originated task or an accept/refuse), never richer-JSON-nobody-branches-on.
- author≠judge preserved; never weaken the pétreo floor (only strengthen).
- Flag-gated; default byte-identical; frozen test proving ON-flips + OFF-noop; pint + phpstan green.
- NEVER mutate brain code paths while a live reality-soak runs (each `brain:next` reloads code — mutating
  mid-soak corrupts the measurement). Freeze, then analyze, then resume.
