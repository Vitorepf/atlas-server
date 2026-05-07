<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Cognitive\SRL\SRLPhaseController;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class SRLPhaseAppropriateGate
{
    public function __construct(
        private readonly SRLPhaseController $controller,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $episode
     * @return array<string,mixed>
     */
    public function evaluate(array $episode, string $requestedPhase): array
    {
        return $this->slo->measure('cognitive.srl.gate', function () use ($episode, $requestedPhase): array {
            $transition = $this->controller->evaluate($episode, $requestedPhase);

            return [
                'schema_version' => 'atlas.gate.srl_phase_appropriate.v1',
                'gate' => 'srl_phase_appropriate',
                'status' => $transition['status'],
                'reason' => $transition['reason'],
                'current_phase' => $transition['current_phase'],
                'requested_phase' => $transition['requested_phase'],
            ];
        }, [
            'domain' => (string) ($episode['domain'] ?? 'learning'),
        ]);
    }
}
