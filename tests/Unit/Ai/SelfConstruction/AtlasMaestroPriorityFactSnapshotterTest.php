<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityFactSnapshotter;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class AtlasMaestroPriorityFactSnapshotterTest extends TestCase
{
    private string $snapshotsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotsPath = sys_get_temp_dir().'/atlas-priority-snap-'.bin2hex(random_bytes(6)).'.jsonl';
        Config::set('atlas.loop.master_enabled', true);
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotsPath);
        parent::tearDown();
    }

    private function snapshotter(array $packets, array $leases = [], array $inFlight = [], ?int $nowNs = 1700000000000000000): AtlasMaestroPriorityFactSnapshotter
    {
        return new AtlasMaestroPriorityFactSnapshotter(
            static fn (): array => $packets,
            static fn (): array => $leases,
            static fn (): array => $inFlight,
            $this->snapshotsPath,
            static fn (): int => (int) $nowNs,
        );
    }

    private function rowCount(): int
    {
        if (! is_file($this->snapshotsPath)) {
            return 0;
        }

        return count(file($this->snapshotsPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []);
    }

    public function test_master_switch_off_returns_disabled_marker_and_writes_zero_rows(): void
    {
        Config::set('atlas.loop.master_enabled', false);
        $before = $this->rowCount();
        $verdict = $this->snapshotter([['task_packet_id' => 'x', 'tags' => ['a'], 'depends_on' => []]])->snapshot();
        $after = $this->rowCount();

        self::assertTrue($verdict['disabled']);
        self::assertSame('master_switch_off', $verdict['reason']);
        self::assertSame($before, $after);
    }

    public function test_snapshot_reports_queue_depth_and_dependency_criticality(): void
    {
        $packets = [
            ['task_packet_id' => 'pkt-A', 'tags' => ['refactor'], 'depends_on' => []],
            ['task_packet_id' => 'pkt-B', 'tags' => ['refactor'], 'depends_on' => ['pkt-A']],
            ['task_packet_id' => 'pkt-C', 'tags' => ['docs'], 'depends_on' => ['pkt-A']],
        ];
        $leases = [
            ['started_at_ms' => 1000, 'completed_at_ms' => 1500, 'worker_id' => 'w1'],
            ['started_at_ms' => 2000, 'completed_at_ms' => 2400, 'worker_id' => 'w1'],
        ];
        $inFlight = [['started_at_ms' => 3000, 'worker_id' => 'w2']];

        $verdict = $this->snapshotter($packets, $leases, $inFlight)->snapshot();

        self::assertSame(2, $verdict['facts']['queue_depth_by_tag']['refactor']);
        self::assertSame(1, $verdict['facts']['queue_depth_by_tag']['docs']);
        self::assertGreaterThanOrEqual(0, $verdict['facts']['worker_idle_prediction_ms']);
        self::assertGreaterThanOrEqual(2, $verdict['facts']['dependency_criticality_by_task_id']['pkt-A']);
    }

    public function test_two_snapshots_in_sequence_produce_two_append_only_rows(): void
    {
        $snapshotter = $this->snapshotter([['task_packet_id' => 'pkt', 'tags' => ['t'], 'depends_on' => []]]);
        $snapshotter->snapshot();
        $bytes = (string) file_get_contents($this->snapshotsPath);
        $snapshotter->snapshot();
        $bytesAfter = (string) file_get_contents($this->snapshotsPath);

        self::assertSame($bytes, substr($bytesAfter, 0, strlen($bytes)));
        self::assertSame(2, $this->rowCount());
    }

    public function test_task_family_backlog_groups_by_first_hyphen_segment(): void
    {
        $packets = [
            ['task_packet_id' => 'pkt-A', 'tags' => [], 'depends_on' => []],
            ['task_packet_id' => 'pkt-B', 'tags' => [], 'depends_on' => []],
            ['task_packet_id' => 'pkt-C', 'tags' => [], 'depends_on' => []],
        ];
        $verdict = $this->snapshotter($packets)->snapshot();
        $this->assertSame(['pkt' => 3], $verdict['facts']['task_family_backlog']);
    }

    public function test_task_family_backlog_counts_multiple_families(): void
    {
        $packets = [
            ['task_packet_id' => 'codex-001', 'tags' => [], 'depends_on' => []],
            ['task_packet_id' => 'codex-002', 'tags' => [], 'depends_on' => []],
            ['task_packet_id' => 'forge-001', 'tags' => [], 'depends_on' => []],
        ];
        $verdict = $this->snapshotter($packets)->snapshot();
        $this->assertSame(['codex' => 2, 'forge' => 1], $verdict['facts']['task_family_backlog']);
    }

    public function test_task_family_backlog_sorted_alphabetically(): void
    {
        $packets = [
            ['task_packet_id' => 'zz-1', 'tags' => [], 'depends_on' => []],
            ['task_packet_id' => 'aa-1', 'tags' => [], 'depends_on' => []],
        ];
        $verdict = $this->snapshotter($packets)->snapshot();
        $this->assertSame(['aa', 'zz'], array_keys($verdict['facts']['task_family_backlog']));
    }

    public function test_task_family_backlog_empty_for_empty_queue(): void
    {
        $verdict = $this->snapshotter([])->snapshot();
        $this->assertSame([], $verdict['facts']['task_family_backlog']);
    }

    public function test_canonical_json_is_deterministic_with_fixed_clock_and_state(): void
    {
        $packets = [['task_packet_id' => 'p', 'tags' => ['t'], 'depends_on' => []]];
        $a = $this->snapshotter($packets, nowNs: 5)->snapshot();
        @unlink($this->snapshotsPath);
        $b = $this->snapshotter($packets, nowNs: 5)->snapshot();

        self::assertSame(json_encode($a), json_encode($b));
    }
}
