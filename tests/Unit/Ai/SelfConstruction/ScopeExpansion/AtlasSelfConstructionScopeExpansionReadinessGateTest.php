<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ScopeExpansion;

use App\Services\Ai\SelfConstruction\ScopeExpansion\AtlasSelfConstructionScopeExpansionReadinessGate;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionScopeExpansionReadinessGateTest extends TestCase
{
    private AtlasSelfConstructionScopeExpansionReadinessGate $gate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->gate = new AtlasSelfConstructionScopeExpansionReadinessGate();
    }

    // AC: blocks when any evidence missing or false
    public function test_missing_lane_isolation_blocked(): void
    {
        $result = $this->gate->evaluate([
            'project_receipt_policy' => true,
            'bounded_proof_plan' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing:lane_isolation_evidence', $result['blockers']);
    }

    public function test_missing_project_receipt_policy_blocked(): void
    {
        $result = $this->gate->evaluate([
            'lane_isolation_evidence' => true,
            'bounded_proof_plan' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing:project_receipt_policy', $result['blockers']);
    }

    public function test_missing_bounded_proof_plan_blocked(): void
    {
        $result = $this->gate->evaluate([
            'lane_isolation_evidence' => true,
            'project_receipt_policy' => true,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertContains('missing:bounded_proof_plan', $result['blockers']);
    }

    public function test_all_false_blocked_with_all_three_blockers(): void
    {
        $result = $this->gate->evaluate([
            'lane_isolation_evidence' => false,
            'project_receipt_policy' => false,
            'bounded_proof_plan' => false,
        ]);

        $this->assertFalse($result['ready']);
        $this->assertCount(3, $result['blockers']);
    }

    public function test_all_present_passes(): void
    {
        $result = $this->gate->evaluate([
            'lane_isolation_evidence' => true,
            'project_receipt_policy' => true,
            'bounded_proof_plan' => true,
        ]);

        $this->assertTrue($result['ready']);
        $this->assertEmpty($result['blockers']);
    }
}
