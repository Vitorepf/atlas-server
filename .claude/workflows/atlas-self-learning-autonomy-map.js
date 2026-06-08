export const meta = {
  name: 'atlas-self-learning-autonomy-map',
  description: 'Map the COMPLETE Atlas self-learning surface (what it registers/learns/proposes) and design the autonomous auto-apply + Sunday memory-digest, adversarially checked against the pétreo floor',
  phases: [
    { title: 'Map', detail: '6 parallel readers across the self-learning subsystems' },
    { title: 'Synthesize', detail: 'complete map + auto-apply/auto-capture/Sunday-report design' },
    { title: 'Critique', detail: 'adversarial: pétreo-floor crossing + 10/10-trap duplication' },
  ],
}

const MAP_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    subsystem: { type: 'string' },
    registers: { type: 'array', items: { type: 'string' }, description: 'what it records/saves (evidence, memory, ledgers) with file:line' },
    learns: { type: 'array', items: { type: 'string' }, description: 'what learnings/heuristics it derives' },
    proposes: { type: 'array', items: { type: 'string' }, description: 'what improvements it proposes + the proposal kinds' },
    auto_today: { type: 'array', items: { type: 'string' }, description: 'what ALREADY runs automatically with no operator approval' },
    approval_gated: { type: 'array', items: { type: 'string' }, description: 'what requires operator approval today + where the gate is (file:line)' },
    wiring_points: { type: 'array', items: { type: 'string' }, description: 'concrete file:line seams to (a) hook auto-capture-on-every-use or (b) flip a SAFE class to auto-apply' },
    reuse_for_goal: { type: 'string', description: 'how to REUSE this for autonomous auto-apply + Sunday report — do NOT rebuild' },
    petreo_constraints: { type: 'array', items: { type: 'string' }, description: 'any never-cross rule this subsystem enforces (never-merge, critical no-auto-apply, kernel, quarantine)' },
  },
  required: ['subsystem', 'registers', 'learns', 'proposes', 'auto_today', 'approval_gated', 'wiring_points', 'reuse_for_goal', 'petreo_constraints'],
}

const CRITIQUE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  properties: {
    lens: { type: 'string' },
    verdict: { type: 'string', enum: ['sound', 'needs_fixes', 'unsound'] },
    crosses_petreo_floor: { type: 'boolean', description: 'does the auto-apply design EVER auto-cross never-merge / critical-no-auto-apply / kernel?' },
    reversibility_complete: { type: 'boolean', description: 'can the operator reverse EVERYTHING from the Sunday report (so "depois eu removo" works)?' },
    rebuilds_existing: { type: 'array', items: { type: 'string' }, description: '10/10-trap: things the design rebuilds that already exist' },
    must_fix: { type: 'array', items: { type: 'object', additionalProperties: false, properties: { issue: { type: 'string' }, where: { type: 'string' }, fix: { type: 'string' } }, required: ['issue', 'where', 'fix'] } },
    sunday_report_gaps: { type: 'array', items: { type: 'string' }, description: 'what saved-to-memory data the Sunday digest would MISS' },
  },
  required: ['lens', 'verdict', 'crosses_petreo_floor', 'reversibility_complete', 'rebuilds_existing', 'must_fix', 'sunday_report_gaps'],
}

const ROOT = '/Users/vitorepf/develop/Atlas/atlas-server'
const GOAL = `OPERATOR GOAL (his exact words, Portuguese): build workflows to UNDERSTAND everything Atlas does to self-learn — everything it registers, everything it proposes as improvement — and make it apply everything AUTOMATICALLY like Hermes (every use of Atlas auto-saves + auto-improves WITHOUT per-item approval), then every SUNDAY deliver a report of everything saved to Atlas memory so the operator can prune/understand after the fact.
PÉTREO FLOOR (the operator's OWN canon, must hold): the automatic path NEVER merges to main and NEVER auto-applies critical/secret/cyber classes — those queue for the Sunday review. Everything else auto-applies, reversibly. Existing canon already states a "critical-change no-auto-apply review gate" (atlas:aaeos:learning-proposals) — honor it.`

const COMMON = `Repo root: ${ROOT}. You have Read/Grep/Glob/Bash. Read the REAL code/docs/CLI — cite file:line. Report REALITY: distinguish what is ALREADY automatic from what is built-but-dormant (config-gated) from what requires operator approval. Do NOT trust names; open the files. Be concrete about wiring seams (exact file:line) because a build depends on your map.\n\n${GOAL}`

