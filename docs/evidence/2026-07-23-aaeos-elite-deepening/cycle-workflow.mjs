// Per-cycle hardening of MASTER-CLAUDE. Edit CYCLE + PLAN_V + LENSES, re-invoke.
export const meta = {
  name: 'aaeos-master-claude-harden',
  description: 'Adversarial hardening cycle for Claude\'s independent MASTER-CLAUDE toward god-SOTA: ≥13 lens-distinct critics + red-team, distrust it as hard as Codex, verify owners, reuse/delete/fuse, never duplicate.',
  phases: [ { title: 'Critics' }, { title: 'Adversary' } ],
}

// ==== EDIT PER CYCLE ====
const CYCLE = 'C6'
const PLAN_V = 'MASTER-CLAUDE C5'
const LENSES = [
  { id: 'BREAK-FABRICATE', lens: 'Break it: fabricate a fake REAL_OPERATION past the 5-leg subtract-one', focus: 'Maximal red-team. C5 now has a 5-leg subtract-one (effect receipt, landed SHA, write-set hash, canonical event, environment attestation) + re-derive-from-world + independence-mint. Find ANY residual fabrication path (correlated legs, a leg the fresh process still reads from the ledger, an environment attestation that is itself forgeable). If none survives, say CONVERGED honestly.' },
  { id: 'BREAK-AUTHORITY', lens: 'Break it: land an unauthorized effect', focus: 'Try to push a mutative/irreversible effect to land without proper authority: exploit the reload-before-act window, the LAND/SETTLE nonce split, the lease/fencing, the AWIS gate, or independence-at-mint. Find the strongest remaining bypass or confirm the chain is closed (CONVERGED).' },
  { id: 'BREAK-ONESHOT-M', lens: 'Break it: game OneShot or inflate M', focus: 'Try to earn operator_experience=oneshot dishonestly or inflate enforced_governed_coverage/refuse-repair. Attack the ProductIntentClarificationContract discriminator, capture_coverage==1.0, and the COVERED-only M denominator. Find a residual game or confirm closed.' },
  { id: 'HIDDEN-ASSUMPTION', lens: 'What unstated assumption does C5 rely on?', focus: 'Surface the load-bearing UNSTATED assumptions: that the ledger DB is trusted, that git history is not rewritten, that the operator machine is not compromised, that owners are not concurrently refactored mid-execution. Which assumption, if false, breaks a god-SOTA claim? Propose the minimal explicit statement (reuse), or confirm assumptions are acceptable for a solo-operator local-first reality.' },
  { id: 'IMPLEMENTER-CONFUSION', lens: 'Give C5 to a junior implementer — where do they go wrong?', focus: 'Simulate a competent-but-junior implementer executing P0-P4 from C5. Where is the prose ambiguous enough to build the wrong thing? Which done-condition could be gamed by a well-meaning implementer? Propose the one clarifying sentence per genuine ambiguity (no bloat).' },
  { id: 'CODEX-FINAL-DIFF', lens: 'Final head-to-head vs Codex latest', focus: 'Read Codex MASTER now. Final honest diff: any axis where Codex is still stronger and C5 should import lean; anything C5 has that Codex lacks (for the operator fuse decision). Give a crisp verdict: which is the better base, and what to graft from the loser.' },
  { id: 'LAW-EDGE', lens: 'Edge cases in the constitutional laws', focus: 'Attack A1.1-A1.9 + A2 + A3 at the edges: Law 4 (assurance tax) vs same-floor under a genuinely novel failure surface; Law 9 (local-first) vs a legitimately-needed external call; OneShot vs a genuine mid-journey intent change. Find a law that produces a wrong verdict at an edge; propose the minimal clarification.' },
  { id: 'HORIZON-CORRECTNESS', lens: 'Are the horizon deferrals actually correct?', focus: 'Audit every Horizon deferral (SUSTAINED, topology ablation, comparative-M, multi-domain, multi-operator, memory-M, P2-HORIZON crash-at-scale). Is anything deferred that is actually REQUIRED for a safe P4 DONE (i.e. a safety-critical thing hiding in horizon)? Or is anything in-scope that should be horizon? Propose the reclass or confirm correct.' },
  { id: 'LEAN-FINAL', lens: 'Any last bloat to cut?', focus: 'C5 is ~208 lines. Is any paragraph (B2/B3/E2 are long) carrying restated or redundant content after 5 cycles? Propose FUSE/DELETE without dropping a done-condition. The bet is a small constitution — protect it.' },
  { id: 'MEMORY-M-CLOSE', lens: 'Close the memory-M loop concretely (deferred from C5)', focus: 'C5 left the memory-M loop as a dangling H4 reference. Name the concrete reuse-only loop: AtlasEvidenceLedger events → AtlasHeldEvidenceMinerService → AtlasMemoryLearningPromotionService (H4 propose-only) → AtlasOpenBrainContextPackService (read-side, live). State it as a horizon note with the current break, or confirm out-of-scope. No new store.' },
  { id: 'NEW-TIER-LAST', lens: 'The truly last god-SOTA leap', focus: 'Final search: is there ONE reuse/fuse that raises the whole plan a level and has not been made? Candidate: a self-verifying certify that runs the 5-leg subtract-one on its OWN certification evidence (dogfooding the proof). Propose it reusing owners, or declare CONVERGED honestly.' },
  { id: 'CONVERGENCE-C6', lens: 'Honest ceiling verdict + god-SOTA score', focus: 'After 5 cycles, is C5 at the god-SOTA ceiling? Honest verdict: CONVERGED (reasons) or the single highest-value remaining move. Never manufacture. Give an overall 0-10 god-SOTA score with axis breakdown (constitutive clarity, reuse-discipline, fail-closed authority, anti-Goodhart, implementability, efficiency, honesty).' },
]
// ==== END EDIT ====

