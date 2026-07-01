<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Live risk radar for the simplification engine: 24/7 autonomy must balance ambition with
 * quality, so this radar senses queue, proof, regression and worker-outcome signals and changes
 * next_action away from originating more compression the moment quality degrades. Priority
 * (first match wins, worst case always wins): any real regression or severely eroded proof
 * coverage stops compression outright; moderately degraded proof coverage demands repair first;
 * a give-back or worker-error spike slows down; only when every signal is healthy does it
 * proceed.
 *
 * Input shape:
 *   { signals: {
 *       queue_depth?:        int,
 *       give_back_rate?:     float,  // 0.0-1.0
 *       regression_count?:   int,
 *       proof_coverage?:     float,  // 0.0-1.0, defaults to 1.0 (healthy) when absent
 *       worker_error_rate?:  float,  // 0.0-1.0
 *   } }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionRiskRadar
{
    public const SCHEMA = 'atlas.self_construction.external_brain.compression_risk_radar.v1';

    public const ACTION_PROCEED = 'proceed';

    public const ACTION_SLOW_DOWN = 'slow_down';

    public const ACTION_PROOF_REPAIR = 'proof_repair';

    public const ACTION_STOP_COMPRESSION = 'stop_compression';

    private const GIVE_BACK_SPIKE_FLOOR = 0.30;

    private const WORKER_ERROR_SPIKE_FLOOR = 0.30;

    private const PROOF_COVERAGE_CRITICAL_CEILING = 0.30;

    private const PROOF_COVERAGE_DEGRADED_CEILING = 0.60;

    /**
     * @param  array{signals?: array<string,mixed>}  $facts
     * @return array{schema:string, next_action:string, triggers:list<string>, signals_observed:array<string,mixed>}
     */
    public function radar(array $facts): array
    {
        $signals = is_array($facts['signals'] ?? null) ? $facts['signals'] : [];

        $giveBackRate = (float) ($signals['give_back_rate'] ?? 0.0);
        $regressionCount = max(0, (int) ($signals['regression_count'] ?? 0));
        $proofCoverage = (float) ($signals['proof_coverage'] ?? 1.0);
        $workerErrorRate = (float) ($signals['worker_error_rate'] ?? 0.0);
        $queueDepth = max(0, (int) ($signals['queue_depth'] ?? 0));

        $triggers = [];
        if ($regressionCount > 0) {
            $triggers[] = 'regression_detected';
        }
        if ($proofCoverage < self::PROOF_COVERAGE_DEGRADED_CEILING) {
            $triggers[] = 'proof_coverage_degraded';
        }
        if ($giveBackRate >= self::GIVE_BACK_SPIKE_FLOOR) {
            $triggers[] = 'give_back_spike';
        }
        if ($workerErrorRate >= self::WORKER_ERROR_SPIKE_FLOOR) {
            $triggers[] = 'worker_error_spike';
        }

        $nextAction = match (true) {
            $regressionCount > 0, $proofCoverage < self::PROOF_COVERAGE_CRITICAL_CEILING => self::ACTION_STOP_COMPRESSION,
            $proofCoverage < self::PROOF_COVERAGE_DEGRADED_CEILING => self::ACTION_PROOF_REPAIR,
            $giveBackRate >= self::GIVE_BACK_SPIKE_FLOOR, $workerErrorRate >= self::WORKER_ERROR_SPIKE_FLOOR => self::ACTION_SLOW_DOWN,
            default => self::ACTION_PROCEED,
        };

        return [
            'schema' => self::SCHEMA,
            'next_action' => $nextAction,
            'triggers' => $triggers,
            'signals_observed' => [
                'queue_depth' => $queueDepth,
                'give_back_rate' => $giveBackRate,
                'regression_count' => $regressionCount,
                'proof_coverage' => $proofCoverage,
                'worker_error_rate' => $workerErrorRate,
            ],
        ];
    }
}
