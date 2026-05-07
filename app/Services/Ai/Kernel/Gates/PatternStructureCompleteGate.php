<?php

namespace App\Services\Ai\Kernel\Gates;

use App\Services\Ai\Cognitive\Pattern\ProcessPatternStructureValidator;
use App\Services\Ai\Kernel\Slo\KernelSloProbe;

class PatternStructureCompleteGate
{
    public function __construct(
        private readonly ProcessPatternStructureValidator $validator,
        private readonly KernelSloProbe $slo,
    ) {}

    /**
     * @param  array<string,mixed>  $pattern
     * @return array<string,mixed>
     */
    public function evaluate(array $pattern): array
    {
        return $this->slo->measure('cognitive.process_pattern.gate', function () use ($pattern): array {
            $validation = $this->validator->validate($pattern);

            return [
                'schema_version' => 'atlas.gate.pattern_structure_complete.v1',
                'gate' => 'pattern_structure_complete',
                'status' => $validation['status'],
                'reason' => $validation['reason'],
            ];
        }, ['domain' => 'learning']);
    }
}
