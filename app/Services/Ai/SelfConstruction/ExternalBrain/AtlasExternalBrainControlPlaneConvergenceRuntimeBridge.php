<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure bridge: translates an AtlasExternalBrainControlPlaneConvergenceCommand verdict
 * (integration_coverage_percent, blocked_organs, stop_go_decision/reasons) into the
 * runtime-ready signals AtlasExternalBrainUnifiedControlPlaneSnapshot::compose() consumes.
 *
 * A high ratio of blocked/ornamental organs is treated as simplification pressure —
 * sprawl that must be consolidated before more capability is originated — rather
 * than silently counted as delivered progress.
 *
 * Pure / deterministic. No I/O, no provider or operator dependency.
 */
final class AtlasExternalBrainControlPlaneConvergenceRuntimeBridge
{
    public const SCHEMA = 'atlas.external_brain.control_plane_convergence_runtime_bridge.v1';

    private const HIGH_BLOCKED_RATIO_THRESHOLD = 0.50;

    /**
     * @param  array{
     *   total_organs?: int,
     *   integration_coverage_percent?: float,
     *   blocked_organs?: list<mixed>,
     *   stop_go_decision?: string,
     *   stop_go_reasons?: list<string>,
     *   next_action?: string,
     * }  $convergenceVerdict
     * @return array{
     *   schema: string,
     *   integration_coverage_percent: float,
     *   simplification_pressure: string,
     *   blocked_organ_ratio: float,
     *   reasons: list<string>,
     * }
     */
    public function translate(array $convergenceVerdict): array
    {
        $totalOrgans = max(0, (int) ($convergenceVerdict['total_organs'] ?? 0));
        $integrationCoverage = max(0.0, min(100.0, (float) ($convergenceVerdict['integration_coverage_percent'] ?? 100.0)));
        $blockedOrgans = array_values((array) ($convergenceVerdict['blocked_organs'] ?? []));
        $reasons = array_values((array) ($convergenceVerdict['stop_go_reasons'] ?? []));

        $blockedRatio = $totalOrgans > 0 ? round(count($blockedOrgans) / $totalOrgans, 4) : 0.0;
        $simplificationPressure = $blockedRatio >= self::HIGH_BLOCKED_RATIO_THRESHOLD ? 'high' : 'low';

        return [
            'schema' => self::SCHEMA,
            'integration_coverage_percent' => $integrationCoverage,
            'simplification_pressure' => $simplificationPressure,
            'blocked_organ_ratio' => $blockedRatio,
            'reasons' => $reasons,
        ];
    }
}
