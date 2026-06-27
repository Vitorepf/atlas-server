<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * PERCEPTION BUNDLE — single façade that calls every read-only perception organ for a scope and
 * returns a consolidated payload. Lets downstream consumers (a future briefer, a workflow step,
 * an export pipeline) pull "everything the brain currently sees" in one call instead of stitching
 * 10+ services together every time.
 *
 * Pure composition over existing organs. No new state, no new IO. Pétreo (the bundle's shape is
 * load-bearing — the réu would re-order to hide signals).
 */
final class AtlasBrainPerceptionBundle
{
    public const SCHEMA = 'atlas.brain.perception_bundle.v1';

    public function __construct(
        private readonly AtlasBrainReflectionStream $stream,
        private readonly AtlasBrainBriefHistogram $briefHistogram,
        private readonly AtlasBrainResultKindHistogram $kindHistogram,
        private readonly AtlasBrainHintEntropy $entropy,
        private readonly AtlasBrainHintTransitionMatrix $transition,
        private readonly AtlasBrainTrendAnalyzer $trend,
        private readonly AtlasBrainEvidenceFreshness $freshness,
        private readonly AtlasBrainHintToPathTranslator $translator,
        private readonly AtlasBrainPathStarvationDetector $starvationDetector,
        private readonly AtlasBrainCascadeRuleOutcomeAnalyzer $cascadeAnalyzer,
        private readonly AtlasBrainPathDiversityScore $diversityScore,
        private readonly AtlasBrainConcentrationHhi $concentrationHhi,
        private readonly AtlasBrainPathYieldMomentum $pathYieldMomentum,
    ) {}

    /**
     * @return array{schema:string, scope:string, brief_histogram:array, result_kind_histogram:array, hint_entropy:array, hint_transitions:array, starvation_trend:array, evidence_freshness:array, path_starvation:array, cascade_outcomes:array, path_diversity_score:array, concentration_hhi:array, path_yield_momentum:array}
     */
    public function build(string $scope): array
    {
        $tail = array_slice($this->stream->forScope($scope), -50);
        $brief = $this->briefHistogram->histogram($tail);

        return [
            'schema' => self::SCHEMA,
            'scope' => $scope,
            'brief_histogram' => $brief,
            'result_kind_histogram' => $this->kindHistogram->histogram($tail),
            'hint_entropy' => $this->entropy->compute($brief),
            'hint_transitions' => $this->transition->build($tail),
            'starvation_trend' => $this->trend->starvation($this->stream->forScope($scope)),
            'evidence_freshness' => $this->freshness->inspect($this->stream->forScope($scope)),
            'path_starvation' => $this->starvationDetector->detect($brief, $this->translator),
            'cascade_outcomes' => $this->cascadeAnalyzer->analyze(
                $scope,
                $this->stream,
                new AtlasBrainDoneSetLedger($scope, (string) config('atlas.brain.done_set_root'))
            ),
            'path_diversity_score' => $this->diversityScore->compute($tail, $this->translator),
            'concentration_hhi' => $this->concentrationHhi->compute($tail, $this->translator),
            'path_yield_momentum' => $this->pathYieldMomentum->compute($tail, $this->translator),
        ];
    }
}
