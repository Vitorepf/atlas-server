<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Policy\RiskAssessmentService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class RiskAssessmentHashTest extends TestCase
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

    public function test_assess_derives_highest_risk_level_and_generates_hash(): void
    {
        $assessment = app(RiskAssessmentService::class)->assess(
            'tool',
            'browser.automation',
            [
                ['name' => 'login_required', 'risk_level' => PolicyCanon::RISK_HIGH],
                ['name' => 'cost_external', 'risk_level' => PolicyCanon::RISK_MEDIUM],
            ],
            [
                ['name' => 'sandbox', 'strength' => 'moderate'],
            ],
        );

        $this->assertSame(PolicyCanon::RISK_HIGH, $assessment->risk_level);
        $this->assertNotEmpty($assessment->assessment_hash);
        $this->assertSame(64, strlen((string) $assessment->assessment_hash));
        $this->assertNotNull($assessment->residual_risk);
        $this->assertContains($assessment->residual_risk, PolicyCanon::RISK_LEVELS);
    }

    public function test_no_factors_returns_low_level(): void
    {
        $assessment = app(RiskAssessmentService::class)->assess(
            'tool',
            'noop',
            [],
        );

        $this->assertSame(PolicyCanon::RISK_LOW, $assessment->risk_level);
    }
}
