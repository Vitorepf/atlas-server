<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\PolicyReadinessService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class PolicyReadinessTest extends TestCase
{
    use CreatesPolicySafetyTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createPolicySafetyTables();
    }

    protected function tearDown(): void
    {
        $this->dropPolicySafetyTables();
        parent::tearDown();
    }

    public function test_readiness_reports_blockers_when_defaults_not_seeded(): void
    {
        $report = app(PolicyReadinessService::class)->report();

        $this->assertFalse($report['ok'], 'expected readiness to fail before seeding defaults');
        $names = collect($report['checks'])->pluck('name')->all();
        $this->assertContains('defaults:policy_profiles_seeded', $names);
        $this->assertContains('table:ai_policy_profiles', $names);
        $this->assertContains('service:PolicyControlPlaneService', $names);
    }

    public function test_readiness_returns_ok_after_seeding_defaults(): void
    {
        app(PolicyProfileRegistryService::class)->seedDefaults();
        $report = app(PolicyReadinessService::class)->report();

        $this->assertTrue($report['ok'], 'expected readiness ok=true after seed; got '.json_encode($report['summary']));
        $this->assertSame(0, $report['summary']['failed']);
        $this->assertSame('atlas.ai.policy.readiness.v1', $report['schema']);
    }
}
