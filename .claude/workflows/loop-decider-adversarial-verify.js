export const meta = {
  name: 'loop-decider-adversarial-verify',
  description: 'Adversarially break the AS-BUILT AtlasLoopNextWorkDecider + planned refiller wiring before it ships',
  phases: [
    { title: 'Refute', detail: '5 adversaries attack the real code from distinct angles' },
    { title: 'Verdict', detail: 'fold findings into a single must-fix build list' },
  ],
}

const BUILT_CODE = String.raw`
// app/Services/Ai/AutonomousEvolution/Discovery/AtlasLoopNextWorkDecider.php  (AS BUILT, compiles clean)
final class AtlasLoopNextWorkDecider {
  private const BAND_WIDTH = 1000;
  private const BAND_SKIP = 0;
  private const BAND_EDGE_FIX = 2000;
  private const BAND_REFACTOR = 4000;
  private const BAND_OBRA = 6000;

  public function __construct(
    private readonly ?AtlasLoopWiredCallerService $wiredCallers = null,
    private readonly ?AtlasLoopSignalAnalyzer $signalAnalyzer = null,
    private readonly ?AtlasLoopWorkShapeRouter $workShapeRouter = null,
    private readonly ?AtlasLoopHarnessGuard $harnessGuard = null,
  ) {}

  // returns {priority:int, shape:string, band:int, offset:int, receipt:array}
  public function decide(string $repoRoot, string $relPath, array $signals, float $storedScore, ?string $shapeHint = null): array {
    $shape = $shapeHint !== null && $shapeHint !== '' ? $shapeHint
           : (string)($this->router()->decideShape($signals)['shape'] ?? AtlasLoopWorkShapeRouter::SHAPE_EDGE_FIX);
    $band = $this->bandFor($shape); // match: SKIP=0, REFACTOR=4000, 'obra'|'obra_candidate'|'multi_file_refactor'=6000, default(incl edge_fix)=2000
    [$offset, $reResolved] = $this->reResolvedOffset($repoRoot, $relPath, $signals, $storedScore);
    $priority = $band + $offset;
    $receipt = [...debug fields...];
    return compact('priority','shape','band','offset','receipt');
  }

  // OFFSET in [0, 999], re-resolved from ground truth:
  private function reResolvedOffset($repoRoot, $relPath, $signals, $storedScore): array {
    $cap = 999;
    // 1. fresh grep caller count via AtlasLoopWiredCallerService::callerCounts([$relPath]) -> tri-state int|null; try/catch -> null
    // 2. fresh worst-method cyclomatic: file_get_contents(repoRoot/relPath), AtlasLoopSignalAnalyzer::fileComplexity($src)['max_per_method'] if measured; try/catch -> null
    // if BOTH null: fallback = round(clamp01($storedScore) * 999); return [fallback, false];   // fail-open within band
    // else: callerComponent = min(1, callers/20); cxComponent = min(1, cyclomatic/30); leverage = 0.65*callerComponent + 0.35*cxComponent;
    //       offset = round(leverage * 999); return [clamp(0,999,offset), true];
  }
  private function callers($repoRoot){ return $this->wiredCallers ?? new AtlasLoopWiredCallerService($repoRoot); }
}
`

const PLANNED_WIRING = String.raw`
PLANNED WIRING (NOT yet written — attack this too):
- In AtlasLoopQueueRefiller::generateAndEnqueue, AFTER the work-shape decision and AFTER the work_skip short-circuit,
  compute ONCE:  $decision = $this->nextWorkDecider?->decide($repoRoot, ltrim($target->target_path,'/'), $signals, (float)$target->score, $decidedShape);
  Then every one of the 5 enqueueTask(...) calls replaces its 6th arg  (int) round(((float)$target->score) * 100)
  with  ($useDecider ? $decision['priority'] : (int) round(((float)$target->score)*100)),  gated by
  config('atlas.loop.decision_priority_enabled', false) (DEFAULT OFF). The decider's receipt is stamped into each payload
  under '_decision'. The shape passed as $shapeHint is the SAME shape the router already decided above (no double-call).
- nextWorkDecider is a NEW nullable ctor param (12th) on AtlasLoopQueueRefiller, bound in AppServiceProvider via rescue().
- AtlasLoopStore::claimNextTask is UNCHANGED — still ORDER BY priority DESC, created_at ASC (pure integer hot path).
- AtlasLoopNextWorkDecider.php is ALREADY in AtlasLoopHarnessGuard::FORBIDDEN_SELF_TARGETS (pétreo).
- The obra multi-file lane currently enqueues with shape unknown to the decider; the obra lane should pass shapeHint='multi_file_refactor' so it lands in BAND_OBRA (6000).
`

