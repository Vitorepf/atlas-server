<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\KnowledgeSync;

/**
 * Gates originator context on fresh task outcome knowledge so stale
 * Code Intelligence or memory snapshots cannot steer new batches.
 *
 * Read-only: never starts processes, never calls providers.
 */
final class AtlasKnowledgeSyncTaskOutcomeFreshnessGate
{
    public const SCHEMA = 'atlas.self_construction.knowledge_sync_task_outcome_freshness_gate.v1';

    public const STALE_THRESHOLD_SECONDS = 3600; // 1 hour

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function evaluate(array $input): array
    {
        $outcomeTimestamp = (string) ($input['outcome_timestamp'] ?? '');
        $syncedTimestamp = (string) ($input['synced_timestamp'] ?? '');
        $referenceTime = (string) ($input['reference_time'] ?? 'now');

        $blockers = [];

        $outcomeFresh = $this->isFresh($outcomeTimestamp, $referenceTime);
        $syncedFresh = $this->isFresh($syncedTimestamp, $referenceTime);

        if (! $outcomeFresh) {
            $blockers[] = 'stale_outcome_timestamp';
        }
        if (! $syncedFresh) {
            $blockers[] = 'stale_synced_timestamp';
        }

        $fresh = $blockers === [];

        return [
            'schema' => self::SCHEMA,
            'fresh' => $fresh,
            'blockers' => $blockers,
            'outcome_timestamp' => $outcomeTimestamp,
            'synced_timestamp' => $syncedTimestamp,
            'outcome_fresh' => $outcomeFresh,
            'synced_fresh' => $syncedFresh,
            'stale_threshold_seconds' => self::STALE_THRESHOLD_SECONDS,
        ];
    }

    private function isFresh(string $timestamp, string $referenceTime): bool
    {
        if ($timestamp === '') {
            return false;
        }

        try {
            $ts = strtotime($timestamp);
            $ref = $referenceTime === 'now' ? time() : strtotime($referenceTime);

            if ($ts === false || $ref === false) {
                return false;
            }

            return ($ref - $ts) <= self::STALE_THRESHOLD_SECONDS;
        } catch (\Throwable) {
            return false;
        }
    }
}
