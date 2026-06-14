export const meta = {
  name: 'loop-heavywork-design',
  description: 'Design + adversarially vet the Atlas Loop heavy-work decision brain + autonomous big-obra candidate producer against the pétreo floor',
  phases: [
    { title: 'Design', detail: '4 independent lenses: decision-brain, obra-producer, floor-safety, minimal-wiring' },
    { title: 'Synthesize', detail: 'merge lenses into one vetted, ordered, floor-guarded slice plan' },
    { title: 'Refute', detail: 'adversarial critic tries to break slice-1 against the floor' },
  ],
}

// ---- Shared, code-VERIFIED context (the orchestrator already read these files) ----
const FACTS = `
ATLAS EVOLUTION LOOP — code-verified current state (atlas-server, 2026-06-14). VERIFY claims against the cited files; do NOT rediscover from scratch — confirm or correct.

PIPELINE: campaign supervisor -> AtlasLoopQueueRefiller.refill() [discover->generate->enqueue] -> AtlasLoopTaskGrinder.grind() [materialize->AtlasEvolutionLoopRunner deep-search + frozen judge -> adversarial gate -> propose-only] -> AtlasLoopAutoMergeService drain -> main. Live, sequential, self-healing, auto_merge_to_main=true.

DECISION BRAIN = app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopTargetDiscoveryService.php — deterministic, provider-free repo scan of app/Services. Hard gate: 40<=LOC<=400 AND php-l clean AND declares class/enum/trait. framework_reach>0 admitted ONLY when discovery_framework_targets flag ON (LIVE). Score = 0.55*self_contained + 0.30*improvement + 0.15*novelty, then 0.72*structural + 0.28*failure_evidence, then impact boost (+0.18*impact where impact uses REAL caller count from AtlasLoopWiredCallerService, failure_evidence, backlog_reach), orphan-gate (x0.15), cooldown (x0.35), test-backed boost (+0.25). It ALSO computes signals.refactor_leverage = 0.6*callerLeverage + 0.4*complexityLeverage and applies +0.12 boost when refactoring_targets_enabled ON (LIVE). signals carry: cyclomatic (max_per_method AST), cyclomatic_total, impact_real_callers (tri-state int|null), has_sibling_test, sibling_test_path, framework_reach.

WORK-SHAPE ROUTING = AtlasLoopQueueRefiller.generateAndEnqueue(): (1) framework_reach>0 -> if framework_refactor_enabled(LIVE) AND not forbidden self-target -> AtlasLoopFrameworkRefactorSynthesizer.synthesizeFrameworkRefactor() emits objective_kind='refactor_reduce_complexity' with EXACTLY ONE allowed_file; else edge-gap framework objective. (2) self-contained -> if refactor_objectives_enabled(LIVE) -> AtlasLoopRefactorObjectiveSynthesizer (Phase-1, ONE allowed_file); else provider-call RED-test generator (edge fix). CRITICAL: NO synthesizer EVER emits >=2 allowed_files.

MULTI-FILE OBRA LANE = AtlasLoopTaskGrinder.maybeRouteMultiFileRefactorToObra(): fires only when refactor_multi_file_via_obra flag ON (currently OFF) AND objective_kind starts 'refactor_' AND allowed_files>=2. Routes to AtlasLoopObraBridgeService.bridge(). Because no synthesizer emits >=2 files, this lane is INERT even if flipped.

OBRA BRIDGE = app/Services/Ai/AutonomousEvolution/AtlasLoopObraBridgeService.php — packages multi-file intent into Forge work-packets but status() returns 'blocked_by_l4_10_real_execution' unless an operator/Forge-supplied L4-10 real-execution receipt (l4_10_evidence_path) is CERTIFIED (deliveryReceipt requires hermes_cli + gpt-5.5 + governed atlas/obra/ branch + never_merged + never_pushed + integrated test pass). It NEVER dispatches a provider, NEVER merges. operator_approval.required=true always.

EXISTING BIG-OBRA SURFACE (disconnected from live loop) = AtlasLoopAutoArchitectureProposalService.php (L6-4, enabled, scheduled 06:30): reads code-graph (atlas_engineering_code_modules), finds structural hotspots (>=20 files / >=120 symbols), creates a self-improvement backlog draft via AtlasSelfImprovementProposalBacklogService + ArchitectureEvolutionProposalAdmissionService, parked for OPERATOR review. Proposal-only: no code mutation, no obra, no merge. It identifies a MODULE but does NOT decompose it into executable multi-file work-packets, and is NOT driven by the live grind loop's leverage signals.

SAFETY (obra-auto-merge rails, BUILT): AtlasLoopObraAutoMergeService crosses to main ONLY when obra certified + AtlasLoopBroaderRegressionGate passes. AtlasLoopBroaderRegressionGate.evaluate(): fail-CLOSED, maps changed files->affected test modules, ALWAYS runs the never-merge invariant test (tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php), boot-smoke, php-l, AND refuses (no_test_coverage_for_changed_file) any changed app/*.php with no mapped/sibling test. Flags refactor_multi_file_via_obra + obra_auto_merge_enabled DEFAULT-OFF.

PÉTREO FLOOR (NON-NEGOTIABLE — a design that weakens ANY of these is NO-GO):
- never-merge default at 3 layers (pgsql CHECK + trigger + Eloquent saving()).
- Big obras NEVER auto-merge to main without operator review (operator-decision frontier; do NOT build autonomous auto-merge of big obras).
- NO fabricated L4-10 / acceptance / evidence. The frozen judge + semantic certifier are the authority; never provider-claimed numbers.
- AtlasLoopHarnessGuard.isForbiddenSelfTarget(): the loop never targets its own gates/judge/never-merge/harness-guard.
- Churn hazard: the soak's auto-merge does git reset/checkout on the real tree -> uncommitted tracked edits are wiped. New producers must be additive + default-OFF.
- Provider-agnostic; no provider call inside discovery/proposal stages.

OPERATOR GOAL (verbatim intent): the Loop should run 24/7 autonomously doing HEAVIER refactors, BIG obras, BIGGER implementations, and decide its own next work intelligently. Single-file heavy refactor is already LIVE. Frontier = multi-file/obra-scale work + a smarter "what's next + what shape" decision — WITHOUT crossing the floor.
`

