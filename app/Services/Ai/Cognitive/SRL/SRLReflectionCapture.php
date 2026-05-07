<?php

namespace App\Services\Ai\Cognitive\SRL;

class SRLReflectionCapture
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        return [
            'what_worked' => trim((string) ($input['what_worked'] ?? '')),
            'what_didnt' => trim((string) ($input['what_didnt'] ?? '')),
            'adjustment_for_next' => trim((string) ($input['adjustment_for_next'] ?? $input['adjustment'] ?? '')),
            'surprise' => trim((string) ($input['surprise'] ?? '')),
            'captured_at' => now()->toJSON(),
        ];
    }
}
