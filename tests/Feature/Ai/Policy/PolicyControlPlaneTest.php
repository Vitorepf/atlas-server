<?php

namespace Tests\Feature\Ai\Policy;

use App\Services\Ai\Policy\BudgetEnvelopeService;
use App\Services\Ai\Policy\PolicyCanon;
use App\Services\Ai\Policy\PolicyControlPlaneService;
use App\Services\Ai\Policy\PolicyProfileRegistryService;
use App\Services\Ai\Policy\RiskAssessmentService;
use App\Services\Ai\Policy\SafetyDecisionService;
use Tests\Concerns\CreatesPolicySafetyTables;
use Tests\TestCase;

class PolicyControlPlaneTest extends TestCase
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

    public function test_snapshot_contains_all_sections_and_aggregates(): void
    {
        app(PolicyProfileRegistryService::class)->seedDefaults();
        app(BudgetEnvelopeService::class)->open(PolicyCanon::SCOPE_GLOBAL, null, ['max_cost' => 10.0]);
        app(RiskAssessmentService::class)->assess('mission', 'm-1', [
            ['name' => 'cost_external', 'risk_level' => PolicyCanon::RISK_MEDIUM],
        ]);
        app(SafetyDecisionService::class)->decide([
            'requested_action' => 'programming.read',
            'gate_type' => 'permission',
            'risk_level' => 'low',
            'domain_id' => 'programming',
        ]);
        app(SafetyDecisionService::class)->decide([
            'requested_action' => 'marketing.publish',
            'gate_type' => 'permission',
            'risk_level' => 'medium',
            'domain_id' => 'marketing',
        ]);

        $snapshot = app(PolicyControlPlaneService::class)->snapshot();

        $this->assertSame('atlas.ai.policy.control_plane.v1', $snapshot['schema']);
        foreach (['profiles', 'gates', 'approvals', 'budgets', 'risks', 'decisions', 'forbidden_actions'] as $section) {
            $this->assertArrayHasKey($section, $snapshot, "control plane missing section [{$section}]");
            $this->assertArrayHasKey('count', $snapshot[$section]);
        }
        $this->assertSame(9, $snapshot['profiles']['count']);
        $this->assertGreaterThanOrEqual(2, $snapshot['gates']['count']);
        $this->assertGreaterThanOrEqual(1, $snapshot['approvals']['count']);
        $this->assertGreaterThanOrEqual(1, $snapshot['budgets']['count']);
        $this->assertGreaterThanOrEqual(1, $snapshot['risks']['count']);
        $this->assertGreaterThanOrEqual(2, $snapshot['decisions']['count']);
    }
}
