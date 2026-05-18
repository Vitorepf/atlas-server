<?php

namespace Tests\Feature\Ai\Strategy;

use App\Services\Ai\Strategy\OpportunityRadarService;
use App\Services\Ai\Strategy\StrategyDomainException;
use Tests\Concerns\CreatesStrategyRuntimeTables;
use Tests\TestCase;

class StrategyDomainOpportunityTest extends TestCase
{
    use CreatesStrategyRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createStrategyRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropStrategyRuntimeTables();
        parent::tearDown();
    }

    public function test_opportunity_creation_requires_problem_icp_pain_market_competitors_risks(): void
    {
        /** @var OpportunityRadarService $svc */
        $svc = app(OpportunityRadarService::class);

        $opportunity = $svc->create([
            'title' => 'Faster CLI bootstrap',
            'problem' => 'Bootstrap takes 90s on a clean install.',
            'icp' => 'Solo operators bootstrapping daily.',
            'pain' => 'Friction kills daily-driver loops.',
            'urgency' => OpportunityRadarService::URGENCY_HIGH,
            'market' => ['size' => 'mid', 'segments' => ['operators']],
            'competitors' => [['name' => 'manual setup']],
            'risks' => ['adoption_risk'],
        ]);

        $this->assertNotEmpty($opportunity->opportunity_hash);
        $this->assertSame(OpportunityRadarService::STATUS_PROPOSED, $opportunity->status);
    }

    public function test_opportunity_creation_rejects_missing_pain(): void
    {
        /** @var OpportunityRadarService $svc */
        $svc = app(OpportunityRadarService::class);

        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'title' => 'Missing pain',
            'problem' => 'X.',
            'icp' => 'Y.',
            'pain' => '',
            'urgency' => OpportunityRadarService::URGENCY_LOW,
            'market' => ['size' => 'small'],
            'competitors' => [['name' => 'none']],
            'risks' => ['none'],
        ]);
    }

    public function test_opportunity_creation_rejects_invalid_urgency(): void
    {
        /** @var OpportunityRadarService $svc */
        $svc = app(OpportunityRadarService::class);

        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'title' => 'Invalid urgency',
            'problem' => 'X.',
            'icp' => 'Y.',
            'pain' => 'Z.',
            'urgency' => 'urgent-now',
            'market' => ['size' => 'small'],
            'competitors' => [['name' => 'none']],
            'risks' => ['none'],
        ]);
    }

    public function test_opportunity_creation_rejects_empty_competitors(): void
    {
        /** @var OpportunityRadarService $svc */
        $svc = app(OpportunityRadarService::class);

        $this->expectException(StrategyDomainException::class);
        $svc->create([
            'title' => 'No competitors',
            'problem' => 'X.',
            'icp' => 'Y.',
            'pain' => 'Z.',
            'urgency' => OpportunityRadarService::URGENCY_MEDIUM,
            'market' => ['size' => 'small'],
            'competitors' => [],
            'risks' => ['none'],
        ]);
    }
}
