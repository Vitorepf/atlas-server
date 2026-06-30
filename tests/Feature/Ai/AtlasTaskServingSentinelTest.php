<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\Aaeos\Generated\AtlasAgentControlPlaneSafetyInvariantsService;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSentinel;
use Tests\TestCase;

/**
 * PART 2 · A8 — the mother-rule DETECTORS. R1/I-17 (queue never dries) + R2/I-18 (serving never fails),
 * plus the declaration of the two new serving invariants (separate from the 16 dry-run hard-laws).
 */
final class AtlasTaskServingSentinelTest extends TestCase
{
    private string $log = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->log = sys_get_temp_dir().'/atlas-serving-sentinel-'.bin2hex(random_bytes(5)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->log);
        parent::tearDown();
    }

    private function sentinel(): AtlasTaskServingSentinel
    {
        $s = new AtlasTaskServingSentinel;
        $s->setLogPathForTesting($this->log);

        return $s;
    }

    public function test_r1_queue_fill_alarms_below_the_floor(): void
    {
        $s = $this->sentinel();

        $dry = $s->recordQueueFill(0, 1);
        $this->assertTrue($dry['below_floor']);
        $this->assertTrue($dry['silent_alarm'], 'a dry queue raises the R1 silent alarm');
        $this->assertSame(AtlasTaskServingSentinel::INVARIANT_R1, $dry['invariant']);
        $this->assertTrue($s->status()['r1_silent_alarm']);

        // Refilled above the floor — the alarm clears.
        $s->recordQueueFill(3, 1);
        $st = $s->status();
        $this->assertFalse($st['r1_silent_alarm']);
        $this->assertSame(3, $st['last_claimable_depth']);
    }

    public function test_r2_serving_breach_only_on_non_honest_outcome(): void
    {
        $s = $this->sentinel();

        // Honest outcomes — served + an empty queue — are NOT breaches.
        $s->recordServe('client-a', 'served');
        $s->recordServe('client-b', 'no_claimable_task');
        $st = $s->status();
        $this->assertFalse($st['r2_breach'], 'served + honest-empty are not breaches');
        $this->assertSame(0.5, $st['serve_success_rate']);

        // A process error IS an R2 breach.
        $s->recordServe('client-c', 'error');
        $st = $s->status();
        $this->assertTrue($st['r2_breach']);
        $this->assertSame(1, $st['r2_breach_count']);
    }

    public function test_status_trend_fields_present_and_healthy_when_no_records(): void
    {
        $st = $this->sentinel()->status();

        $this->assertArrayHasKey('recent_min_claimable_depth', $st);
        $this->assertArrayHasKey('consecutive_below_floor', $st);
        $this->assertArrayHasKey('consecutive_non_honest_serves', $st);
        $this->assertArrayHasKey('continuity_state', $st);
        $this->assertNull($st['recent_min_claimable_depth']);
        $this->assertSame(0, $st['consecutive_below_floor']);
        $this->assertSame(0, $st['consecutive_non_honest_serves']);
        $this->assertSame('healthy', $st['continuity_state']);
    }

    public function test_status_is_healthy_when_depth_stable_above_floor_and_no_breach(): void
    {
        $s = $this->sentinel();
        $s->recordQueueFill(5, 1);
        $s->recordQueueFill(4, 1);
        $s->recordServe('c', 'served');

        $st = $s->status();
        $this->assertSame('healthy', $st['continuity_state']);
        $this->assertSame(0, $st['consecutive_below_floor']);
        $this->assertSame(0, $st['consecutive_non_honest_serves']);
        $this->assertSame(4, $st['recent_min_claimable_depth']);
    }

    public function test_status_becomes_degrading_before_full_dry_when_depth_trends_toward_floor(): void
    {
        $s = $this->sentinel();
        $s->recordQueueFill(5, 3); // above floor
        $s->recordQueueFill(2, 3); // below floor (2 < 3), but depth != 0

        $st = $s->status();
        $this->assertSame('degrading', $st['continuity_state'], 'below floor but not zero = degrading, not dry');
        $this->assertSame(1, $st['consecutive_below_floor']);
        $this->assertSame(2, $st['recent_min_claimable_depth']);
    }

    public function test_status_degrading_on_consecutive_non_honest_serves(): void
    {
        $s = $this->sentinel();
        $s->recordQueueFill(5, 1);
        $s->recordServe('c', 'served');
        $s->recordServe('c', 'error');
        $s->recordServe('c', 'error');

        $st = $s->status();
        $this->assertSame('degrading', $st['continuity_state']);
        $this->assertSame(2, $st['consecutive_non_honest_serves']);
        $this->assertSame(0, $st['consecutive_below_floor']);
    }

    // ── window_*_iso8601 / window_elapsed_seconds ─────────────────────────────

    public function test_status_includes_window_metadata_when_serve_records_exist(): void
    {
        $s = $this->sentinel();
        $s->recordServe('c1', 'served');
        $s->recordServe('c2', 'served');

        $st = $s->status();

        $this->assertArrayHasKey('window_started_at_iso8601', $st);
        $this->assertArrayHasKey('window_ended_at_iso8601', $st);
        $this->assertArrayHasKey('window_elapsed_seconds', $st);
        $this->assertNotNull($st['window_started_at_iso8601']);
        $this->assertNotNull($st['window_ended_at_iso8601']);
        $this->assertIsInt($st['window_elapsed_seconds']);
    }

    public function test_window_elapsed_seconds_is_derived_from_serve_record_timestamps_and_never_negative(): void
    {
        $s = $this->sentinel();
        $s->recordServe('c1', 'served');
        $s->recordServe('c2', 'served');
        $s->recordServe('c3', 'no_claimable_task');

        $st = $s->status();

        $this->assertGreaterThanOrEqual(0, $st['window_elapsed_seconds']);
    }

    public function test_window_metadata_safe_defaults_when_no_serve_records(): void
    {
        $s = $this->sentinel();
        $s->recordQueueFill(5, 1); // queue_fill only, no serve records

        $st = $s->status();

        $this->assertNull($st['window_started_at_iso8601']);
        $this->assertNull($st['window_ended_at_iso8601']);
        $this->assertNull($st['window_elapsed_seconds']);
        $this->assertSame(0, $st['serve_total'], 'no serve records must not fabricate serve_total');
    }

    public function test_empty_log_keeps_safe_defaults_for_window_fields(): void
    {
        $st = $this->sentinel()->status();

        $this->assertNull($st['window_started_at_iso8601']);
        $this->assertNull($st['window_ended_at_iso8601']);
        $this->assertNull($st['window_elapsed_seconds']);
        $this->assertSame(0, $st['serve_total']);
    }

    public function test_serving_invariants_i17_i18_are_declared_separately_from_the_16(): void
    {
        $svc = new AtlasAgentControlPlaneSafetyInvariantsService;

        $serving = $svc->servingInvariants();
        $this->assertCount(2, $serving);
        $this->assertSame(['I-17', 'I-18'], array_column($serving, 'id'));

        // The 16 dry-run hard-laws are UNCHANGED — the doc-bound count stays exactly 16.
        $this->assertSame(16, AtlasAgentControlPlaneSafetyInvariantsService::INVARIANT_COUNT);
        $this->assertCount(16, $svc->describe()['invariants']);
    }
}
