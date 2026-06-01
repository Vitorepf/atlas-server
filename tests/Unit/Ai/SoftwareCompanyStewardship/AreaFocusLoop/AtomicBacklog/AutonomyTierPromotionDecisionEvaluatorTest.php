<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AtomicBacklog\AutonomyTierPromotionDecisionEvaluator;
use PHPUnit\Framework\TestCase;

final class AutonomyTierPromotionDecisionEvaluatorTest extends TestCase
{
    private AutonomyTierPromotionDecisionEvaluator $evaluator;

    protected function setUp(): void
    {
        $this->evaluator = new AutonomyTierPromotionDecisionEvaluator();
    }

    public function testSignedApprovedReceiptMovesTierZeroToOne(): void
    {
        $result = $this->evaluator->decide(
            [
                'operator_signed' => true,
                'approved' => true,
                'requested_tier' => 1,
            ],
            [
                'registered' => true,
                'active_tier' => 0,
                'max_autonomy_tier' => 1,
            ],
            [
                'budget_available' => true,
                'kill_switch_active' => false,
            ],
        );

        $this->assertSame('atlas.loop.autonomy_tier_promotion_decision.v1', $result['schema_version']);
        $this->assertSame('promote', $result['decision']);
        $this->assertSame(1, $result['active_tier']);
        $this->assertSame([], $result['blockers']);
        $this->assertTrue($result['receipt_required']);
    }

    public function testMissingSignatureBlocksAtCurrentTier(): void
    {
        $result = $this->evaluator->decide(
            [
                'approved' => true,
                'requested_tier' => 1,
            ],
            [
                'registered' => true,
                'active_tier' => 0,
                'max_autonomy_tier' => 1,
            ],
            [
                'budget_available' => true,
            ],
        );

        $this->assertSame('block', $result['decision']);
        $this->assertSame(0, $result['active_tier']);
        $this->assertSame(['operator_receipt_signature_missing'], $result['blockers']);
        $this->assertTrue($result['receipt_required']);
    }

    public function testKillSwitchAlwaysBlocks(): void
    {
        $result = $this->evaluator->decide(
            [
                'operator_signed' => true,
                'approved' => true,
                'requested_tier' => 1,
            ],
            [
                'registered' => true,
                'active_tier' => 0,
                'max_autonomy_tier' => 1,
            ],
            [
                'budget_available' => true,
                'kill_switch_active' => true,
            ],
        );

        $this->assertSame('block', $result['decision']);
        $this->assertSame(0, $result['active_tier']);
        $this->assertSame(['kill_switch_active'], $result['blockers']);
    }

    public function testRequestedTierAboveMaxBlocks(): void
    {
        $result = $this->evaluator->decide(
            [
                'operator_signed' => true,
                'approved' => true,
                'requested_tier' => 2,
            ],
            [
                'registered' => true,
                'active_tier' => 0,
                'max_autonomy_tier' => 1,
            ],
            [
                'budget_available' => true,
            ],
        );

        $this->assertSame('block', $result['decision']);
        $this->assertSame(0, $result['active_tier']);
        $this->assertSame(['requested_tier_above_max'], $result['blockers']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $receipt = [
            'operator_signed' => true,
            'approved' => true,
            'requested_tier' => 1,
        ];
        $areaState = [
            'registered' => true,
            'active_tier' => 0,
            'max_autonomy_tier' => 1,
        ];
        $runtimeSignals = [
            'budget_available' => true,
            'kill_switch_active' => false,
        ];

        $first = $this->evaluator->decide($receipt, $areaState, $runtimeSignals);
        $second = $this->evaluator->decide($receipt, $areaState, $runtimeSignals);

        $this->assertSame($first, $second);
    }

    public function testUnregisteredAreaAndUnavailableBudgetBlockWithExplicitReasons(): void
    {
        $result = $this->evaluator->decide(
            [
                'operator_signed' => true,
                'approved' => true,
                'requested_tier' => 1,
            ],
            [
                'registered' => false,
                'active_tier' => 0,
                'max_autonomy_tier' => 1,
            ],
            [
                'budget_available' => false,
            ],
        );

        $this->assertSame('block', $result['decision']);
        $this->assertSame(0, $result['active_tier']);
        $this->assertSame(['area_not_registered', 'budget_not_available'], $result['blockers']);
    }
}