phase('Design')
const DESIGN_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['lens','mechanism','new_components','wiring_points','reuses_existing','floor_guards','flags','dod','honest_ceiling'],
  properties: {
    lens: { type: 'string' },
    mechanism: { type: 'string', description: 'the core design in 4-10 sentences' },
    new_components: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['name','responsibility','suggested_path'], properties: {
      name: { type: 'string' }, responsibility: { type: 'string' }, suggested_path: { type: 'string' } } } },
    wiring_points: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['file','change'], properties: { file: { type: 'string' }, change: { type: 'string' } } } },
    reuses_existing: { type: 'array', items: { type: 'string' }, description: 'existing services to reuse, NOT rebuild' },
    floor_guards: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['risk','guard'], properties: { risk: { type: 'string' }, guard: { type: 'string' } } } },
    flags: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['name','default'], properties: { name: { type: 'string' }, default: { type: 'string' } } } },
    dod: { type: 'array', items: { type: 'string' } },
    honest_ceiling: { type: 'string', description: 'what this CANNOT do autonomously / what is operator-greenlight-only / what is genuinely multi-week' },
  },
}
const LENSES = [
  { key: 'decision_brain', prompt: `LENS = DECISION BRAIN ("a forma que ele decide qual vai ser a próxima coisa"). Design how the loop should DECIDE, per refill cycle, (a) the highest-leverage target AND (b) the SHAPE of work it deserves: micro edge-fix vs single-file refactor vs MULTI-FILE OBRA candidate. Today this is crude flag-routing in AtlasLoopQueueRefiller; the rich signals (impact_real_callers, cyclomatic, refactor_leverage, has_sibling_test, failure_evidence, code-graph coupling) already exist in the discovery score. Design a deterministic, provider-free DECISION/ROUTER layer that consumes those signals and chooses shape by measured leverage thresholds. Be explicit about how an OBRA-shaped decision is recognized (e.g. high-complexity wired hub + N tightly-coupled callers, or a code-graph module hotspot) WITHOUT a provider call.` },
  { key: 'obra_producer', prompt: `LENS = AUTONOMOUS BIG-OBRA CANDIDATE PRODUCER. Design a deterministic service that turns the live loop's leverage signals into a COHERENT multi-file obra CANDIDATE (>=2 genuinely-coupled files: a hub + its real callers from AtlasLoopWiredCallerService / code graph, or a duplicated-logic cluster). It must route the candidate to the OPERATOR-REVIEW surface (reuse AtlasSelfImprovementProposalBacklogService the way AtlasLoopAutoArchitectureProposalService already does, and/or the AtlasLoopObraBridgeService packet) — NEVER auto-merge, NEVER fabricate L4-10. The point: connect the 24/7 grind loop to the big-obra surface, leverage-ranked, so the operator gets coherent big-work candidates instead of only micro-fixes. Specify the cluster-detection signal precisely and how it dedupes/cooldowns so it doesn't spam the backlog.` },
  { key: 'floor_safety', prompt: `LENS = FLOOR-SAFETY ADVERSARY. Assume an autonomous big-obra producer is being built. Enumerate EVERY way it could violate the pétreo floor or cause harm: weakening never-merge, auto-merging big obras without operator review, fabricating L4-10/acceptance, targeting the harness-guard/judge/gates, churn (wiping uncommitted edits via reset/checkout), backlog spam, a multi-file objective slipping into the single-file materializer, an obra candidate auto-promoting, provider calls in the proposal stage, gaming the leverage metric. For EACH, give the concrete guard that makes it fail-closed. Put these in floor_guards (risk -> guard). mechanism = your overall safety verdict.` },
  { key: 'minimal_wiring', prompt: `LENS = MINIMAL WIRING / ANTI-REBUILD. Catalog what ALREADY exists and is reusable: AtlasLoopAutoArchitectureProposalService (hotspot detector + backlog draft + operator review), AtlasLoopObraBridgeService (multi-file packet), AtlasSelfImprovementProposalBacklogService, AtlasLoopBroaderRegressionGate, AtlasLoopWiredCallerService, AtlasLoopOperatorReviewQueueService, code-graph tables. Design the SMALLEST set of NEW code + wiring that connects the live grind loop to the operator-gated big-obra surface, maximally reusing the above. Explicitly list what must NOT be rebuilt. Aim for one new producer service + 1-2 wiring points.` },
]
const designs = await parallel(LENSES.map(l => () =>
  agent(`${FACTS}\n\n${l.prompt}\n\nReturn the structured design. Cite real file paths. Be concrete and honest about the ceiling.`,
    { label: `design:${l.key}`, phase: 'Design', schema: DESIGN_SCHEMA, agentType: 'Explore' })
))
const validDesigns = designs.filter(Boolean)

