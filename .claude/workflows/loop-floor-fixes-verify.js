export const meta = {
  name: 'loop-floor-fixes-verify',
  description: 'Adversarially re-prove the 5 loop floor fixes (TOCTOU, pétreo grader, pre-commit canary, keepalive reap, honest attribution) against the pétreo floor — read committed code + run tests, hunt for holes/regressions',
  phases: [
    { title: 'Audit', detail: '5 adversarial auditors, one per fix, read committed code + run the frozen tests' },
    { title: 'Verdict', detail: 'synthesize GO / NO_GO over confirmed findings only' },
  ],
}

const CTX = `
ATLAS LOOP — 5 floor fixes committed (atlas-server, commits d941dd52 + e940f31d). Read the REAL files (HEAD) and the tests; CONFIRM or REFUTE each. A working tree may be dirty from a PARALLEL session editing AtlasEvolutionFrozenJudge.php — that is NOT part of these fixes; if a file looks unexpectedly changed, verify against the committed version (git show HEAD:<file>).

F1 — L5-9 TOCTOU now ENFORCED. app/Services/Ai/AutonomousEvolution/AtlasLoopAutoMergeService.php: mergeOne() gained a 6th param ?string $authorizedCanonical; at the top of its try{} it calls $this->repoAuthority->stillResolvesTo($repoRoot,$authorizedCanonical) and returns 'canonical_path_changed_toctou' (no git op) when the repo identity changed. drain()/mergeOperatorApproved() pass the captured canonical. VERIFY: the abort runs BEFORE any git op; null/'' authorizedCanonical = inert (legacy safe); the existing happy-path merge still works (drain passes the correct canonical).

F2 — pétreo grader. app/Services/Ai/AutonomousEvolution/AtlasLoopHarnessGuard.php FORBIDDEN_SELF_TARGETS now includes AtlasLoopUtilityGradeService.php + Discovery/AtlasLoopWiredCallerService.php; tests/Feature/Loop/AtlasLoopHarnessGuardTest.php ratchets both. VERIFY: isForbiddenSelfTarget returns true for both; the ratchet test asserts them; nothing legitimately needs the loop to target them.

F3 — pre-commit canary gate. AtlasLoopAutoMergeService.php: a flag-gated (atlas.ai.loop.precommit_canary_gate, default true) canary runs BEFORE the commit; RED => git checkout -- (revert apply, main untouched) + retire (reviewed_at) + enqueueFixForward + return 'canary_red_precommit_gate'; GREEN/no-sibling => commit. Flag OFF => pure v2 (post-commit, never reverts). VERIFY: a RED canary never commits to main; the apply is reverted; fix-forward still enqueued; GREEN still merges; flag-OFF path preserved; the proposal is retired so it can't re-drain+re-fail forever.

F4 — keepalive unbounded + reap. app/Console/Commands/AtlasLoopKeepaliveCommand.php: death-respawn query now matches (elapsed<max_seconds OR max_seconds<=0) so unbounded soaks are eligible; a recency-bounded reaper (atlas.loop.keepalive_reap_after_minutes, 1440) marks ancient orphan running rows completed (stop_reason reaped_orphan_no_process) instead of respawning. VERIFY: a recent dead UNBOUNDED soak respawns; an ancient orphan is reaped not respawned; a recent dead BOUNDED campaign still respawns (no regression); the reaper can't reap a recently-active soak.

F5 — honest attribution. app/Services/Ai/AutonomousEvolution/AtlasLoopUtilityGradeService.php: gitDiffStat returns per_file line counts; primaryTarget(changed, stored, perFile) picks the DOMINANT real .php by changed lines (not diff-order first); perFileLinesFromDiff fallback. VERIFY: a bundle commit is attributed to the file with the most changed lines, not a 4-line incidental; generated/test files excluded; ties stable; callerCounts byte-identical (the F-edit only added callerPaths in WiredCallerService — confirm callerCounts unchanged).

PÉTREO FLOOR (a violation = NO_GO): never-merge default unchanged; no merge without the safety gates; no fabricated evidence; HarnessGuard forbidden set only GROWN never shrunk; default-safe flags; fail-open/closed as appropriate. The fixes must CLOSE their finding without weakening any other floor.

Run the frozen tests yourself: php -d memory_limit=2048M artisan test tests/Feature/Loop/AtlasLoopAutoMergeServiceTest.php tests/Feature/Loop/AtlasLoopHarnessGuardTest.php tests/Feature/Loop/AtlasLoopUtilityGradeAttributionTest.php tests/Feature/Loop/AtlasLoopKeepaliveReapTest.php tests/Feature/Loop/AtlasLoopWiredTargetingTest.php
`

