<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Evidence;

use App\Services\Ai\Evidence\OverrideJustificationSufficiencyClassifier;
use PHPUnit\Framework\TestCase;

final class OverrideJustificationSufficiencyClassifierTest extends TestCase
{
    private OverrideJustificationSufficiencyClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new OverrideJustificationSufficiencyClassifier();
    }

    public function testLongRationaleWithReferenceHashIsSufficient(): void
    {
        $rationale = 'Accepted after reviewing the failing gate and confirming the fix landed.';
        $this->assertGreaterThanOrEqual(32, strlen($rationale));

        $result = $this->classifier->classify([
            'decision' => 'approved',
            'target_risk' => 'high',
            'rationale' => $rationale,
            'referenced_hash' => 'sha256:abcdef0123456789',
        ]);

        $this->assertSame('sufficient', $result['verdict']);
        $this->assertSame(strlen($rationale), $result['rationale_length']);
        $this->assertTrue($result['has_reference_hash']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testLongRationaleWithoutHashIsThin(): void
    {
        $rationale = 'This rationale is comfortably longer than the thirty two character minimum threshold.';
        $this->assertGreaterThanOrEqual(32, strlen($rationale));

        $result = $this->classifier->classify([
            'decision' => 'approved',
            'risk_tier' => 'low',
            'reason' => $rationale,
        ]);

        $this->assertSame('thin', $result['verdict']);
        $this->assertSame(strlen($rationale), $result['rationale_length']);
        $this->assertFalse($result['has_reference_hash']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testShortNonEmptyRationaleIsThin(): void
    {
        $result = $this->classifier->classify([
            'decision' => 'accept',
            'target_risk' => 'low',
            'rationale' => 'looks fine',
            'finding_hash' => 'sha256:deadbeef',
        ]);

        $this->assertSame('thin', $result['verdict']);
        $this->assertSame(10, $result['rationale_length']);
        $this->assertTrue($result['has_reference_hash']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testEmptyRationaleIsAbsent(): void
    {
        $result = $this->classifier->classify([
            'decision' => 'rejected',
            'target_risk' => 'low',
            'rationale' => '   ',
        ]);

        $this->assertSame('absent', $result['verdict']);
        $this->assertSame(0, $result['rationale_length']);
        $this->assertFalse($result['has_reference_hash']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['reasons']);
    }

    public function testHighRiskAcceptWithEmptyRationaleFailsClosedToAbsent(): void
    {
        $result = $this->classifier->classify([
            'decision' => 'approved',
            'target_risk' => 'critical',
            'rationale' => '',
        ]);

        $this->assertSame('absent', $result['verdict']);
        $this->assertSame(0, $result['rationale_length']);
        $this->assertTrue($result['fail_closed']);
        $this->assertSame(['high_risk_accept_empty_rationale'], $result['reasons']);
    }

    public function testExactlyThirtyTwoCharsWithoutHashIsThinAtBoundary(): void
    {
        $rationale = str_repeat('a', 32);
        $this->assertSame(32, strlen($rationale));

        $result = $this->classifier->classify([
            'decision' => 'approved',
            'target_risk' => 'high',
            'rationale' => $rationale,
        ]);

        $this->assertSame('thin', $result['verdict']);
        $this->assertSame(32, $result['rationale_length']);
        $this->assertFalse($result['has_reference_hash']);
        $this->assertFalse($result['fail_closed']);
        $this->assertSame([], $result['reasons']);
    }
}
