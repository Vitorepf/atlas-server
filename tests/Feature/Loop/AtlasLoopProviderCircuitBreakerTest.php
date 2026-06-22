<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\AtlasLoopProviderCircuitBreaker;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignSupervisor;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * §4 · PROVIDER CIRCUIT-BREAKER — proves the discrimination (the whole point): a provider-DOWN grind (no
 * winner, 0 scenarios, 0 proposals) increments the streak and OPENS the breaker after the threshold; a healthy
 * grind (won / explored / proposed / legit skip) RESETS it. So an unattended soak pauses on a real outage but
 * never on the loop legitimately failing to win a hard target.
 */
final class AtlasLoopProviderCircuitBreakerTest extends TestCase
{
    private string $cid;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->cid = 'cb-'.bin2hex(random_bytes(4));
    }

    private function breaker(): AtlasLoopProviderCircuitBreaker
    {
        return new AtlasLoopProviderCircuitBreaker;
    }

    public function test_provider_down_grind_is_unhealthy_hard_task_is_healthy(): void
    {
        // PROVIDER DOWN: no winner, nothing explored, nothing proposed.
        $this->assertFalse(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(
            ['status' => 'no_winner', 'has_winner' => false, 'scenarios_explored' => 0, 'proposals' => 0],
        ));
        $this->assertFalse(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(
            ['status' => 'failed', 'reason' => 'orphan_wiring_authoring_unavailable', 'scenarios_explored' => 0, 'proposals' => 0],
        ));

        // HEALTHY: the provider responded (explored / proposed / won) OR it was a legitimate non-provider skip.
        $this->assertTrue(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(['status' => 'winner', 'has_winner' => true]));
        $this->assertTrue(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(['status' => 'no_winner', 'scenarios_explored' => 4, 'proposals' => 0]), 'hard-task no-win still EXPLORED ⇒ healthy');
        $this->assertTrue(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(['status' => 'no_winner', 'scenarios_explored' => 0, 'proposals' => 2]));
        $this->assertTrue(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(['status' => 'backpressure']));
        $this->assertTrue(AtlasLoopProviderCircuitBreaker::outcomeIsProviderHealthy(['status' => 'skipped', 'reason' => 'hopeless_target']));
    }

    public function test_consecutive_outages_open_the_breaker(): void
    {
        $b = $this->breaker();
        $down = ['status' => 'no_winner', 'scenarios_explored' => 0, 'proposals' => 0];

        $this->assertSame(1, $b->record($this->cid, $down));
        $this->assertSame(2, $b->record($this->cid, $down));
        $this->assertFalse($b->isOpen($this->cid, 5), 'not open at 2 with threshold 5');
        $b->record($this->cid, $down);
        $b->record($this->cid, $down);
        $this->assertSame(5, $b->record($this->cid, $down));
        $this->assertTrue($b->isOpen($this->cid, 5), 'OPEN at 5 consecutive outages');
    }

    public function test_one_healthy_grind_resets_the_streak(): void
    {
        $b = $this->breaker();
        $down = ['status' => 'no_winner', 'scenarios_explored' => 0, 'proposals' => 0];
        $b->record($this->cid, $down);
        $b->record($this->cid, $down);
        $b->record($this->cid, $down);
        $this->assertSame(3, $b->streak($this->cid));

        // A live provider that explored scenarios (even without a win) clears the streak.
        $this->assertSame(0, $b->record($this->cid, ['status' => 'no_winner', 'scenarios_explored' => 3]));
        $this->assertFalse($b->isOpen($this->cid, 5));
    }

    public function test_reset_closes_the_breaker_and_absent_is_zero(): void
    {
        $b = $this->breaker();
        $this->assertSame(0, $b->streak('never-seen'), 'absent campaign ⇒ 0 (fail-safe)');
        $b->record($this->cid, ['status' => 'no_winner', 'scenarios_explored' => 0]);
        $b->reset($this->cid);
        $this->assertSame(0, $b->streak($this->cid));
    }

    /** WIRING: the LIVE supervisor's breaker methods record outages + want a pause once OPEN (flag-gated). */
    public function test_supervisor_breaker_wiring_pauses_on_outage_streak_and_is_flag_gated(): void
    {
        config(['atlas.loop.provider_circuit_breaker_enabled' => true, 'atlas.loop.provider_circuit_breaker_threshold' => 3]);
        $sup = app(AtlasLoopCampaignSupervisor::class);
        $campaign = new AtlasLoopCampaign;
        $campaign->id = $this->cid;

        $record = new \ReflectionMethod($sup, 'recordBreaker');
        $record->setAccessible(true);
        $wants = new \ReflectionMethod($sup, 'breakerWantsPause');
        $wants->setAccessible(true);
        $down = ['status' => 'no_winner', 'has_winner' => false, 'scenarios_explored' => 0, 'proposals' => 0];

        $record->invoke($sup, $this->cid, $down);
        $record->invoke($sup, $this->cid, $down);
        $this->assertFalse($wants->invoke($sup, $campaign), 'not open at 2/3');
        $record->invoke($sup, $this->cid, $down);
        $this->assertTrue($wants->invoke($sup, $campaign), 'supervisor wants pause at 3 consecutive outages');

        // A live provider (explored scenarios) resets the streak — no pause.
        $record->invoke($sup, $this->cid, ['status' => 'no_winner', 'scenarios_explored' => 2]);
        $this->assertFalse($wants->invoke($sup, $campaign));

        // FLAG OFF ⇒ record is a no-op and the supervisor never wants a pause (byte-identical).
        config(['atlas.loop.provider_circuit_breaker_enabled' => false]);
        foreach (range(1, 5) as $_) {
            $record->invoke($sup, $this->cid, $down);
        }
        $this->assertFalse($wants->invoke($sup, $campaign), 'flag OFF ⇒ never pauses');
        $this->assertSame(0, $this->breaker()->streak($this->cid), 'flag OFF ⇒ nothing recorded');
    }
}
