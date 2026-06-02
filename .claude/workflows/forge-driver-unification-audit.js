/**
 * Forge driver unification safety audit (reusable).
 *
 * PURPOSE
 * Atlas has two Forge provider-execution shapes:
 *   1. the canonical AiProviderManager pipe — used additively by the hermes_cli
 *      Forge driver (AtlasForgeHermesCliInvocationDriver), a thin adapter over
 *      HermesCliProvider, because hermes is the executive META-runtime with no
 *      governed Forge CLI shape of its own;
 *   2. the purpose-built Forge CLI drivers (codex/cursor/gemini via
 *      AtlasForgeBaseCliInvocationDriver, minimax via its executor) — which carry
 *      Forge-specific GOVERNANCE the AiProvider path does NOT replicate:
 *      read-only sandbox default, AtlasForgeProviderCommandAllowlistService,
 *      the SEC-003 launder-proof before/after porcelain diff, and the
 *      AtlasForgeProviderInvocationFailureClassifier.
 *
 * This workflow re-runs the per-provider safety analysis BEFORE collapsing any
 * Forge driver onto the canonical pipe. Run it whenever you add a provider or are
 * tempted to "deduplicate" the Forge drivers. Read-only — it produces verdicts,
 * it does not edit code.
 *
 * PROVEN VERDICT (2026-06-02, run wf_6ba6e01b-b73, 6 agents, adversarial critique,
 * source-cited): safeToUnify = [] — codex/gemini/minimax MUST stay separate.
 *   - codex   = PRIVILEGE ESCALATION (Forge path is --sandbox read-only today; a
 *               hermes-style mode:danger adapter flips it to
 *               --dangerously-bypass-approvals-and-sandbox autonomous editing).
 *   - gemini  = MODEL-AUTHORITY OVERRIDE (GeminiCliProvider re-resolves the model
 *               via GeminiModelCatalog, ignoring the Atlas-Decide dispatch model;
 *               auto-upgrades Flash->Pro on keywords; fails closed on non-catalog).
 *   - minimax = RESULT-FIDELITY LOSS (Forge binds the executor's rich invoke();
 *               AiProviderManager->run() uses the narrow execute(), dropping
 *               changed_files/classification/failure_type/performance_signal).
 * The ONLY safe delegation (hermes) is already done. The "duplicate pipes" are
 * purpose-built governance boundaries, not harmful duplication.
 *
 * USAGE
 *   Workflow({ name: 'forge-driver-unification-audit' })
 *   Workflow({ name: 'forge-driver-unification-audit',
 *              args: [{ provider: 'newprovider_cli',
 *                       driver: 'app/Services/Ai/Programming/AtlasForgeNewproviderCliInvocationDriver.php',
 *                       aiProvider: 'app/Services/Ai/NewproviderCliProvider.php' }] })
 */
export const meta = {
  name: 'forge-driver-unification-audit',
  description: 'Read-only safety audit: can a Forge CLI driver be collapsed onto the canonical AiProviderManager pipe without changing behavior or weakening Forge governance? Produces delegate/keep-separate verdicts.',
  whenToUse: 'Before deduplicating any Forge provider driver, or when onboarding a new provider. Re-validates the proven keep-separate verdict for codex/gemini/minimax.',
  phases: [{ title: 'Analyze' }, { title: 'Critique' }],
}

const DEFAULT_CANDIDATES = [
  { provider: 'codex_cli', driver: 'app/Services/Ai/Programming/AtlasForgeCodexCliInvocationDriver.php', aiProvider: 'app/Services/Ai/CodexCliProvider.php' },
  { provider: 'gemini_cli', driver: 'app/Services/Ai/Programming/AtlasForgeGeminiCliInvocationDriver.php', aiProvider: 'app/Services/Ai/GeminiCliProvider.php' },
  { provider: 'minimax_m27_cli', driver: 'app/Services/Ai/Programming/AtlasForgeMinimaxM27CliInvocationDriver.php', aiProvider: 'app/Services/Ai/MinimaxM27CliProvider.php' },
]

const CANDIDATES = Array.isArray(args) && args.length > 0 ? args : DEFAULT_CANDIDATES

