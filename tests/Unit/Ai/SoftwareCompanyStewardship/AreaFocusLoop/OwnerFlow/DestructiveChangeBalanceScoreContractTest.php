<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\DestructiveChangeBalanceScoreContract;
use PHPUnit\Framework\TestCase;

final class DestructiveChangeBalanceScoreContractTest extends TestCase
{
    private DestructiveChangeBalanceScoreContract $contract;

    protected function setUp(): void
    {
        $this->contract = new DestructiveChangeBalanceScoreContract();
    }

    public function testBigGuttingWithTrivialAddsIsRemovalDominant(): void
    {
        $result = $this->contract->toArray(5, 100, 3, 50);

        $this->assertSame(DestructiveChangeBalanceScoreContract::SCHEMA_VERSION, $result['schema_version']);
        $this->assertSame('removal_dominant_low_replacement', $result['verdict']);
        $this->assertTrue($result['removal_dominant']);
        $this->assertSame('removal_dominant_low_replacement', $result['reason']);
        $this->assertTrue($result['signals']['removals_meet_floor']);
        $this->assertTrue($result['signals']['product_growth_trivial']);
    }

    public function testHealthyAddHeavyChangeIsBalanced(): void
    {
        $result = $this->contract->toArray(50, 10, 40, 5);

        $this->assertSame('balanced', $result['verdict']);
        $this->assertFalse($result['removal_dominant']);
        $this->assertSame('change_balance_within_tolerance', $result['reason']);
        $this->assertSame(40, $result['net_balance']);
    }

    public function testSubstantialProductGrowthDefeatsHighRemovalRatio(): void
    {
        $result = $this->contract->toArray(10, 50, 20, 10);

        $this->assertSame('balanced', $result['verdict']);
        $this->assertFalse($result['removal_dominant']);
        $this->assertFalse($result['signals']['product_growth_trivial']);
        $this->assertSame(5.0, $result['removal_ratio']);
    }

    public function testRemovalsBelowFloorStayBalancedAtAnyRatio(): void
    {
        $result = $this->contract->toArray(1, 29, 0, 0);

        $this->assertSame('balanced', $result['verdict']);
        $this->assertFalse($result['removal_dominant']);
        $this->assertFalse($result['signals']['removals_meet_floor']);
        $this->assertSame(29.0, $result['removal_ratio']);
    }

    public function testFourInsertionsAndEightyRemovalsExposeNetBalanceAndRatio(): void
    {
        $result = $this->contract->toArray(4, 80, 0, 0);

        $this->assertSame(-76, $result['net_balance']);
        $this->assertSame(20.0, $result['removal_ratio']);
        $this->assertSame('removal_dominant_low_replacement', $result['verdict']);
    }

    public function testNegativeInputsAreClampedToZero(): void
    {
        $result = $this->contract->toArray(-10, -20, -5, -8);

        $this->assertSame(0, $result['signals']['total_insertions']);
        $this->assertSame(0, $result['signals']['total_removals']);
        $this->assertSame(0, $result['signals']['product_insertions']);
        $this->assertSame(0, $result['signals']['product_removals']);
        $this->assertSame(0, $result['net_balance']);
        $this->assertSame(0.0, $result['removal_ratio']);
        $this->assertSame('balanced', $result['verdict']);
    }

    public function testBoundaryRatioExactlyAtFloorIsRemovalDominant(): void
    {
        $result = $this->contract->toArray(10, 30, 5, 0);

        $this->assertSame(3.0, $result['removal_ratio']);
        $this->assertSame('removal_dominant_low_replacement', $result['verdict']);
    }

    public function testOutputIsDeterministicForIdenticalInputs(): void
    {
        $first = $this->contract->toArray(25, 100, 3, 20);
        $second = $this->contract->toArray(25, 100, 3, 20);

        $this->assertSame($first, $second);
    }
}