phase('Map')
const SUBSYSTEMS = [
  { key: 'compounding', label: 'compounding-plane', prompt: `Map the COMPOUNDING plane. Services in app/Services/Ai/Compounding/: AtlasLearningProposalService, AtlasLearningProposalApplier, AtlasHeldEvidenceMinerService, AtlasLearningDistiller, AtlasHeuristicEvolutionService, AtlasRagFeedbackService, AtlasCompoundingRuntimeService, AtlasCompoundingMemoryService, AtlasCompoundingOutcomeEvaluator, AtlasLearningMutationRuntimeService. CLIs: atlas:ai:learning, atlas:ai:mine-held-evidence, atlas:ai:apply-learning, atlas:ai:compounding. Trace the FULL learning-proposal lifecycle: signal -> proposal -> (approval?) -> applied. Where exactly is the approval gate, and what would it take to AUTO-approve+apply the SAFE classes (routing/heuristic/memory) while leaving critical gated?` },
  { key: 'selfimprovement', label: 'selfimprovement-plane', prompt: `Map the SELF-IMPROVEMENT plane in app/Services/Ai/SelfImprovement/: AtlasSelfImprovementClosedLoopService, AtlasSelfImprovementOrchestrator, AtlasSelfImprovementScheduleService, AtlasSelfImprovementProposalBacklogService, AtlasSelfImprovementResultLedgerService, AtlasSelfImprovementHumanTrustLedgerService, AtlasSelfImprovementRuntime, AtlasSelfImprovementNextCycleRecommendationService. Is there already a CLOSED LOOP + a SCHEDULE service? Could the Sunday report + the autonomous cadence reuse AtlasSelfImprovementScheduleService instead of a new scheduler? What does the ResultLedger record (Sunday-reportable)?` },
  { key: 'aemor', label: 'aemor-execution-memory', prompt: `Map AEMOR (Atlas Execution Memory & Outcome Runtime). CLIs: atlas:aemor, atlas:aemor:distill, atlas:aemor:judgment, atlas:aemor:judgment-certify, atlas:aemor:memory-audit, atlas:aemor:memory-conflicts, atlas:aemor:risk-predict. Find its services (grep Aemor / AEMOR under app/Services). THIS is likely the "capture every use -> distill into learning candidates" seam the operator wants automatic. Does it already auto-capture on every Atlas execution, or is it manual/CLI-triggered? What is the exact seam to make distillation run automatically after every use?` },
  { key: 'memory', label: 'memory-cognitive-immune', prompt: `Map the MEMORY REGISTRY + cognitive-immune system — i.e. "a memória do Atlas" the Sunday report must digest. CLIs: atlas:aaeos:memory-cognitive-immune-learning-kernel, atlas:aaeos:memory-contracts, atlas:aaeos:personal-longitudinal-roadmap, atlas:ai:capture-inbox-pipeline-report. Read docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md and atlas-cognition-operating-system.md. WHERE does Atlas memory physically live (tables/files)? How is a memory written + promoted (G0-G8 ladder, quarantine default)? What is the exact queryable surface a "everything saved this week" Sunday digest would read, and how does the operator REMOVE a memory (reversibility)?` },
  { key: 'metalearning', label: 'metalearning-routing-flywheel', prompt: `Map the ROUTING META-LEARNING flywheel (parallel to the just-built one). CLIs: atlas:atlas-decide:meta-learning, atlas:atlas-decide:meta-learning:activate, atlas:atlas-decide:meta-learning:deactivate, atlas:atlas-decide:gateway-consult. Services: AtlasConductorRoutingMemory, AtlasLearningProposalApplier (app/Services/Ai/Compounding), AtlasChangeClassTrustLadder + AtlasAutonomyAdmissionService (app/Services/Ai/Governance). There appear to be TWO route-learning mechanisms (meta-learning:activate AND the new applyPreferred). Are they redundant or complementary? Which one should the AUTONOMOUS auto-apply use? Map the trust-ladder seam that lets a class auto-earn lower friction.` },
  { key: 'floor', label: 'petreo-governance-floor', prompt: `Map the PÉTREO GOVERNANCE FLOOR that auto-apply must NEVER cross. Services: AtlasAutonomyAdmissionService, AtlasConstitutionalKernelService (app/Services/Ai/Governance), PolicyCanon (app/Services/Ai/Policy). CLIs: atlas:aaeos:learning-proposals (the "critical-change no-auto-apply review gate"), atlas:aaeos:runtime-evidence-learning. Enumerate EXACTLY: which change classes / risk levels / privacy classes are forbidden from auto-apply by canon; where "never-merge to main" is enforced; what the cognitive-immune quarantine blocks. The autonomous loop must auto-apply ONLY within this floor — give the precise predicate ("auto-apply iff ...").` },
]

