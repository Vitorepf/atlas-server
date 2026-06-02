<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context\Gates;

use App\Services\Ai\Context\Gates\IrrelevantContextRatioGate;
use PHPUnit\Framework\TestCase;

final class IrrelevantContextRatioGateTest extends TestCase
{
    private IrrelevantContextRatioGate $gate;

    protected function setUp(): void
    {
        $this->gate = new IrrelevantContextRatioGate();
    }

    public function testRatioAboveCeilingIsBlockedWithDocumentedReason(): void
    {
        $result = $this->gate->evaluate(0.12, 0.05);

        $this->assertSame('atlas.context.irrelevant_ratio_gate.v1', $result['schema_version']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('irrelevant_ratio_exceeds_ceiling', $result['reason']);
        $this->assertSame(0.12, $result['irrelevant_ratio']);
        $this->assertSame(0.05, $result['max_allowed']);
    }

    public function testRatioExactlyAtCeilingIsReadyBoundaryInclusive(): void
    {
        $result = $this->gate->evaluate(0.2, 0.2);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('irrelevant_ratio_within_ceiling', $result['reason']);
        $this->assertSame(0.2, $result['irrelevant_ratio']);
        $this->assertSame(0.2, $result['max_allowed']);
    }

    public function testRatioBelowCeilingIsReady(): void
    {
        $result = $this->gate->evaluate(0.01, 0.05);

        $this->assertSame('ready', $result['status']);
        $this->assertSame('irrelevant_ratio_within_ceiling', $result['reason']);
        $this->assertSame(0.01, $result['irrelevant_ratio']);
    }

    public function testRatioAboveOneIsClampedToOne(): void
    {
        $result = $this->gate->evaluate(1.75, 0.05);

        $this->assertSame(1.0, $result['irrelevant_ratio']);
        $this->assertSame('blocked', $result['status']);
        $this->assertSame('irrelevant_ratio_exceeds_ceiling', $result['reason']);
    }

    public function testNonPositiveMaxAllowedIsClampedIntoOpenUnitInterval(): void
    {
        $result = $this->gate->evaluate(0.0, -0.4);

        $this->assertGreaterThan(0.0, $result['max_allowed']);
        $this->assertLessThanOrEqual(1.0, $result['max_allowed']);
        $this->assertSame(0.0, $result['irrelevant_ratio']);
        $this->assertSame('ready', $result['status']);
    }
}
