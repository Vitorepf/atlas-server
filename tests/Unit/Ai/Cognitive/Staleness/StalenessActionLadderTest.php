<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Cognitive\Staleness;

use App\Services\Ai\Cognitive\Staleness\StalenessActionLadder;
use PHPUnit\Framework\TestCase;

final class StalenessActionLadderTest extends TestCase
{
    private StalenessActionLadder $ladder;

    protected function setUp(): void
    {
        $this->ladder = new StalenessActionLadder();
    }

    public function testCriticalHighRiskStaysBlockAndClampsWithoutEscalation(): void
    {
        $result = $this->ladder->action('critical', true);

        $this->assertSame(3, $result['base_rung']);
        $this->assertSame(3, $result['effective_rung']);
        $this->assertSame('block_until_reindex', $result['action']);
        $this->assertFalse($result['escalated']);
    }

    public function testStaleHighRiskEscalatesToBlock(): void
    {
        $result = $this->ladder->action('stale', true);

        $this->assertSame(2, $result['base_rung']);
        $this->assertSame(3, $result['effective_rung']);
        $this->assertSame('block_until_reindex', $result['action']);
        $this->assertTrue($result['escalated']);
    }

    public function testFreshLowRiskIsNone(): void
    {
        $result = $this->ladder->action('fresh', false);

        $this->assertSame(0, $result['base_rung']);
        $this->assertSame(0, $result['effective_rung']);
        $this->assertSame('none', $result['action']);
        $this->assertFalse($result['escalated']);
    }

    public function testAgingHighRiskBecomesReindexRequired(): void
    {
        $result = $this->ladder->action('aging', true);

        $this->assertSame(1, $result['base_rung']);
        $this->assertSame(2, $result['effective_rung']);
        $this->assertSame('reindex_required', $result['action']);
        $this->assertTrue($result['escalated']);
    }

    public function testUnknownSeverityHighRiskFailsSafeToBlock(): void
    {
        $result = $this->ladder->action('mystery', true);

        $this->assertSame(2, $result['base_rung']);
        $this->assertSame(3, $result['effective_rung']);
        $this->assertSame('block_until_reindex', $result['action']);
        $this->assertTrue($result['escalated']);
    }

    public function testUnknownSeverityLowRiskFailsSafeToStaleRung(): void
    {
        $result = $this->ladder->action('garbled-input', false);

        $this->assertSame(2, $result['base_rung']);
        $this->assertSame(2, $result['effective_rung']);
        $this->assertSame('reindex_required', $result['action']);
        $this->assertFalse($result['escalated']);
    }

    public function testAgingLowRiskIsReindexRecommendedWithoutEscalation(): void
    {
        $result = $this->ladder->action('aging', false);

        $this->assertSame(1, $result['base_rung']);
        $this->assertSame(1, $result['effective_rung']);
        $this->assertSame('reindex_recommended', $result['action']);
        $this->assertFalse($result['escalated']);
    }

    public function testFreshHighRiskEscalatesOneRung(): void
    {
        $result = $this->ladder->action('fresh', true);

        $this->assertSame(0, $result['base_rung']);
        $this->assertSame(1, $result['effective_rung']);
        $this->assertSame('reindex_recommended', $result['action']);
        $this->assertTrue($result['escalated']);
    }

    public function testStaleLowRiskIsReindexRequiredWithoutEscalation(): void
    {
        $result = $this->ladder->action('stale', false);

        $this->assertSame(2, $result['base_rung']);
        $this->assertSame(2, $result['effective_rung']);
        $this->assertSame('reindex_required', $result['action']);
        $this->assertFalse($result['escalated']);
    }

    public function testCriticalLowRiskIsBlockWithoutEscalation(): void
    {
        $result = $this->ladder->action('critical', false);

        $this->assertSame(3, $result['base_rung']);
        $this->assertSame(3, $result['effective_rung']);
        $this->assertSame('block_until_reindex', $result['action']);
        $this->assertFalse($result['escalated']);
    }

    public function testEnvelopeEchoesInputsAndSchemaVersion(): void
    {
        $result = $this->ladder->action('aging', true);

        $this->assertSame('atlas.cognitive.staleness.action_ladder.v1', $result['schema_version']);
        $this->assertSame('aging', $result['severity']);
        $this->assertTrue($result['high_risk']);
    }
}