const REPO = '/Users/vitorepf/develop/Atlas/atlas-server'
const MINE = `${REPO}/docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER-CLAUDE.md`
const CODEX = `${REPO}/docs/superpowers/plans/2026-07-23-aaeos-elite-deepening-MASTER.md`

const GROUND = `
Cycle ${CYCLE}: harden Claude's own independent plan (${PLAN_V}) toward god-SOTA. Distrust it AS HARD as Codex. The operator compares/fuses the two tracks.
Elevate ATLAS AGENTIC OS (the mother block of all Atlas agentic software engineering) to the most absurd quality+efficiency possible.

READ: MINE=${MINE}; CODEX=${CODEX} (competitor, for steal/diff); ${REPO}/docs/engineering-knowledge-base/atlas-elite-executors-dev-forge-autonomos.md, atlas-terminal-first-focus.md; disk under app/Services/Ai/ (verify every owner).

C3 ALREADY CONTAINS (do not re-propose): constitution-first mother-block-of-LAW (A0 binding seam at effect boundaries, promoted to an E2 acceptance leg); OneShot server-derived + ProductIntentClarificationContract discriminator + capture_coverage==1.0; M falsifiable via enforced_governed_coverage=COVERED/total (NOT the ledger governed field which folds advisory consulted at line 145) + refuse/repair rate; owner map (all verified); effect-authority 5-step + LAND nonce (AuthorizedMergeAction::nonce) + SETTLE nonce (CanarySettlementRequest::idempotencyHash) + event_id-PK winner; proof taxonomy illegal-pairs-unrepresentable + subtract-one/mutation invalidator (owner QualityFoundryMutationCoverageRunner) + re-derive-from-world REAL_OP + environment attestation; 10 theme residuals T1-T10 (no-fuse guard) incl Spine applicability census (evidenceSlots), amplification-neutrality, post-long-provider mandate re-check, in-process seed (NativeReplenisherEnqueueRunner, no --max/--specs), AaeosAdmissionVerdict::REPAIR_REQUIRED, evidence readback = EvidenceLedgerHashChainIntegrityVerifier+AtlasLedgerReplayService, gut AaeosScorecardProjector god_sota self-grade before views, runtime_write_performed derived from dispatch effects+changedFiles; imported Codex R95/R100 sovereign witnesses; P4 preflight (AutonomosPreflightService) + blocked_ops; E1 path-manifest fence + Named-RED per gate. C4 ADDED: independence attestation at the release mint (SpecSourceIndependence/SovereignSpecFloor; same-family author+judge degrades to mechanical EngineeringQualityCourt); REPAIR_REQUIRED propagated to AaeosCycleOutcomeRecorder:33-34 + AtlasAaeosCertifyCommand:43 via allowsExecution(); full seed-enqueue seam (AgentControlPlaneTaskQueueOrchestrator::prepareAndEnqueue + AtlasSelfConstructionQueueTopUpPolicy); P4 readback veto = hand-built subtract-one over world-state legs only (Infection/QualityFoundryMutationCoverageRunner is a SEPARATE PHPUnit gate); 3 runtime_write sites (CycleRuntime:93, OrgStateProjector:46, ScorecardProjector); owned B2 pre-authorize ceiling (MergeGovernor/RiskClassifier + ProviderGovernanceConsult); memory/recovery demoted from falsifiable-M to horizon; P2-MVP vs P2-HORIZON split; per-mode P4 producer commands (AtlasDevSeniorLoopRunCommand/AtlasForgeLiveExecuteCommand/AtlasSelfConstructionRuntimeDaemonCommand); failure-surfacing scorecard --view=liveness; owner-census architecture test.

VERIFIED DISK FACTS: Quarantine absent; runtime_write_performed hardcoded AaeosCycleRuntime:93; assertShared(mode,[]) self-greens + evidenceSlots/deliveryArtifacts:94-119 unenforced; R33 brainNextArgs --scope vs positional {scope}; AaeosAdmissionPolicy:36 invalid_mode->HALT_SOVEREIGN, :34 DECISION_REPAIR; ExecutionOrder 28-field v2; AtlasTaskMergeActuator::changedFiles:883 + prepareRevert:125; CanarySettlementRequest idempotencyHash+landedSha+observerIdentity; ProviderGovernanceCoverageLedger covered/bypass schema.v1; ProductIntentClarificationContract; AutonomosPreflightService; AtlasSelfConstructionRuntimeDaemonCommand; scheduler heartbeat stale ~10d.

RULES: anti-duplication is rule #1 — grep before proposing; existing capability → REUSE_WIRE/DELETE/FUSE only, name the owner; re-implementing existing = REJECT_BLOAT (self-reject). Keep C2 LEAN (its bet is a small constitution). Ground every attack LIVE_CODE|DOC_ONLY|MISSING|EXTERNAL. If your lens finds C2 already ceiling: KEEP + is_ceiling=true (valid CONVERGENCE).
`

