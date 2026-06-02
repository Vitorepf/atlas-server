<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognitive\ClaimCoherence;

use App\Services\Ai\Cognitive\ClaimCoherence\ClaimQualifierStrengthClassifier;
use PHPUnit\Framework\TestCase;

final class ClaimQualifierStrengthClassifierTest extends TestCase
{
    private ClaimQualifierStrengthClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new ClaimQualifierStrengthClassifier();
    }

    public function testEmptyTokensYieldNoneBandWithZeroModalCount(): void
    {
        $result = $this->classifier->classify([]);

        $this->assertSame('atlas.cognitive.claim_coherence.qualifier_strength.v1', $result['schema_version']);
        $this->assertSame(0, $result['modalCount']);
        $this->assertSame('none', $result['band']);
        $this->assertSame('classified', $result['status']);
    }

    public function testExactlyTwoModalsIsUpperSoftBoundary(): void
    {
        $result = $this->classifier->classify(['must', 'shall']);

        $this->assertSame(2, $result['modalCount']);
        $this->assertSame('soft', $result['band']);
        $this->assertSame('classified', $result['status']);
    }

    public function testExactlyThreeModalsIsJustAboveSoftAndBecomesHard(): void
    {
        $result = $this->classifier->classify(['must', 'shall', 'will']);

        $this->assertSame(3, $result['modalCount']);
        $this->assertSame('hard', $result['band']);
        $this->assertSame('classified', $result['status']);
    }

    public function testNonModalTokensYieldZeroModalCountAndNoneBand(): void
    {
        $result = $this->classifier->classify(['maybe', 'sky']);

        $this->assertSame(0, $result['modalCount']);
        $this->assertSame('none', $result['band']);
        $this->assertSame('classified', $result['status']);
    }

    public function testMixedCaseModalsAreNormalizedAndCountedAsHard(): void
    {
        $result = $this->classifier->classify(['MUST', 'Shall', 'will']);

        $this->assertSame(3, $result['modalCount']);
        $this->assertSame('hard', $result['band']);
        $this->assertSame('classified', $result['status']);
    }
}
