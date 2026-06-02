<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognitive\ClaimCoherence;

use App\Services\Ai\Cognitive\ClaimCoherence\HedgeCertaintyConflictDetector;
use PHPUnit\Framework\TestCase;

final class HedgeCertaintyConflictDetectorTest extends TestCase
{
    private HedgeCertaintyConflictDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new HedgeCertaintyConflictDetector();
    }

    public function testHedgeAndAbsoluteTokenFlagConflict(): void
    {
        $result = $this->detector->detect(['maybe', 'always']);

        $this->assertSame('atlas.cognitive.claim_coherence.hedge_certainty_conflict.v1', $result['schema_version']);
        $this->assertSame(1, $result['hedgeCount']);
        $this->assertSame(1, $result['absoluteCount']);
        $this->assertSame(0.5, $result['ratio']);
        $this->assertTrue($result['conflict']);
        $this->assertSame('conflict', $result['status']);
    }

    public function testOnlyHedgesProduceNoConflictAndZeroRatio(): void
    {
        $result = $this->detector->detect(['maybe', 'possibly', 'might']);

        $this->assertSame(3, $result['hedgeCount']);
        $this->assertSame(0, $result['absoluteCount']);
        $this->assertSame(0.0, $result['ratio']);
        $this->assertFalse($result['conflict']);
        $this->assertSame('clean', $result['status']);
    }

    public function testEmptyTokensAvoidDivideByZero(): void
    {
        $result = $this->detector->detect([]);

        $this->assertSame(0, $result['hedgeCount']);
        $this->assertSame(0, $result['absoluteCount']);
        $this->assertSame(0.0, $result['ratio']);
        $this->assertFalse($result['conflict']);
        $this->assertSame('clean', $result['status']);
    }

    public function testTwoHedgeTwoAbsoluteGiveHalfRatioAndConflict(): void
    {
        $result = $this->detector->detect(['maybe', 'perhaps', 'never', 'definitely']);

        $this->assertSame(2, $result['hedgeCount']);
        $this->assertSame(2, $result['absoluteCount']);
        $this->assertSame(0.5, $result['ratio']);
        $this->assertTrue($result['conflict']);
        $this->assertSame('conflict', $result['status']);
    }

    public function testDetectionIsCaseInsensitive(): void
    {
        $result = $this->detector->detect(['ALWAYS', 'Maybe']);

        $this->assertSame(1, $result['hedgeCount']);
        $this->assertSame(1, $result['absoluteCount']);
        $this->assertSame(0.5, $result['ratio']);
        $this->assertTrue($result['conflict']);
        $this->assertSame('conflict', $result['status']);
    }
}
