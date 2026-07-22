<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

class ProductiveFailureArticulationCapture
{
    /**
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        return [
            'schema_version' => 'atlas.cognitive.productive_failure.articulation.v1',
            'model_update' => trim((string) ($input['model_update'] ?? $input['insight'] ?? '')),
            'key_insight' => trim((string) ($input['key_insight'] ?? $input['insight'] ?? '')),
            'why_attempt_failed' => trim((string) ($input['why_attempt_failed'] ?? $input['why_failed'] ?? '')),
            'principle_extracted' => trim((string) ($input['principle_extracted'] ?? $input['principle'] ?? '')),
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
