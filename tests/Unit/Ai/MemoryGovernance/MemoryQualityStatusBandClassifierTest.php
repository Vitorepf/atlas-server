<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\MemoryGovernance;

use App\Services\Ai\MemoryGovernance\MemoryQualityStatusBandClassifier;
use Tests\TestCase;

final class MemoryQualityStatusBandClassifierTest extends TestCase
{
    private MemoryQualityStatusBandClassifier $classifier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->classifier = new MemoryQualityStatusBandClassifier();
    }

    public function testEmptyWhenNoActiveMemoryEvenWithHighScore(): void
    {
        $result = $this->classifier->classify(0, 90, false);

        $this->assertSame('atlas.memory_governance.quality_status_band.v1', $result['schema_version']);
        $this->assertSame('empty', $result['status']);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['injection_allowed']);
        $this->assertSame('no_active_memory', $result['reason']);
    }

    public function testEmptyRuleOutranksCriticalIssueFlag(): void
    {
        // Rule (1) activeCount<1 -> empty must be evaluated before rule (2)
        // hasCriticalIssue -> critical. With zero active memory AND a critical
        // issue flagged, the first ordered rule wins: status is 'empty', not
        // 'critical'. A regression that checked hasCriticalIssue before the
        // active-count guard would return 'critical' here and pass every other
        // case in this suite, so this asserts the precedence directly.
        $result = $this->classifier->classify(0, 92, true);

        $this->assertSame('empty', $result['status']);
        $this->assertSame('no_active_memory', $result['reason']);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['injection_allowed']);
    }

    public function testReadyWhenHighScoreAndNoCriticalIssue(): void
    {
        $result = $this->classifier->classify(10, 92, false);

        $this->assertSame('ready', $result['status']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['injection_allowed']);
        $this->assertSame('composite_score_meets_ready_floor', $result['reason']);
    }

    public function testCriticalIssueOverridesHighScore(): void
    {
        $result = $this->classifier->classify(10, 92, true);

        $this->assertSame('critical', $result['status']);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['injection_allowed']);
        $this->assertSame('critical_issue_present', $result['reason']);
    }

    public function testWatchBandAllowsInjection(): void
    {
        $result = $this->classifier->classify(10, 72, false);

        $this->assertSame('watch', $result['status']);
        $this->assertTrue($result['ok']);
        $this->assertTrue($result['injection_allowed']);
        $this->assertSame('composite_score_below_watch_floor', $result['reason']);
    }

    public function testNeedsReviewProvesOkDecoupledFromInjectionAllowed(): void
    {
        $result = $this->classifier->classify(10, 60, false);

        $this->assertSame('needs_review', $result['status']);
        $this->assertTrue($result['ok']);
        $this->assertFalse($result['injection_allowed']);
        $this->assertNotSame($result['ok'], $result['injection_allowed']);
        $this->assertSame('composite_score_below_needs_review_floor', $result['reason']);
    }

    public function testLowScoreWithoutCriticalIssueIsCritical(): void
    {
        $result = $this->classifier->classify(10, 49, false);

        $this->assertSame('critical', $result['status']);
        $this->assertFalse($result['ok']);
        $this->assertFalse($result['injection_allowed']);
        $this->assertSame('composite_score_below_critical_floor', $result['reason']);
    }

    public function testBoundaryScoresMapToBandsExactly(): void
    {
        $this->assertSame('critical', $this->classifier->classify(10, 49, false)['status']);
        $this->assertSame('needs_review', $this->classifier->classify(10, 50, false)['status']);
        $this->assertSame('needs_review', $this->classifier->classify(10, 69, false)['status']);
        $this->assertSame('watch', $this->classifier->classify(10, 70, false)['status']);
        $this->assertSame('watch', $this->classifier->classify(10, 84, false)['status']);
        $this->assertSame('ready', $this->classifier->classify(10, 85, false)['status']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $first = $this->classifier->classify(7, 73, false);
        $second = $this->classifier->classify(7, 73, false);

        $this->assertSame($first, $second);
    }
}
