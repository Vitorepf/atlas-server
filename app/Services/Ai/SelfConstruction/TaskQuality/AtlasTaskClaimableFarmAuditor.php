<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

use App\Services\Ai\SelfConstruction\AtlasTaskServingStack;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricSemanticDuplicateIndex;
use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricTemplateFarmSimilarityGate;

/**
 * Retroactive queue-hygiene organ: audits the LIVE claimable pool for template-farm near-duplicate
 * packets that already made it into the queue, composing the two existing similarity gates
 * ({@see AtlasTaskFabricTemplateFarmSimilarityGate}, {@see AtlasTaskFabricSemanticDuplicateIndex})
 * pairwise rather than reimplementing similarity logic.
 *
 * Two claimable packets cluster together when EITHER gate flags them as near-identical, comparing
 * ONLY objective + allowed_files (acceptance_criteria in this queue is boilerplate — nearly every
 * packet shares "php artisan test ... exits 0" — so including it would false-positive on
 * genuinely unrelated work):
 *   - the template-farm gate's pairwise assess() reports blocking=true, OR
 *   - the semantic duplicate index's check() reports status=semantic_duplicate
 *     (capability_key/target_family are derived from objective + first allowed_files directory
 *     since claimable packets don't carry those fields natively).
 *
 * Clustering is transitive (union-find): if A~B and B~C, all three land in one cluster even when
 * A~C was never directly flagged. Within a cluster, the KEPT member is the one with the most
 * specific proof surface — highest allowed_files_count + acceptance_criteria_count, packet_id
 * ASC as a deterministic tiebreak. Every other member becomes a retired_candidate.
 *
 * Dry mode (default) computes the full cluster report and mutates NOTHING. Apply mode transitions
 * every retired_candidate claimable→blocked with metadata reason=template_farm_cluster via the
 * queue repo, leaving {@see \App\Console\Commands\AtlasTaskRetireCommand} free to later cancel
 * them. Packets outside any cluster, and packets in any other status (already blocked/claimed/
 * quarantined), are never touched.
 */
final class AtlasTaskClaimableFarmAuditor
{
    public const SCHEMA = 'atlas.self_construction.task_quality.claimable_farm_auditor.v1';

    public const BLOCK_REASON = 'template_farm_cluster';

    public function __construct(
        private readonly ?AtlasTaskFabricTemplateFarmSimilarityGate $similarityGate = null,
        private readonly ?AtlasTaskFabricSemanticDuplicateIndex $duplicateIndex = null,
    ) {
    }

    /**
     * @return array{schema:string, dry_run:bool, clusters:list<array<string,mixed>>, decisions:list<array<string,mixed>>}
     */
    public function audit(bool $apply = false): array
    {
        $similarityGate = $this->similarityGate ?? new AtlasTaskFabricTemplateFarmSimilarityGate;
        $duplicateIndex = $this->duplicateIndex ?? new AtlasTaskFabricSemanticDuplicateIndex;

        $queue = AtlasTaskServingStack::queueRepo();
        $records = $queue->list(['status' => 'claimable']);

        $packets = [];
        foreach ($records as $record) {
            $taskPacketId = (string) ($record['task_packet_id'] ?? '');
            if ($taskPacketId === '') {
                continue;
            }
            $taskPacket = (array) ($record['task_packet'] ?? []);
            $allowedFiles = array_values(array_map('strval', (array) ($taskPacket['allowed_files'] ?? [])));
            $acceptanceCriteria = array_values(array_map('strval', (array) ($taskPacket['acceptance_criteria'] ?? [])));

            $packets[] = [
                'task_packet_id' => $taskPacketId,
                'objective' => (string) ($taskPacket['objective'] ?? ''),
                'allowed_files' => $allowedFiles,
                'acceptance_criteria' => $acceptanceCriteria,
            ];
        }

        $count = count($packets);
        $parent = range(0, max(0, $count - 1));
        $find = function (int $x) use (&$parent, &$find): int {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x = $parent[$x];
            }

            return $x;
        };
        $union = function (int $a, int $b) use (&$parent, $find): void {
            $rootA = $find($a);
            $rootB = $find($b);
            if ($rootA !== $rootB) {
                $parent[max($rootA, $rootB)] = min($rootA, $rootB);
            }
        };

