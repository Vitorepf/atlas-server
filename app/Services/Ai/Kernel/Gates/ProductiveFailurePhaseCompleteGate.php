<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class ProductiveFailurePhaseCompleteGate
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @param  array<string,mixed>  $session
     * @return array<string,mixed>
     */
    public function evaluate(array $session, string $advancingTo): array
    {
        return $this->slo->measure('cognitive.productive_failure.phase_gate', function () use ($session, $advancingTo): array {
            if ($advancingTo === 'phase_2' && empty($session['phase_1_attempt'])) {
                return $this->result('passed', 'productive_failure_phase_1_ready_for_attempt', $advancingTo);
            }

            if ($advancingTo === 'phase_2' && ! empty($session['phase_1_attempt'])) {
                return $this->result('blocked', 'productive_failure_phase_1_already_recorded', $advancingTo);
            }

            if ($advancingTo === 'phase_3' && empty($session['phase_1_attempt'])) {
                return $this->result('blocked', 'productive_failure_phase_1_attempt_missing', $advancingTo);
            }

            if ($advancingTo === 'phase_3' && ! empty($session['phase_2_comparison'])) {
                return $this->result('blocked', 'productive_failure_phase_2_already_recorded', $advancingTo);
            }

            if ($advancingTo === 'complete') {
                if (empty($session['phase_2_comparison'])) {
                    return $this->result('blocked', 'productive_failure_phase_2_comparison_missing', $advancingTo);
                }

                if (empty($session['phase_2_comparison']['prediction_error_delta'] ?? null)) {
                    return $this->result('blocked', 'productive_failure_prediction_error_delta_missing', $advancingTo);
                }

                if (empty($session['phase_3_articulation'])) {
                    return $this->result('passed', 'productive_failure_phase_3_ready_for_articulation', $advancingTo);
                }

                if (empty($session['phase_3_articulation']['model_update'] ?? null) || empty($session['phase_3_articulation']['principle_extracted'] ?? null)) {
                    return $this->result('blocked', 'productive_failure_phase_3_articulation_incomplete', $advancingTo);
                }
            }

            return $this->result('passed', 'productive_failure_phase_complete', $advancingTo);
        }, [
            'domain' => (string) ($session['domain'] ?? 'learning'),
            'session_id' => (string) ($session['id'] ?? 'unknown'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, string $advancingTo): array
    {
        return [
            'schema_version' => 'atlas.gate.productive_failure_phase_complete.v1',
            'gate' => 'productive_failure_phase_complete',
            'status' => $status,
            'reason' => $reason,
            'advancing_to' => $advancingTo,
        ];
    }
}
