export const meta = {
  name: 'loop-grade-9-design',
  description: 'Design + adversarially vet the HONEST path to >=9/10 on Decision, Merge-quality, Heavy-work capability — by-construction math, no grade-gaming, pétreo floor intact',
  phases: [
    { title: 'Design', detail: '4 lenses: decision/work-shape-router, merge-quality/honest-non_trivial, heavy-work/autonomous-multi-file, honesty-adversary' },
    { title: 'Synthesize', detail: 'ordered build plan + by-construction math to >=9 per dimension + this-session/soak/multi-week split' },
    { title: 'Refute', detail: 'adversarial: is any of this grade-GAMING? floor intact? can >=9 be HONESTLY guaranteed?' },
  ],
}

const FACTS = `
ATLAS EVOLUTION LOOP — push 3 panel dimensions to >=9/10, HONESTLY. Read the REAL code to ground every claim. The operator's culture is MEDIÇÃO HONESTA + anti-over-claim; the grade is ungameable BY DESIGN and the grader is PÉTREO (AtlasLoopUtilityGradeService + AtlasLoopWiredCallerService are in FORBIDDEN_SELF_TARGETS). A design that inflates the NUMBER without raising the real CAPABILITY is a FAIL.

THE UNGAMEABLE GRADE (app/Services/Ai/AutonomousEvolution/AtlasLoopUtilityGradeService.php):
  U = 10*(0.35*WIRED + 0.20*REAL_TARGET + 0.15*NON_TRIVIAL + 0.20*COMPOUNDING + 0.10*SAFETY), window = last 50 merged_to_main proposals.
  WIRED = share of merges whose FRESH-re-resolved caller count >=1 (re-resolved from git+grep, receipts NOT trusted).
  REAL_TARGET = share NOT on generated/test/docs.
  NON_TRIVIAL = share with touched_lines>15 AND deriveCategory in {bug,edge_case,perf} AND canary ran+green. (deriveCategory keys on objective/diff TEXT.)
  COMPOUNDING = share on HUB targets (fresh caller fan-in >= hub_threshold, default 3).
  SAFETY = canary green-rate.
  CURRENT LIVE: 6.34 — W=0.84, R=0.92, N=0.02, C=0.34, S=0.85. The grade RE-RESOLVES everything from the real merge commit (primaryTarget = dominant changed file by lines; targetKind re-classified from path; caller graph re-grepped). Stored receipt fields are NOT trusted.

PANEL SUB-SCORES TO RAISE (each >=9):
  - DECISION ("o quê a seguir") = 6.2. Target-PICKING is a sound deterministic leverage stack (real callers x complexity, orphan-gate, cooldown, test-backed — all LIVE). The GAP: work-SHAPE (edge-fix vs single-file-refactor vs multi-file-obra) is decided by a STATIC if/else flag cascade in AtlasLoopQueueRefiller.generateAndEnqueue/tryFrameworkRefactor, NOT by leverage reasoning; and the obra-cluster producer (AtlasLoopObraClusterDetectorService, built last session) is DEFAULT-OFF + proposal-only.
  - MERGE-QUALITY = 5.8. Capped by NON_TRIVIAL=0.02. The loop ships high-volume tiny wired edge-fixes (median ~4 touched lines). The framework-refactor lane (AtlasLoopFrameworkRefactorSynthesizer, flag ON) produces behavior-preserving complexity-reducing refactors PROVEN by AtlasLoopSemanticImplementationCertifier (real AST max-per-method cyclomatic DROP, sibling test green) — but these score ZERO on NON_TRIVIAL because their objective text "Refactor X to REDUCE complexity" matches none of {bug,edge_case,perf} in deriveCategory.
  - HEAVY-WORK CAPABILITY = 4.2. Single-file behavior-preserving framework refactor is LIVE+proven. Multi-file = DETECT+PROPOSE only (the obra producer parks operator-review candidates; default-OFF). The actual multi-file CODE CHANGE is Forge-class. Rails EXIST but inert: AtlasLoopObraBridgeService (blocked on operator/Forge L4-10 receipt), AtlasLoopObraAutoMergeService + AtlasLoopBroaderRegressionGate (flag-OFF), AtlasLoopTaskGrinder.maybeRouteMultiFileRefactorToObra (flag refactor_multi_file_via_obra OFF, needs allowed_files>=2 which nothing autonomous emits).

THE HONEST BY-CONSTRUCTION HYPOTHESIS (verify or refute):
  If the loop's DOMINANT merge becomes substantive PROVEN hub-refactors, the grade hits >=9 by construction: W~1.0, R~1.0, S~1.0, C~0.8 (hubs by selection), N~0.8 (substantive). Math: 0.35+0.20+0.15*0.8+0.20*0.8+0.10 = 0.93 = 9.3.
  Two real pieces needed: (a) make substantive proven refactors the DOMINANT merge (steer discovery hard to complex wired hubs + the framework-refactor lane producing them as the main output); (b) the grade must HONESTLY count a proven complexity-reducing refactor as NON_TRIVIAL — and the ONLY honest way is to RE-MEASURE the AST cyclomatic DROP from the real merge commit (before/after the changed file) INSIDE the grade itself, NOT trust objective text or a writable field. AtlasLoopSignalAnalyzer::fileComplexity exists. A refactor that did not actually reduce complexity must NOT count. This is ungameable (git truth, re-measured), consistent with how the grade already re-resolves everything.

PÉTREO FLOOR (violation = FAIL): never-merge default at 3 layers; no merge without the full safety gate stack (harness-guard, frozen reprove, php-l, boot-smoke, value-gate, pre-commit canary); HarnessGuard forbidden set only GROWN; big-obra auto-merge to main without operator review is the OPERATOR-DECISION frontier (do NOT cross autonomously); no fabricated evidence; default-safe flags.
`

