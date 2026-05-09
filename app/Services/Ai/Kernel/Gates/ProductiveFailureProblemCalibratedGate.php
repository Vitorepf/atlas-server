<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class ProductiveFailureProblemCalibratedGate
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @param  array<string,mixed>  $problemPayload
     * @return array<string,mixed>
     */
    public function evaluate(array $problemPayload, int $dreyfusStage): array
    {
        return $this->slo->measure('cognitive.productive_failure.calibration_gate', function () use ($problemPayload, $dreyfusStage): array {
            $stage = max(1, min(5, $dreyfusStage));
            $difficulty = (int) ($problemPayload['expected_difficulty'] ?? 0);
            $min = $stage;
            $max = min(5, $stage + 2);

            if ($difficulty < $min || $difficulty > $max) {
                return $this->result('blocked', 'productive_failure_problem_outside_calibration_band', $stage, $difficulty, [$min, $max]);
            }

            if (trim((string) ($problemPayload['prediction_prompt'] ?? '')) === '') {
                return $this->result('blocked', 'productive_failure_problem_prompt_missing', $stage, $difficulty, [$min, $max]);
            }

            return $this->result('passed', 'productive_failure_problem_calibrated', $stage, $difficulty, [$min, $max]);
        }, [
            'domain' => (string) ($problemPayload['domain'] ?? 'learning'),
            'source' => (string) ($problemPayload['source'] ?? 'unknown'),
        ]);
    }

    /**
     * @param  array{0:int,1:int}  $band
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, int $stage, int $difficulty, array $band): array
    {
        return [
            'schema_version' => 'atlas.gate.productive_failure_problem_calibrated.v1',
            'gate' => 'productive_failure_problem_calibrated',
            'status' => $status,
            'reason' => $reason,
            'dreyfus_stage' => $stage,
            'expected_difficulty' => $difficulty,
            'allowed_band' => $band,
        ];
    }
}
