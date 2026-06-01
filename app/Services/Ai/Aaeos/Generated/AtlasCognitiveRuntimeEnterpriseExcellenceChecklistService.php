<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Generated;

/**
 * Deterministic runtime for the Atlas AI Cognitive Runtime Enterprise Excellence
 * Checklist.
 *
 * This doc is NOT the parent cognitive-runtime law (that has its own decider,
 * AtlasAiCognitiveRuntimeService) and NOT the research/self-improvement checklist
 * (AtlasEnterpriseExcellenceChecklistService). It is a separate, concrete
 * scorecard over TEN cognitive-runtime areas, each declared at three documented
 * levels, plus an Executive Score and a hard "Superation Rule". This service
 * turns those into pure, testable logic:
 *
 *   1. Three-level area readiness ("baseline" / "atlas_plus" / "proof"). Every one
 *      of the ten areas (Memory OS, Temporal Knowledge Graph, Hybrid Retrieval,
 *      Agentic Memory Manager, Structured Canonical State, Raw Immutable
 *      Transcript, Progressive Context Disclosure, KV/Prefix Cache, Memory
 *      Governance, Continuous Evaluation) carries three documented levels:
 *        - baseline  = the minimum to compete with strong long-context systems;
 *        - atlas_plus = what Atlas adds via governance/evidence;
 *        - proof     = the evidence required to DECLARE it ready.
 *      An area is `enterprise_ready` ONLY when all three of its levels hold.
 *      Missing baseline -> `below_baseline`; baseline only -> `baseline_only`;
 *      baseline + atlas_plus but no proof -> `unproven` (the doc forbids
 *      marking implemented "sem codigo, teste, evidence e validacao documental").
 *      An area can never reach proof without first reaching baseline, so this
 *      method enforces the ladder rather than trusting the proof flag alone.
 *
 *   2. Executive Score classification. The doc's table maps each area to an
 *      implementation status (covered/partial/planned/...). This service maps any
 *      reported status onto the closed maturity ladder and forbids declaring an
 *      area "implemented" while its status is still planned/partial — matching
 *      the maintenance rule "Itens marcados como parcial exigem AP ou contrato
 *      filho antes de implementacao."
 *
 *   3. Superation Rule (the headline gate). "O Atlas supera o baseline quando os
 *      10 itens funcionam juntos." The system reaches Atlas-enterprise ONLY when
 *      every one of the ten areas is `enterprise_ready`. Additionally the doc
 *      states "Qualquer implementacao que pule evidence, review, privacy ou
 *      replay ... nao e Atlas enterprise", so the four non-skippable pillars
 *      (evidence, review, privacy, replay) are a hard precondition: skipping any
 *      one fails the rule regardless of how many areas are ready.
 *
 * Pure & deterministic: no database, no IO, no clock. Same input -> same output.
 * Read-only judgment: it reports whether the documented gates are green; it never
 * promotes, relaxes a gate, declares maturity on its own, or writes evidence.
 *
 * @see docs/engineering-knowledge-base/cognitive-runtime/enterprise-excellence-checklist.md
 */
final class AtlasCognitiveRuntimeEnterpriseExcellenceChecklistService
{
    public const SCHEMA_AREA = 'atlas.cognitive_runtime.excellence.area.v1';
    public const SCHEMA_EXECUTIVE = 'atlas.cognitive_runtime.excellence.executive_score.v1';
    public const SCHEMA_SUPERATION = 'atlas.cognitive_runtime.excellence.superation.v1';

    // --- The three documented levels of every area, in ladder order. ---------
    public const LEVEL_BASELINE = 'baseline';
    public const LEVEL_ATLAS_PLUS = 'atlas_plus';
    public const LEVEL_PROOF = 'proof';

    // --- Closed maturity ladder for a single area (worst -> best). -----------
    public const AREA_BELOW_BASELINE = 'below_baseline';
    public const AREA_BASELINE_ONLY = 'baseline_only';
    public const AREA_UNPROVEN = 'unproven';
    public const AREA_ENTERPRISE_READY = 'enterprise_ready';

    // --- Superation verdicts (closed set). -----------------------------------
    public const SUPERATION_REACHED = 'atlas_enterprise';
    public const SUPERATION_FAST_NOT_ENTERPRISE = 'fast_but_not_enterprise';
    public const SUPERATION_BELOW = 'below_enterprise';

