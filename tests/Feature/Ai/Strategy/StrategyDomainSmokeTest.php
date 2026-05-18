<?php

namespace Tests\Feature\Ai\Strategy;

use App\Models\AiExperimentPlan;
use App\Models\AiOpportunity;
use App\Models\AiStrategyMemo;
use App\Models\AiStrategyRun;
use App\Models\AiVentureBlueprint;
use App\Services\Ai\Strategy\ExperimentPlanService;
use App\Services\Ai\Strategy\StrategyMemoService;
use App\Services\Ai\Strategy\StrategyRuntimeService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainSmokeTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_smoke_command_runs_full_chain_and_persists_records(): void
    {
        $exit = $this->artisan('atlas:ai:strategy-domain', [
            '--action' => 'smoke',
            '--json' => true,
        ])->run();

        $this->assertSame(0, $exit);

        $this->assertGreaterThan(0, AiStrategyRun::query()->count());
        $this->assertGreaterThan(0, AiOpportunity::query()->count());
        $this->assertGreaterThan(0, AiVentureBlueprint::query()->count());
        $this->assertGreaterThan(0, AiExperimentPlan::query()->count());
        $this->assertGreaterThan(0, AiStrategyMemo::query()->count());

        $latestRun = AiStrategyRun::query()->latest('created_at')->first();
        $this->assertNotNull($latestRun);
        $this->assertSame(StrategyRuntimeService::STATUS_DECIDED, $latestRun->status);
        $this->assertNotNull($latestRun->completed_at);

        $latestMemo = AiStrategyMemo::query()->latest('created_at')->first();
        $this->assertSame(StrategyMemoService::STATUS_DECIDED, $latestMemo->status);

        $latestExperiment = AiExperimentPlan::query()->latest('created_at')->first();
        $this->assertSame(ExperimentPlanService::STATUS_DECIDED, $latestExperiment->status);
        $this->assertIsArray($latestExperiment->decision);
        $this->assertSame(ExperimentPlanService::DECISION_SCALE, $latestExperiment->decision['kind']);
    }
}
