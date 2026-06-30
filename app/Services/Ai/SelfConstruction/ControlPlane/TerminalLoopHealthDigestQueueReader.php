<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use Closure;

/**
 * Queue-tag-aware read-model access for the Agent Control Plane terminal-loop
 * health digest.
 *
 * Extracted from AgentControlPlaneTerminalLoopHealthDigestService to reduce
 * the god-class. Pure functions over a queue repository (passed in by the
 * orchestrator) and an optional list-of-records.
 */
final class TerminalLoopHealthDigestQueueReader
{
    /**
     * @param  array<string, mixed>  $item
     * @return list<string>
     */
    public static function recordQueueTags(array $item): array
    {
        return array_values(array_map('strval', (array) ($item['queue_tags'] ?? [])));
    }

    /**
     * @param  list<string>  $queueTags
     */
    public static function countQueueRecords(AgentControlPlaneTaskPacketQueueRepository $queue, string $status, array $queueTags): int
    {
        return count(self::listQueueRecords($queue, $status, $queueTags));
    }

    /**
     * @param  list<string>  $queueTags
     * @return list<array<string, mixed>>
     */
    public static function listQueueRecords(AgentControlPlaneTaskPacketQueueRepository $queue, string $status, array $queueTags): array
    {
        if ($queueTags === []) {
            return $queue->list(['status' => $status]);
        }

        return $queue->list([
            'status' => $status,
            'tag' => $queueTags[0],
            'tags' => $queueTags,
        ]);
    }

    /**
     * Aggregate a flat list of queue records into a health digest.
     *
     * @param  list<array<string, mixed>>  $records
     * @return array{queue_depth:int, servable_depth:int, active_leases:int, recoverables:int, blocked_pressure:int, malformed_risk:int, is_healthy:bool, is_dry:bool, provider_safe:bool}
     */
    public static function digest(array $records): array
    {
        $servable  = self::classificationCount($records, 'servable', []);
        $malformed = self::classificationCount($records, 'malformed', []);

        return [
            'queue_depth'      => count($records),
            'servable_depth'   => $servable,
            'active_leases'    => self::classificationCount($records, 'leased', []),
            'recoverables'     => self::classificationCount($records, 'recoverable', []),
            'blocked_pressure' => self::classificationCount($records, 'blocked', []),
            'malformed_risk'   => $malformed,
            'is_healthy'       => count($records) > 0 && $servable > 0 && $malformed === 0,
            'is_dry'           => count($records) === 0 || $servable === 0,
            'provider_safe'    => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $classifications
     * @param  list<string>  $queueTags
     */
    public static function classificationCount(array $classifications, string $classification, array $queueTags): int
    {
        return count(array_filter(
            $classifications,
            static function (array $item) use ($classification, $queueTags): bool {
                if ((string) ($item['classification'] ?? '') !== $classification) {
                    return false;
                }
                if ($queueTags === []) {
                    return true;
                }

                $tags = (array) data_get($item, 'queue_tags', []);

                return array_diff($queueTags, array_map('strval', $tags)) === [];
            },
        ));
    }
}