        $pairEvidence = [];
        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                // Deliberately compares objective + allowed_files only — acceptance_criteria in this
                // queue is boilerplate ("php artisan test ... exits 0") shared by nearly every
                // packet, so including it would false-positive on genuinely unrelated work.
                $gateResult = $similarityGate->assess([
                    ['objective' => $packets[$i]['objective'], 'allowed_files' => $packets[$i]['allowed_files']],
                    ['objective' => $packets[$j]['objective'], 'allowed_files' => $packets[$j]['allowed_files']],
                ]);

                $candidateShape = $this->duplicateShape($packets[$i]);
                $existingShape = $this->duplicateShape($packets[$j]);
                $duplicateResult = $duplicateIndex->check($candidateShape, [$existingShape]);

                $isMatch = (bool) $gateResult['blocking'] || $duplicateResult['status'] === AtlasTaskFabricSemanticDuplicateIndex::DUPLICATE_SEMANTIC;
                if (! $isMatch) {
                    continue;
                }

                $union($i, $j);
                $pairKey = min($find($i), $find($j));
                $pairEvidence[$pairKey][] = [
                    'similarity_score' => (float) $gateResult['similarity_score'],
                    'duplicate_reasons' => (array) $duplicateResult['reasons'],
                ];
            }
        }

        $groups = [];
        for ($i = 0; $i < $count; $i++) {
            $groups[$find($i)][] = $i;
        }

        $clusters = [];
        foreach ($groups as $root => $indices) {
            if (count($indices) < 2) {
                continue; // singleton — not a cluster, untouched
            }

            $members = array_map(fn (int $idx): array => $packets[$idx], $indices);
            usort($members, static function (array $a, array $b): int {
                $specificityA = count($a['allowed_files']) + count($a['acceptance_criteria']);
                $specificityB = count($b['allowed_files']) + count($b['acceptance_criteria']);

                return $specificityB <=> $specificityA ?: strcmp($a['task_packet_id'], $b['task_packet_id']);
            });

            $kept = $members[0]['task_packet_id'];
            $retiredCandidates = array_values(array_map(
                static fn (array $m): string => $m['task_packet_id'],
                array_slice($members, 1),
            ));

            $evidenceForCluster = $pairEvidence[$root] ?? [];
            $maxScore = 0.0;
            $duplicateReasons = [];
            foreach ($evidenceForCluster as $ev) {
                $maxScore = max($maxScore, $ev['similarity_score']);
                $duplicateReasons = array_merge($duplicateReasons, $ev['duplicate_reasons']);
            }
            $duplicateReasons = array_values(array_unique($duplicateReasons));
            sort($duplicateReasons, SORT_STRING);

            $clusters[] = [
                'kept' => $kept,
                'retired_candidates' => $retiredCandidates,
                'similarity_evidence' => [
                    'max_similarity_score' => round($maxScore, 3),
                    'duplicate_reasons' => $duplicateReasons,
                ],
            ];
        }

        usort($clusters, static fn (array $a, array $b): int => strcmp($a['kept'], $b['kept']));

        $decisions = [];
        foreach ($clusters as $cluster) {
            foreach ($cluster['retired_candidates'] as $retiredId) {
                if (! $apply) {
                    $decisions[] = ['task_packet_id' => $retiredId, 'action' => 'retire_plan', 'reason' => self::BLOCK_REASON];

                    continue;
                }

                $result = $queue->updateStatus($retiredId, 'blocked', ['reason' => self::BLOCK_REASON]);
                $decisions[] = [
                    'task_packet_id' => $retiredId,
                    'action' => (string) ($result['status'] ?? '') === 'ok' ? 'blocked' : 'failed',
                    'reason' => self::BLOCK_REASON,
                ];
            }
        }

        return [
            'schema' => self::SCHEMA,
            'dry_run' => ! $apply,
            'clusters' => $clusters,
            'decisions' => $decisions,
        ];
    }

    /** @param  array{objective:string, allowed_files:list<string>}  $packet
     * @return array{capability_key:string, target_family:string, allowed_files:list<string>}
     */
    private function duplicateShape(array $packet): array
    {
        $firstFile = $packet['allowed_files'][0] ?? '';

        return [
            'capability_key' => $packet['objective'],
            'target_family' => $firstFile !== '' ? dirname($firstFile) : '',
            'allowed_files' => $packet['allowed_files'],
        ];
    }
}
