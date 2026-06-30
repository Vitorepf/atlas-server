<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure canary kill-switch evaluator. Checks live canary metrics against
 * hard ceilings and recommends rollback, continue, or wait for more samples.
 *
 * AC2: rollback when any metric breaches its ceiling.
 * AC3: continue only when all metrics are within thresholds AND sample size ≥ MIN.
 *      Insufficient sample with no breach → wait_for_sample.
 * AC4: output always includes action, breached_thresholds, rollback_scope,
 *      sample_size, and next_safe_variant.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAmplifierCanaryKillSwitch
{
    public const SCHEMA = 'atlas.external_brain.amplifier_canary_kill_switch.v1';

    public const ACTION_ROLLBACK          = 'rollback';
    public const ACTION_CONTINUE          = 'continue';
    public const ACTION_WAIT_FOR_SAMPLE   = 'wait_for_sample';

    private const MIN_SAMPLE_SIZE         = 10;

    // Hard ceilings (inclusive breach at >)
    private const DUPLICATE_CEILING       = 0.20;
    private const GIVE_BACK_CEILING       = 0.25;
    private const MALFORMED_CEILING       = 0.10;
    private const WEAK_EVIDENCE_CEILING   = 0.30;
    private const LOW_VALUE_CEILING       = 0.30;

    /**
     * @param  array{
     *   sample_size?: int,
     *   duplicate_rate?: float,
     *   give_back_rate?: float,
     *   malformed_rate?: float,
     *   weak_evidence_rate?: float,
     *   low_value_rate?: float,
     * }  $input
     * @return array{schema:string, action:string, breached_thresholds:list<string>, rollback_scope:string|null, sample_size:int, next_safe_variant:string}
     */
    public function evaluate(array $input): array
    {
        $sampleSize       = max(0, (int) ($input['sample_size']        ?? 0));
        $duplicateRate    = max(0.0, min(1.0, (float) ($input['duplicate_rate']     ?? 0.0)));
        $giveBackRate     = max(0.0, min(1.0, (float) ($input['give_back_rate']     ?? 0.0)));
        $malformedRate    = max(0.0, min(1.0, (float) ($input['malformed_rate']     ?? 0.0)));
        $weakEvidenceRate = max(0.0, min(1.0, (float) ($input['weak_evidence_rate'] ?? 0.0)));
        $lowValueRate     = max(0.0, min(1.0, (float) ($input['low_value_rate']     ?? 0.0)));

        $breached = [];

        if ($duplicateRate > self::DUPLICATE_CEILING) {
            $breached[] = sprintf('duplicate_rate:%.4f>%.2f', $duplicateRate, self::DUPLICATE_CEILING);
        }
        if ($giveBackRate > self::GIVE_BACK_CEILING) {
            $breached[] = sprintf('give_back_rate:%.4f>%.2f', $giveBackRate, self::GIVE_BACK_CEILING);
        }
        if ($malformedRate > self::MALFORMED_CEILING) {
            $breached[] = sprintf('malformed_rate:%.4f>%.2f', $malformedRate, self::MALFORMED_CEILING);
        }
        if ($weakEvidenceRate > self::WEAK_EVIDENCE_CEILING) {
            $breached[] = sprintf('weak_evidence_rate:%.4f>%.2f', $weakEvidenceRate, self::WEAK_EVIDENCE_CEILING);
        }
        if ($lowValueRate > self::LOW_VALUE_CEILING) {
            $breached[] = sprintf('low_value_rate:%.4f>%.2f', $lowValueRate, self::LOW_VALUE_CEILING);
        }

        if ($breached !== []) {
            return [
                'schema'              => self::SCHEMA,
                'action'              => self::ACTION_ROLLBACK,
                'breached_thresholds' => $breached,
                'rollback_scope'      => 'canary_only',
                'sample_size'         => $sampleSize,
                'next_safe_variant'   => 'baseline',
            ];
        }

        if ($sampleSize < self::MIN_SAMPLE_SIZE) {
            return [
                'schema'              => self::SCHEMA,
                'action'              => self::ACTION_WAIT_FOR_SAMPLE,
                'breached_thresholds' => [],
                'rollback_scope'      => null,
                'sample_size'         => $sampleSize,
                'next_safe_variant'   => 'current_canary',
            ];
        }

        return [
            'schema'              => self::SCHEMA,
            'action'              => self::ACTION_CONTINUE,
            'breached_thresholds' => [],
            'rollback_scope'      => null,
            'sample_size'         => $sampleSize,
            'next_safe_variant'   => 'current_canary',
        ];
    }
}
