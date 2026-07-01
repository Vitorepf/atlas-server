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
        $missingEvidence = [];
        if (! array_key_exists('before', $input) || ! is_array($input['before'])) {
            $missingEvidence[] = 'missing_baseline_snapshot';
        }
        if (! array_key_exists('after', $input) || ! is_array($input['after'])) {
            $missingEvidence[] = 'missing_current_snapshot';
        }
        if (! array_key_exists('evidence_fresh', $input) || $input['evidence_fresh'] !== true) {
            $missingEvidence[] = 'missing_or_stale_evidence_freshness';
        }
        if ($missingEvidence !== []) {
            return [
                'schema'                      => self::SCHEMA,
                'autonomy_delta'              => 0.0,
                'regression_flags'            => $missingEvidence,
                'improvement_flags'           => [],
                'severity'                    => self::SEVERITY_CRITICAL,
                'required_repair_task_family' => self::REPAIR_FAMILY[self::SEVERITY_CRITICAL],
                'regression_status'           => 'fail_closed_missing_evidence',
                'worsened_dimensions'         => [],
                'blocking_reason'             => 'refusing to declare pass without complete before/after/evidence_fresh evidence: '.implode(', ', $missingEvidence),
            ];
        }

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

        $worsenedDimensions = [];
        if ($humanDelta > 0) {
            $worsenedDimensions[] = 'human_dependency';
        }
        if ($operatorDelta > 0) {
            $worsenedDimensions[] = 'human_dependency';
        }
        if ($effectiveProviderDelta > 0 || $manualDelta > 0) {
            $worsenedDimensions[] = 'provider_dependency';
        }
        if ($assumptionDelta > 0) {
            $worsenedDimensions[] = 'stale_evidence';
        }

        // Stale evidence: medium — evidence freshness regressed.
        $staleEvidenceDelta = $this->delta($after, $before, 'stale_evidence_count');
        if ($staleEvidenceDelta > 0) {
            $flags[] = "stale_evidence_count_increased_by_{$staleEvidenceDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_MEDIUM);
            $negativeScore += $staleEvidenceDelta;
            $worsenedDimensions[] = 'stale_evidence';
        }

        // Proxy proof: high — accepted "looks done" evidence in place of real proof.
        $proxyProofDelta = $this->delta($after, $before, 'proxy_proof_count');
        if ($proxyProofDelta > 0) {
            $flags[] = "proxy_proof_count_increased_by_{$proxyProofDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_HIGH);
            $negativeScore += $proxyProofDelta;
            $worsenedDimensions[] = 'proxy_proof';
        }

        // Queue poison risk: critical — the queue itself can be silently poisoned.
        $poisonRiskDelta = $this->delta($after, $before, 'queue_poison_risk_count');
        if ($poisonRiskDelta > 0) {
            $flags[] = "queue_poison_risk_count_increased_by_{$poisonRiskDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_CRITICAL);
            $negativeScore += $poisonRiskDelta;
            $worsenedDimensions[] = 'queue_poison_risk';
        }

        // Neutral-refactor guard: a change with zero penalty deltas is ONLY a true
        // non-regression when capability and evidence coverage were preserved too.
        $capabilityDelta = $this->delta($after, $before, 'capability_count');
        $evidenceCoverageDelta = $this->delta($after, $before, 'evidence_coverage_count');
        if ($capabilityDelta < 0) {
            $flags[] = "capability_count_decreased_by_{$capabilityDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_MEDIUM);
            $negativeScore += abs($capabilityDelta);
            $worsenedDimensions[] = 'capability_coverage';
        }
        if ($evidenceCoverageDelta < 0) {
            $flags[] = "evidence_coverage_count_decreased_by_{$evidenceCoverageDelta}";
            $severity = $this->worseSeverity($severity, self::SEVERITY_MEDIUM);
            $negativeScore += abs($evidenceCoverageDelta);
            $worsenedDimensions[] = 'evidence_coverage';
        }

        // Atlas native paths proven: positive signal (improvement_flags, not regression).
        $nativeDelta = $this->delta($after, $before, 'atlas_native_paths_proven');
        if ($nativeDelta > 0) {
            $improvementFlags[] = "atlas_native_paths_proven_increased_by_{$nativeDelta}";
            $positiveScore += $nativeDelta;
        }

        $autonomyDelta = $this->computeAutonomyDelta($positiveScore, $negativeScore);

        $worsenedDimensions = array_values(array_unique($worsenedDimensions));
        $regressionStatus = match (true) {
            $flags !== [] => 'regression',
            $autonomyDelta > 0.0 => 'improvement',
            default => 'none',
        };
        $blockingReason = in_array($severity, [self::SEVERITY_CRITICAL, self::SEVERITY_HIGH], true)
            ? sprintf('autonomy regression severity=%s across dimensions: %s', $severity, implode(', ', $worsenedDimensions))
            : null;

        return [
            'schema'                      => self::SCHEMA,
            'autonomy_delta'              => $autonomyDelta,
            'regression_flags'            => $flags,
            'improvement_flags'           => $improvementFlags,
            'severity'                    => $severity,
            'required_repair_task_family' => self::REPAIR_FAMILY[$severity] ?? null,
            'regression_status'           => $regressionStatus,
            'worsened_dimensions'         => $worsenedDimensions,
            'blocking_reason'             => $blockingReason,
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
