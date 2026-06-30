<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Pure oracle. Detects when a proposed task or recent implementation makes the
 * external brain less autonomous by comparing before/after capability snapshots.
 *
 * Regression detection (after > before = regression):
 *   human_in_steady_state          → critical  — zero autonomy without humans
 *   operator_in_steady_state       → high       — requires operator attention
 *   provider_in_steady_state       → high       — external provider in steady state
 *   manual_only_decisions          → high       — decisions that can only be made manually
 *   unverified_runtime_assumptions → medium     — assumptions not grounded in evidence
 *
 * Improvement signals (after > before = positive):
 *   atlas_native_paths_proven      → reduces autonomy_delta penalty
 *
 * AC2: bootstrap_seams with is_bootstrap_only=true AND atlas_native_path_proven=true
 * are NOT counted as provider_in_steady_state regressions.
 *
 * severity hierarchy: critical > high > medium > low > none
 * required_repair_task_family: emitted for severity ≥ medium.
 *
 * Pure: no I/O, no side effects.
 */
final class AtlasExternalBrainAutonomyRegressionOracle
{
    public const SCHEMA = 'atlas.external_brain.autonomy_regression_oracle.v1';

    public const SEVERITY_CRITICAL = 'critical';
    public const SEVERITY_HIGH     = 'high';
    public const SEVERITY_MEDIUM   = 'medium';
    public const SEVERITY_LOW      = 'low';
    public const SEVERITY_NONE     = 'none';

    private const REPAIR_FAMILY = [
        self::SEVERITY_CRITICAL => 'autonomy_blocking_human_dependency_removal',
        self::SEVERITY_HIGH     => 'autonomy_blocking_provider_operator_removal',
        self::SEVERITY_MEDIUM   => 'autonomy_risk_assumption_verification',
    ];

    /**
     * @param  array{
     *   before?: array<string,int>,
     *   after?: array<string,int>,
     *   bootstrap_seams?: list<array{dependency_type?:string,is_bootstrap_only?:bool,atlas_native_path_proven?:bool}>,
     * }  $input
     * @return array{schema:string, autonomy_delta:float, regression_flags:list<string>, severity:string, required_repair_task_family:string|null}
     */
    public function assess(array $input): array
    {
        $before = (array) ($input['before'] ?? []);
        $after  = (array) ($input['after']  ?? []);
        $seams  = (array) ($input['bootstrap_seams'] ?? []);

        $provenBootstrapProviderCount = $this->countProvenBootstrapProviderSeams($seams);

        $flags            = [];
        $improvementFlags = [];
        $severity         = self::SEVERITY_NONE;
        $negativeScore    = 0;
        $positiveScore    = 0;

        // Human steady-state: critical
        $humanDelta = $this->delta($after, $before, 'human_in_steady_state');
        if ($humanDelta > 0) {
            $flags[]  = "human_in_steady_state_increased_by_{$humanDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_CRITICAL);
            $negativeScore += $humanDelta;
        }

        // Operator steady-state: high
        $operatorDelta = $this->delta($after, $before, 'operator_in_steady_state');
        if ($operatorDelta > 0) {
            $flags[]  = "operator_in_steady_state_increased_by_{$operatorDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_HIGH);
            $negativeScore += $operatorDelta;
        }

        // Provider steady-state: high (minus proven bootstrap seams)
        $providerDelta = $this->delta($after, $before, 'provider_in_steady_state');
        $effectiveProviderDelta = max(0, $providerDelta - $provenBootstrapProviderCount);
        if ($effectiveProviderDelta > 0) {
            $flags[]  = "provider_in_steady_state_increased_by_{$effectiveProviderDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_HIGH);
            $negativeScore += $effectiveProviderDelta;
        }

        // Manual-only decisions: high
        $manualDelta = $this->delta($after, $before, 'manual_only_decisions');
        if ($manualDelta > 0) {
            $flags[]  = "manual_only_decisions_increased_by_{$manualDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_HIGH);
            $negativeScore += $manualDelta;
        }

        // Unverified runtime assumptions: medium
        $assumptionDelta = $this->delta($after, $before, 'unverified_runtime_assumptions');
        if ($assumptionDelta > 0) {
            $flags[]  = "unverified_runtime_assumptions_increased_by_{$assumptionDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_MEDIUM);
            $negativeScore += $assumptionDelta;
        }

        // Atlas native paths proven: positive signal (improvement_flags, not regression).
        $nativeDelta = $this->delta($after, $before, 'atlas_native_paths_proven');
        if ($nativeDelta > 0) {
            $improvementFlags[] = "atlas_native_paths_proven_increased_by_{$nativeDelta}";
            $positiveScore += $nativeDelta;
        }

        $autonomyDelta = $this->computeAutonomyDelta($positiveScore, $negativeScore);

        return [
            'schema'                      => self::SCHEMA,
            'autonomy_delta'              => $autonomyDelta,
            'regression_flags'            => $flags,
            'improvement_flags'           => $improvementFlags,
            'severity'                    => $severity,
            'required_repair_task_family' => self::REPAIR_FAMILY[$severity] ?? null,
        ];
    }

    private function delta(array $after, array $before, string $key): int
    {
        return (int) ($after[$key] ?? 0) - (int) ($before[$key] ?? 0);
    }

    private function countProvenBootstrapProviderSeams(array $seams): int
    {
        $count = 0;
        foreach ($seams as $seam) {
            if (! is_array($seam)) {
                continue;
            }
            $depType     = trim((string) ($seam['dependency_type']          ?? ''));
            $bootstrap   = (bool) ($seam['is_bootstrap_only']               ?? false);
            $nativeProven = (bool) ($seam['atlas_native_path_proven']       ?? false);

            if (in_array($depType, ['external_provider', 'claude_codex'], true) && $bootstrap && $nativeProven) {
                $count++;
            }
        }

        return $count;
    }

    private function worseSeverity(string $current, string $candidate): string
    {
        $order = [
            self::SEVERITY_NONE     => 0,
            self::SEVERITY_LOW      => 1,
            self::SEVERITY_MEDIUM   => 2,
            self::SEVERITY_HIGH     => 3,
            self::SEVERITY_CRITICAL => 4,
        ];

        return ($order[$candidate] ?? 0) > ($order[$current] ?? 0) ? $candidate : $current;
    }

    private function computeAutonomyDelta(int $positiveScore, int $negativeScore): float
    {
        $total = $positiveScore + $negativeScore;
        if ($total === 0) {
            return 0.0;
        }

        $net = $positiveScore - $negativeScore;

        return round(max(-1.0, min(1.0, $net / max(1, $total))), 4);
    }
}
