<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * Verifies batch enqueue outcomes distinguish all-enqueued,
 * partial-conflict and rejected specs so originators cannot
 * over-credit partial rounds.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasTaskServingBatchAtomicityVerifier
{
    public const SCHEMA = 'atlas.self_construction.task_serving_batch_atomicity_verifier.v1';

    public const OUTCOME_ALL_ENQUEUED = 'all_enqueued';
    public const OUTCOME_PARTIAL_CONFLICT = 'partial_conflict';
    public const OUTCOME_ALL_REJECTED = 'all_rejected';

    /**
     * @param  array<int, array<string, mixed>>  $results
     * @return array<string, mixed>
     */
    public function verify(array $results): array
    {
        $enqueued = [];
        $rejected = [];
        $requestedCount = count($results);

        foreach ($results as $result) {
            if (! is_array($result)) {
                continue;
            }
            $packetId = (string) ($result['task_packet_id'] ?? '');
            $status = (string) ($result['status'] ?? '');

            if ($status === 'enqueued') {
                $enqueued[] = $packetId;
            } else {
                $rejected[] = [
                    'task_packet_id' => $packetId,
                    'reason' => $status,
                ];
            }
        }

        $creditedCount = count($enqueued);
        $rejectedCount = count($rejected);

        $outcome = match (true) {
            $creditedCount === $requestedCount && $requestedCount > 0 => self::OUTCOME_ALL_ENQUEUED,
            $creditedCount === 0 => self::OUTCOME_ALL_REJECTED,
            default => self::OUTCOME_PARTIAL_CONFLICT,
        };

        return [
            'schema' => self::SCHEMA,
            'outcome' => $outcome,
            'atomic' => $outcome === self::OUTCOME_ALL_ENQUEUED,
            'requested_count' => $requestedCount,
            'credited_count' => $creditedCount,
            'rejected_count' => $rejectedCount,
            'enqueued_packet_ids' => $enqueued,
            'rejected_packets' => $rejected,
            'over_credit_prevented' => $outcome !== self::OUTCOME_ALL_ENQUEUED,
        ];
    }
}