const maps = await parallel(SUBSYSTEMS.map(s => () =>
  agent(`${COMMON}\n\n=== YOUR SUBSYSTEM: ${s.label} ===\n${s.prompt}`, { label: `map:${s.key}`, phase: 'Map', schema: MAP_SCHEMA })
)).then(r => r.filter(Boolean))

log(`Mapped ${maps.length}/6 self-learning subsystems`)

phase('Synthesize')
const design = await agent(
  `${COMMON}\n\nYou are the SYNTHESIS architect. Here are the 6 subsystem maps as JSON:\n\n${JSON.stringify(maps, null, 2)}\n\nProduce a DECISION-GRADE markdown brief with these sections:\n1. **The complete self-learning map** — one coherent picture: every place Atlas registers, learns, and proposes; the full pipeline from "operator uses Atlas" to "memory/heuristic updated". Mark each stage AUTO / DORMANT / APPROVAL-GATED today.\n2. **Auto-capture on every use** — the smallest wiring to make every Atlas use auto-save a learning candidate (reuse AEMOR/signal-collection; cite the seam). No new parallel capture brain.\n3. **Autonomous auto-apply (Hermes-like)** — the precise design: which SAFE classes auto-apply (routing/heuristic/memory), reusing which existing services (applier / meta-learning:activate / trust-ladder), behind what config posture (a single "autonomous mode" flag), and the EXACT predicate that keeps it within the pétreo floor (never-merge, critical/secret/cyber -> queue for Sunday, cognitive-immune quarantine respected). Asymmetric + reversible.\n4. **The Sunday memory digest** — a scheduled command (reuse Laravel scheduler / AtlasSelfImprovementScheduleService) that every Sunday reports EVERYTHING saved to Atlas memory that week + every auto-applied learning, each with a one-command REVERSE handle so the operator prunes after the fact. Specify exactly what it reads and the output shape.\n5. **Anti-duplication ledger** — explicit REUSE-not-build list (the ~40 existing services); name what NOT to create.\n6. **First shippable slice** — the smallest end-to-end: auto-capture + auto-apply ONE safe class + the Sunday digest, default-OFF behind one flag, fully reversible.\nGround every claim in file:line from the maps. Prefer reuse; flag every 10/10-trap.`,
  { label: 'synthesize:design', phase: 'Synthesize' }
)

phase('Critique')
const CRITICS = [
  { key: 'petreo', lens: 'pétreo-floor adversary', prompt: `You are an ADVERSARIAL sovereignty auditor. Try HARD to find a path where this autonomous auto-apply design auto-crosses the pétreo floor: auto-merges to main, auto-applies a critical/secret/cyber class, bypasses the constitutional kernel or the cognitive-immune quarantine, or makes something IRREVERSIBLE (so the operator could NOT prune it from the Sunday report). Default to crosses_petreo_floor=true unless the design structurally prevents each. List concrete must-fixes.` },
  { key: 'duplication', lens: '10/10-trap + Sunday-completeness adversary', prompt: `You are an ADVERSARIAL anti-over-engineering auditor. The repo already has ~40 self-learning services. Find every place this design REBUILDS something that exists (rebuilds_existing). Separately, stress the Sunday report: list every category of saved-to-memory data it would MISS (sunday_report_gaps) — AEMOR candidates, cognitive-immune promotions, quarantined items, applied routing, heuristic deltas, etc. A report that silently misses a memory class fails the operator's "entender tudo que foi salvo".` },
]
const critiques = await parallel(CRITICS.map(c => () =>
  agent(`${COMMON}\n\n=== DESIGN UNDER REVIEW ===\n${design}\n\n=== YOUR LENS: ${c.lens} ===\n${c.prompt}`, { label: `critique:${c.key}`, phase: 'Critique', schema: CRITIQUE_SCHEMA })
)).then(r => r.filter(Boolean))

return {
  maps_count: maps.length,
  design,
  critiques,
  petreo_clean: critiques.every(c => c.crosses_petreo_floor === false),
  reversibility_ok: critiques.every(c => c.reversibility_complete === true),
}