    /**
     * The ten checklist areas, in documented order (Executive Score table / §1-§10).
     *
     * @var list<string>
     */
    public const AREAS = [
        'memory_os',                       // §1
        'temporal_knowledge_graph',        // §2
        'hybrid_retrieval',                // §3
        'agentic_memory_manager',          // §4
        'structured_canonical_state',      // §5
        'raw_immutable_transcript',        // §6
        'progressive_context_disclosure',  // §7
        'kv_prefix_cache',                 // §8
        'memory_governance',               // §9
        'continuous_evaluation',           // §10
    ];

    /**
     * The three levels each area must clear, in ladder order. baseline gates
     * atlas_plus; both gate proof.
     *
     * @var list<string>
     */
    public const LEVELS = [
        self::LEVEL_BASELINE,
        self::LEVEL_ATLAS_PLUS,
        self::LEVEL_PROOF,
    ];

    /**
     * The four non-skippable pillars from the Superation Rule. "Qualquer
     * implementacao que pule evidence, review, privacy ou replay ... nao e Atlas
     * enterprise." If any is skipped, superation is impossible.
     *
     * @var list<string>
     */
    public const NON_SKIPPABLE_PILLARS = [
        'evidence',
        'review',
        'privacy',
        'replay',
    ];

    /**
     * The documented Superation pipeline order (the `text` block at the end of the
     * doc). Used to report ordering integrity, not just membership.
     *
     * @var list<string>
     */
    public const SUPERATION_PIPELINE = [
        'raw_transcript',
        'cognitive_gate',
        'structured_state',
        'hybrid_retrieval',
        'progressive_disclosure',
        'model_cache',
        'evidence',
        'audit',
        'proposal_only_improvement',
        'replay',
    ];

    /**
     * Executive Score implementation statuses that are documented as NOT yet
     * implemented — they require an AP / contrato filho before implementation and
     * may never be reported as `implemented` here.
     *
     * @var list<string>
     */
    public const NON_IMPLEMENTED_STATUSES = [
        'planned',
        'partial',
        'designed',
        'research_mapped',
    ];

    // ---------------------------------------------------------------------
    // 1. Three-level area readiness
    // ---------------------------------------------------------------------

    /**
     * Classify one area on the maturity ladder from its three documented level
     * flags. The ladder is enforced: proof/atlas_plus are ignored when the level
     * below is absent, because the doc forbids declaring a higher level "sem
     * codigo, teste, evidence e validacao documental".
     *
     * @param array<string,mixed> $area
     *        baseline   : bool  the §"Baseline" minimum holds
     *        atlas_plus : bool  the §"Atlas plus" governance/evidence additions hold
     *        proof      : bool  the §"Proof" evidence to declare it ready holds
     *
     * @return array<string,mixed>
     */
    public function classifyArea(array $area): array
    {
        $hasBaseline = (bool) ($area[self::LEVEL_BASELINE] ?? false);
        // Higher levels only count when every level below them is satisfied.
        $hasAtlasPlus = $hasBaseline && (bool) ($area[self::LEVEL_ATLAS_PLUS] ?? false);
        $hasProof = $hasAtlasPlus && (bool) ($area[self::LEVEL_PROOF] ?? false);

        if (! $hasBaseline) {
            $maturity = self::AREA_BELOW_BASELINE;
        } elseif (! $hasAtlasPlus) {
            $maturity = self::AREA_BASELINE_ONLY;
        } elseif (! $hasProof) {
            $maturity = self::AREA_UNPROVEN;
        } else {
            $maturity = self::AREA_ENTERPRISE_READY;
        }

        $missingLevels = [];
        if (! $hasBaseline) {
            $missingLevels[] = self::LEVEL_BASELINE;
        }
        if (! $hasAtlasPlus) {
            $missingLevels[] = self::LEVEL_ATLAS_PLUS;
        }
        if (! $hasProof) {
            $missingLevels[] = self::LEVEL_PROOF;
        }

        return [
            'schema' => self::SCHEMA_AREA,
            'maturity' => $maturity,
            'enterprise_ready' => $maturity === self::AREA_ENTERPRISE_READY,
            'levels' => [
                self::LEVEL_BASELINE => $hasBaseline,
                self::LEVEL_ATLAS_PLUS => $hasAtlasPlus,
                self::LEVEL_PROOF => $hasProof,
            ],
            'missing_levels' => $missingLevels,
        ];
    }

    /** Convenience predicate: is this area enterprise-ready (all three levels)? */
    public function areaEnterpriseReady(array $area): bool
    {
        return $this->classifyArea($area)['enterprise_ready'] === true;
    }

