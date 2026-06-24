<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\TaskClassDiscovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopProjectionOutcomeLedger;

final class AtlasLoopTaskClassMiner
{
    private readonly AtlasLoopTaskClassMinerSupport $support;

    public function __construct(
        private readonly AtlasLoopProjectionOutcomeLedger $ledger = new AtlasLoopProjectionOutcomeLedger,
        ?AtlasLoopTaskClassMinerSupport $support = null,
    ) {
        $this->support = $support ?? new AtlasLoopTaskClassMinerSupport;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return list<array{
     *   cluster_id:string,
     *   member_packet_ids:list<string>,
     *   cohesion_metric:float,
     *   support_count:int,
     *   success_rate:float,
     *   evidence_kind_histogram:array<string,int>
     * }>
     */
    public function mine(string $campaignId, array $records): array
    {
        $outcomeIndex = $this->support->indexOutcomeLabels($this->ledger->read($campaignId));
        if ($outcomeIndex === []) {
            return [];
        }

        $clusters = [];
        foreach ($records as $record) {
            if (! is_array($record)) {
                continue;
            }

            $packetId = $this->support->packetId($record);
            $clusterKey = $this->support->clusterKey($record);
            $targetPath = $this->support->targetPath($record);
            $outcomeLabel = $outcomeIndex[$targetPath] ?? '';

            if ($packetId === '' || $clusterKey === '' || $targetPath === '' || $outcomeLabel === '') {
                continue;
            }

            $clusters[$clusterKey][] = [
                'packet_id' => $packetId,
                'evidence_kinds' => $this->support->evidenceKinds($record),
                'outcome_label' => $outcomeLabel,
            ];
        }

        ksort($clusters);

        $facts = [];
        foreach ($clusters as $clusterKey => $members) {
            if (count($members) < 3) {
                continue;
            }

            $labels = [];
            $packetIds = [];
            $histogram = [];
            $successCount = 0;

            foreach ($members as $member) {
                $labels[$member['outcome_label']] = true;
                $packetIds[] = $member['packet_id'];
                if ($member['outcome_label'] === 'success') {
                    $successCount++;
                }

                foreach ($member['evidence_kinds'] as $kind) {
                    $histogram[$kind] = ($histogram[$kind] ?? 0) + 1;
                }
            }

            if (count($labels) > 1) {
                continue;
            }

            sort($packetIds);
            ksort($histogram);
            $supportCount = count($members);

            $facts[] = [
                'cluster_id' => hash('sha256', json_encode([
                    'cluster_key' => $clusterKey,
                    'member_packet_ids' => $packetIds,
                ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
                'member_packet_ids' => $packetIds,
                'cohesion_metric' => $this->support->cohesionMetric($members),
                'support_count' => $supportCount,
                'success_rate' => round($successCount / $supportCount, 4),
                'evidence_kind_histogram' => $histogram,
            ];
        }

        usort($facts, static fn (array $left, array $right): int => strcmp($left['cluster_id'], $right['cluster_id']));

        return $facts;
    }
}
