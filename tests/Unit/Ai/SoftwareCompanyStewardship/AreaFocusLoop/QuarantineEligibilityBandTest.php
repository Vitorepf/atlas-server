<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\QuarantineEligibilityBand;
use PHPUnit\Framework\TestCase;

final class QuarantineEligibilityBandTest extends TestCase
{
    private QuarantineEligibilityBand $band;

    protected function setUp(): void
    {
        $this->band = new QuarantineEligibilityBand();
    }

    public function testHighScoreClassifiesAsQuarantine(): void
    {
        $result = $this->band->classify(85);

        $this->assertSame('quarantine', $result['band']);
        $this->assertSame('quarantine_now', $result['action']);
        $this->assertSame(85, $result['score']);
    }

    public function testLowerQuarantineBoundaryIsInclusive(): void
    {
        $result = $this->band->classify(80);

        $this->assertSame('quarantine', $result['band']);
        $this->assertSame('quarantine_now', $result['action']);
        $this->assertSame(80, $result['score']);
    }

    public function testScoreJustBelowQuarantineBoundaryIsRetryBounded(): void
    {
        $result = $this->band->classify(79);

        $this->assertSame('retry_bounded', $result['band']);
        $this->assertSame('allow_bounded_retry', $result['action']);
        $this->assertSame(79, $result['score']);
    }

    public function testLowerRetryBoundedBoundaryIsInclusive(): void
    {
        $result = $this->band->classify(40);

        $this->assertSame('retry_bounded', $result['band']);
        $this->assertSame('allow_bounded_retry', $result['action']);
        $this->assertSame(40, $result['score']);
    }

    public function testLowScoreClassifiesAsKeep(): void
    {
        $result = $this->band->classify(5);

        $this->assertSame('keep', $result['band']);
        $this->assertSame('keep_selectable', $result['action']);
        $this->assertSame(5, $result['score']);
    }

    public function testScoreAboveOneHundredClampsToQuarantine(): void
    {
        $result = $this->band->classify(150);

        $this->assertSame('quarantine', $result['band']);
        $this->assertSame('quarantine_now', $result['action']);
        $this->assertSame(100, $result['score']);
    }

    public function testNegativeScoreClampsToZeroAndKeep(): void
    {
        $result = $this->band->classify(-10);

        $this->assertSame('keep', $result['band']);
        $this->assertSame('keep_selectable', $result['action']);
        $this->assertSame(0, $result['score']);
    }

    public function testScoreJustBelowRetryBoundedBoundaryIsKeep(): void
    {
        $result = $this->band->classify(39);

        $this->assertSame('keep', $result['band']);
        $this->assertSame('keep_selectable', $result['action']);
        $this->assertSame(39, $result['score']);
    }

    public function testResultContainsAllRequiredKeys(): void
    {
        $result = $this->band->classify(50);

        $this->assertArrayHasKey('band', $result);
        $this->assertArrayHasKey('action', $result);
        $this->assertArrayHasKey('score', $result);
    }
}