const ANALYSIS_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['provider', 'forgeDriverMechanism', 'aiProviderMechanism', 'behaviorEquivalent', 'keyDifferences', 'recommendation', 'rationale', 'risks'],
  properties: {
    provider: { type: 'string' },
    forgeDriverMechanism: { type: 'string', description: 'how the Forge driver spawns/invokes the CLI today' },
    aiProviderMechanism: { type: 'string', description: 'how the AiProvider invokes the CLI' },
    behaviorEquivalent: { type: 'boolean' },
    keyDifferences: { type: 'array', items: { type: 'string' } },
    recommendation: { type: 'string', enum: ['delegate', 'keep-separate'] },
    rationale: { type: 'string' },
    risks: { type: 'array', items: { type: 'string' } },
  },
}

const CRITIQUE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['provider', 'delegationSafe', 'refutations', 'verdict'],
  properties: {
    provider: { type: 'string' },
    delegationSafe: { type: 'boolean', description: 'true ONLY if delegation is provably behavior-preserving after trying to refute it' },
    refutations: { type: 'array', items: { type: 'string' } },
    verdict: { type: 'string' },
  },
}

const results = await pipeline(
  CANDIDATES,
  (c) => agent(
    `Analyze whether the Atlas Forge driver for provider '${c.provider}' can be safely refactored to DELEGATE to the canonical AiProviderManager (like the reference adapter app/Services/Ai/Programming/AtlasForgeHermesCliInvocationDriver.php — it calls AiProviderManager->get(provider)->run(job) and maps AiProviderResult into atlas.forge.provider_driver_result.v1) INSTEAD of spawning the CLI via AtlasForgeBaseCliInvocationDriver / AtlasForgeProviderProcessRunner.

Read IN FULL: ${c.driver}, ${c.aiProvider}, the reference adapter app/Services/Ai/Programming/AtlasForgeHermesCliInvocationDriver.php, app/Services/Ai/Programming/AtlasForgeBaseCliInvocationDriver.php, and grep app/Services/Ai/Programming/AtlasForgeProviderInvocationService.php for what the driver receives.

Determine PRECISELY (cite line behavior): does the AiProvider invoke the same binary with comparable flags and the SAME workspace-mutation/sandbox semantics? Would delegating change the Forge result (atlas.forge.provider_driver_result.v1, changed_files capture, exit_code/classification, scope/verification gating, command allowlist, auth/credential resolution, model authority)? Recommend 'delegate' ONLY if behavior is genuinely preserved AND no Forge governance is lost.`,
    { label: `analyze:${c.provider}`, phase: 'Analyze', schema: ANALYSIS_SCHEMA }
  ),
  (analysis, c) => agent(
    `Adversarially CRITIQUE the recommendation to '${analysis.recommendation}' for the Forge driver of '${c.provider}'.

Analysis to refute: ${JSON.stringify(analysis)}

REFUTE delegation safety. Read the same files yourself. Hunt for ANY concrete behavior-changing difference or LOST Forge governance: privilege/sandbox escalation, command allowlist bypass, SEC-003 launder-proof diff loss, failure-classifier loss, model-authority override, cwd-fallback divergence, stdout/hash/fingerprint change, credential-scope change, router/registration blast radius. Default to delegationSafe=false on ANY unresolved behavior-changing difference or governance loss. delegationSafe=true ONLY if delegation is provably behavior-preserving AND governance-preserving.`,
    { label: `critique:${c.provider}`, phase: 'Critique', schema: CRITIQUE_SCHEMA }
  ).then((critique) => ({
    provider: c.provider,
    recommendation: analysis.recommendation,
    behaviorEquivalent: analysis.behaviorEquivalent,
    keyDifferences: analysis.keyDifferences,
    risks: analysis.risks,
    delegationSafe: critique.delegationSafe,
    refutations: critique.refutations,
    verdict: critique.verdict,
    safeToUnify: analysis.recommendation === 'delegate' && critique.delegationSafe === true,
  }))
)

const safe = results.filter((r) => r && r.safeToUnify).map((r) => r.provider)
const keep = results.filter((r) => r && !r.safeToUnify).map((r) => ({ provider: r.provider, why: r.refutations?.[0] || r.verdict }))
log(`safeToUnify: [${safe.join(', ')}] | keep-separate: ${keep.length}`)

return { results, safeToUnify: safe, keepSeparate: keep }
