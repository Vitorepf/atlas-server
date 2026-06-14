export const meta = {
  name: 'loop-obra-candidate-floor-verify',
  description: 'Adversarially re-prove the autonomous obra-candidate producer respects the pétreo floor (read real code + run tests, hunt for floor violations / gaming / regressions)',
  phases: [
    { title: 'Audit', detail: '4 adversarial lenses read the real built code + run targeted tests' },
    { title: 'Verdict', detail: 'synthesize a GO / NO_GO over confirmed findings only' },
  ],
}

const FILES = `
BUILT SLICE (atlas-server) — the autonomous multi-file obra CANDIDATE producer. Read the REAL files and verify against them; cite file:line evidence; do NOT take this summary on faith.
NEW:
- app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopObraClusterDetectorService.php (the producer)
- app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopObraClusterCandidate.php (DTO, >=2-file invariant in fromHub)
- tests/Feature/Loop/AtlasLoopObraClusterDetectorTest.php (9 frozen tests)
CHANGED:
- app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopWiredCallerService.php (added public callerPaths(); refactored grepCallerCount to delegate to new private grepCallerFiles — callerCounts MUST be byte-identical)
- app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopQueueRefiller.php (9th optional ctor param obraClusterDetector; flag-gated fail-open detect() call at end of refill(); added obra_candidates to return)
- app/Providers/AppServiceProvider.php (~line 210: AtlasLoopQueueRefiller bind now passes arg 8 frameworkRefactorSynthesizer + arg 9 detector, both rescue-resolved)
- config/atlas.php (~line 1832: 7 obra_cluster_* flags, all default-OFF/safe)

PÉTREO FLOOR (a violation = NO_GO): never-merge default unchanged; producer is PROPOSAL-ONLY (never enqueues a loop task, never calls a provider, never mutates the repo/git, never creates an obra, never merges); no fabricated L4-10/acceptance; AtlasLoopHarnessGuard forbidden-self-targets never proposed; default-OFF => inert; fail-open everywhere.

The 5 MUST-FIX items the design phase demanded (confirm each is ACTUALLY closed in the built code):
1. callerPaths() exists, is public, returns MEASURED caller file paths (grep-based), self+tests excluded, tri-state (absent key = unmeasured).
2. Dedup does NOT rely on the lossy backlog createProposal (which mints a fresh id + drops unknown keys) — the detector keeps its OWN durable cluster-hash cooldown index.
3. Constructor: detector is the 9th param; the bind passes BOTH frameworkRefactorSynthesizer (8th) AND detector (9th) so the LIVE framework_refactor lane is not clobbered.
4. isForbiddenSelfTarget runs on the hub AND every resolved caller path; ANY forbidden member rejects the whole cluster.
5. A stale code-graph cannot manufacture a phantom cluster — cluster formation requires REAL grep caller paths (>=1), so the >=2-file floor needs working-tree truth.
`

phase('Audit')
const FINDING_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['lens','findings','lens_verdict'],
  properties: {
    lens: { type: 'string' },
    findings: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity','claim','evidence','status'], properties: {
      severity: { type: 'string', enum: ['critical','high','medium','low','info'] },
      claim: { type: 'string' },
      evidence: { type: 'string', description: 'file:line or test output proving it' },
      status: { type: 'string', enum: ['confirmed_hole','refuted_safe'] },
    } } },
    lens_verdict: { type: 'string', enum: ['GO','NO_GO'] },
  },
}
const LENSES = [
  { key: 'floor_integrity', prompt: 'Try to find ANY path where the producer weakens never-merge, merges, mutates the repo/git, enqueues a loop task, calls a provider, creates an obra, or fabricates L4-10/acceptance evidence. Read AtlasLoopObraClusterDetectorService + AtlasLoopObraClusterCandidate + the refiller change. Trace every external call (backlog createProposal, callerPaths, Storage). A single real floor violation = NO_GO.' },
  { key: 'inertness_and_regression', prompt: 'Prove (or refute) three regression-safety claims by reading code AND running tests: (a) with atlas.loop.obra_cluster_detection_enabled=false the producer is fully inert and refill() counters are unchanged; (b) callerCounts() is byte-identical after grepCallerCount was refactored to delegate to grepCallerFiles; (c) the AppServiceProvider bind does NOT clobber the framework_refactor synthesizer. Run: php -d memory_limit=2048M artisan test tests/Feature/Loop/AtlasLoopObraClusterDetectorTest.php tests/Feature/Loop/AtlasLoopWiredTargetingTest.php tests/Feature/Loop/AtlasLoopFrameworkRefactorSynthesizerTest.php — report pass/fail counts as evidence.' },
  { key: 'gaming_and_dedup', prompt: 'Attack the anti-spam + correctness. Can the cluster_hash cooldown be bypassed so the operator backlog floods? Does the dedup truly live in the detector\'s own durable index (not the lossy backlog registry)? Can a single-file or stale-graph-only cluster slip through fromHub? Can a non-measured (null) caller count qualify a hub? Read the detector + DTO + the createProposal persistence (app/Services/Ai/SelfImprovement/AtlasSelfImprovementProposalBacklogService.php).' },
  { key: 'must_fix_closure', prompt: 'For EACH of the 5 must-fix items listed above, read the built code and decide confirmed_hole (NOT actually closed) or refuted_safe (closed), citing file:line. Be skeptical — a fix that is described in a comment but not enforced in code is a confirmed_hole.' },
]
const audits = await parallel(LENSES.map(l => () =>
  agent(`${FILES}\n\nLENS = ${l.key}. ${l.prompt}\n\nReturn structured findings. status=confirmed_hole only with concrete file:line or test-output evidence; otherwise refuted_safe. lens_verdict=NO_GO if you confirmed any critical/high floor hole, else GO.`,
    { label: `audit:${l.key}`, phase: 'Audit', schema: FINDING_SCHEMA, agentType: 'Explore' })
))
const valid = audits.filter(Boolean)

phase('Verdict')
const VERDICT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['verdict','confirmed_holes','floor_intact','must_fix_all_closed','summary'],
  properties: {
    verdict: { type: 'string', enum: ['GO','GO_WITH_FIXES','NO_GO'] },
    floor_intact: { type: 'boolean' },
    must_fix_all_closed: { type: 'boolean' },
    confirmed_holes: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity','description','fix'], properties: {
      severity: { type: 'string', enum: ['critical','high','medium','low'] },
      description: { type: 'string' }, fix: { type: 'string' } } } },
    summary: { type: 'string' },
  },
}
const verdict = await agent(
  `${FILES}\n\nHere are 4 adversarial audit results (JSON):\n${JSON.stringify(valid)}\n\nSynthesize ONE verdict over CONFIRMED holes only (discard refuted_safe + duplicates). NO_GO if any confirmed critical/high floor violation; GO_WITH_FIXES if only medium/low or non-floor concerns; GO if clean. Set floor_intact + must_fix_all_closed honestly.`,
  { label: 'verdict', phase: 'Verdict', schema: VERDICT_SCHEMA }
)
return verdict
