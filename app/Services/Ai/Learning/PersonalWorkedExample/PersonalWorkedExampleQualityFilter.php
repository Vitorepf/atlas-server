<?php

namespace App\Services\Ai\Cognitive\PersonalWorkedExample;

use App\Services\Ai\Kernel\Gates\PersonalWorkedExampleQualityGate;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class PersonalWorkedExampleQualityFilter
{
    public function __construct(
        private readonly PersonalWorkedExampleQualityGate $gate,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function evaluate(array $candidate): array
    {
        return $this->slo->measure('cognitive.personal_worked_example.quality_gate', fn (): array => $this->gate->evaluate($candidate), [
            'domain' => (string) ($candidate['domain'] ?? 'learning'),
            'source_type' => (string) ($candidate['source_type'] ?? 'unknown'),
        ]);
    }
}