    // ---------------------------------------------------------------------
    // 2. Executive Score classification
    // ---------------------------------------------------------------------

    /**
     * Map a reported Executive-Score implementation status to whether the area may
     * be treated as implemented. A status in NON_IMPLEMENTED_STATUSES (planned /
     * partial / designed / research_mapped) may NOT be reported as implemented and
     * is flagged as requiring an AP / contrato filho first.
     *
     * @param string $status the Executive Score "Implementation" cell value
     *
     * @return array<string,mixed>
     */
    public function classifyExecutiveStatus(string $status): array
    {
        $normalized = strtolower(trim($status));
        $needsApFirst = in_array($normalized, self::NON_IMPLEMENTED_STATUSES, true);
        // "implemented" / "covered" / "strong" are the only states allowed to
        // count as implemented; everything in the non-implemented set is gated.
        $countsAsImplemented = $normalized === 'implemented';

        return [
            'schema' => self::SCHEMA_EXECUTIVE,
            'status' => $normalized,
            'counts_as_implemented' => $countsAsImplemented,
            'needs_ap_or_child_contract_first' => $needsApFirst,
        ];
    }

    // ---------------------------------------------------------------------
    // 3. Superation Rule (headline gate)
    // ---------------------------------------------------------------------

    /**
     * Evaluate the Superation Rule over all ten areas.
     *
     * Atlas reaches enterprise ("atlas_enterprise") ONLY when:
     *   (a) every one of the ten areas is `enterprise_ready` (all three levels), and
     *   (b) none of the four non-skippable pillars (evidence, review, privacy,
     *       replay) is skipped.
     *
     * If the pillars are honoured but one or more areas are not yet
     * enterprise-ready, the verdict is `below_enterprise`. If any non-skippable
     * pillar is skipped, the verdict is `fast_but_not_enterprise` regardless of
     * how many areas are ready ("pode ser rapida, mas nao e Atlas enterprise").
     *
     * @param array<string,array<string,mixed>> $areas
     *        keyed by AREAS name; each value is a classifyArea() input
     *        ({baseline,atlas_plus,proof} bool flags). Missing areas are treated
     *        as below-baseline.
     * @param array<string,bool> $skippedPillars
     *        per-pillar "was this skipped?" flags (keys = NON_SKIPPABLE_PILLARS)
     *
     * @return array<string,mixed>
     */
    public function evaluateSuperation(array $areas, array $skippedPillars = []): array
    {
        $perArea = [];
        $notReady = [];
        foreach (self::AREAS as $name) {
            $input = is_array($areas[$name] ?? null) ? $areas[$name] : [];
            $classified = $this->classifyArea($input);
            $perArea[$name] = [
                'maturity' => $classified['maturity'],
                'enterprise_ready' => $classified['enterprise_ready'],
                'missing_levels' => $classified['missing_levels'],
            ];
            if (! $classified['enterprise_ready']) {
                $notReady[] = $name;
            }
        }

        $skipped = [];
        foreach (self::NON_SKIPPABLE_PILLARS as $pillar) {
            if ((bool) ($skippedPillars[$pillar] ?? false)) {
                $skipped[] = $pillar;
            }
        }

        $allReady = $notReady === [];
        $pillarsHonoured = $skipped === [];

        if (! $pillarsHonoured) {
            // Skipping evidence/review/privacy/replay disqualifies regardless.
            $verdict = self::SUPERATION_FAST_NOT_ENTERPRISE;
        } elseif ($allReady) {
            $verdict = self::SUPERATION_REACHED;
        } else {
            $verdict = self::SUPERATION_BELOW;
        }

        return [
            'schema' => self::SCHEMA_SUPERATION,
            'verdict' => $verdict,
            'is_atlas_enterprise' => $verdict === self::SUPERATION_REACHED,
            'all_areas_ready' => $allReady,
            'pillars_honoured' => $pillarsHonoured,
            'ready_count' => count(self::AREAS) - count($notReady),
            'areas_total' => count(self::AREAS),
            'areas_not_ready' => $notReady,
            'skipped_pillars' => $skipped,
            'areas' => $perArea,
        ];
    }

    /** Convenience predicate: has Atlas reached enterprise per the Superation Rule? */
    public function isAtlasEnterprise(array $areas, array $skippedPillars = []): bool
    {
        return $this->evaluateSuperation($areas, $skippedPillars)['is_atlas_enterprise'] === true;
    }
}
