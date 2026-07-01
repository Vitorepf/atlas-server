<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Post-merge accountability gate: a compression task's claimed success is never trusted until it
 * is measured AFTER the commit lands. This auditor compares the PROMISED metric row (what the
 * task claimed it would deliver) against the ACTUAL measured row across fitness, proof,
 * capability and line-reduction — any metric where actual falls short of promised is a failed
 * promise, and any failed promise emits repair_required naming exactly which promises were
 * missed, never a success summary without measurement.
 *
 * Input shape (both `promised` and `actual`):
 *   { fitness_score?:    float,
 *     proof_coverage?:   float,
 *     capability_score?: float,
 *     line_reduction?:   int }
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainPostMergeCompressionAuditor
{
    public const SCHEMA = 'atlas.self_construction.external_brain.post_merge_compression_auditor.v1';

    public const STATUS_PROMISE_MET = 'promise_met';

    public const STATUS_REPAIR_REQUIRED = 'repair_required';

    /** @var list<string> */
    private const AUDITED_METRICS = [
        'fitness_score',
        'proof_coverage',
        'capability_score',
        'line_reduction',
    ];

    /**
     * @param  array{promised?: array<string,mixed>, actual?: array<string,mixed>}  $facts
     * @return array{schema:string, status:string, failed_promises:list<string>}
     */
    public function audit(array $facts): array
    {
        $promised = is_array($facts['promised'] ?? null) ? $facts['promised'] : [];
        $actual = is_array($facts['actual'] ?? null) ? $facts['actual'] : [];

        $failedPromises = [];
        foreach (self::AUDITED_METRICS as $metric) {
            $promisedValue = (float) ($promised[$metric] ?? 0);
            $actualValue = (float) ($actual[$metric] ?? 0);

            if ($actualValue < $promisedValue) {
                $failedPromises[] = $metric;
            }
        }

        return [
            'schema' => self::SCHEMA,
            'status' => $failedPromises === [] ? self::STATUS_PROMISE_MET : self::STATUS_REPAIR_REQUIRED,
            'failed_promises' => $failedPromises,
        ];
    }
}
