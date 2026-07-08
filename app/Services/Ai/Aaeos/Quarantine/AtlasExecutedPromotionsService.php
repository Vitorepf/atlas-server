<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Quarantine;

/**
 * Deterministic runtime for the Legacy Cleanup Executed Promotions policy.
 *
 * The doc is a historical record of legacy documentation promotions plus two
 * hard governance rules. This service turns that record + rules into pure,
 * testable decision logic — it never touches the filesystem, git or Obsidian;
 * it consumes already-gathered facts and emits verdicts + reasons.
 *
 * Concrete contract grounded in the doc:
 *
 *   1. Executed promotions registry (the "2026-05-05 Promotions" table): each
 *      legacy source theme was promoted to exactly one destination doc, which is
 *      now the authority for that theme.
 *
 *   2. Already Canonical Or Preserved families: Engineering Blueprint,
 *      Memory/Open Brain, Super Tool Runtime, Finance/Personal Development —
 *      these are already canonical, source material remains historical only.
 *
 *   3. Redirect Principle: "If a legacy source was promoted, the destination doc
 *      is the authority. The old source ... must not be used by an AI to create a
 *      parallel flow." -> resolveAuthority() routes a promoted source to its
 *      destination and forbids building a parallel flow from the source.
 *
 *   4. Re-Promotion Rule (ordered 5-step gate). Before promoting a source again:
 *        1. read the destination doc;
 *        2. identify the exact missing decision;
 *        3. patch the owner doc;
 *        4. preserve source link;
 *        5. run docs-health and architecture validation.
 *      plus the frontmatter decision: "Future sessions should not re-promote the
 *      same source without diffing against the destination." -> evaluateRePromotion()
 *      only allows a re-promotion when every step holds, and otherwise names the
 *      FIRST unsatisfied step (the gate is ordered: a later step cannot pass while
 *      an earlier one is unmet).
 *
 * Pure functions only: no database, no IO, no side effects. Every method returns
 * a strict typed array shape.
 *
 * @see docs/engineering-knowledge-base/legacy-cleanup/executed-promotions.md
 */
final class AtlasExecutedPromotionsService
{
    /** Stable receipt schema id for the verdicts this service emits. */
    public const SCHEMA = 'atlas.aaeos.executed_promotions.v1';

    /** Re-Promotion / authority verdicts. */
    public const VERDICT_ALLOW = 'allow';
    public const VERDICT_BLOCK = 'block';

    /**
     * The "2026-05-05 Promotions" table: canonicalized source theme -> the
     * destination doc that is now the authority for that theme.
     *
     * @var array<string,string>
     */
    private const PROMOTIONS = [
        'atlas identity, glossary and master prompt' => 'atlas-ai-layer-0-glossary.md',
        'sessions, compaction and continuity' => 'atlas-ai-continuity-session-state.md',
        'telemetry, evidence and efficiency' => 'atlas-ai-telemetry-evidence-performance.md',
        'paste image and cli multimodal' => 'atlas-ai-cli-multimodal.md',
        'mobile gateway, push and inbox' => 'atlas-ai-mobile-surface-gateway.md',
        'runtime packets' => 'atlas-ai-runtime-packets.md',
        'skill system' => 'atlas-ai-skill-system.md',
        'gaps and backlog' => 'atlas-ai-governed-backlog.md',
        'mac local agent' => 'atlas-local-agent-surface.md',
    ];

    /**
     * The "Already Canonical Or Preserved" table: canonicalized family -> the
     * documented handling. These are already canonical; their legacy source
     * material remains historical only and is never re-promoted as authority.
     *
     * @var array<string,string>
     */
    private const ALREADY_CANONICAL = [
        'engineering blueprint' => 'Canonical docs under engineering-blueprint*.md.',
        'memory/open brain' => 'Canonical docs under memory and Open Brain docs.',
        'super tool runtime' => 'Canonical docs under super-tool-runtime-core.md and tool-runtime/.',
        'finance and personal development' => 'Domain specs live under domains/; source material remains historical.',
    ];

    /**
     * The Re-Promotion Rule, as an ordered list of steps. Order is load-bearing:
     * a re-promotion may only proceed when every step is satisfied, and the gate
     * reports the FIRST unmet step.
     *
     * Each entry: input flag the caller must set true -> stable step key.
     *
     * @var list<array{flag:string,step:string,reason:string}>
     */
    private const RE_PROMOTION_STEPS = [
        ['flag' => 'destination_read', 'step' => 'read_destination_doc', 'reason' => 'must read the destination doc before re-promoting (no re-promotion without diffing against the destination)'],
        ['flag' => 'missing_decision_identified', 'step' => 'identify_missing_decision', 'reason' => 'must identify the exact missing decision (nothing missing => nothing to re-promote)'],
        ['flag' => 'owner_doc_patched', 'step' => 'patch_owner_doc', 'reason' => 'must patch the owner doc, not the legacy source'],
        ['flag' => 'source_link_preserved', 'step' => 'preserve_source_link', 'reason' => 'must preserve the source link for historical nuance'],
        ['flag' => 'docs_health_validated', 'step' => 'run_docs_health_and_architecture_validation', 'reason' => 'must run docs-health and architecture validation'],
    ];

    /**
     * Resolve the destination doc that now holds authority for a legacy source
     * theme. Returns null for a theme not in the executed-promotions registry.
     */
    public function destinationFor(string $theme): ?string
    {
        return self::PROMOTIONS[$this->canonicalize($theme)] ?? null;
    }

