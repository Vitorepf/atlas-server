export const meta = {
  name: 'loop-grade9-verify-regrade',
  description: 'Adversarially verify the 2 honest slices (merge-quality re-measure + decision router) are floor-safe + ungameable, AND honestly re-score the 3 dimensions on the committed mechanisms',
  phases: [
    { title: 'Audit', detail: '2 slice auditors + 3 dimension graders' },
    { title: 'Verdict', detail: 'synthesize honest per-dimension scores + GO/NO_GO on the slices' },
  ],
}

const CTX = [
  'ATLAS LOOP — verify 2 honest slices + re-score 3 panel dimensions on the COMMITTED code (commits 34f9c11d merge-quality, a236d482+fdeacdf0 decision). Read the real files + run the frozen tests. The grade is ungameable by design; the grader (AtlasLoopUtilityGradeService) + AtlasLoopWiredCallerService are PETREO (FORBIDDEN_SELF_TARGETS). A slice that inflates the NUMBER without real capability = FAIL.',
  '',
  'SLICE A (MERGE-QUALITY): AtlasLoopUtilityGradeService now credits NON_TRIVIAL for a refactor objective ONLY via reMeasuredComplexityDrop(commit,target) — it runs git show <commit>^:<path> vs git show <commit>:<path>, parses both with AtlasLoopSignalAnalyzer::fileComplexity, returns true iff candidate_max < baseline_max AND candidate_total <= baseline_total. For a refactor objective the re-measure is AUTHORITATIVE (the bug/edge_case/perf keyword path is NOT accepted — the OR-bypass the design adversary flagged is closed; see the score loop). Fail-closed (null => not credited). Tests: tests/Feature/Loop/AtlasLoopUtilityGradeRefactorNonTrivialTest.php (by-construction grade>=9 on a proven-refactor window + negative control that a non-reducing refactor with null/fallback keywords scores 0 + non-refactor correctness still counts) + AtlasLoopUtilityGradeAttributionTest.php (unchanged, must stay green).',
  '',
  'SLICE B (DECISION): AtlasLoopWorkShapeRouter (pure, provider-free) reasons work-shape (work_skip, single_file_refactor, edge_fix) from stamped signals; wired into AtlasLoopQueueRefiller (10th optional ctor param + bind) flag-gated default-ON, fail-open to the cascade; load-bearing decision = work_skip defers a CONFIRMED orphan. Tests: tests/Feature/Loop/AtlasLoopWorkShapeRouterTest.php (8 tests incl. orphan-deferred integration, unmeasured-callers fail-open, determinism).',
  '',
  'HONEST CEILING (the design+adversary established, verify it): MERGE-QUALITY >=9 honestly by construction (capability+measurement shipped; LIVE number fills as the 50-merge window turns over to proven refactors — soak). DECISION capability ships this session (router); LIVE number soak-realized. HEAVY-WORK CANNOT honestly hit 9 this session or by soak (ceiling ~5-6): single-file behavior-preserving refactor is LIVE+proven and now COUNTED, but true multi-file autonomous code change is DETECT+PROPOSE-only and Forge-class, and 9 there = autonomously merging multi-file obras to main = the OPERATOR-DECISION/never-merge frontier the floor forbids crossing autonomously.',
  '',
  'Run: php -d memory_limit=2048M artisan test tests/Feature/Loop/AtlasLoopUtilityGradeRefactorNonTrivialTest.php tests/Feature/Loop/AtlasLoopUtilityGradeAttributionTest.php tests/Feature/Loop/AtlasLoopWorkShapeRouterTest.php',
].join('\n')