const SCHEMA = {
  type: 'object', additionalProperties: false,
  required: ['agent_id','lens','verdict','score','one_liner','attacks','proposals'],
  properties: {
    agent_id: { type: 'string' }, lens: { type: 'string' },
    verdict: { type: 'string', enum: ['KEEP','AMEND','REPLACE'] }, score: { type: 'number' }, is_ceiling: { type: 'boolean' },
    attacks: { type: 'array', items: { type: 'object', additionalProperties: false, required: ['claim','severity','grounding'], properties: {
      claim: { type: 'string' }, severity: { type: 'string', enum: ['S0','S1','S2','S3'] },
      grounding: { type: 'string', enum: ['LIVE_CODE','DOC_ONLY','MISSING','EXTERNAL'] }, evidence: { type: 'string' } } } },
    proposals: { type: 'array', items: { type: 'object', additionalProperties: false,
      required: ['id','kind','change','reuses_or_deletes','master_section','done_condition','is_duplication_of'], properties: {
      id: { type: 'string' }, kind: { type: 'string', enum: ['REUSE_WIRE','DELETE','FUSE','NEW_TIER','SHARPEN','REJECT_BLOAT','IMPORT_FROM_CODEX'] },
      change: { type: 'string' }, reuses_or_deletes: { type: 'string' },
      master_section: { type: 'string' }, done_condition: { type: 'string' }, is_duplication_of: { type: 'string' } } } },
    one_liner: { type: 'string' },
  },
}

phase('Critics')
const verdicts = (await parallel(LENSES.map(c => () =>
  agent(`${GROUND}\n\nYOUR LENS = ${c.id} — ${c.lens}\nMANDATE:\n${c.focus}\n\n`
    + `Read MINE + (where needed) CODEX + disk. Attack ${PLAN_V} (claim+severity+grounding+evidence). Propose 1-3 lean fixes; each names section + reused owner + falsifiable done-condition + is_duplication_of. Verdict + is_ceiling + 0-10. agent_id="${c.id}".`,
    { label: `${CYCLE}:${c.id}`, phase: 'Critics', schema: SCHEMA, effort: 'high' }
  ).then(v => v ? { ...v, agent_id: c.id, lens: c.lens } : null)
))).filter(Boolean)

phase('Adversary')
const digest = verdicts.map(v => `${v.agent_id}[${v.verdict} ${v.score} ceiling=${v.is_ceiling}] ${v.one_liner}\n  ${(v.proposals||[]).map(p=>`${p.id}:${p.kind}:${p.change.slice(0,90)}`).join(' | ')||'none'}`).join('\n')
const adversary = await agent(
  `${GROUND}\n\nRED-TEAM + anti-dup judge over the ${CYCLE} panel:\n\n${digest}\n\n(a) rule each proposal keep/reject-as-dup (grep vs existing owner or C2 text); (b) name the SINGLE highest-value surviving move; (c) honest verdict: is ${PLAN_V} at god-SOTA ceiling, or what's the gap. Ground everything. agent_id="ADV".`,
  { label: `${CYCLE}:ADV`, phase: 'Adversary', schema: SCHEMA, effort: 'high' }
).then(v => v ? { ...v, agent_id: 'ADV', lens: 'redteam' } : null)

return { cycle: CYCLE, planVersion: PLAN_V, verdicts, adversary }