phase('Design')
const DESIGN_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['lens','mechanism','new_or_changed','reuses_existing','by_construction_effect','honesty_argument','floor_guards','effort_class'],
  properties: {
    lens: { type: 'string' },
    mechanism: { type: 'string', description: 'the core design, 4-10 sentences, grounded in real files' },
    new_or_changed: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['file_or_component','change'], properties: { file_or_component: { type: 'string' }, change: { type: 'string' } } } },
    reuses_existing: { type: 'array', items: { type: 'string' } },
    by_construction_effect: { type: 'string', description: 'which grade axis / dimension it raises, and the math/argument for how far' },
    honesty_argument: { type: 'string', description: 'WHY this is real capability/honest measurement, NOT grade-gaming — the test an adversary would apply' },
    floor_guards: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['risk','guard'], properties: { risk: { type: 'string' }, guard: { type: 'string' } } } },
    effort_class: { type: 'string', enum: ['this_session','soak_time','multi_week'] },
  },
}
const LENSES = [
  { key: 'decision_router', prompt: 'LENS = DECISION ("o quê a seguir") to >=9. Design AtlasLoopWorkShapeRouter: a deterministic, provider-free chokepoint that consumes the leverage signals discovery already stamps (impact_real_callers, cyclomatic, refactor_leverage, has_sibling_test, framework_reach) and REASONS about the highest-leverage SHAPE per target (edge_fix vs single_file_refactor vs obra_candidate), replacing the static flag cascade in AtlasLoopQueueRefiller. Also: turn the obra-cluster producer ON (governed) so the decision layer is LIVE not OFF. Specify how a fresh adversarial panel would score decision-intelligence >=9 (it genuinely reasons + is live + measurable).' },
  { key: 'merge_quality_nontrivial', prompt: 'LENS = MERGE-QUALITY to >=9. The crux is NON_TRIVIAL. Design the HONEST recognition: the grade RE-MEASURES the AST cyclomatic drop from the real merge commit (parse changed .php before@parent vs after@commit via AtlasLoopSignalAnalyzer::fileComplexity), and counts a merge non_trivial when it is a PROVEN complexity-reduction (max-per-method dropped, file total not increasing) OR the existing correctness/perf+canary path — both >15 lines + canary green. Plus: steer discovery so substantive proven hub-refactors become the DOMINANT merge (not micro edge-fixes). Give the by-construction math to >=9 and prove an adversary cannot game it (a non-reducing or behavior-changing diff must not count; re-measured from git, not text/receipt).' },
  { key: 'heavy_work_autonomous', prompt: 'LENS = HEAVY-WORK CAPABILITY to >=9. This is the hardest. The panel defines it as autonomous DELIVERY of heavy multi-file work, not just proposing it. Design the autonomous multi-file refactor grind path REUSING the existing rails: obra producer emits a multi-file refactor_* TASK (>=2 coupled files) -> AtlasLoopTaskGrinder.maybeRouteMultiFileRefactorToObra -> the provider makes the coordinated change on an isolated worktree (Forge router) -> AtlasLoopBroaderRegressionGate certifies (fail-closed, full affected-module tests) -> operator-review (NEVER autonomous merge to main — that is the operator-decision frontier). Be brutally honest about effort_class: which part is this_session vs multi_week (autonomous CORRECT multi-file code change + L4-10 evidence). State whether heavy-work can HONESTLY reach 9 without crossing the never-merge/operator floor, or whether 9 there requires an operator greenlight + multi-week build.' },
  { key: 'honesty_adversary', prompt: 'LENS = HONESTY ADVERSARY. Assume the other 3 lenses want to hit >=9. Hunt for every way the proposed mechanisms could be GRADE-GAMING rather than real capability: counting refactors that do not really reduce complexity; redefining an axis to inflate; steering that farms easy hubs; a router that just relabels; turning a flag ON that produces noise; claiming heavy-work 9 when the loop only PROPOSES. For each, give the test that distinguishes honest-capability-gain from gaming, and the guard. mechanism = your verdict on whether >=9 is HONESTLY reachable this session for each dimension, or what is genuinely soak-time / multi-week.' },
]
const designs = await parallel(LENSES.map(l => () =>
  agent(`${FACTS}\n\n${l.prompt}\n\nReturn the structured design. Cite real file paths/line behavior. effort_class must be honest. The honesty_argument is mandatory and load-bearing.`,
    { label: `design:${l.key}`, phase: 'Design', schema: DESIGN_SCHEMA, agentType: 'Explore' })
))
const valid = designs.filter(Boolean)

