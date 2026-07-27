<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ControlPlane;

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

    private const STATUS_TO_CLASSIFICATION = [
        'queued' => 'servable',
        'claimable' => 'servable',
        'claimed' => 'leased',
        'lease_expired' => 'recoverable',
        'released' => 'recoverable',
        'blocked' => 'blocked',
        'cancelled' => 'cancelled',
        'completed_dry_run' => 'completed',
    ];

    /**
     * Read the live queue through the repository and produce a terminal-safe digest.
     * Fails closed: if the repository is unavailable or throws, the returned digest carries
     * `source_available=false` and a diagnostic reason instead of a fake-green empty digest.
     *
     * @param  list<string>  $queueTags
     * @return array{schema:string, source_available:bool, diagnostic:?string, queue_depth:int, servable_depth:int, active_leases:int, recoverables:int, blocked_pressure:int, malformed_risk:int, is_healthy:bool, is_dry:bool, provider_safe:bool}
     */
    public static function digestFromQueue(AgentControlPlaneTaskPacketQueueRepository $queue, array $queueTags = []): array
    {
        try {
            $records = [];
            foreach (self::listQueueRecords($queue, '', []) as $record) {
                $status = (string) ($record['status'] ?? '');
                $record['classification'] = self::STATUS_TO_CLASSIFICATION[$status] ?? 'malformed';
                if ($record['classification'] === 'malformed' || ! self::isWellFormedPacketRecord($record)) {
                    $record['classification'] = 'malformed';
                }
                $records[] = $record;
            }
        } catch (\Throwable $e) {
            return [
                'schema' => 'atlas.self_construction.terminal_loop_health_digest_queue_reader.v1',
                'source_available' => false,
                'diagnostic' => 'queue_source_unavailable: '.$e->getMessage(),
                'queue_depth' => 0,
                'servable_depth' => 0,
                'active_leases' => 0,
                'recoverables' => 0,
                'blocked_pressure' => 0,
                'malformed_risk' => 0,
                'is_healthy' => false,
                'is_dry' => false,
                'provider_safe' => true,
            ];
        }

        $digest = self::digest($records);
        $digest['schema'] = 'atlas.self_construction.terminal_loop_health_digest_queue_reader.v1';
        $digest['source_available'] = true;
        $digest['diagnostic'] = null;

        return $digest;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private static function isWellFormedPacketRecord(array $record): bool
    {
        $taskPacket = is_array($record['task_packet'] ?? null) ? $record['task_packet'] : [];

        return ($record['task_packet_id'] ?? '') !== ''
            && ($record['task_packet_hash'] ?? '') !== ''
            && ($taskPacket['objective'] ?? '') !== ''
            && is_array($taskPacket['allowed_files'] ?? null)
            && $taskPacket['allowed_files'] !== [];
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

                $tags = (array) data_get($item, 'queue_tags', data_get($item, 'tags', []));

                return array_diff($queueTags, array_map('strval', $tags)) === [];
            },
        ));
    }
}