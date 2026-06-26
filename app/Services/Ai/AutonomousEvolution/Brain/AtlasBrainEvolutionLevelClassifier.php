<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopAmbitionLeapProposer;
use App\Services\Ai\AutonomousEvolution\AtlasLoopFrontierGapModel;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopHeavyWorkSelector;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;

/**
 * PURE evolution-level classifier — composes the existing organs to classify an
 * objective into one of three evolution levels:
 *
 *   - 'rejected_proxy' — the objective is faxina/proxy work (cyclomatic, dead-code,
 *     formatting, refactor, rename, whitespace). Detected by composing
 *     {@see AtlasLoopAmbitionLeapProposer} which already rejects these via its
 *     PROXY_DELTA_TERMS constant.
 *   - 'patamar' — the objective crosses more than one frontier gap with high
 *     magnitude (a plateau leap), OR it matches a doc-stated capability gap.
 *     Frontier gaps come from {@see AtlasLoopFrontierGapModel}; magnitude comes
 *     from {@see AtlasLoopHeavyWorkSelector}.
 *   - 'evolucao' — single-component evolution (the default).
 *
 * Deterministic, no provider call, no DB, no mutation. Composes the organs —
 * does NOT reimplement their math.
 */
final class AtlasBrainEvolutionLevelClassifier
{
    public const SCHEMA_VERSION = 'atlas.brain.evolution_level_classifier.v1';

    /** Minimum leap magnitude for a multi-frontier objective to qualify as 'patamar'. */
    private const PATAMAR_MAGNITUDE_THRESHOLD = 10.0;

    /** Minimum frontier-gap count for the multi-frontier patamar path. */
    private const PATAMAR_MIN_FRONTIERS = 2;

    public function __construct(
        private readonly ?AtlasLoopHeavyWorkSelector $heavyWorkSelector = null,
        private readonly ?AtlasLoopAmbitionLeapProposer $leapProposer = null,
        private readonly ?AtlasLoopFrontierGapModel $frontierGapModel = null,
    ) {}

    /**
     * @param  array<string,mixed>  $runtimeFacts
     * @return array{class:string, magnitude:float, p_land:float, evidence:list<string>}
     */
    public function classify(string $objective, AtlasLoopScopeComprehensionModel $model, array $runtimeFacts = []): array
    {
        $selector = $this->heavyWorkSelector ?? new AtlasLoopHeavyWorkSelector;
        $proposer = $this->leapProposer ?? new AtlasLoopAmbitionLeapProposer;
        $frontierModel = $this->frontierGapModel ?? new AtlasLoopFrontierGapModel;

        // 1. PROXY DETECTION — compose AtlasLoopAmbitionLeapProposer.
        //    Feed the objective text as the target_capability_delta into the proposer's
        //    own isProxyDelta() check (which uses PROXY_DELTA_TERMS). If it abstains
        //    with 'proxy_delta_rejected', the objective is faxina/proxy work.
        if ($this->isProxyObjective($proposer, $objective)) {
            return [
                'class' => 'rejected_proxy',
                'magnitude' => 0.0,
                'p_land' => 0.0,
                'evidence' => ['proxy_term_detected_by_ambition_leap_proposer'],
            ];
        }

        // 2. FRONTIER GAPS — compose AtlasLoopFrontierGapModel.
        $frontierGaps = $frontierModel->compute($runtimeFacts);
        $frontierCount = count($frontierGaps);

        // 3. DOC-STATED GAP MATCH — from the comprehension model's own docStatedGaps.
        $matchesDocGap = $this->objectiveMatchesDocStatedGap($objective, $model->docStatedGaps);

        // 4. MAGNITUDE + P(LAND) — compose AtlasLoopHeavyWorkSelector.
        $candidate = $this->buildCandidateFromObjective($objective, $model);
        $context = $this->buildSelectorContext($runtimeFacts);
        $selection = $selector->select([$candidate], $context);
        $pick = $selection['pick'];
        $magnitude = $pick !== null ? (float) ($pick['leap_magnitude'] ?? 0.0) : 0.0;
        $pLand = $pick !== null ? (float) ($pick['p_land'] ?? 0.0) : 0.0;

        // 5. CLASSIFY.
        $isPatamar = ($frontierCount >= self::PATAMAR_MIN_FRONTIERS && $magnitude >= self::PATAMAR_MAGNITUDE_THRESHOLD)
            || $matchesDocGap;

        $evidence = [];
        if ($frontierCount > 0) {
            $evidence[] = 'frontier_gaps_detected:'.$frontierCount;
        }
        if ($matchesDocGap) {
            $evidence[] = 'doc_stated_gap_match';
        }
        if ($evidence === []) {
            $evidence[] = 'single_component_evolution';
        }

        return [
            'class' => $isPatamar ? 'patamar' : 'evolucao',
            'magnitude' => $magnitude,
            'p_land' => $pLand,
            'evidence' => $evidence,
        ];
    }