    /**
     * Has this legacy source theme already been promoted (and therefore has a
     * canonical destination authority)?
     */
    public function isPromoted(string $theme): bool
    {
        return array_key_exists($this->canonicalize($theme), self::PROMOTIONS);
    }

    /**
     * Apply the Redirect Principle to a single source theme.
     *
     * If the source was promoted, the destination doc is the authority and an AI
     * must NOT use the source to create a parallel flow. If the source was not
     * promoted (unknown theme), there is no recorded destination authority.
     *
     * @return array{
     *     schema_version:string,
     *     source_theme:string,
     *     promoted:bool,
     *     authority:?string,
     *     may_build_parallel_flow_from_source:bool,
     *     verdict:string,
     *     reasons:list<string>
     * }
     */
    public function resolveAuthority(string $theme): array
    {
        $destination = $this->destinationFor($theme);
        $promoted = $destination !== null;
        $reasons = [];

        if ($promoted) {
            // "the destination doc is the authority ... must not be used by an AI
            // to create a parallel flow."
            $reasons[] = 'destination_is_authority: use ' . $destination . ' as the authority for this theme';
            $reasons[] = 'no_parallel_flow: the legacy source may inform historical nuance but must not seed a parallel flow';
        } else {
            $reasons[] = 'unknown_source: no executed promotion is recorded for this theme — no destination authority';
        }

        return [
            'schema_version' => self::SCHEMA,
            'source_theme' => trim($theme),
            'promoted' => $promoted,
            'authority' => $destination,
            // A promoted source must never seed a parallel flow.
            'may_build_parallel_flow_from_source' => false,
            'verdict' => $promoted ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'reasons' => $reasons,
        ];
    }

    /**
     * Evaluate a request to re-promote a legacy source, applying the ordered
     * Re-Promotion Rule plus the "diff against the destination first" decision.
     *
     * The gate is ordered and fail-closed: it walks the five steps in document
     * order and BLOCKS at the first one that is not satisfied, naming that step.
     * Only when all five hold is the re-promotion allowed.
     *
     * @param  array{
     *     source_theme?:string,
     *     destination_read?:bool,
     *     missing_decision_identified?:bool,
     *     owner_doc_patched?:bool,
     *     source_link_preserved?:bool,
     *     docs_health_validated?:bool
     * }  $request
     * @return array{
     *     schema_version:string,
     *     source_theme:string,
     *     promoted:bool,
     *     destination:?string,
     *     verdict:string,
     *     allowed:bool,
     *     completed_steps:list<string>,
     *     blocking_step:?string,
     *     pending_steps:list<string>,
     *     reasons:list<string>
     * }
     */
    public function evaluateRePromotion(array $request): array
    {
        $theme = trim((string) ($request['source_theme'] ?? ''));
        $destination = $this->destinationFor($theme);
        $promoted = $destination !== null;

        $completed = [];
        $pending = [];
        $blockingStep = null;
        $reasons = [];

        foreach (self::RE_PROMOTION_STEPS as $step) {
            // Default false: an unspecified precondition is treated as NOT met
            // (the rule is fail-closed — you do not get credit for a step you did
            // not assert you completed).
            $satisfied = (bool) ($request[$step['flag']] ?? false);

            if ($blockingStep !== null) {
                // Already blocked upstream: every later step is pending regardless.
                $pending[] = $step['step'];

                continue;
            }

            if ($satisfied) {
                $completed[] = $step['step'];

                continue;
            }

            // First unmet step in document order -> this is the blocker.
            $blockingStep = $step['step'];
            $pending[] = $step['step'];
            $reasons[] = $step['step'] . ': ' . $step['reason'];
        }

        $allowed = $blockingStep === null;

        if ($allowed) {
            $reasons[] = 're_promotion_gate_passed: all five Re-Promotion steps satisfied';
        }

        return [
            'schema_version' => self::SCHEMA,
            'source_theme' => $theme,
            'promoted' => $promoted,
            'destination' => $destination,
            'verdict' => $allowed ? self::VERDICT_ALLOW : self::VERDICT_BLOCK,
            'allowed' => $allowed,
            'completed_steps' => $completed,
            'blocking_step' => $blockingStep,
            'pending_steps' => $pending,
            'reasons' => $reasons,
        ];
    }

    /**
     * The full executed-promotions registry as canonical reference data: the
     * 2026-05-05 promotion table, the already-canonical families, and the ordered
     * Re-Promotion Rule steps.
     *
     * @return array{
     *     schema_version:string,
     *     promotion_count:int,
     *     promotions:list<array{source_theme:string,destination:string}>,
     *     already_canonical:list<array{family:string,handling:string}>,
     *     re_promotion_steps:list<string>,
     *     redirect_principle:string
     * }
     */
    public function registry(): array
    {
        $promotions = [];
        foreach (self::PROMOTIONS as $theme => $destination) {
            $promotions[] = ['source_theme' => $theme, 'destination' => $destination];
        }

        $alreadyCanonical = [];
        foreach (self::ALREADY_CANONICAL as $family => $handling) {
            $alreadyCanonical[] = ['family' => $family, 'handling' => $handling];
        }

        $steps = array_map(static fn (array $s): string => $s['step'], self::RE_PROMOTION_STEPS);

        return [
            'schema_version' => self::SCHEMA,
            'promotion_count' => count($promotions),
            'promotions' => $promotions,
            'already_canonical' => $alreadyCanonical,
            're_promotion_steps' => $steps,
            'redirect_principle' => 'A promoted legacy source\'s destination doc is the authority; the source must not seed a parallel flow.',
        ];
    }

    private function canonicalize(string $value): string
    {
        return strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? $value));
    }
}
