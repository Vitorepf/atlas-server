<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedLivenessSnapshot;
use Tests\TestCase;

class AtlasSelfConstructionUnattendedLivenessSnapshotTest extends TestCase
{
    private function healthyFacts(): array
    {
        return [
            'queue' => ['depth' => 5, 'claimable_count' => 3, 'malformed_count' => 0, 'safety_stop' => false],
            'heartbeat' => ['last_seen_age_seconds' => 5, 'stale_threshold_seconds' => 120],
            'active_leases' => [['lease_id' => 'l1']],
            'continuous_runtime_cycle' => ['last_cycle_id' => 'c1', 'last_stop_reason' => '', 'last_stopped' => false],
            'native_worker' => ['ready' => true, 'concurrency_floor_ok' => true],
            'replenisher' => ['last_run_status' => 'ok', 'last_run_age_seconds' => 30],
            'verification' => ['last_verdict' => 'verified', 'failed_run_count' => 0],
            'merge' => ['last_decision' => 'request_merge', 'blocked' => false],
        ];
    }

    public function test_healthy_snapshot_when_all_sources_green(): void
    {
        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($this->healthyFacts());

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_HEALTHY, $snapshot['status']);
        self::assertSame([], $snapshot['missing_sources']);
        self::assertArrayNotHasKey('score', $snapshot);
        self::assertStringStartsWith('snapshot_', $snapshot['snapshot_hash']);
    }

    public function test_missing_required_source_reports_unknown_and_lists_it(): void
    {
        $facts = $this->healthyFacts();
        unset($facts['merge']);

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_UNKNOWN, $snapshot['status']);
        self::assertContains('merge', $snapshot['missing_sources']);
    }

    public function test_stale_heartbeat_reports_degraded(): void
    {
        $facts = $this->healthyFacts();
        $facts['heartbeat']['last_seen_age_seconds'] = 999;
        $facts['heartbeat']['stale_threshold_seconds'] = 120;

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_DEGRADED, $snapshot['status']);
        self::assertTrue($snapshot['facts']['heartbeat']['is_stale']);
    }

    public function test_safety_stop_reports_degraded(): void
    {
        $facts = $this->healthyFacts();
        $facts['queue']['safety_stop'] = true;

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_DEGRADED, $snapshot['status']);
    }

    public function test_malformed_packets_report_degraded(): void
    {
        $facts = $this->healthyFacts();
        $facts['queue']['malformed_count'] = 3;

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_DEGRADED, $snapshot['status']);
    }

    public function test_snapshot_hash_is_deterministic_for_identical_facts(): void
    {
        $svc = new AtlasSelfConstructionUnattendedLivenessSnapshot();
        $a = $svc->compose($this->healthyFacts());
        $b = $svc->compose($this->healthyFacts());

        self::assertSame($a['snapshot_hash'], $b['snapshot_hash']);
    }

    public function test_brain_quota_stall_facts_are_included_deterministically(): void
    {
        $facts = $this->healthyFacts();
        $facts['brain_quota'] = [
            'status' => 'stalled',
            'actor' => 'atlas:brain:next',
            'remaining' => 3,
            'stall_reason' => 'quota_exhausted',
            'must_run_now' => true,
            'active_brain_commands' => 2,
            'temp_spec_path' => '/tmp/brain-spec.json',
        ];

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);
        $bq = $snapshot['facts']['brain_quota'];

        self::assertSame('stalled', $bq['status']);
        self::assertSame('atlas:brain:next', $bq['actor']);
        self::assertSame(3, $bq['remaining']);
        self::assertSame('quota_exhausted', $bq['stall_reason']);
        self::assertTrue($bq['must_run_now']);
        self::assertSame(2, $bq['active_brain_commands']);
        self::assertSame('/tmp/brain-spec.json', $bq['temp_spec_path']);
        // Overall status unaffected by brain_quota presence — still healthy.
        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_HEALTHY, $snapshot['status']);
    }

    public function test_brain_quota_emits_safe_defaults_when_absent(): void
    {
        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($this->healthyFacts());
        $bq = $snapshot['facts']['brain_quota'];

        self::assertArrayHasKey('brain_quota', $snapshot['facts']);
        self::assertSame('', $bq['status']);
        self::assertSame('', $bq['actor']);
        self::assertNull($bq['remaining']);
        self::assertFalse($bq['must_run_now']);
        self::assertSame(0, $bq['active_brain_commands']);
    }

    public function test_snapshot_source_does_not_call_io_or_provider(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedLivenessSnapshot.php'));
        foreach (['file_get_contents(', 'file_put_contents', 'fopen(', 'shell_exec', 'exec(', 'system(', 'proc_open', 'curl_', 'Http::', 'DB::', 'Storage::'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "snapshot must not contain {$forbidden}");
        }
    }
}
