<?php

namespace App\Services\Ai\Cognitive\ProductiveFailure;

class ProductiveFailureAttemptCapture
{
    /**
     * @return array<string,mixed>
     */
    public function capture(array $input): array
    {
        $prediction = trim((string) ($input['operator_prediction'] ?? $input['prediction'] ?? ''));
        $attempt = trim((string) ($input['solution_attempt'] ?? $input['attempt'] ?? ''));

        return [
            'schema_version' => 'atlas.cognitive.productive_failure.attempt.v1',
            'operator_prediction' => $prediction,
            'solution_attempt' => $attempt,
            'time_spent_min' => max(0, (int) ($input['time_spent_min'] ?? 0)),
            'confidence_pre' => max(1, min(5, (int) ($input['confidence_pre'] ?? 3))),
            'surrender_reason' => trim((string) ($input['surrender_reason'] ?? '')) ?: null,
            'attempt_complete' => $prediction !== '' && $attempt !== '',
            'captured_at' => now()->toIso8601String(),
        ];
    }
}
