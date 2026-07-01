<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Autonomous simplification safety gate. Turns numeric blast-radius, worker-pressure,
 * test-proof and rollback-readiness facts into one of three wave decisions:
 *
 *   hold        — rollback is not ready, or there is no runnable test proof, or the queue
 *                 is already under too much worker pressure to absorb a risky wave.
 *   split_wave  — the candidate is high-value but its blast radius is too wide to land in
 *                 one shot; the gate demands prework (scoped, low-blast-radius slices)
 *                 before the full change is attempted.
 *   approve     — blast radius, worker pressure, test proof and rollback readiness are all
 *                 within budget; the wave can land as proposed.
 *
 * A wide blast radius WITHOUT proven high value is never split into "worth it later" —
 * it holds, because nothing justifies the risk yet.
 *
 * Pure PHP, deterministic, no I/O, no file mutations.
 */
final class AtlasExternalBrainWaveRiskBudgeter
{
    public const SCHEMA = 'atlas.external_brain.wave_risk_budgeter.v1';

    public const DECISION_APPROVE    = 'approve';
    public const DECISION_SPLIT_WAVE = 'split_wave';
    public const DECISION_HOLD       = 'hold';

    private const BLAST_RADIUS_THRESHOLD   = 0.50;
    private const WORKER_PRESSURE_THRESHOLD = 0.80;

    /**
     * @param  array<string,mixed>  $wave
     * @return array{schema:string, decision:string, reasons:list<string>, blast_radius:float, worker_pressure:float, prework_actions:list<string>}
     */
    public function evaluate(array $wave): array
    {
        $blastRadius     = max(0.0, min(1.0, (float) ($wave['blast_radius'] ?? 0.0)));
        $workerPressure  = max(0.0, min(1.0, (float) ($wave['worker_pressure'] ?? 0.0)));
        $hasRunnableTests = (bool) ($wave['has_runnable_tests'] ?? false);
        $rollbackReady   = (bool) ($wave['rollback_ready'] ?? false);
        $highValue       = (bool) ($wave['high_value'] ?? false);

        $blastRadiusThreshold   = (float) ($wave['blast_radius_threshold'] ?? self::BLAST_RADIUS_THRESHOLD);
        $workerPressureThreshold = (float) ($wave['worker_pressure_threshold'] ?? self::WORKER_PRESSURE_THRESHOLD);

        $reasons = [];

        // hold — a wave with no rollback plan, no proof, or an already-saturated queue can
        // never be approved or even partially split; there's nothing safe to attempt yet.
        if (! $rollbackReady) {
            $reasons[] = 'rollback_not_ready';
        }
        if (! $hasRunnableTests) {
            $reasons[] = 'missing_runnable_test_proof';
        }
        if ($workerPressure >= $workerPressureThreshold) {
            $reasons[] = sprintf('worker_pressure:%.4f>=%.2f', $workerPressure, $workerPressureThreshold);
        }

        $blastRadiusHigh = $blastRadius >= $blastRadiusThreshold;

        if ($reasons !== []) {
            return $this->result(self::DECISION_HOLD, $reasons, $blastRadius, $workerPressure, []);
        }

        // A wide blast radius without proven value never earns a split into "do it later" —
        // it holds until either the blast radius shrinks or real value is demonstrated.
        if ($blastRadiusHigh && ! $highValue) {
            return $this->result(self::DECISION_HOLD, [
                sprintf('blast_radius:%.4f>=%.2f', $blastRadius, $blastRadiusThreshold),
                'no_proven_value_to_justify_risk',
            ], $blastRadius, $workerPressure, []);
        }

        if ($blastRadiusHigh) {
            return $this->result(self::DECISION_SPLIT_WAVE, [
                sprintf('blast_radius:%.4f>=%.2f', $blastRadius, $blastRadiusThreshold),
                'high_value_candidate_requires_prework',
            ], $blastRadius, $workerPressure, [
                'scope_prework_slice_below_blast_radius_threshold',
                'prove_prework_slice_green_before_full_wave',
                'reassess_blast_radius_after_prework_lands',
            ]);
        }

        return $this->result(self::DECISION_APPROVE, ['within_risk_budget'], $blastRadius, $workerPressure, []);
    }

    /** @param  list<string>  $reasons @param  list<string>  $preworkActions */
    private function result(string $decision, array $reasons, float $blastRadius, float $workerPressure, array $preworkActions): array
    {
        return [
            'schema'          => self::SCHEMA,
            'decision'        => $decision,
            'reasons'         => $reasons,
            'blast_radius'    => round($blastRadius, 4),
            'worker_pressure' => round($workerPressure, 4),
            'prework_actions' => $preworkActions,
        ];
    }
}
