<?php

namespace App\Services\Ai\Cognitive\SRL;

class SRLForethoughtCapture
{
    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        return [
            'objective' => trim((string) ($input['objective'] ?? '')),
            'expected_difficulty' => $this->rating($input['expected_difficulty'] ?? 3),
            'strategy_chosen' => trim((string) ($input['strategy_chosen'] ?? $input['strategy'] ?? 'explore')),
            'planned_duration_min' => max(1, (int) ($input['planned_duration_min'] ?? $input['duration_min'] ?? 30)),
            'captured_at' => now()->toJSON(),
        ];
    }

    private function rating(mixed $value): int
    {
        return min(5, max(1, (int) $value));
    }
}
