<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure governance that manages scaffold lifecycle: promote, hold, deprecate, or retire
 * variants based on measured lift, overfit risk, and active compatibility version.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainScaffoldRegistryGovernance
{
    public const SCHEMA = 'atlas.external_brain.scaffold_registry_governance.v1';

    public const ACTION_PROMOTE = 'promote';
    public const ACTION_HOLD = 'hold';
    public const ACTION_DEPRECATE = 'deprecate';
    public const ACTION_RETIRE = 'retire';

    /**
     * @param  array{
     *   variant_id?:string,
     *   measured_lift_verdict?:string,
     *   overfit_risk?:float,
     *   overfit_threshold?:float,
     *   compatible_with_active_version?:bool,
     *   replacement_candidate_id?:?string,
     *   usage_count?:int,
     * }  $variant
     * @return array{
     *   schema:string,
     *   action:string,
     *   reason:string,
     *   next_review_trigger:string,
     *   replacement_candidate:?string,
     * }
     */
    public function decide(array $variant): array
    {
        $verdict = (string) ($variant['measured_lift_verdict'] ?? 'no_measured_lift');
        $overfitRisk = (float) ($variant['overfit_risk'] ?? 1.0);
        $overfitThreshold = (float) ($variant['overfit_threshold'] ?? 0.3);
        $compatible = (bool) ($variant['compatible_with_active_version'] ?? false);
        $replacementId = $variant['replacement_candidate_id'] ?? null;
        $usageCount = (int) ($variant['usage_count'] ?? 0);

        // Incompatible + has replacement → retire
        if (! $compatible && $replacementId !== null) {
            return $this->envelope(
                self::ACTION_RETIRE,
                'incompatible with active version, replacement available',
                'on_next_release_cycle',
                (string) $replacementId
            );
        }

        // Incompatible without replacement → deprecate
        if (! $compatible) {
            return $this->envelope(
                self::ACTION_DEPRECATE,
                'incompatible with active version',
                'on_replacement_available',
                null
            );
        }

        // High overfit risk → hold (even if lift detected)
        if ($overfitRisk > $overfitThreshold) {
            return $this->envelope(
                self::ACTION_HOLD,
                'overfit_risk ' . round($overfitRisk, 2) . ' > threshold ' . round($overfitThreshold, 2),
                'after_10_more_usages',
                null
            );
        }

        // Measured lift + low overfit risk + compatible → promote
        if ($verdict === 'measured_lift') {
            return $this->envelope(
                self::ACTION_PROMOTE,
                'measured lift with low overfit risk (' . round($overfitRisk, 2) . ')',
                'periodic_quality_recheck',
                null
            );
        }

        // No measured lift → hold
        return $this->envelope(
            self::ACTION_HOLD,
            'no measured lift — awaiting more evidence',
            'after_5_more_usages',
            null
        );
    }

    private function envelope(string $action, string $reason, string $nextReviewTrigger, ?string $replacementCandidate): array
    {
        return [
            'schema' => self::SCHEMA,
            'action' => $action,
            'reason' => $reason,
            'next_review_trigger' => $nextReviewTrigger,
            'replacement_candidate' => $replacementCandidate,
        ];
    }
}
