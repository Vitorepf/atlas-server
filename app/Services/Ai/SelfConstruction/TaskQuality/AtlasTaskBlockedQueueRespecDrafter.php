<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskQuality;

/**
 * Turns classified blocked packet records into sorted replacement task drafts.
 *
 * INPUT: classified records from AtlasTaskBlockedPacketFamilyClassifier::classifyBatch() with optional
 * extra fields: allowed_files (list<string>) for proxy cluster dedup; behavior_matrix_size (int) for
 * microtest promotion gating.
 *
 * OUTPUT: { schema, drafts, waves, summary } — waves groups drafts by wave int, depends_on on each
 * draft lists draft_ids of all drafts in the preceding wave (muscle scheduling hint).
 *
 * RULES:
 *   - duplicate_already_done            → retire_only  (wave 1)
 *   - dormant_cli_arm_proxy             → implementation_or_contract_task (wave 2), deduped by
 *                                         allowed_files target; same target → one draft, all ids merged
 *   - test_only_microtask_requires_contract → implementation_or_contract_task (wave 2) only when
 *                                         behavior_matrix_size >= STRONG_MATRIX_THRESHOLD; else skipped
 *   - respec_candidate / repeated_give_back / unknown → review_recommended (wave 3)
 *
 * INVARIANTS: pure, deterministic, never mutates queue status, never enqueues packets, never writes files.
 */
final class AtlasTaskBlockedQueueRespecDrafter
{
    public const SCHEMA = 'atlas.task_quality.blocked_queue_respec_draft.v1';

    private const STRONG_MATRIX_THRESHOLD = 3;

    /**
     * @param  list<array<string,mixed>>  $classifiedRecords
     * @return array{schema:string, drafts:list<array<string,mixed>>, waves:array<int,list<array<string,mixed>>>, summary:array<string,int>}
     */
    public function draft(array $classifiedRecords): array
    {
        $byFamily = [];
        foreach ($classifiedRecords as $record) {
            if (! is_array($record)) {
                continue;
            }
            $family = (string) ($record['family'] ?? 'unknown');
            $byFamily[$family][] = $record;
        }

        $drafts = [];
        $summary = [];

        // Wave 1: retire_only — duplicates are already done, retire without re-enqueueing.
        foreach ($byFamily[AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DUPLICATE_ALREADY_DONE] ?? [] as $record) {
            $id = (string) ($record['task_packet_id'] ?? '');
            $summary['retire_only'] = ($summary['retire_only'] ?? 0) + 1;
            $drafts[] = $this->makeDraft('retire_only', 1, [$id], 'duplicate already resolved — retire without re-enqueueing');
        }

        // Wave 2a: implementation_or_contract_task — dormant CLI arms, deduped by allowed_files target.
        $proxyGroups = [];
        foreach ($byFamily[AtlasTaskBlockedPacketFamilyClassifier::FAMILY_DORMANT_CLI_ARM_PROXY] ?? [] as $record) {
            $key = $this->proxyClusterKey($record);
            $proxyGroups[$key][] = (string) ($record['task_packet_id'] ?? '');
        }
        ksort($proxyGroups, SORT_STRING);
        foreach ($proxyGroups as $target => $ids) {
            sort($ids, SORT_STRING);
            $count = count($ids);
            $summary['implementation_or_contract_task'] = ($summary['implementation_or_contract_task'] ?? 0) + $count;
            $noun = $count > 1 ? "{$count} packets deduplicated" : '1 packet';
            $drafts[] = $this->makeDraft(
                'implementation_or_contract_task', 2, $ids,
                "wire or implement dormant CLI arm: {$target} ({$noun})"
            );
        }

        // Wave 2b: promote microtest only when behavior_matrix_size is strong; else skip silently.
        foreach ($byFamily[AtlasTaskBlockedPacketFamilyClassifier::FAMILY_TEST_ONLY_MICROTASK] ?? [] as $record) {
            $matrixSize = (int) ($record['behavior_matrix_size'] ?? 0);
            $id = (string) ($record['task_packet_id'] ?? '');
            if ($matrixSize >= self::STRONG_MATRIX_THRESHOLD) {
                $summary['implementation_or_contract_task'] = ($summary['implementation_or_contract_task'] ?? 0) + 1;
                $drafts[] = $this->makeDraft(
                    'implementation_or_contract_task', 2, [$id],
                    "microtest with strong behavior matrix (size={$matrixSize}): promote to implementation task"
                );
            } else {
                // ponytail: singleton padding — counted but no draft emitted; callers can check summary['skip']
                $summary['skip'] = ($summary['skip'] ?? 0) + 1;
            }
        }

        // Wave 3: review_recommended — non-automatable, need manual respec or operator review.
        foreach ([
            AtlasTaskBlockedPacketFamilyClassifier::FAMILY_RESPEC_CANDIDATE,
            AtlasTaskBlockedPacketFamilyClassifier::FAMILY_REPEATED_GIVE_BACK,
            AtlasTaskBlockedPacketFamilyClassifier::FAMILY_UNKNOWN,
        ] as $family) {
            foreach ($byFamily[$family] ?? [] as $record) {
                $id = (string) ($record['task_packet_id'] ?? '');
                $summary['review_recommended'] = ($summary['review_recommended'] ?? 0) + 1;
                $drafts[] = $this->makeDraft('review_recommended', 3, [$id], "family={$family}: manual respec or operator review required");
            }
        }

        // Sort: wave ASC, then draft_id ASC for determinism.
        usort($drafts, static fn (array $a, array $b): int => [$a['wave'], $a['draft_id']] <=> [$b['wave'], $b['draft_id']]);

        // Inject depends_on: wave N drafts depend on ALL draft_ids in wave N-1.
        $byWave = [];
        foreach ($drafts as $d) {
            $byWave[(int) $d['wave']][] = $d['draft_id'];
        }
        ksort($byWave);
        $prevIds = [];
        foreach (array_keys($byWave) as $w) {
            foreach ($drafts as &$d) {
                if ((int) $d['wave'] === $w) {
                    $d['depends_on'] = $prevIds;
                }
            }
            unset($d);
            $prevIds = $byWave[$w];
        }

        // Group into waves output.
        $wavesOut = [];
        foreach ($drafts as $d) {
            $wavesOut[(int) $d['wave']][] = $d;
        }
        ksort($wavesOut);
        ksort($summary);

        return [
            'schema' => self::SCHEMA,
            'drafts' => $drafts,
            'waves' => $wavesOut,
            'summary' => $summary,
        ];
    }

    private function proxyClusterKey(array $record): string
    {
        $files = is_array($record['allowed_files'] ?? null) ? $record['allowed_files'] : [];
        if ($files !== []) {
            sort($files, SORT_STRING);

            return implode(',', $files);
        }

        return (string) ($record['task_packet_id'] ?? '');
    }

    private function makeDraft(string $kind, int $wave, array $sourceIds, string $rationale): array
    {
        sort($sourceIds, SORT_STRING);
        $sourceIds = array_values(array_unique($sourceIds));
        $draftId = 'dr_'.substr(hash('sha256', $kind.'|'.$wave.'|'.implode(',', $sourceIds)), 0, 12);

        return [
            'draft_id' => $draftId,
            'kind' => $kind,
            'wave' => $wave,
            'source_packet_ids' => $sourceIds,
            'rationale' => $rationale,
            'depends_on' => [], // filled in by the wave-pass above
        ];
    }
}
