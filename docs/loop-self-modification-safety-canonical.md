# Loop — Self-Modification Safety (Canonical)

This is the canonical safety contract every Autopoiesis (Loop self-modification) primitive is held against.
The contract is fail-closed by construction: every gate refuses by default. Adding a new self-modification
surface that does not honor every primitive listed below is a defect, not a feature.

## Master ON/OFF switch — `ATLAS_LOOP_MASTER_ENABLED`

- Single boolean kill-switch read from the `.env` file (or the test-only override path).
- **Default: FALSE / fail-closed.** With the switch OFF, every Loop mutating surface is a byte-identical
  no-op: keepalive, campaign launch, auto-merge, projection runs, dashboard mutations.
- Toggled by the operator via `atlas:loop:on` / `atlas:loop:off`. The Loop itself NEVER re-enables it
  programmatically — that would close the only safety loop the operator owns.
- Read centrally via `AtlasLoopMasterSwitch::enabled()`. Each consumer MUST check the switch before any
  observable action — never assume a prior layer already gated it.

## Materializer sandbox floor

- Anchor: `AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource()`.
- Every workspace write goes through this sandbox floor. A write that targets `app_path()` (the live
  source tree) is REFUSED — the floor throws and the caller's mutation is rejected.
- The floor is the single chokepoint: every `writeFile` site in the materializer family delegates to it.
  Adding a new materializer write path WITHOUT routing through `assertOutsideLiveSource()` is forbidden.

## FORBIDDEN core list

The cycle's certify phase enforces a FORBIDDEN list of files / directories that no self-edit may touch —
the moat that judges the judge. Examples in the canonical FORBIDDEN core:

- The Constitution / FrozenJudge contracts.
- The MasterSwitch source.
- The certifier itself (`AtlasLoopSemanticImplementationCertifier`).
- The merge subsystem entry (`AtlasLoopAutoMergeService`).
- The materializer sandbox floor (`AtlasLoopWorkspaceMaterializerSupport2`).

A self-edit attempting to land bytes inside any FORBIDDEN entry is REJECTED at certify, even if the
candidate cleared every other gate. This is the moat-bounded self-edit rule.

## Moat-bounded self-edit rule

- A candidate that touches a FORBIDDEN core file is REJECTED even when the Loop is fully armed
  (master switch ON, certify enabled, autonomous-merge enabled).
- The rule is enforced by certify; the candidate-bytes execution proves that a judge still kills every
  mutant under the FORBIDDEN list. A weakening of the judge cannot be self-merged because the judge
  itself is in the FORBIDDEN core.

## Exclusive flock on merge-to-main

- Anchor: `AtlasLoopAutoMergeService` + the Merge subsystem (`AtlasLoopAutoMergePreFlightGate`,
  `AtlasLoopAutoMergeConflictDetector`, `AtlasLoopAutoMergeStalenessRefuser`,
  `AtlasLoopAutoMergeReverseAuditor`, `AtlasLoopAutoMergeReceiptLedger`).
- The merge-to-main path acquires an EXCLUSIVE `flock(LOCK_EX | LOCK_NB)` for the duration of
  PreFlight → ConflictDetect → Reset/Apply → Commit. A second crossing on the same repo is REFUSED
  (`reason=preflight_lock_busy`) — never raced.
- The flock is the load-bearing safety primitive that lets the cycle resume from a crash without
  ever leaving a half-applied merge on shared main.

## Propose-only is NOT a merge gate

- The "propose-only" flag in the campaign supervisor is a REPORTING flag — it changes what the
  loop emits, not what reaches `main`. Setting `propose_only = true` does NOT block auto-merge.
- The three flags that ACTUALLY gate merge-to-main are the auto-merge enabled flags
  (`atlas.loop.obra_auto_merge_enabled`, `atlas.loop.auto_merge_enabled`, and the certify-required
  flag pair). These flags are independently OFF by default; flipping any subset other than the
  full required set keeps the propose-only path active.
- An operator reviewing "is the Loop safe?" MUST verify the auto-merge gates, not the propose-only flag.

## Anti-Goodhart for self-modification

- Self-edits are scored by FACTS only — the certifier produces a yes/no, never a single scalar.
- A candidate that cleared certify by editing a comment / whitespace / cosmetic line is REJECTED
  by `AtlasLoopAntiFarmFloor` (cycle phase 7) because it does not BITE any frozen check.
- The FrozenJudge contract is canonical: it is not retunable from inside the Loop, it must KILL
  every radius-1 mutant of the candidate, and weakening it is a FORBIDDEN core edit.

## Summary of safety primitives

| Primitive | Default | Role |
|---|---|---|
| `ATLAS_LOOP_MASTER_ENABLED` | FALSE (fail-closed) | Single kill-switch for every mutating surface. |
| `AtlasLoopWorkspaceMaterializerSupport2::assertOutsideLiveSource` | enforced | Sandbox floor for every workspace write. |
| FORBIDDEN core | enforced by certify | Files no self-edit may touch — judge-of-the-judge. |
| Moat-bounded self-edit | enforced by certify | A FORBIDDEN-core edit fails even when the Loop is fully armed. |
| `AtlasLoopAutoMergeService` + flock(LOCK_EX) | enforced | Merge-to-main serialized; second crossing refused. |
| Propose-only flag | reporting only | NOT a merge gate — auto-merge flags own that. |

The Loop never weakens this contract from inside; that would be a self-modification of the contract that
the contract itself rejects.
