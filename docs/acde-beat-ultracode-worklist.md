> ⚠️ **DEFINIÇÃO CANÔNICA DO LOOP — leia primeiro: `docs/loop-canonical-definition.md` + memórias `loop-*`.** Este doc descreve IMPLEMENTAÇÃO / ESTADO / HISTÓRICO; parte do framing aqui (refactor / ciclomática / landing-rate / best-of-N / proxy) é o **ALVO ERRADO**. O Loop = evolução autônoma **exponencial** de features REAIS do Atlas (entender escopo → projeção frontier + crítica cross-model → multi-agente → teste → wiring), **nunca faxina / proxy / one-shot**. Objetivo final: ser o ÚNICO que evolui o Atlas 24/7 sozinho.

# ACDE — Perfect-Flow Work-List: weak-engine loop ≥ Opus-4.8 ultracode by architecture

Source: adversarial gap-audit (9 dimensions, 9 confirmed gaps, workflow wf_842fc20d-a96, 2026-06-15).
Target: the loop on MiniMax-M3 via Hermes (all subsystems on) must match-or-beat Opus-4.8 ultracode +
dynamic Workspace on **delivery quality**, by FLOW not model. Every item is PHP-native, flag-gated
default-OFF, re-proven by the loop's own frozen suite before the flag flips in `.env`.

**Recurring root cause:** the keystones (conductor, planner, intent→spec, escalation ladder, budget
scheduler, completeness resolver) EXIST and are unit-tested but are ORPHANS — no service-provider
binding, no production caller, no flag arming them in the live `hermes_cli` flow. The work is WIRING +
a few surgical changes, not new keystones. The conductor is the central orphan.

## Tier 0 — ship first (cheapest wiring, biggest correctness lift)
1. **Decorrelate the weak engine per-attempt** — `AtlasEvolutionScenarioExplorer.php:137` freezes hints
   byte-identical across all N on one engine, so best-of-5 buys ~0 lift (MiniMax collapses 5 identical
   prompts → 5 correlated diffs). Add per-`$i` `sampling_seed` + stepped `temperature` (0.2→0.8) into
   surfaceHints/runScenario, thread through `driver->attempt()` into the hermes invocation. Highest
   leverage-per-effort on a one-engine setup. Measure: mean pairwise diff-distance across N rises; per-task certified-win rate rises.
2. **Iterate against the JUDGE's bar, not a proxy** — `WorkspaceProviderLoopExecutionDriver.php:144-149`
   drives a raw Process to exit 0, but cert needs Guard 4 diff-earned + 4b complexity-earned. Weak model
   games the thin command; iterate gives 8 MORE re-prompts amplifying the gaming, then judge rejects as
   acceptance_not_diff_earned. Pass the FROZEN acceptance into `attempt()`, use FrozenJudge as the green
   check, feed the rejection reason into buildFixPrompt. Guard: `$acceptance===[]` → byte-identical raw path.