    /**
     * Composes {@see AtlasLoopAmbitionLeapProposer::propose()} to detect proxy/faxina
     * terms. The proposer's own isProxyDelta() (using PROXY_DELTA_TERMS) does the
     * detection — we just feed the objective as the target_capability_delta.
     */
    private function isProxyObjective(AtlasLoopAmbitionLeapProposer $proposer, string $objective): bool
    {
        $leaps = $proposer->propose([[
            'gap_id' => 'classifier:proxy_check',
            'target_capability_delta' => $objective,
            'plateau_signal' => true,
            'evidence_refs' => ['ref_1', 'ref_2', 'ref_3'],
            'scope' => 'classifier',
        ]]);

        return $leaps !== [] && ($leaps[0]['abstain_reason'] ?? '') === 'proxy_delta_rejected';
    }

    /**
     * @param  list<string>  $docStatedGaps
     */
    private function objectiveMatchesDocStatedGap(string $objective, array $docStatedGaps): bool
    {
        $objectiveLower = strtolower($objective);
        foreach ($docStatedGaps as $gap) {
            $gapLower = strtolower(trim((string) $gap));
            if ($gapLower !== '' && (str_contains($objectiveLower, $gapLower) || str_contains($gapLower, $objectiveLower))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Builds a synthetic candidate for the heavy-work selector from the objective
     * text and the comprehension model's structural facts (orphans, clones).
     *
     * @return array<string,mixed>
     */
    private function buildCandidateFromObjective(string $objective, AtlasLoopScopeComprehensionModel $model): array
    {
        $relatedItems = [];
        foreach ($model->inventory as $item) {
            $relPath = (string) ($item['rel_path'] ?? '');
            $fqcn = (string) ($item['fqcn'] ?? '');
            $baseName = $relPath !== '' ? basename($relPath, '.php') : '';
            if ($baseName !== '' && str_contains(strtolower($objective), strtolower($baseName))) {
                $relatedItems[] = $item;
            } elseif ($fqcn !== '' && str_contains(strtolower($objective), strtolower($fqcn))) {
                $relatedItems[] = $item;
            }
        }

        $nodeCount = max(1, count($relatedItems) ?: 1);
        $hasOrphan = false;
        $hasClone = false;
        foreach ($relatedItems as $item) {
            if (($item['is_orphan'] ?? false)) {
                $hasOrphan = true;
            }
            if (($item['clone_cluster_id'] ?? null) !== null) {
                $hasClone = true;
            }
        }

        return [
            'candidateId' => 'classifier:'.sha1($objective),
            'kind' => 'evolution_objective',
            'class' => $hasOrphan ? 'orphan_wiring' : ($hasClone ? 'dedup' : 'feature'),
            'node_count' => $nodeCount,
            'evidence' => [
                'refactor_leverage' => $hasOrphan ? 0.5 : 0.0,
                'cyclomatic_total' => 0,
                'failure_evidence' => [],
                'blast_radius' => 'low',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $runtimeFacts
     * @return array<string,mixed>
     */
    private function buildSelectorContext(array $runtimeFacts): array
    {
        $context = [];
        if (is_array($runtimeFacts['class_stats'] ?? null)) {
            $context['class_stats'] = $runtimeFacts['class_stats'];
        }
        if (isset($runtimeFacts['risk_tolerance'])) {
            $context['risk_tolerance'] = (float) $runtimeFacts['risk_tolerance'];
        }
        if (array_key_exists('capability_factor', $runtimeFacts)) {
            $context['capability_factor'] = (float) $runtimeFacts['capability_factor'];
        }

        return $context;
    }
}
