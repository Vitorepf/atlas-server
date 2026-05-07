<?php

namespace App\Services\Ai\Cognitive\SRL;

class SRLPerformanceObserver
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function observe(array $input): array
    {
        $load = strtolower(trim((string) ($input['cognitive_load'] ?? 'medium')));

        return [
            'at' => now()->toJSON(),
            'cognitive_load' => in_array($load, ['low', 'medium', 'high'], true) ? $load : 'medium',
            'self_rating' => min(5, max(1, (int) ($input['self_rating'] ?? 3))),
            'note' => trim((string) ($input['note'] ?? '')),
        ];
    }
}
