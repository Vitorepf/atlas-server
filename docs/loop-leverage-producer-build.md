# Loop High-Leverage Objective Producer (the rédea) — Build Report

**Goal:** make the Loop autonomously find and execute the BIGGEST evolution leap per least time,
each cycle, closing to main — measured, brain-connected, anti-gaming. The producer (the "rédea")
decides WHAT/WHERE; it must read the Atlas brain, not generate blind.

Branch: `feat/loop-leverage-producer` (off the fresh main). All commits below verified + frozen.

## What is BUILT + PROVEN (committed)

1. **`AtlasLoopLeverageScorer`** (`8a176047f`) — the leverage equation, the heart:
   `leverage = (strategic_impact × breadth × compounding) / (cost × risk)`. Pure, deterministic,
   anti-Goodhart (strategic-progress-per-effort, never diff size), explainable (per-component
   rationale), with the **ambition floor** (rejects trivia, non-verifiable "dreams", no-unblock).
   5 frozen tests pin the math + the floor.

2. **`AtlasLoopObjectiveProducer`** (`8a176047f`, calibrated `f8a2b6f02`, fallback `33d2c705b`) —
   reads the brain per candidate (callers=breadth, cyclomatic=debt/compounding, Open Brain
   file-context memory+reality-graph=strategic), ranks, and selects the single biggest verifiable
   leap above the floor. PURE `select()` is frozen (3 tests). Architecture: cheap structural
   pre-rank → the expensive brain read (~3s/file) runs ONLY on the top-N finalists.
   **Proven on real code:** of 5 core files it selects `AiWorker` (16 callers, cx 61) as the
   highest leverage (0.70) with an explainable rationale.

3. **Wiring** (`8b324babe`) — producer is arg 17 of `AtlasLoopQueueRefiller`, in a default-OFF,
   fail-open block after the per-target lanes, enqueuing through the existing
   claim→grind→gate→merge path. **Byte-identical when off** (firewall + funnel 10/10 green); the
   container resolves the refiller with the new arg. `objective_producer_enabled` default OFF.

## The honest BLOCKER found by building

The producer SELECTS high-leverage targets correctly, but the Loop's existing objective-builders
(`AtlasLoopRefactorObjectiveSynthesizer` plain-php; `AtlasLoopFrameworkRefactorSynthesizer`) are
**narrow by design** — they fail-close unless a file meets strict provable-refactor criteria
(plain-php sibling, surgically-targetable worst method, behaviour anchor). On arbitrary large
hubs they emit nothing. So `produce()` on arbitrary files returns null.

**Nuance:** in the REAL loop the producer operates on **discovery-pre-qualified** targets (already
filtered for refactor-eligibility), not arbitrary files — so the closed cycle may work live. That
is unproven until a **live campaign run** with the flag armed.

## The real frontier (the operator's actual goal)

"Implement features / biggest leaps, not faxina" needs an objective-builder that, for a selected
high-leverage target, can **originate a verifiable high-value objective** — especially a FEATURE:
author a genuinely-RED acceptance test for a new capability, then implement with a strong engine,
gated by the existing diff-earned (`revert_recheck`) proof. That origination is the irreducible
frontier (needs strong model + real comprehension) and is the next build. The leverage selection
is already cross-shape, so a feature builder slots into the same `produce()` seam.

## LIVE PROOF (shadow campaign, never-merge)

Armed `objective_producer_enabled` on a bounded shadow campaign; discovery qualified 8 real
targets; ran `produce()` on them. **Result: the rédea emits a real, enqueueable objective on real
discovery targets.**
- Selected `WorkspaceReader.php` (the highest-leverage target the synthesizer accepts), leverage
  0.43, explainable rationale, `materializer=framework`, objective = "reduce cyclomatic complexity
  of worst method repoRoots() (cyclomatic 10)".
- So the **select→emit cycle entry is PROVEN end-to-end on real code.** grind→gate→merge is the
  existing proven machinery (same framework materializer + diff-earned gate path).

**Honest ceiling found:** the emitted objective is a MODEST refactor (worst-method cyclomatic 10 —
the floor). The producer picks the biggest leap *among synthesizer-accepted targets*; the existing
synthesizers cap ambition at provable single-method complexity reductions. The genuinely BIG leaps
(features, structural obras) are NOT reachable until the feature/impl-origination builder lands.

**Operational note:** the full campaign was slow — the per-target provider grind runs BEFORE the
producer block in refill(), so a cold first refill takes minutes/stalls. This is the loop's
"run-for-hours" reliability gap, separate from the producer.

## Next steps
- [x] Prove the producer emits on real discovery targets — DONE (WorkspaceReader, leverage 0.43).
- [ ] Run grind→gate→merge of a producer objective end-to-end live (needs a working provider + time).
- [ ] **Feature/impl-origination objective builder (RED-test authoring) — the real differentiator
      and the ambition-ceiling lift. This is the build that makes the loop do BIG leaps, not modest
      refactors.**
- [ ] Deepen strategic signal: connect to active goals / gap-maps (biggest leap toward OBJECTIVES).
