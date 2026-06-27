<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainDoneSetLedger;
use App\Services\Ai\AutonomousEvolution\Brain\AtlasBrainMetricSnapshot;
use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopScopeComprehensionModel;
use Tests\TestCase;

/**
 * FROZEN proof of the metric snapshot — the brain's measurable targets. Proves each declared metric
 * computes correctly from a known model+ledger, the direction (minimize/maximize) is per-metric, the
 * output is byte-stable, and the organ is pétreo.
 */
final class AtlasBrainMetricSnapshotTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-brain-metric-'.bin2hex(random_bytes(6));
        @mkdir($this->root, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->root)) {
            foreach (glob($this->root.'/*.jsonl') ?: [] as $f) {
                @unlink($f);
            }
            @rmdir($this->root);
        }
        parent::tearDown();
    }

    private function ledger(): AtlasBrainDoneSetLedger
    {
        return new AtlasBrainDoneSetLedger('loop', $this->root);
    }

    private function model(array $overrides = []): AtlasLoopScopeComprehensionModel
    {
        return AtlasLoopScopeComprehensionModel::fromArray(array_merge([
            'snapshot_id' => 'snap',
            'inventory' => [],
            'edges' => [],
            'orphans' => ['App\\A', 'App\\B'],
            'clone_clusters' => [
                ['cluster_id' => 'c1', 'clone_hash' => 'h1', 'members' => []],
            ],
            'forbidden' => [],
            'doc_purposes' => [],
            'doc_stated_gaps' => ['gap one', 'gap two', 'gap three'],
        ], $overrides));
    }

    private function byId(array $metrics): array
    {
        $out = [];
        foreach ($metrics as $m) {
            $out[$m['id']] = $m;
        }

        return $out;
    }

    public function test_counts_match_model_inputs(): void
    {
        $snap = (new AtlasBrainMetricSnapshot)->snapshot($this->model(), $this->ledger());
        $by = $this->byId($snap['metrics']);

        self::assertSame(2, $by['orphan_count']['value']);
        self::assertSame(1, $by['clone_cluster_count']['value']);
        self::assertSame(3, $by['doc_stated_gap_count']['value']);
    }

    public function test_direction_is_per_metric(): void
    {
        $snap = (new AtlasBrainMetricSnapshot)->snapshot($this->model(), $this->ledger());
        $by = $this->byId($snap['metrics']);

        self::assertSame(AtlasBrainMetricSnapshot::DIRECTION_MINIMIZE, $by['orphan_count']['direction']);
        self::assertSame(AtlasBrainMetricSnapshot::DIRECTION_MINIMIZE, $by['recent_refusal_count']['direction']);
        self::assertSame(AtlasBrainMetricSnapshot::DIRECTION_MAXIMIZE, $by['recent_served_streak']['direction']);
    }

    public function test_ledger_derived_metrics_count_refusals_and_streak(): void
    {
        $l = $this->ledger();
        $rec = fn (string $status) => $l->record([
            'snapshot_id' => 'snap',
            'status' => $status,
            'produced' => $status === 'served',
            'action' => 'origin',
            'target_path' => 'X.php',
            'task_packet_id' => 'pkt-'.bin2hex(random_bytes(3)),
            'refusal' => $status !== 'served',
        ]);
        // 3 refusals first, then 2 served streak from the tail.
        $rec('refused');
        $rec('abstain');
        $rec('refused');
        $rec('served');
        $rec('served');

        $by = $this->byId((new AtlasBrainMetricSnapshot)->snapshot($this->model(), $l)['metrics']);

        self::assertSame(3, $by['recent_refusal_count']['value']);
        self::assertSame(2, $by['recent_served_streak']['value']);
    }

    public function test_served_ratio_pct_is_percentage_of_decisive_cycles(): void
    {
        $l = $this->ledger();
        $rec = fn (string $status) => $l->record([
            'snapshot_id' => 's', 'status' => $status, 'produced' => $status === 'served',
            'action' => 'origin', 'target_path' => 'X.php', 'task_packet_id' => 'pkt-'.bin2hex(random_bytes(3)),
            'refusal' => $status !== 'served',
        ]);
        $rec('served');
        $rec('served');
        $rec('refused');
        $rec('served');
        // served=3, refused=1 ⇒ 75%
        $by = $this->byId((new AtlasBrainMetricSnapshot)->snapshot($this->model(), $l)['metrics']);
        self::assertSame(75, $by['served_ratio_pct']['value']);
        self::assertSame(AtlasBrainMetricSnapshot::DIRECTION_MAXIMIZE, $by['served_ratio_pct']['direction']);
    }

    public function test_snapshot_is_byte_stable_across_invocations(): void
    {
        $snap = new AtlasBrainMetricSnapshot;
        self::assertSame(
            $snap->snapshot($this->model(), $this->ledger()),
            $snap->snapshot($this->model(), $this->ledger()),
            'same inputs ⇒ identical snapshot (no wall-clock / no random)'
        );
    }

    public function test_snapshot_organ_is_a_petreo_forbidden_self_target(): void
    {
        $verdict = app(AtlasLoopHarnessGuard::class)->admit(
            'app/Services/Ai/AutonomousEvolution/Brain/AtlasBrainMetricSnapshot.php',
            true
        );

        self::assertSame('forbidden', $verdict);
    }
}
