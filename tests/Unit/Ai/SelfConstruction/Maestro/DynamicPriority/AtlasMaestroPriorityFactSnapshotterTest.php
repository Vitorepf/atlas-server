<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\DynamicPriority;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\Maestro\DynamicPriority\AtlasMaestroPriorityFactSnapshotter;
use Tests\TestCase;

class AtlasMaestroPriorityFactSnapshotterTest extends TestCase
{
    private string $snapshotsPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->snapshotsPath = sys_get_temp_dir().'/atlas-priority-fact-snap-'.bin2hex(random_bytes(6)).'.jsonl';
        AtlasLoopMasterSwitch::$envPathOverride = (static function () { $p = sys_get_temp_dir().'/atlas-mst-'.bin2hex(random_bytes(4)).'.env'; file_put_contents($p, "ATLAS_AUTONOMOS_MASTER_ENABLED=true
ATLAS_LOOP_MASTER_ENABLED=true
"); return $p; })();
    }

    protected function tearDown(): void
    {
        @unlink($this->snapshotsPath);
        parent::tearDown();
    }

    private function snapshotter(array $packets, ?int $nowNs = 1_700_000_000_000_000_000): AtlasMaestroPriorityFactSnapshotter
    {
        return new AtlasMaestroPriorityFactSnapshotter(
            static fn (): array => $packets,
            static fn (): array => [],
            static fn (): array => [],
            $this->snapshotsPath,
            static fn (): int => (int) $nowNs,
        );
    }

    // ── AC: snapshots include value_score, risk_score, age_bucket, give_back_pressure, proof_freshness ──

    public function test_snapshot_includes_all_priority_driver_facts_for_a_fully_described_packet(): void
    {
        $verdict = $this->snapshotter([[
            'task_packet_id' => 'pkt-A',
            'tags' => [],
            'depends_on' => [],
            'value_hint' => 80,
            'risk_hint' => 10,
            'give_back_count' => 1,
            'created_at_ms' => 0,
            'proof_age_days' => 2,
        ]], nowNs: 1_000_000)->snapshot();

        $facts = $verdict['facts']['priority_facts_by_task_id']['pkt-A'];

        self::assertArrayHasKey('value_score', $facts);
        self::assertArrayHasKey('risk_score', $facts);
        self::assertArrayHasKey('age_bucket', $facts);
        self::assertArrayHasKey('give_back_pressure', $facts);
        self::assertArrayHasKey('proof_freshness', $facts);
        self::assertSame(80, $facts['value_score']);
        self::assertSame(10, $facts['risk_score']);
        self::assertSame(1, $facts['give_back_pressure']);
        self::assertSame(AtlasMaestroPriorityFactSnapshotter::PROOF_FRESHNESS_FRESH, $facts['proof_freshness']);
    }

    // ── AC: missing optional facts degrade to conservative defaults, not optimistic priority ──

    public function test_missing_optional_facts_degrade_to_conservative_defaults(): void
    {
        $verdict = $this->snapshotter([[
            'task_packet_id' => 'pkt-bare',
            'tags' => [],
            'depends_on' => [],
        ]])->snapshot();

        $facts = $verdict['facts']['priority_facts_by_task_id']['pkt-bare'];

        // No proven value → conservative floor, never assumed valuable.
        self::assertSame(0, $facts['value_score']);
        // No proven safety → conservative ceiling, never assumed low-risk.
        self::assertSame(100, $facts['risk_score']);
        // No age evidence → unknown, never assumed fresh or stale.
        self::assertSame(AtlasMaestroPriorityFactSnapshotter::AGE_BUCKET_UNKNOWN, $facts['age_bucket']);
        // No give-back history → neutral zero, not inflated.
        self::assertSame(0, $facts['give_back_pressure']);
        // No proof evidence → unknown, never assumed fresh.
        self::assertSame(AtlasMaestroPriorityFactSnapshotter::PROOF_FRESHNESS_UNKNOWN, $facts['proof_freshness']);
        // Conservative defaults must never yield an optimistic (high) effective priority.
        self::assertSame(0, $facts['effective_priority']);
    }

    // ── AC: stale proof lowers effective priority for risky tasks ──

    public function test_stale_proof_lowers_effective_priority_for_risky_tasks(): void
    {
        $base = [
            'task_packet_id' => 'pkt-risky',
            'tags' => [],
            'depends_on' => [],
            'value_hint' => 80,
            'risk_hint' => 90,
        ];

        $freshVerdict = $this->snapshotter([$base + ['proof_age_days' => 1]])->snapshot();
        $staleVerdict = $this->snapshotter([$base + ['proof_age_days' => 30]])->snapshot();

        $freshPriority = $freshVerdict['facts']['priority_facts_by_task_id']['pkt-risky']['effective_priority'];
        $stalePriority = $staleVerdict['facts']['priority_facts_by_task_id']['pkt-risky']['effective_priority'];

        self::assertLessThan($freshPriority, $stalePriority);
    }

    public function test_stale_proof_does_not_penalize_low_risk_tasks(): void
    {
        $base = [
            'task_packet_id' => 'pkt-safe',
            'tags' => [],
            'depends_on' => [],
            'value_hint' => 50,
            'risk_hint' => 5,
        ];

        $freshVerdict = $this->snapshotter([$base + ['proof_age_days' => 1]])->snapshot();
        $staleVerdict = $this->snapshotter([$base + ['proof_age_days' => 30]])->snapshot();

        self::assertSame(
            $freshVerdict['facts']['priority_facts_by_task_id']['pkt-safe']['effective_priority'],
            $staleVerdict['facts']['priority_facts_by_task_id']['pkt-safe']['effective_priority'],
        );
    }

    public function test_age_buckets_reflect_packet_age(): void
    {
        $now = 100_000_000;
        $verdict = $this->snapshotter([
            ['task_packet_id' => 'pkt-new', 'tags' => [], 'depends_on' => [], 'created_at_ms' => $now - 1_000],
            ['task_packet_id' => 'pkt-recent', 'tags' => [], 'depends_on' => [], 'created_at_ms' => $now - 7_200_000],
            ['task_packet_id' => 'pkt-aging', 'tags' => [], 'depends_on' => [], 'created_at_ms' => $now - 200_000_000],
        ], nowNs: $now * 1_000_000)->snapshot();

        $facts = $verdict['facts']['priority_facts_by_task_id'];
        self::assertSame(AtlasMaestroPriorityFactSnapshotter::AGE_BUCKET_NEW, $facts['pkt-new']['age_bucket']);
        self::assertSame(AtlasMaestroPriorityFactSnapshotter::AGE_BUCKET_RECENT, $facts['pkt-recent']['age_bucket']);
        self::assertSame(AtlasMaestroPriorityFactSnapshotter::AGE_BUCKET_AGING, $facts['pkt-aging']['age_bucket']);
    }

    public function test_priority_facts_are_deterministic_and_key_sorted(): void
    {
        $packets = [
            ['task_packet_id' => 'zz', 'tags' => [], 'depends_on' => [], 'value_hint' => 1],
            ['task_packet_id' => 'aa', 'tags' => [], 'depends_on' => [], 'value_hint' => 1],
        ];
        $verdict = $this->snapshotter($packets, nowNs: 5)->snapshot();

        self::assertSame(['aa', 'zz'], array_keys($verdict['facts']['priority_facts_by_task_id']));
    }
}
