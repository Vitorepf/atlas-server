<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\Context;

use App\Services\Ai\Context\RetrievalFanoutGate;
use PHPUnit\Framework\TestCase;

final class RetrievalFanoutGateTest extends TestCase
{
    private RetrievalFanoutGate $gate;

    protected function setUp(): void
    {
        $this->gate = new RetrievalFanoutGate();
    }

    public function testAllDimensionsAboveThresholdRunOrderedByDescendingScore(): void
    {
        $result = $this->gate->gate([
            'memory' => 0.6,
            'code' => 0.9,
            'docs' => 0.7,
        ], 0.5);

        $this->assertSame(['code', 'docs', 'memory'], $result['run']);
        $this->assertSame([], $result['skipped']);
        $this->assertSame([
            'memory' => 'above_threshold',
            'code' => 'above_threshold',
            'docs' => 'above_threshold',
        ], $result['reasons']);
    }

    public function testMixedScoresRunAboveAndSkipBelowWithScoredReason(): void
    {
        $result = $this->gate->gate([
            'memory' => 0.8,
            'code' => 0.3,
            'docs' => 0.45,
        ], 0.5);

        $this->assertSame(['memory'], $result['run']);
        $this->assertSame(['code', 'docs'], $result['skipped']);
        $this->assertSame('above_threshold', $result['reasons']['memory']);
        $this->assertSame('below_threshold:0.3', $result['reasons']['code']);
        $this->assertSame('below_threshold:0.45', $result['reasons']['docs']);
    }

    public function testAllBelowThresholdForcesSingleTopDimensionIntoRun(): void
    {
        $result = $this->gate->gate([
            'memory' => 0.1,
            'code' => 0.4,
            'docs' => 0.2,
        ], 0.5);

        $this->assertSame(['code'], $result['run']);
        $this->assertSame(['memory', 'docs'], $result['skipped']);
        $this->assertSame('forced_top_relevance', $result['reasons']['code']);
        $this->assertSame('below_threshold:0.1', $result['reasons']['memory']);
        $this->assertSame('below_threshold:0.2', $result['reasons']['docs']);
    }

    public function testMissingAndNonNumericScoresAreTreatedAsZeroAndSkipped(): void
    {
        $result = $this->gate->gate([
            'memory' => 0.9,
            'code' => 'not-a-number',
            // docs missing entirely
        ], 0.5);

        $this->assertSame(['memory'], $result['run']);
        $this->assertSame(['code', 'docs'], $result['skipped']);
        $this->assertSame('below_threshold:0', $result['reasons']['code']);
        $this->assertSame('below_threshold:0', $result['reasons']['docs']);
    }

    public function testThresholdAboveOneIsClampedToOne(): void
    {
        $result = $this->gate->gate([
            'memory' => 1.0,
            'code' => 0.95,
            'docs' => 0.8,
        ], 1.75);

        // Only the perfect-score dimension clears the clamped 1.0 threshold.
        $this->assertSame(['memory'], $result['run']);
        $this->assertSame(['code', 'docs'], $result['skipped']);
        $this->assertSame('above_threshold', $result['reasons']['memory']);
        $this->assertSame('below_threshold:0.95', $result['reasons']['code']);
    }

    public function testAllBelowClampedThresholdStillNeverYieldsZeroRetrievers(): void
    {
        $result = $this->gate->gate([
            'memory' => 0.49,
            'code' => 0.2,
            'docs' => 0.1,
        ], 2.5);

        $this->assertCount(1, $result['run']);
        $this->assertSame(['memory'], $result['run']);
        $this->assertSame('forced_top_relevance', $result['reasons']['memory']);
        $this->assertSame(['code', 'docs'], $result['skipped']);
    }
}
