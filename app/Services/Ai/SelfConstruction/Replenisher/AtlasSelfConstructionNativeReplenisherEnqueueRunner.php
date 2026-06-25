<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Replenisher;

/**
 * Native replenisher enqueue runner. Accepts preflighted packet drafts + a top-up DECISION and calls
 * the orchestrator's `prepareAndEnqueue($packet)` ONLY for accepted drafts within the bounded count.
 *
 * INPUT:
 *   $accepted    — list<{packet:array, inspection:array}> (from preflight)
 *   $topUpDecision — { outcome:string, new_packet_count:int } (from queue-top-up policy)
 *   $orchestrator — object with a public method `prepareAndEnqueue(array $packet): array` that returns
 *                   one of {status:'enqueued',...} | {status:'existing',...} | {status:'prepare_blocked',...}
 *
 * OUTPUT:
 *   { schema, enqueued:list<string>, skipped_existing:list<string>, skipped_rejected:list<string>,
 *     prepare_blocked:list<{packet_id:string, reason:string}>, counts:array<string,int> }
 *
 * INVARIANTS:
 *   - Only ALLOW outcomes drive enqueue; other outcomes ⇒ counts.attempted=0.
 *   - DETERMINISTIC envelope (lists sorted byte-stably).
 *   - prepare_blocked is RECORDED as repair evidence — NEVER counted as success.
 */
final class AtlasSelfConstructionNativeReplenisherEnqueueRunner
{
    public const SCHEMA = 'atlas.replenisher.enqueue_runner.v1';

    /**
     * @param  list<array<string,mixed>>  $accepted
     * @param  array{outcome:string, new_packet_count:int}  $topUpDecision
     * @return array{schema:string, enqueued:list<string>, skipped_existing:list<string>, skipped_rejected:list<string>, prepare_blocked:list<array{packet_id:string, reason:string}>, counts:array<string,int>}
     */
    public function run(array $accepted, array $topUpDecision, object $orchestrator): array
    {
        $enqueued = [];
        $skippedExisting = [];
        $blocked = [];
        $skippedRejected = [];

        $outcome = (string) ($topUpDecision['outcome'] ?? '');
        $limit = (int) ($topUpDecision['new_packet_count'] ?? 0);

        if ($outcome !== AtlasSelfConstructionQueueTopUpPolicy::OUTCOME_ALLOW || $limit <= 0) {
            return $this->envelope($enqueued, $skippedExisting, $skippedRejected, $blocked, attempted: 0);
        }

        $attempted = 0;
        foreach ($accepted as $row) {
            if ($attempted >= $limit) {
                break;
            }
            if (! is_array($row) || ! isset($row['packet'])) {
                continue;
            }
            $packet = is_array($row['packet']) ? $row['packet'] : [];
            $id = (string) ($packet['frontier_id'] ?? ($packet['packet_id'] ?? ''));
            try {
                $result = $orchestrator->prepareAndEnqueue($packet);
            } catch (\Throwable $e) {
                $blocked[] = ['packet_id' => $id, 'reason' => 'orchestrator_threw:'.$e->getMessage()];

                continue;
            }
            $attempted++;
            $status = (string) ($result['status'] ?? '');
            if ($status === 'enqueued') {
                $enqueued[] = $id;
            } elseif ($status === 'existing') {
                $skippedExisting[] = $id;
            } elseif ($status === 'prepare_blocked') {
                $blocked[] = ['packet_id' => $id, 'reason' => (string) ($result['reason'] ?? 'unknown')];
            } else {
                $skippedRejected[] = $id;
            }
        }

        sort($enqueued, SORT_STRING);
        sort($skippedExisting, SORT_STRING);
        sort($skippedRejected, SORT_STRING);
        usort($blocked, static fn (array $a, array $b): int => strcmp($a['packet_id'], $b['packet_id']));

        return $this->envelope($enqueued, $skippedExisting, $skippedRejected, $blocked, attempted: $attempted);
    }

    /**
     * @param  list<string>  $enqueued
     * @param  list<string>  $skippedExisting
     * @param  list<string>  $skippedRejected
     * @param  list<array{packet_id:string, reason:string}>  $blocked
     * @return array{schema:string, enqueued:list<string>, skipped_existing:list<string>, skipped_rejected:list<string>, prepare_blocked:list<array{packet_id:string, reason:string}>, counts:array<string,int>}
     */
    private function envelope(array $enqueued, array $skippedExisting, array $skippedRejected, array $blocked, int $attempted): array
    {
        return [
            'schema' => self::SCHEMA,
            'enqueued' => $enqueued,
            'skipped_existing' => $skippedExisting,
            'skipped_rejected' => $skippedRejected,
            'prepare_blocked' => $blocked,
            'counts' => [
                'attempted' => $attempted,
                'enqueued' => count($enqueued),
                'skipped_existing' => count($skippedExisting),
                'skipped_rejected' => count($skippedRejected),
                'prepare_blocked' => count($blocked),
            ],
        ];
    }
}
