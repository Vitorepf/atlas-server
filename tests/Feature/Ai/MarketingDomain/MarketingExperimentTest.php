<?php

namespace Tests\Feature\Ai\MarketingDomain;

use App\Services\Ai\MarketingDomain\GrowthExperimentPlanService;
use App\Services\Ai\MarketingDomain\MarketingDomainCanon;
use App\Services\Ai\MarketingDomain\MarketingRuntimeService;
use InvalidArgumentException;
use Tests\Concerns\CreatesMarketingDomainTables;
use Tests\TestCase;

class MarketingExperimentTest extends TestCase
{
    use CreatesMarketingDomainTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createMarketingDomainTables();
    }

    protected function tearDown(): void
    {
        $this->dropMarketingDomainTables();
        parent::tearDown();
    }

    public function test_experiment_requires_hypothesis_metric_success_decision(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(GrowthExperimentPlanService::class)->plan($run, [
            'name' => 'E', 'hypothesis' => 'H', 'primary_metric' => 'm',
            'success_criterion' => '', 'decision_rule' => 'r',
            'variants' => [['name' => 'c'], ['name' => 'v']],
        ]);
    }

    public function test_experiment_requires_two_variants(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $this->expectException(InvalidArgumentException::class);
        app(GrowthExperimentPlanService::class)->plan($run, [
            'name' => 'E', 'hypothesis' => 'H', 'primary_metric' => 'm',
            'success_criterion' => 's', 'decision_rule' => 'r',
            'variants' => [['name' => 'only_control']],
        ]);
    }

    public function test_experiment_starts_proposed_and_requires_launch_approval(): void
    {
        $run = app(MarketingRuntimeService::class)->open('Atlas Vault', 'objective');
        $experiment = app(GrowthExperimentPlanService::class)->plan($run, [
            'name' => 'E', 'hypothesis' => 'H', 'primary_metric' => 'm',
            'success_criterion' => 's', 'decision_rule' => 'r',
            'variants' => [['name' => 'c'], ['name' => 'v']],
        ]);
        $this->assertSame(MarketingDomainCanon::EXPERIMENT_PROPOSED, $experiment->status);
        $this->assertTrue($experiment->metadata['requires_approval_to_launch']);
    }
}
