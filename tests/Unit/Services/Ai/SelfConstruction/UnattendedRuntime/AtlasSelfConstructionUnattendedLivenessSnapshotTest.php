<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\UnattendedRuntime;

use App\Services\Ai\SelfConstruction\UnattendedRuntime\AtlasSelfConstructionUnattendedLivenessSnapshot;
use Tests\TestCase;

/**
 * The 24/7 Autonomos supervisor sees honest bounded facts instead of fabricated readiness:
 * missing required sources are reported, a missing heartbeat is treated as stale, active lease
 * count comes only from active_leases input, healthy facts produce status healthy, degraded facts
 * produce status degraded, and snapshot_hash is deterministic for equivalent facts — pure composer,
 * no I/O, no provider calls, no dispatch, no recovery decisions.
 */
final class AtlasSelfConstructionUnattendedLivenessSnapshotTest extends TestCase
{
    private function healthyFacts(): array
    {
        return [
            'queue' => ['depth' => 5, 'claimable_count' => 3, 'malformed_count' => 0, 'safety_stop' => false],
            'heartbeat' => ['last_seen_age_seconds' => 5, 'stale_threshold_seconds' => 120],
            'active_leases' => [['lease_id' => 'l1'], ['lease_id' => 'l2']],
            'continuous_runtime_cycle' => ['last_cycle_id' => 'c1', 'last_stop_reason' => '', 'last_stopped' => false],
            'native_worker' => ['ready' => true, 'concurrency_floor_ok' => true],
            'replenisher' => ['last_run_status' => 'ok', 'last_run_age_seconds' => 30],
            'verification' => ['last_verdict' => 'verified', 'failed_run_count' => 0],
            'merge' => ['last_decision' => 'request_merge', 'blocked' => false],
        ];
    }

    public function test_missing_required_sources_are_reported(): void
    {
        $facts = $this->healthyFacts();
        unset($facts['merge'], $facts['replenisher']);

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_UNKNOWN, $snapshot['status']);
        self::assertSame(['replenisher', 'merge'], $snapshot['missing_sources']);
    }

    public function test_missing_heartbeat_is_stale(): void
    {
        $facts = $this->healthyFacts();
        unset($facts['heartbeat']['last_seen_age_seconds']);

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertTrue($snapshot['facts']['heartbeat']['is_stale']);
    }

    public function test_active_lease_count_comes_only_from_active_leases_input(): void
    {
        $facts = $this->healthyFacts();
        $facts['active_leases'] = [['lease_id' => 'l1'], ['lease_id' => 'l2'], ['lease_id' => 'l3']];

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(3, $snapshot['facts']['heartbeat']['active_lease_count']);
    }

    public function test_healthy_facts_produce_status_healthy(): void
    {
        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($this->healthyFacts());

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_HEALTHY, $snapshot['status']);
    }

    public function test_degraded_facts_produce_status_degraded(): void
    {
        $facts = $this->healthyFacts();
        $facts['merge']['blocked'] = true;

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        self::assertSame(AtlasSelfConstructionUnattendedLivenessSnapshot::STATUS_DEGRADED, $snapshot['status']);
    }

    public function test_snapshot_hash_is_deterministic_for_equivalent_facts(): void
    {
        $svc = new AtlasSelfConstructionUnattendedLivenessSnapshot();
        $a = $svc->compose($this->healthyFacts());
        $b = $svc->compose($this->healthyFacts());

        self::assertSame($a['snapshot_hash'], $b['snapshot_hash']);
    }

    public function test_all_source_facts_are_normalized_into_the_envelope(): void
    {
        $facts = $this->healthyFacts();
        $facts['brain_quota'] = ['status' => 'stalled', 'actor' => 'atlas:brain:next'];

        $snapshot = (new AtlasSelfConstructionUnattendedLivenessSnapshot)->compose($facts);

        foreach (['queue', 'heartbeat', 'continuous_runtime_cycle', 'native_worker', 'replenisher', 'verification', 'merge', 'brain_quota'] as $key) {
            self::assertArrayHasKey($key, $snapshot['facts'], "envelope must normalize the {$key} source");
        }
        self::assertSame('c1', $snapshot['facts']['continuous_runtime_cycle']['last_cycle_id']);
        self::assertSame('ok', $snapshot['facts']['replenisher']['last_run_status']);
        self::assertSame('verified', $snapshot['facts']['verification']['last_verdict']);
        self::assertSame('request_merge', $snapshot['facts']['merge']['last_decision']);
        self::assertSame('stalled', $snapshot['facts']['brain_quota']['status']);
        self::assertTrue($snapshot['facts']['native_worker']['ready']);
    }

    public function test_composer_source_performs_no_io_provider_or_dispatch(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/UnattendedRuntime/AtlasSelfConstructionUnattendedLivenessSnapshot.php'));
        foreach (['file_get_contents(', 'file_put_contents', 'fopen(', 'shell_exec', 'exec(', 'system(', 'proc_open', 'curl_', 'Http::', 'DB::', 'Storage::', 'Queue::', 'dispatch('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "snapshot must not contain {$forbidden}");
        }
    }
}
