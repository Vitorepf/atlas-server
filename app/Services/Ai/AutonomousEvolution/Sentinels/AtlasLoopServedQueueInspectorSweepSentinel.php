<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Sentinels;

use App\Services\Ai\SelfConstruction\AgentControlPlaneTaskPacketQueueRepository;
use App\Services\Ai\SelfConstruction\AtlasTaskPacketQualityInspector;
use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;

/**
 * REGRESSION SENTINEL — closes the gap where 18 broken packets (all sharing
 * `test_evidence_without_test_in_allowed_files`) were served on the LIVE claimable queue because the quality
 * inspector was added AFTER those packets were already enqueued.
 *
 * It reads the live serving queue via AgentControlPlaneTaskPacketQueueRepository::list(['status'=>'claimable'])
 * — no mock — runs every claimable packet through AtlasTaskPacketQualityInspector::inspect(), and asserts ZERO
 * blocking deficiencies across the whole set. Any offender is reported with its id + blocking_deficiencies
 * (a FACTS-only verdict; no scalar score). This would have caught the 18 broken packets before they were
 * claimable.
 */
final class AtlasLoopServedQueueInspectorSweepSentinel
{
    public const SCHEMA = 'atlas.loop.served_queue_quality_sweep.v1';

    public function __construct(
        private readonly ?AgentControlPlaneTaskPacketQueueRepository $queue = null,
        private readonly ?AtlasTaskPacketQualityInspector $inspector = null,
    ) {}

    /**
     * Inspect every CLAIMABLE packet on the live queue.
     *
     * @return array{schema:string, clean:bool, scanned:int, offenders:list<array{id:string, blocking_deficiencies:list<string>}>}
     */
    public function sweep(): array
    {
        $queue = $this->queue ?? new AgentControlPlaneTaskPacketQueueRepository(AtlasTaskServingStack::disk());
        $inspector = $this->inspector ?? new AtlasTaskPacketQualityInspector;

        $scanned = 0;
        $offenders = [];
        foreach ($queue->list(['status' => 'claimable']) as $record) {
            $packet = (array) (is_array($record) ? ($record['task_packet'] ?? []) : []);
            if ($packet === []) {
                continue;
            }
            $scanned++;

            $blocking = array_values((array) ($inspector->inspect($packet)['blocking_deficiencies'] ?? []));
            if ($blocking !== []) {
                $offenders[] = [
                    'id' => (string) ($packet['task_packet_id'] ?? ($record['task_packet_id'] ?? '')),
                    'blocking_deficiencies' => $blocking,
                ];
            }
        }

        usort($offenders, static fn (array $a, array $b): int => strcmp((string) $a['id'], (string) $b['id']));

        return [
            'schema' => self::SCHEMA,
            'clean' => $offenders === [],
            'scanned' => $scanned,
            'offenders' => $offenders,
        ];
    }
}
