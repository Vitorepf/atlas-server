---
id: atlas-earned-autonomy-adversarial-immune-system
type: engineering_knowledge
doc_schema: atlas_canonical_module_doc.v1
title: Atlas Earned-Autonomy Adversarial Immune System — Autonomy Earned One Risk-Class at a Time
slug: atlas-earned-autonomy-adversarial-immune-system
status: future
implementation_state: built_default_off_no_runtime_authority_yet
category: agentic-engineering
priority: 96
summary: >
  Canonical design for the EARNED-AUTONOMY layer over Governed RSI: the only place
  in Atlas where a guard-PASSED self-improvement proposal may become auto_apply
  instead of staying proposal-only — and ONLY when the operator has explicitly
  turned the layer on AND the loop has paid for that autonomy by surviving a
  standing adversary. The thesis is adversarial and antifragile: autonomy is never
  granted, it is EARNED one risk-class at a time, paid for by a run of qualifying
  proven cycles that each survived a live red team and showed no drift, and is
  AUTO-REVOKED to tier 0 the instant drift or a red-team breach appears. The layer
  is DEFAULT-OFF behind ATLAS_EARNED_AUTONOMY_MODE: with the flag off, behaviour is
  byte-identical to today — every RSI proposal stays proposal-only / human-gated.
  Six composable leaf services (RiskClassifier, TrustLedger, DriftAnomalyDetector,
  KillAuthority, StandingRedTeam, EarnedAutonomyGate composer) sit ON TOP of the
  existing Immutable Invariant Registry + RsiInvariantGuard + measured-or-reverted
  keystone. They add NO new provider, NO new OS, and crucially NO new authority to
  weaken any sacred gate: the standing red team attacks THROUGH the live guard, so
  the new layer's safety is exactly as strong as the production barrier it composes.
tags: [atlas-ai, software-company, rsi, earned-autonomy, adversarial, immune-system, invariant-registry, drift-detection, kill-switch, red-team, default-off, provider-proof, no-scaffold]
capabilities: [risk_class_ladder, append_only_trust_ledger, drift_anomaly_detection, external_kill_switch_dead_man, standing_red_team, earned_autonomy_gate_composer]
decisions:
  - DEFAULT-OFF is the master contract. ATLAS_EARNED_AUTONOMY_MODE defaults false; with the flag off (and the kill switch disarmed by default) the EarnedAutonomyGate composer returns decision=human_gate at rule R0, so RsiSelfImprovementProposalGate is byte-identical to today's proposal-only behaviour. The flag alone authorizes NOTHING — KillAuthority::isArmed() must ALSO be true.
  - Autonomy is EARNED, never granted. The TrustLedger maps a run of CONSECUTIVE qualifying proven cycles to a max-auto-rank; a cycle qualifies only when outcome_proven AND red_team_survived AND drift_clean are all genuinely true (strict-bool, fail-closed). The loop has no method to write a pass without those four real signals.
  - The risk-class CEILING is absolute. A gate_or_invariant_touch (rank 3) proposal can NEVER auto_apply at ANY tier, regardless of accrued trust — hard-capped in the composer before tier comparison. earnedTier() never returns 3.
  - DRIFT and a RED-TEAM BREACH each force an immediate REVOKE to tier 0 + human_gate. The DriftAnomalyDetector reads ONLY the existing append-only ComponentValueLedger (no invented metric) and fails closed; the StandingRedTeam attacks THROUGH the live RsiInvariantGuard, so "survived" is never a simulated yes.
  - The KILL SWITCH lives OUTSIDE loop control. Only an explicit operator actor through an operator-driven path may arm/disarm; there is by design NO loop-facing disarm method and NO self-arm path. An operator kill file or a stale dead-man heartbeat forces autonomy killed, fail-closed.
  - The layer STRENGTHENS the sacred set; it never weakens a gate. Each of the six services registers its OWN source files as sacred in the Immutable Invariant Registry, so an RSI diff that tries to edit the earned-autonomy machinery open is auto-rejected by the live guard — the same proof that protects the registry protects this layer.
