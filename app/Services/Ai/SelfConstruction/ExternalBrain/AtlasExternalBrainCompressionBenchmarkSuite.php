<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Proof-backed benchmark gate: scores a compression candidate across five measurable quality
 * floors — line reduction, behavior preservation, test strength, rollback readiness, and
 * worker-yield preservation — and holds readiness whenever any floor scores below threshold.
 * A candidate is never approved on the strength of one strong dimension (e.g. a huge line
 * reduction) while another floor (e.g. behavior preservation) silently fails.
 *
 * Input contract (all scores are 0.0..1.0, default 0.0 when absent — missing evidence never
 * passes a floor implicitly):
 *   line_reduction_score?:            float
 *   behavior_preservation_score?:     float
 *   test_strength_score?:             float
 *   rollback_readiness_score?:        float
 *   worker_yield_preservation_score?: float
 *   threshold?:                       float (default 0.6, the minimum passing score per floor)
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionBenchmarkSuite
{
    public const SCHEMA = 'atlas.external_brain.compression_benchmark_suite.v1';

    public const DECISION_APPROVE = 'approve';
    public const DECISION_HOLD    = 'hold';

    public const DEFAULT_THRESHOLD = 0.6;

    private const FLOOR_KEYS = [
        'line_reduction'            => 'line_reduction_score',
        'behavior_preservation'     => 'behavior_preservation_score',
        'test_strength'             => 'test_strength_score',
        'rollback_readiness'        => 'rollback_readiness_score',
        'worker_yield_preservation' => 'worker_yield_preservation_score',
    ];

    /**
     * @param  array<string,mixed>  $facts
     * @return array{schema:string, decision:string, floor_scores:array<string,float>, failed_floors:list<string>, threshold:float}
     */
    public function evaluate(array $facts): array
    {
        $threshold = (float) ($facts['threshold'] ?? self::DEFAULT_THRESHOLD);

        $floorScores = [];
        $failedFloors = [];

        foreach (self::FLOOR_KEYS as $floor => $factKey) {
            $score = $this->clamp((float) ($facts[$factKey] ?? 0.0));
            $floorScores[$floor] = $score;

            if ($score < $threshold) {
                $failedFloors[] = $floor;
            }
        }

        return [
            'schema'         => self::SCHEMA,
            'decision'       => $failedFloors === [] ? self::DECISION_APPROVE : self::DECISION_HOLD,
            'floor_scores'   => $floorScores,
            'failed_floors'  => $failedFloors,
            'threshold'      => $threshold,
        ];
    }

    private function clamp(float $score): float
    {
        return AiValueNormalizer::clampUnit($score);
    }
}
