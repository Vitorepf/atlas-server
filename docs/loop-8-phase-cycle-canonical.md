# Loop — 8-Phase Cycle (Canonical)

This is the authoritative specification of the Loop's 8-phase cycle. It is the contract every primitive,
runner, and reviewer is held against. The phases are sequential and named EXACTLY as below; one-shot,
"faxina", and proxy work are FORBIDDEN by the cycle (a phase cannot be skipped to fake the previous one).

The eight phases, in order:

1. orient
2. comprehend
3. decide-leverage
4. architect
5. decompose
6. implement
7. certify
8. close-on-main

A ninth, optional `learn` step persists what the cycle's receipts taught the next cycle.

## Anti-Goodhart preamble

- One-shot work (a single best-of-N attempt with no measured comprehension) is rejected.
- Faxina / cosmetic-only edits that don't bite a frozen check are rejected at the anti-farm floor.
- Proxy work (cyclomatic shrinks, test-count nudges, doc-line padding) is rejected by the certify
  phase even if the green-state matches.
- Every phase emits FACTS — never a single scalar score, never a ranking.

## Phase 1 — orient

Anchor: `AtlasLoopCampaignSupervisor` (the live-cycle orchestrator that owns the cycle id).

Invariant: the cycle has a single canonical scope and a wall-clock budget. Orient never proposes
work; it only fixes "what scope, with what budget, against which frozen contracts".

## Phase 2 — comprehend

Anchors: `AtlasLoopScopeComprehensionModelBuilder`, `AtlasLoopComprehensionOriginator`.

Invariant: comprehension produces a grounded model of the scope (inventory + roles + frozen
organs) before any leverage decision. The cycle refuses to skip from orient → architect; without a
comprehension model the next phase is unreachable.

## Phase 3 — decide-leverage

Anchor: `AtlasLoopNextWorkDecider`.

Invariant: leverage is chosen from the comprehension model's surfaced gaps, never from a heuristic
on file shape. The decider returns a leverage FACT — never a leverage SCORE. A tie is broken by the
cycle's deterministic ordering, not by a learned weight.

## Phase 4 — architect

Anchors: `AtlasLoopGroundedProjectionRoles`, `AtlasLoopProjectionEngine`.

Invariant: the architect emits a typed obligation set (consumer_intact, contract_upheld, etc.)
that downstream phases enforce; an unprojected target is parked, never minted as a task.

## Phase 5 — decompose

Anchor: `AtlasLoopFullCycleConductor` (the canonical 8-phase conductor) + `AtlasLoopTaskGrinder`
for per-target decomposition.

Invariant: each decomposed step is COMMITTABLE in isolation. A step that requires a sibling step
to be green is not a step — it's a multi-step obra and must be routed to the Obra bridge.

## Phase 6 — implement

Anchor: `AtlasLoopTaskGrinder` + `AtlasMaestroPacketReshaper` (when adaptive packet shaping is on).

Invariant: implement edits ONLY the allowed_files of the active task packet. A worker that touches
a forbidden self-target is failed and reaped — never silently kept.

## Phase 7 — certify

Anchor: `AtlasLoopSemanticImplementationCertifier`, `AtlasLoopAntiFarmFloor`, `AtlasLoopFrozenBattery`.

Invariant: a certified result must clear the anti-farm floor (bite-proof + production-path-proven)
AND survive the frozen mutation operators. A cycle whose certify phase reports `status:failed`
aborts — the close phase is NEVER reached.

## Phase 8 — close-on-main

Anchor: `AtlasLoopAutoMergeService` (Merge subsystem with PreFlightGate, ConflictDetector,
StalenessRefuser, ReverseAuditor, ReceiptLedger).

Invariant: close-on-main produces a `merged_sha` and writes the auto-merge receipt. A cycle whose
close receipt does NOT carry a non-null `merged_sha` is `aborted`, not `completed`. There is no
"completed but no merge" success state — that is proxy success and forbidden.

## (Optional) Phase 9 — learn

Anchor: `AtlasLoopProjectionOutcomeLedger`, `AtlasLoopCycleReceiptLedger`.

Invariant: learn is persistence-only — it appends what this cycle's receipts taught and never
mutates a frozen contract. A cycle that succeeded without recording a receipt is a bug; learn is
the chain link the next cycle's orient phase reads.

## Cycle contract summary

- Strictly sequential. No phase is skipped to "speed up" the cycle.
- A failure in any phase aborts the cycle at that phase; subsequent phases are NEVER invoked.
- close-on-main requires `merged_sha`. No proxy success.
- certify enforces anti-farm (`AtlasLoopAntiFarmFloor`) and frozen contracts (`AtlasLoopFrozenBattery`).
- The conductor that owns this contract is `AtlasLoopFullCycleConductor`.
- Receipts chain via `AtlasLoopCycleReceiptSigner` + `AtlasLoopCycleReceiptLedger`.

## Forbidden patterns

- One-shot work (single best-of-N without measured comprehension) — forbidden.
- Faxina / cosmetic edits that bite nothing — rejected at the anti-farm floor.
- Proxy success (close phase without `merged_sha`) — aborted as if certify had failed.
- Multi-step obra masquerading as a single decompose step — routed to the Obra bridge instead.
