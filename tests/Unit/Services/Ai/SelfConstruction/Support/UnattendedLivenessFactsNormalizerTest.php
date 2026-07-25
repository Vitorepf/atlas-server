<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\UnattendedLivenessFactsNormalizer;
use Tests\TestCase;

/**
 * Pure unit coverage for UnattendedLivenessFactsNormalizer — no I/O, no Laravel services.
 */
final class UnattendedLivenessFactsNormalizerTest extends TestCase
{
    public function test_normalize_queue_defaults_and_casts(): void
    {
        $out = UnattendedLivenessFactsNormalizer::normalizeQueue(['depth' => '2', 'claimable_count' => 1]);

        self::assertSame(2, $out['depth']);
        self::assertSame(1, $out['claimable_count']);
        self::assertSame(0, $out['malformed_count']);
        self::assertFalse($out['safety_stop']);
    }

    public function test_normalize_queue_null_is_empty_defaults(): void
    {
        $out = UnattendedLivenessFactsNormalizer::normalizeQueue(null);

        self::assertSame([
            'depth' => 0,
            'claimable_count' => 0,
            'malformed_count' => 0,
            'safety_stop' => false,
        ], $out);
    }

    public function test_heartbeat_missing_age_is_stale(): void
    {
        self::assertTrue(UnattendedLivenessFactsNormalizer::heartbeatIsStale([]));
        $hb = UnattendedLivenessFactsNormalizer::normalizeHeartbeat([], [['lease_id' => 'a']]);
        self::assertTrue($hb['is_stale']);
        self::assertNull($hb['last_seen_age_seconds']);
        self::assertSame(1, $hb['active_lease_count']);
        self::assertSame(
            UnattendedLivenessFactsNormalizer::HEARTBEAT_STALE_THRESHOLD_SECONDS,
            $hb['stale_threshold_seconds']
        );
    }

    public function test_heartbeat_fresh_and_over_threshold(): void
    {
        self::assertFalse(UnattendedLivenessFactsNormalizer::heartbeatIsStale([
            'last_seen_age_seconds' => 10,
            'stale_threshold_seconds' => 120,
        ]));
        self::assertTrue(UnattendedLivenessFactsNormalizer::heartbeatIsStale([
            'last_seen_age_seconds' => 121,
            'stale_threshold_seconds' => 120,
        ]));
    }

    public function test_classify_unknown_when_missing_sources(): void
    {
        $status = UnattendedLivenessFactsNormalizer::classify(
            $this->healthyNormalizedFacts(),
            ['merge']
        );

        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_UNKNOWN, $status);
    }

    public function test_classify_degraded_on_safety_stop_stale_blocked_worker_malformed_dry(): void
    {
        $base = $this->healthyNormalizedFacts();

        $safety = $base;
        $safety['queue']['safety_stop'] = true;
        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_DEGRADED, UnattendedLivenessFactsNormalizer::classify($safety, []));

        $stale = $base;
        $stale['heartbeat']['is_stale'] = true;
        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_DEGRADED, UnattendedLivenessFactsNormalizer::classify($stale, []));

        $blocked = $base;
        $blocked['merge']['blocked'] = true;
        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_DEGRADED, UnattendedLivenessFactsNormalizer::classify($blocked, []));

        $worker = $base;
        $worker['native_worker']['ready'] = false;
        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_DEGRADED, UnattendedLivenessFactsNormalizer::classify($worker, []));

        $malformed = $base;
        $malformed['queue']['malformed_count'] = 2;
        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_DEGRADED, UnattendedLivenessFactsNormalizer::classify($malformed, []));

        $dry = $base;
        $dry['queue']['claimable_count'] = 0;
        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_DEGRADED, UnattendedLivenessFactsNormalizer::classify($dry, []));
    }

    public function test_classify_healthy(): void
    {
        $status = UnattendedLivenessFactsNormalizer::classify($this->healthyNormalizedFacts(), []);

        self::assertSame(UnattendedLivenessFactsNormalizer::STATUS_HEALTHY, $status);
    }

    public function test_snapshot_hash_is_deterministic_and_prefixed(): void
    {
        $facts = $this->healthyNormalizedFacts();
        $a = UnattendedLivenessFactsNormalizer::snapshotHash(UnattendedLivenessFactsNormalizer::STATUS_HEALTHY, $facts, []);
        $b = UnattendedLivenessFactsNormalizer::snapshotHash(UnattendedLivenessFactsNormalizer::STATUS_HEALTHY, $facts, []);

        self::assertSame($a, $b);
        self::assertStringStartsWith('snapshot_', $a);
        self::assertSame(41, strlen($a)); // snapshot_ + 32 hex
    }

    public function test_normalize_brain_quota_and_cycle_defaults(): void
    {
        $bq = UnattendedLivenessFactsNormalizer::normalizeBrainQuota(null);
        self::assertSame('', $bq['status']);
        self::assertNull($bq['remaining']);
        self::assertFalse($bq['must_run_now']);

        $cycle = UnattendedLivenessFactsNormalizer::normalizeCycle(['last_cycle_id' => 'c9']);
        self::assertSame('c9', $cycle['last_cycle_id']);
        self::assertSame('', $cycle['last_stop_reason']);
        self::assertFalse($cycle['last_stopped']);
    }

    public function test_normalizer_source_is_pure_no_io(): void
    {
        $src = (string) file_get_contents(base_path('app/Services/Ai/SelfConstruction/Support/UnattendedLivenessFactsNormalizer.php'));
        foreach (['file_get_contents(', 'file_put_contents', 'fopen(', 'shell_exec', 'exec(', 'DB::', 'Http::', 'Queue::', 'dispatch('] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $src, "normalizer must not contain {$forbidden}");
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function healthyNormalizedFacts(): array
    {
        return [
            'queue' => UnattendedLivenessFactsNormalizer::normalizeQueue([
                'depth' => 5,
                'claimable_count' => 3,
                'malformed_count' => 0,
                'safety_stop' => false,
            ]),
            'heartbeat' => UnattendedLivenessFactsNormalizer::normalizeHeartbeat([
                'last_seen_age_seconds' => 5,
                'stale_threshold_seconds' => 120,
            ], [['lease_id' => 'l1']]),
            'native_worker' => UnattendedLivenessFactsNormalizer::normalizeWorker([
                'ready' => true,
                'concurrency_floor_ok' => true,
            ]),
            'merge' => UnattendedLivenessFactsNormalizer::normalizeMerge([
                'last_decision' => 'request_merge',
                'blocked' => false,
            ]),
        ];
    }
}