phase('Audit')
const AUDIT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['kind','status','evidence','floor_safe','holes'],
  properties: {
    kind: { type: 'string' },
    status: { type: 'string', enum: ['confirmed_honest','gaming','incomplete','regressed'] },
    evidence: { type: 'array', items: { type: 'string' } },
    floor_safe: { type: 'boolean' },
    holes: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['severity','description','fix'], properties: { severity: { type: 'string', enum: ['critical','high','medium','low'] }, description: { type: 'string' }, fix: { type: 'string' } } } },
  },
}
const GRADE_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['dimension','score_now','ceiling_this_session','justification','soak_or_multiweek_note'],
  properties: {
    dimension: { type: 'string' },
    score_now: { type: 'number' },
    ceiling_this_session: { type: 'number' },
    justification: { type: 'string' },
    soak_or_multiweek_note: { type: 'string' },
  },
}
const tasks = [
  () => agent(CTX + '\n\nAUDIT SLICE A (merge-quality re-measure). Verify HONEST + ungameable + floor-safe: re-measure from git parent-vs-commit, AUTHORITATIVE for refactor objectives (keyword OR-bypass closed), fail-closed on null, grader stays petreo, no flag silences it. Try to game it (fake a drop, launder via keyword). Run the 2 tests; report pass counts.', { label: 'audit:slice-A', phase: 'Audit', schema: AUDIT_SCHEMA, agentType: 'Explore' }),
  () => agent(CTX + '\n\nAUDIT SLICE B (decision router). Verify pure leverage function, fail-open to edge_fix, never bypasses a RED-gate, work_skip only on a measured orphan, and the 10th-param wiring did not clobber the framework synthesizer / obra detector. Run the router test; report pass counts.', { label: 'audit:slice-B', phase: 'Audit', schema: AUDIT_SCHEMA, agentType: 'Explore' }),
  () => agent(CTX + '\n\nRE-SCORE MERGE-QUALITY (was 5.8) on the committed mechanism. The honest non_trivial re-measure lifts the cap; the by-construction test proves grade>=9 on a proven-refactor window. Score the CAPABILITY honestly; be clear about soak-fill of the LIVE number.', { label: 'grade:merge', phase: 'Audit', schema: GRADE_SCHEMA, agentType: 'Explore' }),
  () => agent(CTX + '\n\nRE-SCORE DECISION (was 6.2) on the committed router: reasons work-shape from leverage + defers dead code + auditable + tested. Score the CAPABILITY honestly; note soak-realization of the merge-mix.', { label: 'grade:decision', phase: 'Audit', schema: GRADE_SCHEMA, agentType: 'Explore' }),
  () => agent(CTX + '\n\nRE-SCORE HEAVY-WORK CAPABILITY (was 4.2). Single-file proven refactor is LIVE and now counted; multi-file is detect+propose-only (Forge-class, operator-gated). Score HONESTLY — do NOT inflate to 9. State plainly whether 9 is reachable this session and what it would require.', { label: 'grade:heavy', phase: 'Audit', schema: GRADE_SCHEMA, agentType: 'Explore' }),
]
const out = await parallel(tasks)
const audits = out.slice(0, 2).filter(Boolean)
const grades = out.slice(2).filter(Boolean)

phase('Verdict')
const VERDICT_SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['slices_verdict','floor_intact','dimension_scores','dimensions_at_or_above_9','honest_summary','remaining_for_heavy_work_9'],
  properties: {
    slices_verdict: { type: 'string', enum: ['GO','GO_WITH_FIXES','NO_GO'] },
    floor_intact: { type: 'boolean' },
    dimension_scores: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['dimension','score','at_least_9'], properties: { dimension: { type: 'string' }, score: { type: 'number' }, at_least_9: { type: 'boolean' } } } },
    dimensions_at_or_above_9: { type: 'array', items: { type: 'string' } },
    honest_summary: { type: 'string' },
    remaining_for_heavy_work_9: { type: 'string' },
  },
}
const verdict = await agent(
  CTX + '\n\nSlice audits (JSON):\n' + JSON.stringify(audits) + '\n\nDimension re-scores (JSON):\n' + JSON.stringify(grades) + '\n\nSynthesize: are the 2 slices honest + floor-safe (GO/NO_GO)? Honest per-dimension scores. Which dimensions are at-or-above 9 (capability) HONESTLY. A brutally honest summary. And exactly what remains for HEAVY-WORK to reach 9. Do NOT round heavy-work up to 9 if it is not honestly there.',
  { label: 'verdict', phase: 'Verdict', schema: VERDICT_SCHEMA })
return verdict