phase('Synthesize')
const SYNTH_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['slice_1_detail','later_slices','reused_existing','floor_invariants_preserved','what_is_multiweek','decision_brain_summary'],
  properties: {
    decision_brain_summary: { type: 'string', description: 'the agreed decision/router design in 3-6 sentences' },
    slice_1_detail: { type: 'object', additionalProperties: false, required: ['title','scope','new_components','wiring','floor_guards','flags','dod','frozen_tests'], properties: {
      title: { type: 'string' },
      scope: { type: 'string' },
      new_components: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['name','suggested_path','responsibility'], properties: { name: { type: 'string' }, suggested_path: { type: 'string' }, responsibility: { type: 'string' } } } },
      wiring: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['file','change'], properties: { file: { type: 'string' }, change: { type: 'string' } } } },
      floor_guards: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['risk','guard'], properties: { risk: { type: 'string' }, guard: { type: 'string' } } } },
      flags: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['name','default'], properties: { name: { type: 'string' }, default: { type: 'string' } } } },
      dod: { type: 'array', items: { type: 'string' } },
      frozen_tests: { type: 'array', items: { type: 'string' }, description: 'the ungameable test cases slice-1 must ship' },
    } },
    later_slices: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['title','scope','operator_greenlight_only'], properties: { title: { type: 'string' }, scope: { type: 'string' }, operator_greenlight_only: { type: 'boolean' } } } },
    reused_existing: { type: 'array', items: { type: 'string' } },
    floor_invariants_preserved: { type: 'array', items: { type: 'string' } },
    what_is_multiweek: { type: 'array', items: { type: 'string' }, description: 'honest: what cannot be shipped this session' },
  },
}
const plan = await agent(
  `${FACTS}\n\nYou are the SYNTHESIS architect. Here are 4 independent design lenses (JSON):\n${JSON.stringify(validDesigns)}\n\nMerge them into ONE vetted, ordered slice plan. SLICE-1 must be the SMALLEST shippable+verifiable increment that delivers real value THIS session: connect the live grind loop's leverage signals to the operator-gated big-obra surface as a default-OFF, additive, floor-safe autonomous PRODUCER of coherent multi-file obra CANDIDATES routed to operator review (never auto-merge, no fabricated L4-10). Maximize reuse of existing services. Be ruthlessly honest in what_is_multiweek (fully-autonomous correct multi-file CODE changes = Forge-class, not slice-1). Specify slice_1 concretely enough to implement directly: component names, file paths, exact wiring points in AtlasLoopQueueRefiller/Discovery, floor guards, flags (default-OFF), and the ungameable frozen tests.`,
  { label: 'synthesize:plan', phase: 'Synthesize', schema: SYNTH_SCHEMA }
)

