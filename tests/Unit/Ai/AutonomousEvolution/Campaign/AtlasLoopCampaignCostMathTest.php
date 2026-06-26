<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Campaign;

use App\Models\AtlasLoopCampaign;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignCostGovernor;
use App\Services\Ai\AutonomousEvolution\Campaign\AtlasLoopCampaignCostMath;
use Tests\TestCase;

class AtlasLoopCampaignCostMathTest extends TestCase
{
    private function makeGovernor(): AtlasLoopCampaignCostGovernor
    {
        // The real AtlasLoopCampaignCostGovernor reads config; config is fine.
        return new AtlasLoopCampaignCostGovernor();
    }

    private function makeCampaign(int $maxCents = 1000, int $spendCents = 0): AtlasLoopCampaign
    {
        $c = new AtlasLoopCampaign();
        $c->max_usd_cents = $maxCents;
        $c->spend_usd_cents = $spendCents;

        return $c;
    }

    public function test_grind_timeout_returns_task_cap_when_no_budget(): void
    {
        self::assertSame(1800, AtlasLoopCampaignCostMath::grindTimeout(null, 1800));
    }

    public function test_grind_timeout_enforces_minimum_cap(): void
    {
        // taskCap=30 → floored to 60
        self::assertSame(60, AtlasLoopCampaignCostMath::grindTimeout(null, 30));
        self::assertSame(60, AtlasLoopCampaignCostMath::grindTimeout(100, 30));
    }

    public function test_grind_timeout_returns_min_of_budget_and_cap(): void
    {
        self::assertSame(300, AtlasLoopCampaignCostMath::grindTimeout(300, 1800));
    }

    public function test_grind_timeout_enforces_minimum_floor_when_budget_provided(): void
    {
        // budget=2 → floored to 5
        self::assertSame(5, AtlasLoopCampaignCostMath::grindTimeout(2, 1800));
    }

    public function test_grind_timeout_deterministic(): void
    {
        self::assertSame(
            AtlasLoopCampaignCostMath::grindTimeout(120, 600),
            AtlasLoopCampaignCostMath::grindTimeout(120, 600),
        );
    }

    public function test_cost_governor_decision_delegates(): void
    {
        $governor = $this->makeGovernor();
        $campaign = $this->makeCampaign(5000, 100);

        $result = AtlasLoopCampaignCostMath::costGovernorDecision($governor, $campaign, 3);

        self::assertArrayHasKey('status', $result);
        self::assertArrayHasKey('spend_usd_cents', $result);
        self::assertSame(100, $result['spend_usd_cents']);
        self::assertSame(5000, $result['max_usd_cents']);
    }

    public function test_cost_governor_decision_without_scenarios_uses_default(): void
    {
        $governor = $this->makeGovernor();
        $campaign = $this->makeCampaign();

        $result = AtlasLoopCampaignCostMath::costGovernorDecision($governor, $campaign, null);

        self::assertIsArray($result);
        self::assertArrayHasKey('base_scenarios_per_task', $result);
    }

    public function test_spend_cents_from_result_delegates(): void
    {
        $governor = $this->makeGovernor();

        self::assertSame(0, AtlasLoopCampaignCostMath::spendCentsFromResult($governor, []));
    }

    public function test_spend_cents_from_worker_summaries_delegates(): void
    {
        $governor = $this->makeGovernor();

        self::assertSame(0, AtlasLoopCampaignCostMath::spendCentsFromWorkerSummaries($governor, []));
    }

    public function test_cost_governor_decision_matches_direct_call(): void
    {
        $governor = $this->makeGovernor();
        $campaign = $this->makeCampaign(10000, 500);

        $viaMath = AtlasLoopCampaignCostMath::costGovernorDecision($governor, $campaign, 5);
        $direct = $governor->costGovernorDecision($campaign, 5);

        self::assertSame($direct, $viaMath);
    }
}