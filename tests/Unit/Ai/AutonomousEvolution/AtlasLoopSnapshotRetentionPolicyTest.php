<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Retention\AtlasLoopSnapshotRetentionPolicy;
use Tests\TestCase;

final class AtlasLoopSnapshotRetentionPolicyTest extends TestCase
{
    /** @return list<string> */
    private function snapshotIds(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = 'snap-'.str_pad((string) $i, 3, '0', STR_PAD_LEFT);
        }

        return $out;
    }

    public function test_keep_last_n_zero_falls_through_to_every_mth(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 0, keepEveryMth: 3);
        $verdict = $policy->classify($this->snapshotIds(7));

        $kept = array_column(array_filter($verdict['entries'], static fn (array $e): bool => $e['status'] === 'keep'), 'snapshot_id');
        // every 3rd from the boundary: indexes 0, 3, 6
        $this->assertSame(['snap-000', 'snap-003', 'snap-006'], $kept);
    }

    public function test_every_mth_equals_one_keeps_everything(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 1, keepEveryMth: 1);
        $verdict = $policy->classify($this->snapshotIds(5));

        foreach ($verdict['entries'] as $entry) {
            $this->assertSame('keep', $entry['status']);
        }
        $this->assertSame(5, $verdict['keep_count']);
        $this->assertSame(0, $verdict['prune_candidate_count']);
    }

    public function test_keep_last_n_smaller_than_total_classifies_older_with_every_mth(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 3, keepEveryMth: 2);
        $ids = $this->snapshotIds(9);
        $verdict = $policy->classify($ids);

        // First 3 are within_keep_last_n.
        foreach ([0, 1, 2] as $i) {
            $this->assertSame('keep', $verdict['entries'][$i]['status']);
            $this->assertSame('within_keep_last_n', $verdict['entries'][$i]['reason']);
        }
        // Older boundary slice indexes 3..8 → olderIndex 0..5; keep when olderIndex % 2 === 0
        // → original indexes 3 (olderIdx 0), 5 (olderIdx 2), 7 (olderIdx 4) kept.
        $this->assertSame('keep', $verdict['entries'][3]['status']);
        $this->assertSame('every_mth_kept', $verdict['entries'][3]['reason']);
        $this->assertSame('prune_candidate', $verdict['entries'][4]['status']);
        $this->assertSame('keep', $verdict['entries'][5]['status']);
        $this->assertSame('prune_candidate', $verdict['entries'][6]['status']);
        $this->assertSame('keep', $verdict['entries'][7]['status']);
        $this->assertSame('prune_candidate', $verdict['entries'][8]['status']);
    }

    public function test_keep_last_n_greater_or_equal_total_keeps_everything(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 10, keepEveryMth: 4);
        $verdict = $policy->classify($this->snapshotIds(5));

        foreach ($verdict['entries'] as $entry) {
            $this->assertSame('keep', $entry['status']);
            $this->assertSame('within_keep_last_n', $entry['reason']);
        }
    }

    public function test_every_mth_greater_than_remainder_keeps_only_boundary(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 2, keepEveryMth: 99);
        $ids = $this->snapshotIds(5);
        $verdict = $policy->classify($ids);

        // First 2 within_keep_last_n; older indexes 2..4 (olderIdx 0..2); only olderIdx 0 kept (index 2).
        $this->assertSame('keep', $verdict['entries'][0]['status']);
        $this->assertSame('keep', $verdict['entries'][1]['status']);
        $this->assertSame('keep', $verdict['entries'][2]['status']);
        $this->assertSame('every_mth_kept', $verdict['entries'][2]['reason']);
        $this->assertSame('prune_candidate', $verdict['entries'][3]['status']);
        $this->assertSame('prune_candidate', $verdict['entries'][4]['status']);
    }

    public function test_deterministic_replay_produces_byte_identical_output(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 4, keepEveryMth: 3);
        $ids = $this->snapshotIds(12);

        $a = $policy->classify($ids);
        $b = $policy->classify($ids);

        $this->assertSame(
            json_encode($a, JSON_UNESCAPED_SLASHES),
            json_encode($b, JSON_UNESCAPED_SLASHES),
        );
    }

    public function test_empty_input_produces_empty_classification(): void
    {
        $policy = new AtlasLoopSnapshotRetentionPolicy(keepLastN: 3, keepEveryMth: 2);
        $verdict = $policy->classify([]);

        $this->assertSame([], $verdict['entries']);
        $this->assertSame(0, $verdict['total']);
        $this->assertSame(0, $verdict['keep_count']);
        $this->assertSame(0, $verdict['prune_candidate_count']);
    }
}
