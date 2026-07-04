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
    private const LOW_COVERAGE_THRESHOLD = 50.0;

    /**
     * @param  array{
     *   total_organs?: int,
     *   integration_coverage_percent?: float,
     *   blocked_organs?: list<mixed>,
     *   ornamental_organs?: list<mixed>,
     *   stop_go_decision?: string,
     *   stop_go_reasons?: list<string>,
     *   next_action?: string,
     * }  $convergenceVerdict
     * @return array{
     *   schema: string,
     *   integration_coverage_percent: float,
     *   effective_integration_coverage_percent: float,
     *   simplification_pressure: string,
     *   blocked_organ_ratio: float,
     *   ornamental_organ_ratio: float,
     *   stop_go_decision: string,
     *   reasons: list<string>,
     *   next_runtime_action: string,
     *   readiness_band: string,
     * }
     */
    public function translate(array $convergenceVerdict): array
    {
        $totalOrgans = max(0, (int) ($convergenceVerdict['total_organs'] ?? 0));
        $integrationCoverage = max(0.0, min(100.0, (float) ($convergenceVerdict['integration_coverage_percent'] ?? 100.0)));
        $blockedOrgans = array_values((array) ($convergenceVerdict['blocked_organs'] ?? []));
        $ornamentalOrgans = array_values((array) ($convergenceVerdict['ornamental_organs'] ?? []));
        $stopGoDecision = (string) ($convergenceVerdict['stop_go_decision'] ?? 'go');
        $reasons = array_values((array) ($convergenceVerdict['stop_go_reasons'] ?? []));

        $blockedRatio = $totalOrgans > 0 ? round(count($blockedOrgans) / $totalOrgans, 4) : 0.0;
        $ornamentalRatio = $totalOrgans > 0 ? round(count($ornamentalOrgans) / $totalOrgans, 4) : 0.0;
        $simplificationPressure = $blockedRatio >= self::HIGH_BLOCKED_RATIO_THRESHOLD ? 'high' : 'low';

        // Ornamental organs (present but non-functional) and blocked organs (present but
        // unwired) never inflate reported coverage — both discount the effective figure the
        // control plane actually trusts.
        $effectiveIntegrationCoverage = round($integrationCoverage * (1.0 - $ornamentalRatio) * (1.0 - $blockedRatio), 4);

        if ($ornamentalOrgans !== [] && ! in_array('ornamental_organs_present', $reasons, true)) {
            $reasons[] = 'ornamental_organs_present';
        }
        if ($blockedOrgans !== [] && ! in_array('blocked_organs_present', $reasons, true)) {
            $reasons[] = 'blocked_organs_present';
        }

        // Derive actionable next_runtime_action from signals
        $nextRuntimeAction = $this->resolveNextRuntimeAction(
            $integrationCoverage,
            $blockedOrgans,
            $ornamentalOrgans,
            $stopGoDecision,
            $simplificationPressure
        );

        // Compute readiness_band — can be downgraded by simplification pressure
        $readinessBand = $this->resolveReadinessBand(
            $integrationCoverage,
            $effectiveIntegrationCoverage,
            $stopGoDecision,
            $simplificationPressure
        );

        return [
            'schema' => self::SCHEMA,
            'integration_coverage_percent' => $integrationCoverage,
            'effective_integration_coverage_percent' => $effectiveIntegrationCoverage,
            'simplification_pressure' => $simplificationPressure,
            'blocked_organ_ratio' => $blockedRatio,
            'ornamental_organ_ratio' => $ornamentalRatio,
            'stop_go_decision' => $stopGoDecision,
            'reasons' => $reasons,
            'next_runtime_action' => $nextRuntimeAction,
            'readiness_band' => $readinessBand,
        ];
    }

    /**
     * Resolve the next actionable runtime step from convergence signals.
     *
     * @param list<mixed> $blockedOrgans
     * @param list<mixed> $ornamentalOrgans
     */
    private function resolveNextRuntimeAction(
        float $integrationCoverage,
        array $blockedOrgans,
        array $ornamentalOrgans,
        string $stopGoDecision,
        string $simplificationPressure
    ): string {
        // Stop decision always wins — halt and diagnose
        if ($stopGoDecision === 'stop') {
            return 'stop_and_diagnose: critical convergence failure requires human review';
        }

        // High simplification pressure → consolidate before more work
        if ($simplificationPressure === 'high') {
            return 'consolidate_blocked_organs: reduce sprawl before originating new capability';
        }

        // Ornamental organs present → rewire to make them functional
        if ($ornamentalOrgans !== []) {
            return 'rewire_ornamental_organs: make non-functional organs operational';
        }

        // Low coverage → integrate more organs
        if ($integrationCoverage < self::LOW_COVERAGE_THRESHOLD) {
            return 'integrate_missing_organs: expand coverage to acceptable floor';
        }

        // Healthy — just monitor
        return 'monitor: convergence is healthy, continue normal operations';
    }

    /**
     * Resolve readiness band, downgrading for simplification pressure.
     */
    private function resolveReadinessBand(
        float $integrationCoverage,
        float $effectiveIntegrationCoverage,
        string $stopGoDecision,
        string $simplificationPressure
    ): string {
        // Stop decision → always blocked
        if ($stopGoDecision === 'stop') {
            return 'blocked';
        }

        // Very low effective coverage → blocked
        if ($effectiveIntegrationCoverage < 30.0) {
            return 'blocked';
        }

        // High simplification pressure downgrades readiness even if raw coverage is high
        if ($simplificationPressure === 'high') {
            return 'degraded';
        }

        // Low effective coverage → degraded
        if ($effectiveIntegrationCoverage < 60.0) {
            return 'degraded';
        }

        return 'ready';
    }
}
