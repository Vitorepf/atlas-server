# LOOP-OS · Slice 0 — Provenance Audit · Decision Receipt v2

- **audited_at:** 2026-06-18
- **head_sha:** `e4b47fb0fb82e0db42ce17aead826b3c41e7f318`
- **branch:** `feat/loop-os-phase1-foundation`
- **scope:** has the loop ALREADY auto-merged edits to the (now-)frozen cert-chain closure / config back-door, before Slice -1 froze the door? (Doc §7 / §12 Phase 1 prerequisite.)
- **method:** `git log --grep="loop auto-merge"` (author `atlas-loop`) intersected with each frozen closure file + `config/atlas.php`. Two-lens (commit-subject + path), exact paths.

## Verdict: BASELINE CONTAMINATED, reviewed as mostly-strengthening; ONE item flagged.

The door was **provably open and USED** — the loop auto-merged edits touching its own judge/config via the **sibling-ride hole** (the declared target differs from a file the commit also changed). This validates the architecture and makes the Slice -1 freeze urgent, not theoretical. Total `atlas loop auto-merge` commits in history: **214**.

| Frozen file | loop-auto-merges that touched it | Assessment |
|---|---|---|
| `Verify/AtlasEngineeringHonestyGate.php` (deterministic judge) | 1 (`a77d706ec`, declared target `WorkspaceReader.php`, 2026-06-14) | ⚠️ **FLAGGED** — relaxed `revert_recheck` (anti-fake-green) for behavior-preserving refactors. Rationale plausible (revert_recheck falsely fails a refactor) but it is the defendant editing the judge, unreviewed. |
| `AtlasLoopMutationAdequacyGateService.php` (judge) | 1 (`f9ca356f1`, declared target `AtlasAaeosThresholdLadderNormalizer.php`) | ✅ STRENGTHENING — added `addedLineMap` so a mutation can never land on unchanged code (fixes a false-certify). |
| `config/atlas.php` (back-door) | 11 | ✅ NEUTRAL/ADDITIVE — observed flag changes are NEW gates defaulting `true` (value_gate, orphan_gate, boot_smoke); **no `true→false` flip of an existing safety gate** found. |
| HeldOutDeltaCertifier · MutationOperators · CrossFileConsumer · JudgeConsensus · QualityGrader · CompletenessGate · BehavioralEquivalence · others | 0 each | clean |

## Actions taken (Slice -1 + Slice 0)
1. **Freeze (code):** the transitive cert-chain closure (16 loop-local + `AdversarialProofPanelService`), the `Constitution/` subtree, `config/atlas.php`, `AtlasLoopMutationOperators.php`, `bin/atlas-loop-watchdog.sh` → `AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS`. Verified: `AtlasLoopHarnessGuardTest` 6 tests / 180 assertions green; no regression (`FormalInvariantGate` green).
2. **Freeze (flag):** `.env` `ATLAS_LOOP_SELF_IMPROVEMENT_AUTO_MERGE_ENABLED=true → false` (no loop running; reversible; `.env` gitignored — no leak).

## OPERATOR DECISION PENDING (does NOT block further Phase-1 build)
- **`a77d706ec` HonestyGate `revert_recheck` carve-out:** accept as the frozen genesis (it predates this work and the loop ran against it), OR revert to the pre-carve-out `revert_recheck = true` and freeze that. Recommendation: **eyeball the diff, then accept** if the refactor-carve-out is sound — it is gated downstream by the complexity-drop proof — else revert. This is the one place the genesis is not provably clean.