phase('Audit')
const AUDIT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['fix','status','evidence','floor_safe','holes'],
  properties: {
    fix: { type: 'string' },
    status: { type: 'string', enum: ['confirmed_fixed','incomplete','regressed'] },
    evidence: { type: 'array', items: { type: 'string' }, description: 'file:line citations + test outcomes proving the verdict' },
    floor_safe: { type: 'boolean' },
    holes: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity','description','fix'], properties: {
      severity: { type: 'string', enum: ['critical','high','medium','low'] },
      description: { type: 'string' }, fix: { type: 'string' } } } },
  },
}
const FIXES = [
  { key: 'F1_toctou', p: 'Audit F1 (TOCTOU enforce). Confirm the abort runs before ANY git op, uses the real stillResolvesTo, is inert for null/legacy, and the happy-path merge still works. Try to find a path where a changed canonical still merges, or where a legit merge is wrongly aborted.' },
  { key: 'F2_petreo_grader', p: 'Audit F2 (pétreo grader). Confirm both files are in FORBIDDEN_SELF_TARGETS + ratcheted, and that making them forbidden does not break any legitimate flow (e.g. discovery still works). Confirm the set only GREW.' },
  { key: 'F3_precommit_canary', p: 'Audit F3 (pre-commit canary). This is the highest-stakes change to the merge path. Confirm a RED canary NEVER commits to main + reverts the apply + retires + fix-forwards; GREEN still merges; flag-OFF restores legacy; never-merge intact. Try to find a way a behavioral regression still reaches main, or a way the gate deadlocks the drain.' },
  { key: 'F4_keepalive', p: 'Audit F4 (keepalive). Confirm unbounded soaks respawn, ancient orphans reap (not respawn), bounded still respawns, and the reaper cannot kill a recently-active soak. Try to find a way the reaper reaps a live/healthy campaign or a way an unbounded zombie still resurrects.' },
  { key: 'F5_attribution', p: 'Audit F5 (attribution). Confirm dominant-by-lines selection, generated/test exclusion, stable ties, and callerCounts byte-identity. Try to find a way the wrong file is still credited, or a regression in callerCounts.' },
]
const audits = await parallel(FIXES.map(f => () =>
  agent(`${CTX}\n\nAUDIT TARGET: ${f.key}. ${f.p}\n\nRead the committed code + run the relevant frozen test. status=confirmed_fixed only with file:line + green-test evidence; incomplete/regressed otherwise. floor_safe=false if the fix weakens ANY floor. List concrete holes with severity+fix.`,
    { label: `audit:${f.key}`, phase: 'Audit', schema: AUDIT_SCHEMA, agentType: 'Explore' })
))
const valid = audits.filter(Boolean)

phase('Verdict')
const VERDICT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['verdict','per_fix','floor_intact','confirmed_holes','summary'],
  properties: {
    verdict: { type: 'string', enum: ['GO','GO_WITH_FIXES','NO_GO'] },
    floor_intact: { type: 'boolean' },
    per_fix: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['fix','status'], properties: { fix: { type: 'string' }, status: { type: 'string' } } } },
    confirmed_holes: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['fix','severity','description','fix_needed'], properties: {
      fix: { type: 'string' }, severity: { type: 'string', enum: ['critical','high','medium','low'] }, description: { type: 'string' }, fix_needed: { type: 'string' } } } },
    summary: { type: 'string' },
  },
}
const verdict = await agent(
  `${CTX}\n\n5 adversarial audits (JSON):\n${JSON.stringify(valid)}\n\nSynthesize ONE verdict over CONFIRMED holes only (discard duplicates + status-field mislabels — trust the prose+evidence). NO_GO if any confirmed critical/high floor violation; GO_WITH_FIXES if only medium/low or non-floor concerns; GO if clean. Report per-fix status, floor_intact, confirmed holes.`,
  { label: 'verdict', phase: 'Verdict', schema: VERDICT_SCHEMA })
return verdict
