<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure policy that governs how the brain degrades from strong model to smaller
 * or local clients — by RAISING scaffold, proof, and review requirements instead
 * of lowering task quality.
 *
 * Provider outage recommends local/existing fallback before stopping origination.
 *
 * NO network I/O, NO file I/O, NO provider calls.
 */
final class AtlasExternalBrainGracefulDegradationPolicy
{
    public const SCHEMA = 'atlas.external_brain.graceful_degradation_policy.v1';

    public const LEVEL_FULL = 'full_capability';
    public const LEVEL_DEGRADED = 'degraded';
    public const LEVEL_LOCAL_FALLBACK = 'local_fallback';

    /**
     * @param  array{
     *   primary_provider_available?:bool,
     *   fallback_provider_available?:bool,
     *   local_client_available?:bool,
     *   model_capability_score?:float,
     *   full_capability_score?:float,
     * }  $facts
     * @return array{
     *   schema:string,
     *   level:string,
     *   scaffold_requirement:string,
     *   proof_requirement:string,
     *   review_requirement:string,
     *   prohibits:list<string>,
     *   recommendation:string,
     * }
     */
    public function evaluate(array $facts): array
    {
        $primaryAvailable = (bool) ($facts['primary_provider_available'] ?? true);
        $fallbackAvailable = (bool) ($facts['fallback_provider_available'] ?? false);
        $localAvailable = (bool) ($facts['local_client_available'] ?? false);
        $capabilityScore = (float) ($facts['model_capability_score'] ?? 1.0);
        $fullScore = (float) ($facts['full_capability_score'] ?? 1.0);

        $capabilityRatio = $fullScore > 0 ? $capabilityScore / $fullScore : 0.0;

        // Primary unavailable → try fallback before stopping
        if (! $primaryAvailable) {
            if ($fallbackAvailable) {
                return $this->envelope(
                    self::LEVEL_DEGRADED,
                    'enhanced_scaffold',
                    'double_proof_required',
                    'mandatory_peer_review',
                    'primary_unavailable_fallback_active'
                );
            }
            if ($localAvailable) {
                return $this->envelope(
                    self::LEVEL_LOCAL_FALLBACK,
                    'full_scaffold_required',
                    'triple_proof_required',
                    'mandatory_dual_review',
                    'primary_unavailable_local_fallback'
                );
            }
            // No fallback at all — but don't lower quality, just stop origination
            return $this->envelope(
                self::LEVEL_DEGRADED,
                'enhanced_scaffold',
                'double_proof_required',
                'mandatory_peer_review',
                'primary_unavailable_no_fallback_pause_origination'
            );
        }

        // Primary available but capability is degraded
        if ($capabilityRatio < 0.8) {
            return $this->envelope(
                self::LEVEL_DEGRADED,
                'enhanced_scaffold',
                'double_proof_required',
                'mandatory_peer_review',
                'degraded_capability_raise_requirements'
            );
        }

        // Full capability
        return $this->envelope(
            self::LEVEL_FULL,
            'standard_scaffold',
            'standard_proof',
            'standard_review',
            'full_capability_continue'
        );
    }

    private function envelope(string $level, string $scaffold, string $proof, string $review, string $recommendation): array
    {
        // Degradation NEVER permits these regardless of level
        $prohibits = [
            'proxy_tasks',
            'vague_acceptance_criteria',
            'test_only_packets',
        ];

        return [
            'schema' => self::SCHEMA,
            'level' => $level,
            'scaffold_requirement' => $scaffold,
            'proof_requirement' => $proof,
            'review_requirement' => $review,
            'prohibits' => $prohibits,
            'recommendation' => $recommendation,
        ];
    }
}
