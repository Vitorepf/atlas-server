<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ContinuousRuntime;

use App\Services\Ai\SelfConstruction\ContinuousRuntime\AtlasAaeosAcosSimplifyCyclePlanner;
use App\Services\Ai\SelfConstruction\Governance\AtlasAaeosAcosLaneScope;
use PHPUnit\Framework\TestCase;

final class AtlasAaeosAcosSimplifyCyclePlannerTest extends TestCase
{
    public function test_plan_dry_run_skips_real_seed(): void
    {
        $plan = (new AtlasAaeosAcosSimplifyCyclePlanner)->plan([
            'dry_run' => true,
            'queue_claimable' => 10,
            'replenish_target' => 5,
        ]);

        $this->assertSame(AtlasAaeosAcosLaneScope::SLUG, $plan['scope']);
        $this->assertTrue($plan['dry_run']);
        $this->assertFalse($plan['queue']['needs_top_up']);

        $ids = array_column($plan['executable_steps'], 'id');
        $this->assertContains('brain_next', $ids);
        $this->assertContains('brain_seed_dry', $ids);
        $this->assertNotContains('brain_seed', $ids);
        $this->assertContains('brain_worker_prompt', $ids);
        $this->assertContains('task_worker_prompt', $ids);
        $this->assertSame('orchestration_only_external_workers_required', $plan['sovereignty_claim']);
        $this->assertContains('loc_raw', $plan['elite_contract']['forbidden_kpis']);
    }

    public function test_plan_adds_replenish_when_queue_dry(): void
    {
        $plan = (new AtlasAaeosAcosSimplifyCyclePlanner)->plan([
            'dry_run' => true,
            'queue_claimable' => 0,
            'replenish_target' => 5,
        ]);

        $this->assertTrue($plan['queue']['needs_top_up']);
        $this->assertContains('replenish_if_dry', array_column($plan['executable_steps'], 'id'));
    }

    public function test_non_dry_run_includes_real_seed_step(): void
    {
        $plan = (new AtlasAaeosAcosSimplifyCyclePlanner)->plan(['dry_run' => false]);

        $this->assertContains('brain_seed', array_column($plan['executable_steps'], 'id'));
    }
}