const CONTRACT = String.raw`
ATLAS LOOP PÉTREO FLOOR (non-negotiable invariants the decider must NOT break):
- Default-OFF: with decision_priority_enabled=false the enqueue priority MUST be byte-identical to today: (int) round(score*100) in [0,100].
- FAIL-OPEN: any re-resolution failure must never REGRESS ordering below today and never throw out of generateAndEnqueue.
- The decider NEVER merges, never calls a provider, never mutates code/git/db, never bypasses a RED-gate.
- claimNextTask hot path stays pure-integer (no git/grep/AST inside FOR UPDATE SKIP LOCKED).
- A CONFIRMED orphan (measured 0 callers/0 evidence/0 reach => shape work_skip) must NEVER out-prioritize a wired hub.
- A forged/stale STORED score or signal must NOT buy the next-work slot ahead of genuinely higher-leverage work.
- Tri-state caller measurement: int = measured (0 = confirmed orphan), null = unmeasured (fail-open, never assume orphan).
Ground-truth seams (real signatures): AtlasLoopWiredCallerService::callerCounts(array $relPaths): array (values int|null);
AtlasLoopSignalAnalyzer::fileComplexity(string $src): {measured:bool,max_per_method:int,total:int,methods:int,worst_method:?string};
AtlasLoopWorkShapeRouter::decideShape(array $signals): {shape, reason, log} with SHAPE_SKIP='work_skip', SHAPE_REFACTOR='single_file_refactor', SHAPE_EDGE_FIX='edge_fix'.
`

phase('Refute')
const ADV = (angle) => `You are a rigorous adversary trying to BREAK the AS-BUILT next-work decider for the Atlas Loop.
Assume it is broken until proven otherwise. Be concrete and code-level.

${CONTRACT}

AS-BUILT CODE:
${BUILT_CODE}

${PLANNED_WIRING}

YOUR ATTACK ANGLE: ${angle}

Produce: (1) the single most damaging concrete hole on this angle — exact input/state/sequence, why it breaks an
invariant, severity (critical/high/medium/low); (2) the MINIMAL fix (file + what to change); (3) if you genuinely
cannot break it on this angle, say so and explain precisely why the design closes it. Do NOT invent holes that the
default-OFF flag or the band-dominance already prevent — verify against the actual code first.`

