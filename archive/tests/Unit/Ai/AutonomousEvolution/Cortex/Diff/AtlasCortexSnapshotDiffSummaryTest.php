<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Cortex\Diff;

use App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Diff\AtlasCortexSnapshotDiffSummary;
use Tests\TestCase;

final class AtlasCortexSnapshotDiffSummaryTest extends TestCase
{
    private const FORBIDDEN_KEYS = [
        'total_changes',
        'total',
        'severity',
        'score',
        'weight',
        'importance',
        'improvement_score',
    ];

    private function emptyDiff(): array
    {
        return [
            'left' => ['snapshot_id' => 'snap-a'],
            'right' => ['snapshot_id' => 'snap-b'],
            'inventory' => ['added' => [], 'removed' => [], 'shape_changed' => []],
            'orphans' => ['appeared' => [], 'resolved' => []],
            'edges' => ['added' => [], 'removed' => []],
            'clone_clusters' => ['appeared' => [], 'dissolved' => [], 'shape_changed' => []],
            'forbidden' => ['added' => [], 'removed' => []],
            'doc_stated_gaps' => ['opened' => [], 'closed' => []],
        ];
    }

    public function test_zero_transition_diff_yields_all_zero_counts_and_empty_categories(): void
    {
        $verdict = (new AtlasCortexSnapshotDiffSummary)->summarize($this->emptyDiff());

        foreach ([
            'inventory_added', 'inventory_removed', 'inventory_shape_changed',
            'orphans_appeared', 'orphans_resolved',
            'edges_added', 'edges_removed',
            'clone_clusters_appeared', 'clone_clusters_dissolved', 'clone_clusters_shape_changed',
            'forbidden_added', 'forbidden_removed',
            'doc_stated_gaps_opened', 'doc_stated_gaps_closed',
        ] as $category) {
            $this->assertSame(0, $verdict[$category], "{$category} must be 0 on empty diff");
        }
        $this->assertSame([], $verdict['nonEmptyCategories']);
    }

    public function test_no_aggregate_scalar_keys_emitted(): void
    {
        $verdict = (new AtlasCortexSnapshotDiffSummary)->summarize($this->emptyDiff());
        $keys = array_keys($verdict);
        foreach (self::FORBIDDEN_KEYS as $forbidden) {
            $this->assertNotContains($forbidden, $keys, "summary must NOT emit forbidden aggregate key: {$forbidden}");
        }
    }

    public function test_inventory_added_and_orphans_resolved_are_listed_in_alpha_order(): void
    {
        $diff = $this->emptyDiff();
        $diff['inventory']['added'] = ['a.php', 'b.php'];
        $diff['orphans']['resolved'] = ['o-1'];

        $verdict = (new AtlasCortexSnapshotDiffSummary)->summarize($diff);
        $this->assertSame(2, $verdict['inventory_added']);
        $this->assertSame(1, $verdict['orphans_resolved']);
        $this->assertSame(['inventory_added', 'orphans_resolved'], $verdict['nonEmptyCategories']);

        // every other category must be zero
        foreach ([
            'inventory_removed', 'inventory_shape_changed',
            'orphans_appeared',
            'edges_added', 'edges_removed',
            'clone_clusters_appeared', 'clone_clusters_dissolved', 'clone_clusters_shape_changed',
            'forbidden_added', 'forbidden_removed',
            'doc_stated_gaps_opened', 'doc_stated_gaps_closed',
        ] as $other) {
            $this->assertSame(0, $verdict[$other]);
        }
    }

    public function test_prose_only_diff_does_not_alter_structural_categories(): void
    {
        $diff = $this->emptyDiff();
        $diff['doc_purposes_prose'] = ['added' => ['p1', 'p2', 'p3'], 'changed' => ['p4'], 'removed' => []];

        $verdict = (new AtlasCortexSnapshotDiffSummary)->summarize($diff);

        // Structural categories must all stay zero.
        foreach ([
            'inventory_added', 'inventory_removed', 'inventory_shape_changed',
            'orphans_appeared', 'orphans_resolved',
            'edges_added', 'edges_removed',
            'clone_clusters_appeared', 'clone_clusters_dissolved', 'clone_clusters_shape_changed',
            'forbidden_added', 'forbidden_removed',
            'doc_stated_gaps_opened', 'doc_stated_gaps_closed',
        ] as $cat) {
            $this->assertSame(0, $verdict[$cat]);
        }
        $this->assertSame([], $verdict['nonEmptyCategories']);

        // Prose lives under output['prose'] with provenance tag.
        $this->assertSame('writable_untrusted_prose', $verdict['prose']['provenance']);
        $this->assertSame(3, $verdict['prose']['added']);
        $this->assertSame(1, $verdict['prose']['changed']);
        $this->assertSame(0, $verdict['prose']['removed']);
    }

    public function test_real_engine_diff_shape_yields_prose_counts_and_snapshot_ids(): void
    {
        // Real engine shape: left/right.snapshot_id + doc_purposes_prose
        $diff = array_merge($this->emptyDiff(), [
            'left' => ['snapshot_id' => 'engine-snap-left'],
            'right' => ['snapshot_id' => 'engine-snap-right'],
            'doc_purposes_prose' => ['added' => ['d1', 'd2'], 'changed' => ['d3'], 'removed' => ['d4', 'd5', 'd6']],
        ]);

        $verdict = (new AtlasCortexSnapshotDiffSummary)->summarize($diff);

        $this->assertSame('engine-snap-left', $verdict['from_snapshot_id'], 'from_snapshot_id must read left.snapshot_id');
        $this->assertSame('engine-snap-right', $verdict['to_snapshot_id'], 'to_snapshot_id must read right.snapshot_id');
        $this->assertSame(2, $verdict['prose']['added'], 'prose added count must be non-zero from doc_purposes_prose');
        $this->assertSame(1, $verdict['prose']['changed']);
        $this->assertSame(3, $verdict['prose']['removed']);
    }

    public function test_anchor_snapshot_ids_are_carried_through(): void
    {
        $diff = $this->emptyDiff();
        $verdict = (new AtlasCortexSnapshotDiffSummary)->summarize($diff);
        $this->assertSame('snap-a', $verdict['from_snapshot_id']);
        $this->assertSame('snap-b', $verdict['to_snapshot_id']);
    }
}
