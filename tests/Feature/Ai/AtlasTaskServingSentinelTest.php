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
