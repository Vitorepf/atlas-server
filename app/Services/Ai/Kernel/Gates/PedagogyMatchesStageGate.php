<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class PedagogyMatchesStageGate
{
    public function __construct(private readonly KernelSloProbe $slo) {}

    /**
     * @param  array<string,mixed>  $payload
     * @return array{schema_version:string,gate:string,status:string,reason:string,stage:?int,mode:?string}
     */
    public function evaluate(array $payload): array
    {
        return $this->slo->measure('cognitive.dreyfus.gate', function () use ($payload): array {
            $cognitiveDecision = (array) ($payload['cognitive_decision'] ?? data_get($payload, 'metadata.cognitive_decision', []));
            $stage = $cognitiveDecision['dreyfus_stage_resolved'] ?? null;
            $mode = $cognitiveDecision['pedagogy_mode_resolved'] ?? null;

            if (! is_numeric($stage)) {
                return $this->result('blocked', 'pedagogy_matches_stage_missing_dreyfus_stage', null, is_string($mode) ? $mode : null);
            }

            $stage = (int) $stage;
            if ($stage < 1 || $stage > 5) {
                return $this->result('blocked', 'pedagogy_matches_stage_invalid_level', $stage, is_string($mode) ? $mode : null);
            }

            if (! is_string($mode) || trim($mode) === '' || $mode === 'auto') {
                return $this->result('blocked', 'pedagogy_matches_stage_unresolved_auto', $stage, is_string($mode) ? $mode : null);
            }

            return $this->result('passed', 'pedagogy_matches_stage_resolved', $stage, $mode);
        }, [
            'domain' => (string) ($payload['domain'] ?? 'learning'),
            'surface_id' => (string) ($payload['surface_id'] ?? 'atlas_kernel_gate'),
        ]);
    }

    /**
     * @return array{schema_version:string,gate:string,status:string,reason:string,stage:?int,mode:?string}
     */
    private function result(string $status, string $reason, ?int $stage, ?string $mode): array
    {
        return [
            'schema_version' => 'atlas.gate.pedagogy_matches_stage.v1',
            'gate' => 'pedagogy_matches_stage',
            'status' => $status,
            'reason' => $reason,
            'stage' => $stage,
            'mode' => $mode,
        ];
    }
}
