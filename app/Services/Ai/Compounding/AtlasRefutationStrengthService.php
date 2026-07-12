<?php

declare(strict_types=1);

namespace App\Services\Ai\Compounding;

use App\Models\AiLearningCandidate;

final class AtlasRefutationStrengthService
{
    /**
     * @return array<string,mixed>
     */
    public function forCandidate(AiLearningCandidate $candidate): array
    {
        $payload = is_array($candidate->payload) ? $candidate->payload : [];
        $recurrence = max(
            1,
            (int) data_get($payload, 'repeated_failure_count', 0),
            (int) data_get($payload, 'repetition_count', 0),
            (int) data_get($payload, 'case_count', 0),
            count(array_unique(array_map('strval', (array) ($candidate->evidence_refs ?? [])))),
        );
        $severity = $this->severity($payload);
        $avoidedCost = max(0.0, min(1.0, (float) data_get($payload, 'avoided_cost_normalized', 0.0)));

        $strength = min(1.0, round(
            min(1.0, $recurrence / 3) * 0.5
            + ($severity / 3) * 0.4
            + $avoidedCost * 0.1,
            4,
        ));

        return [
            'schema' => 'atlas.refutation_strength.v1',
            'strength' => $strength,
            'denominator' => $recurrence,
            'components' => [
                'recurrence' => $recurrence,
                'severity' => $severity,
                'avoided_cost' => $avoidedCost,
            ],
            'source' => [
                'candidate_id' => (string) $candidate->getKey(),
                'candidate_hash' => (string) $candidate->candidate_hash,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function severity(array $payload): int
    {
        $status = mb_strtolower((string) (
            data_get($payload, 'outcome')
            ?? data_get($payload, 'outcome_status')
            ?? data_get($payload, 'status')
            ?? data_get($payload, 'caused_by.status')
            ?? 'give_back'
        ));

        return match (true) {
            str_contains($status, 'quarantine') => 3,
            str_contains($status, 'failed_gate') || str_contains($status, 'failed') => 2,
            default => 1,
        };
    }
}