3. **Universalize the behavioral-equivalence floor onto the vanilla lane** — `AtlasLoopSemanticImplementationCertifier.php`
   fences `BehavioralEquivalenceGate->evaluate()` (163-167) inside `complexityProofRequired` (138) → it
   never fires on feature/bugfix (the operator's live lane = where the proven gaming lives). Move it out,
   key off mutation_kill_ratio_floor; raise `ATLAS_LOOP_MUTATION_ADEQUACY_GATE_MAX_MUTANTS` 1→≥3. Keep sampled<=0 fail-open.
4. **Deterministic overfit-constant-return probe** — `AtlasLoopMutationAdequacyGateService`: scan added
   lines for literal-return short-circuits keyed on `func_num_args()`/single-input equality; synthesize a
   second adversarial argument tuple from the signature; re-run acceptance; refuse `overfit_constant_return`.
   Wire `mutationPropertyCommands` (grinder:790, empty) to auto-synthesize ≥1 off-happy-path case.

## Tier 1 — the real moat (more build, structural beats-a-weak-model)
5. **Wire the orphaned escalation conductor as the per-task driver** — bind `AtlasLoopAutonomousConductor`
   + `AtlasLoopEscalationLadder` in AppServiceProvider; `conduct($goal, ['tier_executors'=>...])` so a
   no-winner best-of-N round or budget-exhausted iterate escalates STRUCTURALLY (best_of_n →
   repair_from_refutation → decompose → escalate_provider), feeding AttemptLedger forward (thrash-jump).
   The genuine flow-level substitute for model intelligence. Pure + unit-tested; only tier_executors callables + binding missing.
6. **Intra-task scenario fan-out** — `AtlasEvolutionScenarioExplorer::exploreAttempts` serial `for` (123),
   ~14min×5≈70min serial, search_time_budget truncates. Replace with bounded concurrent dispatcher reusing
   `LoopWorkerSpawner` (Process::start) + `LoopWorkerPool::tick` + `AtlasLoopResourceGate::admitScenario`.
   pickWinner/improvesBest are pure reducers (no change). Converts MiniMax latency tax (sum N) into width (max N + cert).
7. **Dependency-BODY grounding injector** — driver grounds the target body but only dependency SIGNATURES
   (373) → weak model edits target right, hallucinates the callee contract (code-graph sig map makes it
   WORSE: confident wrong calls). Add `dependencyBodyLines()` off `atlas_engineering_code_symbols` read-model
   + `CodeGraphContextRetriever::packFor` top-K, exact body via line_start/line_end range reads, ~1500 chars/symbol,
   flag `atlas.loop.inject_dependency_bodies` default-OFF. (EdgeResolver::resolve is graph-build, not runtime lookup — use the symbols read-model.)

## Tier 2 — planning + completeness (highest build, large-obra story)
8. **Arm a planning phase ahead of best-of-N** — bind IntentSpecCompiler + ObraDecompositionPlanner +
   PlanReadinessGate (orphans); for ≥2 allowed_files OR objective_kind refactor_/feature_: spec-only
   hermes_cli → falsifiable acceptance + decomposition_hint (iterate-to-ready) → DAG (validated by
   PlanReadinessGate). **CRITICAL:** `AtlasObraExecutor` orders by `seq` (111/171), NOT depends_on — the
   planner must emit a **create-class node at seq 0** for the NEW file + redirect-caller nodes at higher seq.
   Replace `AtlasLoopObraExecutionAdapter::buildPlan` (257-273, one-node-per-existing-file with depends_on=[],
   never a to-be-created class = the live class-not-found root). Flag `ATLAS_LOOP_PLANNING_ENABLED` default-OFF.
9. **Machine-verified completeness resolver** — new `Discovery/AtlasLoopCompletenessCriteriaResolver`:
   derive criteria ONLY from trusted signals (one per frozen acceptance command; one per cross-file consumer
   contract; class_no_longer_god for refactor) and RESOLVE each `satisfied` by RE-RUNNING its bound
   command/metric in the candidate workspace — never model-declared. Wire in certify() before line 233.
   Empty-derivable → `[]` (keep fail-open). Then arm `ATLAS_LOOP_COMPLETENESS_GATE_ENABLED`.

## Tier 3 — calibration honesty (do last, lowest delivery-quality leverage)
10. **Universal feature grade + arm + real calibration** — `QualityGrader::gradeFeature(signals)` 0-10 from
    signals certify() already computes; `ATLAS_LOOP_DELIVERY_BAR_ARMED` injects quality_bar_gate into
    feature lane; `OutcomeCalibrationFeeder` appends {predicted,correct} post-merge → scheduled
    `ConfidenceCalibrator::calibrate()`. Until calibrate() returns non-null recommended_threshold (n≥20
    guard), confidence GATE stays OFF — rely on deterministic gradeFeature()>=9, never an uncalibrated 0.93.

## The single biggest risk (brutally honest)
Items 8+9 route through MiniMax's own GENERATION (spec/plan/criteria) which the flow can validate only
STRUCTURALLY (acyclic, well-formed), NOT semantically. A weak model that can't hold a large obra produces
a plausible-but-wrong plan, and no deterministic gate distinguishes a correct decomposition from an
incorrect-but-acyclic one. **Mitigation + honest limit:** keep the HUMAN-FROZEN acceptance as the spine
(never the model's spec); all deterministic gates anchor on it + machine-resolved signals; the overfit
probe manufactures the killing input deterministically; the escalation ladder thrash-jumps on repeated
failure. Net verdict: the flow makes a weak engine **match-or-beat ultracode on single-target,
well-specified delivery** (deterministic gates carry it); it **narrows but does not fully close** the gap
on large multi-file obras requiring novel decomposition. **Claim parity on verified-against-a-frozen-bar
delivery — NOT on greenfield large-obra decomposition.**