maintenance:
  - Update BEFORE changing the risk-class ladder, the promotion thresholds, the kill-switch/dead-man contract, the drift signatures, the red-team strategy battery, or the R0..R4 composer ordering.
  - Block when an agent tries to (a) make auto_apply reachable with the flag off, (b) let a tier ever unlock gate_or_invariant_touch, (c) add a loop-facing self-arm/disarm path to the kill switch, (d) let the trust ledger record a pass without the four real signals, (e) make the red team a simulated yes instead of attacking through the live guard, or (f) edit any EarnedAutonomy/* file open (those paths are sacred).
risk_level: high
owner: agentic_engineering_os/dev_forge
authority_class: planner
graph_id: atlas-earned-autonomy-adversarial-immune-system
graph_title: Atlas Earned-Autonomy Adversarial Immune System
graph_world: atlas
graph_layer: module
graph_kind: module
graph_parent: atlas-governed-rsi-self-improvement-substrate
graph_status: future
graph_source: repo
related_paths:
  - docs/engineering-knowledge-base/atlas-governed-rsi-self-improvement-substrate.md
  - app/Services/Ai/Foundry/Rsi/EarnedAutonomy/EarnedAutonomyGateService.php
  - app/Services/Ai/Foundry/Rsi/RsiSelfImprovementProposalGate.php
repo_paths:
  - app/Services/Ai/Foundry/Rsi/EarnedAutonomy
  - tests/Unit/Ai/Foundry/Rsi/EarnedAutonomy
allowed_changes:
  - Refine default-off earned-autonomy risk classes, trust thresholds and proof docs.
  - Update evidence links when EarnedAutonomy service contracts or tests change.
forbidden_changes:
  - Do not make auto_apply reachable with the flag off or kill switch disarmed.
  - Do not unlock gate_or_invariant_touch at any tier.
  - Do not add loop-facing self-arm or disarm authority.
depends_on:
  - atlas-governed-rsi-self-improvement-substrate
flows_to:
  - rsi-self-improvement-proposal-gate
unlocks:
  - default_off_earned_autonomy_review
governs:
  - earned_autonomy_gate
  - earned_autonomy_trust_ledger
  - earned_autonomy_kill_authority
evidence:
  - app/Services/Ai/Foundry/Rsi/EarnedAutonomy/EarnedAutonomyGateService.php
  - app/Services/Ai/Foundry/Rsi/EarnedAutonomy/KillAuthorityService.php
  - tests/Unit/Ai/Foundry/Rsi/EarnedAutonomy
required_tests:
  - php artisan test tests/Unit/Ai/Foundry/Rsi/EarnedAutonomy
requires_evidence: true
next_actions:
  - Keep the layer default-off and operator-armed before any live-loop promotion.
  - Re-run the EarnedAutonomy tests after changing risk, trust, red-team or kill-switch rules.
---

# Atlas Earned-Autonomy Adversarial Immune System

> **Read this first.** This layer is the ONLY place in Atlas where a self-improvement
> proposal can stop being proposal-only and become `auto_apply`. It is dangerous by
> definition. It is shipped **default-off** and is **inert** until the operator turns
> it on. This document is honest about that danger and exists so the operator never
> turns it on without understanding exactly what the machine may then do unattended.

## Resumo

Earned Autonomy is a future/default-off RSI safety layer: it may only signal safer
auto-apply classes after real qualifying cycles, live red-team survival, clean drift
signals and explicit operator arming.

## Papel no Atlas

It sits above Governed RSI and converts proven, reversible trust into a narrow
auto-apply signal; it does not merge, canonize, call providers or weaken any sacred
gate.

## Onde Se Encaixa

Parent: `atlas-governed-rsi-self-improvement-substrate`. Runtime seams live under
`app/Services/Ai/Foundry/Rsi/EarnedAutonomy/` and are consulted only through the RSI
proposal gate.

## Contratos

Default-off flag, operator kill authority, append-only trust ledger, standing red
team, drift detector and the hard risk ceiling are the contracts. Missing or
ambiguous signals fail closed to human gate.

## Fluxo

Guard-passed proposal -> risk classification -> kill/flag check -> drift/red-team
checks -> earned tier comparison -> `human_gate` or narrow `auto_apply` signal.

## Regras para IA

Never claim live autonomy from this doc alone. Never pre-seed trust, bypass the kill
authority, or treat a test seam as operator arming.

## Escopo de Implementacao

Services and proof tests exist as a default-off layer; live unattended autonomy remains
future until the operator explicitly arms it in a running loop.

## Dependencias

Depends on Governed RSI, the immutable invariant registry, RSI proposal gating, the
component value ledger and live guard tests.

## Evidencias

Primary evidence is the EarnedAutonomy service directory and its unit proof suite.

## Riscos

Critical risk is false autonomy: auto-apply without earned trust, live red-team
survival or operator-controlled kill authority.

## Exemplos

Cosmetic changes may become eligible only after the required consecutive qualifying
cycles; gate or invariant touches remain ineligible forever.

## Proximas Acoes

Keep the layer inert by default, keep the proof suite green, and require operator
review before any live-loop activation.

## 1. Thesis: autonomy is earned, paid for, and revocable

The Governed RSI substrate (`atlas-governed-rsi-self-improvement-substrate.md`) proved
that the loop can improve its own machinery **without ever weakening a sacred gate and
without ever auto-canonizing** — by keeping every proposal proposal-only and
human-gated. That is safe, but it means the operator is the bottleneck on every single
self-improvement, forever.

The earned-autonomy layer relaxes that bottleneck **adversarially**, under three
non-negotiable principles:

1. **Autonomy is EARNED one risk-class at a time, never granted.** The loop starts at
   tier 0 (no autonomy — everything human-gated, exactly as today). It climbs the
   risk-class ladder only by accumulating a run of **consecutive qualifying proven
   cycles**. Each higher tier unlocks auto-apply for one more (strictly safer-ranked)
   risk class, and only that class. The ceiling — touching a gate or an invariant — is
   **never** unlocked by any amount of trust.

2. **Autonomy is PAID FOR by surviving a standing adversary.** A cycle only counts
   toward promotion if it (a) was proven by the existing measured-or-reverted keystone,
   (b) survived a **standing red team** that attacks the LIVE guard with a battery of
   corrupted proposals, and (c) showed **no drift** in the loop's own proven
   value-per-token history. Trust is the integral of survival, not a config value.

3. **Autonomy is AUTO-REVOKED on drift.** The instant the drift detector or the red
   team reports the loop is no longer trustworthy, accrued trust is revoked to tier 0
   and the proposal is human-gated. Trust decays to zero on the first sign of gaming,
   decay, or tamper. This is what makes the layer **antifragile**: adversarial
   pressure and chaos make the gate *more* conservative, never less.

The composite effect: when the loop is genuinely getting better (provable, surviving
attack, non-regressing), the operator is freed from rubber-stamping the safest classes
of change. The moment it stops being trustworthy, the operator is back in the loop on
everything — automatically, without anyone noticing in time being required.

## 2. The six components

All six live in `app/Services/Ai/Foundry/Rsi/EarnedAutonomy/` and compose the existing
`ImmutableInvariantRegistryService` + `RsiInvariantGuardService` + `ComponentValueLedgerService`.
They are strict-typed, `final`, constructor-DI with nullable seam deps defaulting via
`app()`, deterministic (injected clock, no `time()`/`random` in domain logic), and
emit canonical-hashed payloads via `MissionCanonicalHash::sha256()`. None calls a
provider; none applies, merges, or canonizes anything.

| # | Service | Role | Writes? | Provider? |
|---|---------|------|---------|-----------|
| 1 | `RiskClassifierService` | PURE classifier: maps a proposal diff to an ordered risk class (rank 0..3). Conservative-by-default: ambiguous/unreadable ⇒ highest class. `rankOf()` fails closed to 3. | No (read-only) | No |
| 2 | `TrustLedgerService` | Append-only, prev-hash-chained per-area/focus trust record. Records one event per cycle carrying the four real signals; `earnedTier()` is a pure fold counting consecutive qualifying cycles since the last revocation, mapped to a max-auto-rank. | Append-only ledger | No |
| 3 | `DriftAnomalyDetectorService` | Fail-closed gaming sentinel over the EXISTING `ComponentValueLedger`: value-per-token regression, revert-rate spike, ledger discontinuity. No new metric invented. | No (read-only) | No |
| 4 | `KillAuthorityService` | External operator-only kill switch + dead-man heartbeat, OUTSIDE loop control. `isArmed()` true only iff flag enabled AND folded ledger state ARMED. Kill file or stale heartbeat ⇒ killed. | Append-only ledger + heartbeat (operator-driven only) | No |
| 5 | `StandingRedTeamService` | Per-cycle adversary that runs a fixed battery of CORRUPTED proposals through the LIVE `RsiInvariantGuard::screen()`. "Survived" means the real guard rejected every attack — never a simulated yes. | No (read-only) | No |
| 6 | `EarnedAutonomyGateService` | The composer: the ONLY authority that may signal `auto_apply`. Evaluates R0..R4 in a frozen order over a guard-PASSED proposal; any failing check short-circuits to `human_gate`. Never applies anything itself. | No (read-only; may trigger a TrustLedger revocation append) | No |

The composer's frozen rule order (any failing check short-circuits to `human_gate`):

- **R0 — DEFAULT-OFF.** If the kill switch is not armed (flag off and/or no operator
  arm), EVERYTHING is human-gated. This makes the whole feature inert by default.
- **R1 — INVARIANT CEILING.** A `gate_or_invariant_touch` (rank 3) proposal can NEVER
  auto_apply at any tier — hard-capped here regardless of accrued trust.
- **R2 — DRIFT ⇒ REVOKE.** `drift_detected` forces a revocation to tier 0 + human_gate.
- **R3 — RED-TEAM ⇒ REVOKE.** Any standing red-team strategy not provably blocked by
  the live guard forces a revocation to tier 0 + human_gate.
- **R4 — EARNED CEILING.** Only when all the above are clean does the composer compare
  the proposal's `risk_rank` to the earned max-auto-rank: `auto_apply` iff
  `risk_rank <= earned_tier`, else `human_gate`.

The single wiring seam into production is `RsiSelfImprovementProposalGate::admit()`:
after a proposal PASSES the live guard, it consults the composer. With the flag off the
composer returns `human_gate` at R0, so the gate routes to the operator's existing human
gate exactly as today (`auto_applied = false`, `proposal_only = true`).

## 3. The immutable-invariant keys

The layer STRENGTHENS the sacred set rather than weakening it. Five new gates were
registered in `ImmutableInvariantRegistryService`; each lists the earned-autonomy source
files it owns, so an RSI diff that tries to edit the machinery open is auto-rejected by
the live guard:

| Gate key constant | Invariant it freezes |
|-------------------|----------------------|
| `GATE_EA_DEFAULT_OFF` (`earned_autonomy.default_off`) | Kill disarmed + flag off ⇒ always `human_gate`. Owns `KillAuthorityService` + `EarnedAutonomyGateService`. |
| `GATE_EA_TIER_CEILING` (`earned_autonomy.tier_ceiling`) | `auto_apply` only iff `risk_rank <= earned max-auto-rank`; a tier never unlocks `gate_or_invariant_touch`. Owns the composer + `RiskClassifierService`. |
| `GATE_EA_TRUST_LEDGER_APPEND_ONLY` (`earned_autonomy.trust_ledger_append_only`) | Trust ledger is append-only; `earnedTier()` is a pure fold; a revocation resets tier to 0. Owns `TrustLedgerService`. |
| `GATE_EA_KILL_CANNOT_DISARM` (`earned_autonomy.kill_cannot_disarm`) | Only an explicit operator actor may arm/disarm; the loop/composer never self-arms. Owns `KillAuthorityService`. |
| `GATE_EA_INVARIANT_TOUCH_NEVER_AUTO` (`earned_autonomy.invariant_touch_never_auto`) | A `gate_or_invariant_touch` proposal can NEVER auto_apply; drift/red-team breach revokes to tier 0. Owns the composer + `DriftAnomalyDetectorService` + `StandingRedTeamService`. |

These join the pre-existing RSI/registry gates (the registry remains self-protecting and
lists every sacred gate, asserted by `RsiCannotWeakenInvariantTest`).

## 4. The risk-class ladder

The classifier assigns an ordered risk class (ascending rank; lower = safer). The
TrustLedger maps a run of consecutive qualifying proven cycles to the **max auto-eligible
risk rank** an area/focus has earned:

| Rank | Risk class | What it covers | Unlocked at tier | Consecutive qualifying cycles required |
|------|-----------|----------------|------------------|----------------------------------------|
| 0 | `cosmetic` | comments / docstrings / whitespace; non-behavioural | tier 1 | 3 |
| 1 | `non_sacred_logic` | behaviour change in a NON-sacred component | tier 2 | 10 |
| 2 | `ledger_or_schema` | touches an append-only ledger writer / schema shape | tier 3 | 30 |
| 3 | `gate_or_invariant_touch` | touches ANY sacred path OR matches a weakening / eligibility / auto-canonize signature | **NEVER** | — (hard-capped) |

- A cycle **qualifies** only when `outcome_proven && red_team_survived && drift_clean`
  are all genuinely `true` (strict-bool; a missing or non-boolean signal fails closed).
- A **revocation** event resets the consecutive counter to zero; the area/focus drops to
  tier 0 (max-auto-rank −1) and must re-accrue from scratch.
- `earnedTier()` returns −1 / 0 / 1 / 2 and **never 3**. Rank 3 is hard-capped out in the
  composer (R1) regardless of accrued trust.

`tier 0` (max-auto-rank −1) = no autonomy = byte-identical to today.

## 5. The `ATLAS_EARNED_AUTONOMY_MODE` default-off contract

```
config/atlas.php
  atlas.foundry.rsi.earned_autonomy.mode = (bool) env('ATLAS_EARNED_AUTONOMY_MODE', false)
```

The contract, in order of strength:

1. **The env var defaults to `false`.** Absent or false ⇒ the layer is OFF.
2. **The flag alone authorizes NOTHING.** It only un-mutes the composer consultation in
   `RsiSelfImprovementProposalGate::admit()`. For any `auto_apply`, `KillAuthority::isArmed()`
   must ALSO be true — which requires the flag enabled AND the append-only kill-switch
   ledger folded to ARMED (default: no events ⇒ DISARMED).
3. **Flag off ⇒ byte-identical to today.** The composer returns `decision=human_gate` at
   R0 for every proposal; the gate emits `STATUS_ROUTED_TO_HUMAN_GATE`,
   `auto_applied=false`, `proposal_only=true` — exactly the proposal-only path shipped
   today. Proven by `test_p7_wired_flag_off_is_byte_identical_to_proposal_only` and
   `test_p5_default_off_flag_makes_the_rsi_gate_inert`.
4. **An `$input['earned_autonomy_mode_enabled'] === true` override** exists as a TEST and
   operator seam mirroring the RSI gate convention; it does not change the default-off
   posture of production config.

> The flag is a *mute switch on the consultation*, not an authorization. Even with the
> flag on, the operator must separately ARM the kill switch (an explicit operator actor)
> and keep the dead-man heartbeat fresh, or the layer stays killed.

## 6. Controlled-activation runbook (read every line before arming)

This is the ONLY supported way to turn the layer on. Do not improvise around it.

0. **Pre-flight.** Confirm `php artisan test tests/Unit/Ai/Foundry/Rsi/` is fully green
   (87 tests at time of writing, including P1..P8 cannot-weaken proofs). If anything is
   red, DO NOT proceed.
1. **Turn the flag on, narrowly.** Set `ATLAS_EARNED_AUTONOMY_MODE=true` in the local
   environment only. Nothing auto-applies yet — the kill switch is still disarmed.
2. **Arm with an explicit operator actor.** Call `KillAuthorityService::arm($context, $actor)`
   with a real operator id. This appends an ARM event AND writes a fresh dead-man
   heartbeat. There is no loop-facing arm path; only you can do this.
3. **Lowest class only.** Do NOT pre-seed the trust ledger. Let the loop earn tier 1
   (cosmetic, rank 0) honestly: 3 consecutive cycles that are each proven, survive the
   red team, and show no drift. Watch the first auto-applied cosmetic change land and
   verify it is exactly what you expected.
4. **Tiny budget.** Keep the loop's per-run merge/cycle budget small (single-digit) while
   observing. Earned autonomy multiplies whatever the loop does — start where a mistake
   is cheap and reversible.
5. **Operator approves each tier crossing.** Treat each promotion (tier 1 → 2 → 3) as a
   deliberate decision, not an automatic one. A higher tier unlocks a strictly riskier
   class; cross it only after you have watched the lower tier behave for a meaningful run.
   Rank 3 is never available — do not attempt to make it so.
6. **Keep the dead-man alive deliberately.** The heartbeat TTL is 15 minutes
   (`DEAD_MAN_TTL_SECONDS = 900`). If you stop attesting liveness, the layer goes killed
   and everything reverts to human-gated. This is intentional: walking away IS the kill.
7. **Kill switch — the abort.** To stop everything immediately: either
   `touch storage/atlas/earned_autonomy/AUTONOMY_KILL` (the operator kill file), or call
   `KillAuthorityService::disarm($context, $actor, $reason)`. Either forces
   `isAutonomyKilled()` true / `isArmed()` false, so the composer returns `human_gate` for
   every subsequent proposal. A disarm is irreversible-for-the-cycle: re-arming requires
   the explicit operator path again.
8. **Trust auto-revokes under you.** You do not have to catch drift manually. A
   value-per-token regression, a revert-rate spike, a ledger discontinuity, or any
   red-team breach revokes accrued trust to tier 0 automatically and human-gates the
   proposal. Re-accrual starts from scratch.

### Honest danger statement

This layer lets the machine change its own code without asking. The safety case rests on
five things ALL holding simultaneously: (a) the flag being off by default, (b) the kill
switch being operator-only and dead-man-gated, (c) the risk ceiling never unlocking gate
or invariant touches, (d) the red team attacking the *real* guard so survival is not
faked, and (e) trust auto-revoking on the first drift signal. If any one of those is
weakened — by a future diff, a mis-set env var, a forgotten heartbeat, or a red team
reduced to a rubber stamp — the safety case collapses. The Immutable Invariant Registry
gates listed in §3 exist precisely to make weakening (a)–(e) auto-rejected by the live
guard. **Do not route around them. Do not pre-seed trust. Do not leave the layer armed
unattended.**

## 7. What this layer is NOT

- It is **not** a new provider, a new OS, or a new merge/canonize authority. It composes
  the existing guard, registry, value-ledger and measured-or-reverted keystone.
- It does **not** weaken any existing invariant or gate (provider-proof, no-scaffold,
  honest-stop, I1–I9, proposal-only, RSI default-off). It only adds new sacred gates.
- It does **not** make the RSI path auto-canonize. `auto_apply` is a SIGNAL the proposal
  gate may act on under its existing governed-apply responsibility; the apply itself
  remains the caller's, and `auto_canonized` stays false.
- It is **not** on by default and is **not** runtime-authoritative yet
  (`implementation_state: built_default_off_no_runtime_authority_yet`): the services and
  proofs are built and green, but the operator has not armed the layer in any running
  loop.
