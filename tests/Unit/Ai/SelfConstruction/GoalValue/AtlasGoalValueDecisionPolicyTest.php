<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\GoalValue;

use App\Services\Ai\SelfConstruction\GoalValue\AtlasGoalValueDecisionPolicy;
use PHPUnit\Framework\TestCase;

final class AtlasGoalValueDecisionPolicyTest extends TestCase
{
    private AtlasGoalValueDecisionPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new AtlasGoalValueDecisionPolicy();
    }

    // AC: green + impl evidence but no compounding/autonomy/consumer → revise or defer
    public function test_green_with_impl_but_no_leverage_is_deferred(): void
    {
        $result = $this->policy->decide([
            'verification_status' => 'green',
            'has_implementation_evidence' => true,
        ]);

        $this->assertNotSame('promote', $result['decision']);
        $this->assertTrue(in_array($result['decision'], ['revise', 'defer'], true));
    }

    public function test_green_with_compounding_metric_promoted(): void
    {
        $result = $this->policy->decide([
            'verification_status' => 'green',
            'has_implementation_evidence' => true,
            'compounding_metric' => 'give_back_rate_improved',
        ]);

        $this->assertSame('promote', $result['decision']);
    }

    public function test_green_with_autonomy_unlock_promoted(): void
    {
        $result = $this->policy->decide([
            'verification_status' => 'green',
            'has_implementation_evidence' => true,
            'autonomy_unlock' => true,
        ]);

        $this->assertSame('promote', $result['decision']);
    }

    public function test_green_with_downstream_consumer_promoted(): void
    {
        $result = $this->policy->decide([
            'verification_status' => 'green',
            'has_implementation_evidence' => true,
            'downstream_consumer_evidence' => true,
        ]);

        $this->assertSame('promote', $result['decision']);
    }

    public function test_not_green_is_revised(): void
    {
        $result = $this->policy->decide([
            'verification_status' => 'yellow',
            'has_implementation_evidence' => true,
            'compounding_metric' => 'x',
        ]);

        $this->assertSame('revise', $result['decision']);
    }

    public function test_green_no_impl_evidence_revised(): void
    {
        $result = $this->policy->decide([
            'verification_status' => 'green',
            'has_implementation_evidence' => false,
            'compounding_metric' => 'x',
        ]);

        $this->assertSame('revise', $result['decision']);
    }
}