phase('Refute')
const REFUTE_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['holes','verdict','slice_1_safe_to_build','must_fix_before_build'],
  properties: {
    holes: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity','floor_or_concern','description','fix'], properties: {
      severity: { type: 'string', enum: ['critical','high','medium','low'] },
      floor_or_concern: { type: 'string' },
      description: { type: 'string' },
      fix: { type: 'string' } } } },
    verdict: { type: 'string', enum: ['GO','GO_WITH_FIXES','NO_GO'] },
    slice_1_safe_to_build: { type: 'boolean' },
    must_fix_before_build: { type: 'array', items: { type: 'string' } },
  },
}
const refute = await agent(
  `${FACTS}\n\nYou are an ADVERSARIAL floor auditor. Here is the proposed slice plan (JSON):\n${JSON.stringify(plan)}\n\nTry HARD to refute slice_1. Default to skepticism. Find every way it could: weaken never-merge, auto-merge a big obra without operator review, fabricate L4-10/acceptance, let a multi-file objective reach the single-file materializer, target the harness guard, cause churn (wipe uncommitted edits), spam the backlog, game the leverage metric, or over-claim autonomy. For each hole give severity + the concrete fix. Then a verdict: NO_GO if any unfixable critical floor violation; GO_WITH_FIXES if criticals/highs have clear fixes that must land in slice-1; GO only if genuinely clean. Set slice_1_safe_to_build and list must_fix_before_build.`,
  { label: 'refute:slice-1', phase: 'Refute', schema: REFUTE_SCHEMA }
)

return { decision_brain_summary: plan?.decision_brain_summary, slice_1: plan?.slice_1_detail, later_slices: plan?.later_slices, what_is_multiweek: plan?.what_is_multiweek, reused_existing: plan?.reused_existing, refutation: refute, design_lens_count: validDesigns.length }
