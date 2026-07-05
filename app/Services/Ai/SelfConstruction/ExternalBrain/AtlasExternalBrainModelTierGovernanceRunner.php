<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Composes the four orphan model-tier governance organs into one
 * calibration+escalation verdict. Model amplification is gated by
 * measured tier quality instead of raw amplification.
 */
final class AtlasExternalBrainModelTierGovernanceRunner
{
    public const SCHEMA = 'atlas.external_brain.model_tier_governance_runner.v1';

    public const ACTION_ALLOW = 'allow_amplification';
    public const ACTION_HOLD = 'hold_amplification';

    /**
     * @param  array<string, mixed>  $input  facts forwarded to all four organs
     * @return array<string, mixed>
     */
    public function run(array $input): array
    {
        $weaknessGuard = new AtlasExternalBrainModelWeaknessGuard;
        $sloLedger = new AtlasExternalBrainModelQualitySloLedger;
        $calibrationLedger = new AtlasExternalBrainModelTierCalibrationLedger;
        $escalationCompiler = new AtlasExternalBrainModelTierEscalationPolicyCompiler;

        $weakness = $weaknessGuard->guard($input);
        $slo = $sloLedger->compute($input);
        $calibration = $calibrationLedger->calibrate($input);
        $escalation = $escalationCompiler->compile($input);

        // Determine overall action.
        $blockedUntilFixed = (bool) ($weakness['blocked_until_fixed'] ?? false);
        $hasFailingSegments = ($slo['failing_segment_count'] ?? 0) > 0;

        $action = self::ACTION_ALLOW;
        $blockers = [];

        if ($blockedUntilFixed) {
            $action = self::ACTION_HOLD;
            $blockers[] = 'weakness_guard_blocked';
        }

        if ($hasFailingSegments) {
            $action = self::ACTION_HOLD;
            $blockers[] = 'quality_slo_breach';
        }

        if (($escalation['escalation'] ?? false)) {
            $action = self::ACTION_HOLD;
            $blockers[] = 'escalation_policy_requires_frontier';
        }

        return [
            'schema_version' => self::SCHEMA,
            'action' => $action,
            'weakness_guard' => [
                'blocked_until_fixed' => $blockedUntilFixed,
                'weakness_count' => count((array) ($weakness['findings'] ?? [])),
                'findings' => array_map(static fn (array $f): array => [
                    'weakness' => (string) ($f['weakness'] ?? ''),
                    'severity' => (string) ($f['severity'] ?? ''),
                    'detail' => (string) ($f['detail'] ?? ''),
                ], (array) ($weakness['findings'] ?? [])),
            ],
            'quality_slo' => [
                'failing_segment_count' => $hasFailingSegments ? $slo['failing_segment_count'] : 0,
                'insufficient_segment_count' => (int) ($slo['insufficient_segment_count'] ?? 0),
                'green_segment_count' => (int) ($slo['green_segment_count'] ?? 0),
                'failing_segments' => (array) ($slo['failing_segments'] ?? []),
            ],
            'calibrated_tier' => [
                'tier_stats' => (array) ($calibration['tier_stats'] ?? []),
                'routing_recommendations' => (array) ($calibration['routing_recommendations'] ?? []),
                'under_sampled_segments' => (array) ($calibration['under_sampled_segments'] ?? []),
            ],
            'escalation_policy' => [
                'recommended_tier' => (string) ($escalation['recommended_tier'] ?? ''),
                'reason' => (string) ($escalation['reason'] ?? ''),
                'escalation' => (bool) ($escalation['escalation'] ?? false),
                'analysis' => (array) ($escalation['analysis'] ?? []),
            ],
            'blockers' => $blockers,
        ];
    }
}