phase('Synthesize')
const SYNTH_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['this_session_slices','soak_time','multi_week','by_construction_math','honest_ceiling_per_dimension','grade_test_spec'],
  properties: {
    this_session_slices: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['title','dimension','scope','new_or_changed','floor_guards','frozen_tests','flags'], properties: {
      title: { type: 'string' }, dimension: { type: 'string' }, scope: { type: 'string' },
      new_or_changed: { type: 'array', items: { type: 'string' } },
      floor_guards: { type: 'array', items: { type: 'string' } },
      frozen_tests: { type: 'array', items: { type: 'string' } },
      flags: { type: 'array', items: { type: 'string' } } } } },
    soak_time: { type: 'array', items: { type: 'string' }, description: 'what only the running soak can realize (window filling), with the mechanism that guarantees it' },
    multi_week: { type: 'array', items: { type: 'string' }, description: 'honest Forge-class pieces not shippable this session' },
    by_construction_math: { type: 'string', description: 'the explicit formula computation showing >=9 once the target merge mix dominates' },
    honest_ceiling_per_dimension: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['dimension','this_session_ceiling','full_ceiling','why'], properties: {
      dimension: { type: 'string' }, this_session_ceiling: { type: 'string' }, full_ceiling: { type: 'string' }, why: { type: 'string' } } } },
    grade_test_spec: { type: 'string', description: 'the deterministic test that PROVES the grade computes >=9 on a window of the target substantive-merge mix' },
  },
}
const plan = await agent(
  `${FACTS}\n\n4 design lenses (JSON):\n${JSON.stringify(valid)}\n\nSynthesize the ordered build plan to push Decision + Merge-quality + Heavy-work to >=9, HONESTLY. Separate this_session_slices (buildable + provable now) from soak_time (window-filling the mechanism guarantees) from multi_week (Forge-class). Give the explicit by-construction math to >=9 and the grade_test_spec that deterministically proves >=9 on the target merge mix. Be ruthlessly honest in honest_ceiling_per_dimension: if heavy-work cannot honestly hit 9 this session without crossing the operator/never-merge floor, say so and give the operator-greenlight path.`,
  { label: 'synthesize:plan', phase: 'Synthesize', schema: SYNTH_SCHEMA })

phase('Refute')
const REFUTE_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['gaming_findings','floor_findings','can_9_be_honestly_guaranteed','verdict','must_fix_before_build'],
  properties: {
    gaming_findings: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['dimension','severity','description','fix'], properties: {
      dimension: { type: 'string' }, severity: { type: 'string', enum: ['critical','high','medium','low'] }, description: { type: 'string' }, fix: { type: 'string' } } } },
    floor_findings: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity','description','fix'], properties: { severity: { type: 'string' }, description: { type: 'string' }, fix: { type: 'string' } } } },
    can_9_be_honestly_guaranteed: { type: 'object', additionalProperties: false, required: ['decision','merge_quality','heavy_work'], properties: {
      decision: { type: 'string' }, merge_quality: { type: 'string' }, heavy_work: { type: 'string' } } },
    verdict: { type: 'string', enum: ['GO','GO_WITH_FIXES','NO_GO'] },
    must_fix_before_build: { type: 'array', items: { type: 'string' } },
  },
}
const refute = await agent(
  `${FACTS}\n\nProposed plan (JSON):\n${JSON.stringify(plan)}\n\nYou are the HONESTY + FLOOR adversary. Try HARD to prove the plan is grade-GAMING or floor-violating. For the non_trivial-recognizes-refactors change especially: is re-measuring AST cyclomatic drop from the git commit genuinely honest+ungameable, or a redefinition that inflates? Can a refactor be crafted to fake a drop? Does steering-to-substantive farm easy wins? Does ANY slice cross never-merge / operator-review / fabricate evidence? Then answer per-dimension: can >=9 be HONESTLY guaranteed this session, by soak, or only multi-week? verdict NO_GO if the plan can only reach 9 by gaming; GO_WITH_FIXES if honest with fixes; GO if clean.`,
  { label: 'refute', phase: 'Refute', schema: REFUTE_SCHEMA })

return { this_session_slices: plan?.this_session_slices, soak_time: plan?.soak_time, multi_week: plan?.multi_week, by_construction_math: plan?.by_construction_math, honest_ceiling: plan?.honest_ceiling_per_dimension, grade_test_spec: plan?.grade_test_spec, refutation: refute }
