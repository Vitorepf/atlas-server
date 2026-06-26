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