/**
 * Atlas Evolution Loop — independent adversarial proposal verification (rigorous).
 *
 * The loop's in-process FROZEN JUDGE already rejects scope/tamper/red candidates.
 * This workflow is the SECOND, out-of-process guard (the design's Goodhart-guard
 * #4 — independent re-proof): for each certified-for-review proposal it spins up an
 * independent verifier that re-applies the diff into a clean checkout, re-runs the
 * frozen acceptance ITSELF, and then an adversary that actively tries to REFUTE
 * that the change honestly satisfies the objective (gaming, weakened assertions,
 * scope creep, hidden side effects). A proposal is CONFIRMED only if it survives
 * both. Run it over a loop run's proposals before trusting any of them.
 *
 * USAGE
 *   Workflow({ name: 'loop-proposal-adversarial-verify',
 *              args: { repo: '/abs/path/to/base/repo',
 *                      proposals: [ { objective, diff_text, acceptance: {commands, allowed_globs, frozen_globs} } ] } })
 *   (The proposals array is exactly what `atlas:loop:evolve --json` emits under .proposals,
 *    each enriched with the task's acceptance contract.)
 */
export const meta = {
  name: 'loop-proposal-adversarial-verify',
  description: 'Independently re-prove + adversarially refute each Atlas Evolution Loop proposal (clean-checkout re-apply, re-run frozen acceptance, hunt for gaming). Confirms only what survives both.',
  whenToUse: 'After an atlas:loop:evolve run, before trusting/merging any certified-for-review proposal. The out-of-process Goodhart guard.',
  phases: [{ title: 'Re-prove' }, { title: 'Refute' }],
}

const proposals = Array.isArray(args?.proposals) ? args.proposals : []
const repo = typeof args?.repo === 'string' ? args.repo : ''

if (proposals.length === 0) {
  log('No proposals passed in args.proposals — nothing to verify.')
  return { confirmed: [], rejected: [], note: 'empty_input' }
}

const REPROVE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['index', 'applies_cleanly', 'acceptance_passes', 'changed_paths', 'notes'],
  properties: {
    index: { type: 'number' },
    applies_cleanly: { type: 'boolean', description: 'the diff applies to a clean checkout of repo without conflict' },
    acceptance_passes: { type: 'boolean', description: 'the frozen acceptance commands ALL exit 0 after applying' },
    changed_paths: { type: 'array', items: { type: 'string' } },
    notes: { type: 'string' },
  },
}

const REFUTE_SCHEMA = {
  type: 'object',
  additionalProperties: false,
  required: ['index', 'honest', 'refutations', 'verdict'],
  properties: {
    index: { type: 'number' },
    honest: { type: 'boolean', description: 'true ONLY if the change genuinely satisfies the objective with no gaming/scope-creep/hidden side effect' },
    refutations: { type: 'array', items: { type: 'string' } },
    verdict: { type: 'string' },
  },
}

const results = await pipeline(
  proposals.map((p, i) => ({ p, i })),
  ({ p, i }) => agent(
    `Independently RE-PROVE Atlas Evolution Loop proposal #${i}. Do NOT trust the loop — verify from scratch.

Proposal objective: ${JSON.stringify(p.objective ?? '')}
Frozen acceptance: ${JSON.stringify(p.acceptance ?? {})}
Unified diff:
\`\`\`
${(p.diff_text ?? '').slice(0, 12000)}
\`\`\`

Steps (use Bash):
1. Make a CLEAN temp checkout/copy of the base repo${repo ? ` at ${repo}` : ' (if no repo path was given, reconstruct the touched files from the diff alone in a temp dir)'}.
2. Apply the diff there (git apply, or recreate the changed files from the diff).
3. Confirm ONLY the acceptance.allowed_globs paths changed and NO acceptance.frozen_globs path changed.
4. Run EVERY command in acceptance.commands in that checkout; record whether all exit 0.
Report applies_cleanly, acceptance_passes, the changed_paths you observed, and notes. Clean up temp dirs.`,
    { label: `reprove:#${i}`, phase: 'Re-prove', schema: REPROVE_SCHEMA },
  ),
  (reprove, { p, i }) => agent(
    `Adversarially REFUTE that Atlas Evolution Loop proposal #${i} HONESTLY satisfies its objective. Independent re-proof said: ${JSON.stringify(reprove)}.

Objective: ${JSON.stringify(p.objective ?? '')}
Diff:
\`\`\`
${(p.diff_text ?? '').slice(0, 12000)}
\`\`\`

Hunt for any way this is NOT an honest improvement: gaming the acceptance (weakening behavior so the command passes without real value), scope creep / unrelated edits, hidden side effects, deleting or short-circuiting logic the objective implies, a fix that passes the specific command but breaks the intent. If the re-proof reported applies_cleanly=false or acceptance_passes=false, it is already not honest. Default honest=false on ANY unresolved doubt.`,
    { label: `refute:#${i}`, phase: 'Refute', schema: REFUTE_SCHEMA },
  ).then((refute) => ({
    index: i,
    objective: p.objective ?? '',
    reprove,
    refute,
    confirmed: reprove.applies_cleanly === true && reprove.acceptance_passes === true && refute.honest === true,
  })),
)

const confirmed = results.filter((r) => r && r.confirmed)
const rejected = results.filter((r) => r && !r.confirmed)
log(`confirmed: ${confirmed.length}/${proposals.length} | rejected: ${rejected.length}`)

return {
  total: proposals.length,
  confirmed_count: confirmed.length,
  rejected_count: rejected.length,
  confirmed: confirmed.map((r) => ({ index: r.index, objective: r.objective })),
  rejected: rejected.map((r) => ({ index: r.index, objective: r.objective, why: r.refute?.refutations?.[0] || r.reprove?.notes })),
  results,
}
