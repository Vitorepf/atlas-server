<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class WorkedExampleAppropriateForStageGate
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    public function evaluate(array $payload): array
    {
        return $this->slo->measure('cognitive.worked_example.gate', function () use ($payload): array {
            $fadingLevel = (int) ($payload['fading_level_resolved'] ?? data_get($payload, 'fading.fading_level_resolved', 0));
            $dreyfusStage = (int) ($payload['dreyfus_stage'] ?? data_get($payload, 'fading.dreyfus_stage', 0));

            if ($fadingLevel < 1 || $fadingLevel > 5) {
                return $this->result('blocked', 'worked_example_invalid_fading_level', $fadingLevel, $dreyfusStage);
            }

            if ($dreyfusStage === 5 && $fadingLevel !== 5) {
                return $this->result('blocked', 'worked_example_unnecessary_for_master_stage', $fadingLevel, $dreyfusStage);
            }

            return $this->result('passed', 'worked_example_stage_appropriate', $fadingLevel, $dreyfusStage);
        }, [
            'domain' => (string) ($payload['domain'] ?? 'learning'),
            'surface_id' => (string) ($payload['surface_id'] ?? 'atlas_kernel_gate'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    private function result(string $status, string $reason, int $fadingLevel, int $dreyfusStage): array
    {
        return [
            'schema_version' => 'atlas.gate.worked_example_appropriate_for_stage.v1',
            'gate' => 'worked_example_appropriate_for_stage',
            'status' => $status,
            'reason' => $reason,
            'fading_level_resolved' => $fadingLevel,
            'dreyfus_stage' => $dreyfusStage,
        ];
    }
}
