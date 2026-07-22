<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Retention;

/**
 * Pure declarative snapshot-retention policy.
 *
 * Rule:
 *   - The K most-recent snapshots (newest-first) are ALWAYS kept.
 *   - Older snapshots are kept only every Mth (counted from the boundary).
 *
 * No I/O. No DB. No clock. No filesystem. Identical inputs → byte-identical outputs.
 * Consumed by AtlasLoopSnapshotRetentionGc and AtlasLoopSnapshotRetentionCli — the
 * single canonical predicate so retention math is never inlined.
 */
final class AtlasLoopSnapshotRetentionPolicy
{
    public const SCHEMA = 'atlas.loop.snapshot_retention_policy.v1';

    public const REASON_WITHIN_KEEP_LAST_N = 'within_keep_last_n';

    public const REASON_EVERY_MTH_KEPT = 'every_mth_kept';

    public const REASON_PRUNEABLE = 'pruneable_between_window';

    public const STATUS_KEEP = 'keep';

    public const STATUS_PRUNE_CANDIDATE = 'prune_candidate';

    public function __construct(
        public readonly int $keepLastN,
        public readonly int $keepEveryMth,
    ) {}

    /**
     * Classify a chronologically ordered list of snapshot ids (newest first).
     *
     * @param  list<string>  $snapshotsNewestFirst
     * @return array<string,mixed>
     */
    public function classify(array $snapshotsNewestFirst): array
    {
        $keepLast = max(0, $this->keepLastN);
        $keepEvery = max(1, $this->keepEveryMth);

        $entries = [];
        $keepCount = 0;
        $pruneCount = 0;
        $olderIndex = 0; // 0-based offset INTO the older-than-window slice

        foreach (array_values($snapshotsNewestFirst) as $index => $snapshotId) {
            $snapshotId = (string) $snapshotId;
            if ($index < $keepLast) {
                $entries[] = [
                    'snapshot_id' => $snapshotId,
                    'status' => self::STATUS_KEEP,
                    'reason' => self::REASON_WITHIN_KEEP_LAST_N,
                    'index' => $index,
                ];
                $keepCount++;

                continue;
            }
            // Older-than-window: keep every Mth, counted from the boundary.
            // olderIndex 0 = first older snapshot. We keep when olderIndex % M === 0
            // — that means the boundary snapshot AND every Mth one after it survive.
            if (($olderIndex % $keepEvery) === 0) {
                $entries[] = [
                    'snapshot_id' => $snapshotId,
                    'status' => self::STATUS_KEEP,
                    'reason' => self::REASON_EVERY_MTH_KEPT,
                    'index' => $index,
                ];
                $keepCount++;
            } else {
                $entries[] = [
                    'snapshot_id' => $snapshotId,
                    'status' => self::STATUS_PRUNE_CANDIDATE,
                    'reason' => self::REASON_PRUNEABLE,
                    'index' => $index,
                ];
                $pruneCount++;
            }
            $olderIndex++;
        }

        return [
            'schema_version' => self::SCHEMA,
            'keep_last_n' => $keepLast,
            'keep_every_mth' => $keepEvery,
            'total' => count($entries),
            'keep_count' => $keepCount,
            'prune_candidate_count' => $pruneCount,
            'entries' => $entries,
        ];
    }
}