const refutes = await parallel([
  () => agent(ADV('SHAPE-TIER INVERSION: can a confirmed orphan (work_skip, band 0) or a trivial edge_fix (band 2000) ever end up with a higher final priority than a real wired refactor hub (band 4000) or obra (band 6000)? Check the offset cap (999) vs band gaps (2000) rigorously, and the match() default landing, and the obra shapeHint path.'), { label: 'refute:tier', phase: 'Refute' }),
  () => agent(ADV('FORGED/STALE STORED FIELDS: trace every path where the FINAL priority still depends on the writable stored score or stored signals[impact_real_callers]. Especially the BOTH-null fail-open fallback (round(score*999)) — can an attacker force both fresh sources to be null (e.g. unreadable path, parse failure, deleted file) to make the forged score drive the offset? Does band-dominance still contain it? Is there any non-orphan shape whose band an orphan could reach?'), { label: 'refute:forge', phase: 'Refute' }),
  () => agent(ADV('FAIL-OPEN / REGRESSION / DEFAULT-OFF: prove (or break) that with decision_priority_enabled=false the priority is byte-identical to today. Then check: does the decider ever throw out of generateAndEnqueue? Does the work_skip short-circuit (which returns BEFORE enqueue today) interact correctly — i.e. is the decider even reached for a skip, and if reached, does its band-0 matter? Is there a case where enabling the flag REORDERS existing in-flight tasks unfairly or starves edge_fix work forever (obra/refactor always 4000-6000 > edge 2000-2999 => edge work may never be claimed)?'), { label: 'refute:failopen', phase: 'Refute' }),
  () => agent(ADV('STARVATION / FAIRNESS / LIVENESS: with band-tiered priority, lower bands (edge_fix=2000, skip=0) can be permanently out-competed by a steady supply of refactor(4000)/obra(6000) tasks, since claim is strict ORDER BY priority DESC, created_at ASC. Is that a real starvation bug for the 24/7 loop? Does any aging/fairness mechanism exist? Propose the minimal fix (e.g. created_at tiebreak already helps within a band, but across bands a flood of high-band tasks starves low). Decide if this is acceptable-by-design or a must-fix.'), { label: 'refute:starve', phase: 'Refute' }),
  () => agent(ADV('CORRECTNESS OF LEVERAGE MATH + CALLER-RESOLVER REPO ANCHORING: the injected AtlasLoopWiredCallerService may be anchored to a DIFFERENT repoRoot than the campaign workspace (callers() returns the injected instance if non-null, ignoring $repoRoot). Does that produce WRONG caller counts for the target repo? Also audit the saturation constants (callers/20, cyclomatic/30, 0.65/0.35) — can a hub with 100 callers and a trivial file tie a 5-caller god-method? Is offset monotonic in real leverage?'), { label: 'refute:anchor', phase: 'Refute' }),
])

phase('Verdict')
const verdict = await agent(`You are the final reviewer. Fold these 5 adversarial findings into ONE authoritative must-fix build list
for the AtlasLoopNextWorkDecider before it is wired + frozen-tested.

${CONTRACT}

AS-BUILT:
${BUILT_CODE}
${PLANNED_WIRING}

FINDINGS:
1. TIER: ${refutes[0] ?? '(none)'}
2. FORGE: ${refutes[1] ?? '(none)'}
3. FAIL-OPEN: ${refutes[2] ?? '(none)'}
4. STARVATION: ${refutes[3] ?? '(none)'}
5. ANCHOR/MATH: ${refutes[4] ?? '(none)'}

Dedupe overlapping findings. For each REAL must-fix give: severity, the exact file + change, and whether it BLOCKS
shipping. Then give the final ordered build checklist (decider fixes -> wiring -> frozen tests). Finally, ONE honest
sentence: once these fixes + frozen tests land, does this mechanism legitimately raise the Decisão dimension to ~9 by
the merge-quality standard (re-resolve from ground truth, ungameable, frozen-proven), or not — and why.`,
  { phase: 'Verdict', schema: {
    type: 'object', additionalProperties: false,
    required: ['must_fix', 'non_issues', 'build_checklist', 'frozen_tests', 'honest_9_assessment'],
    properties: {
      must_fix: { type: 'array', items: { type: 'object', additionalProperties: false,
        required: ['severity', 'file', 'change', 'blocks_shipping'],
        properties: { severity: { type: 'string', enum: ['critical','high','medium','low'] }, file: { type: 'string' }, change: { type: 'string' }, blocks_shipping: { type: 'boolean' } } } },
      non_issues: { type: 'array', items: { type: 'string' }, description: 'adversary claims that are NOT real (band-dominance/flag-off already prevent), with why' },
      build_checklist: { type: 'array', items: { type: 'string' } },
      frozen_tests: { type: 'array', items: { type: 'string' }, description: 'each test name + the ungameability/correctness property it proves' },
      honest_9_assessment: { type: 'string' },
    },
  } })

return verdict
