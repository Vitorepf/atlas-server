<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aemor\Judgment;

use App\Services\Ai\Aemor\Judgment\AemorJudgmentQualityScorer;
use Tests\TestCase;

final class AemorJudgmentQualityScorerTest extends TestCase
{
    private const BASE = 50;
    private const EVIDENCE_PRESENT = 15;
    private const EVIDENCE_MISSING = -30;
    private const FALSE_LEARNING_PASS = 20;
    private const FALSE_LEARNING_FAIL = -20;
    private const REPEATED_FAILURE_CLEAR = 10;
    private const REPEATED_FAILURE_FAIL = -15;
    private const ROI_HIGH = 10;
    private const ROI_LOW = -10;

    private AemorJudgmentQualityScorer $scorer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scorer = new AemorJudgmentQualityScorer();
    }

    private static function clamp(int $raw): int
    {
        return max(0, min(100, $raw));
    }

    public function testStrongScoreClampsAtUpperBound(): void
    {
        $raw = self::BASE + self::EVIDENCE_PRESENT + self::FALSE_LEARNING_PASS
            + self::REPEATED_FAILURE_CLEAR + self::ROI_HIGH;
        $this->assertSame(105, $raw);

        $result = $this->scorer->score(2, 'pass', 'clear', 90);

        $this->assertSame(self::clamp($raw), $result['score']);
        $this->assertSame(100, $result['score']);
        $this->assertSame('strong', $result['status']);
    }

    public function testFullPenaltyScoreClampsAtLowerBound(): void
    {
        $raw = self::BASE + self::EVIDENCE_MISSING + self::FALSE_LEARNING_FAIL
            + self::REPEATED_FAILURE_FAIL + self::ROI_LOW;
        $this->assertSame(-25, $raw);

        $result = $this->scorer->score(0, 'blocked_for_learning', 'blocked', 10);

        $this->assertSame(self::clamp($raw), $result['score']);
        $this->assertSame(0, $result['score']);
        $this->assertSame('weak', $result['status']);
    }

    public function testLowRoiAppliesNegativeWeightButStaysStrong(): void
    {
        $raw = self::BASE + self::EVIDENCE_PRESENT + self::FALSE_LEARNING_PASS
            + self::REPEATED_FAILURE_CLEAR + self::ROI_LOW;
        $this->assertSame(85, $raw);

        $result = $this->scorer->score(2, 'pass', 'clear', 60);

        $this->assertSame(self::clamp($raw), $result['score']);
        $this->assertSame(85, $result['score']);
        $this->assertSame('strong', $result['status']);
    }

    public function testMissingEvidencePenaltyDominatesPass(): void
    {
        $raw = self::BASE + self::EVIDENCE_MISSING + self::FALSE_LEARNING_PASS
            + self::REPEATED_FAILURE_CLEAR + self::ROI_HIGH;
        $this->assertSame(60, $raw);

        $result = $this->scorer->score(0, 'pass', 'clear', 90);

        $this->assertSame(self::clamp($raw), $result['score']);
        $this->assertSame(60, $result['score']);
        $this->assertSame('watch', $result['status']);
    }

    public function testBlockedFalseLearningDropsToWatch(): void
    {
        $raw = self::BASE + self::EVIDENCE_PRESENT + self::FALSE_LEARNING_FAIL
            + self::REPEATED_FAILURE_CLEAR + self::ROI_HIGH;
        $this->assertSame(65, $raw);

        $result = $this->scorer->score(2, 'blocked_for_learning', 'clear', 90);

        $this->assertSame(self::clamp($raw), $result['score']);
        $this->assertSame(65, $result['score']);
        $this->assertSame('watch', $result['status']);
    }

    public function testSchemaVersionIsCanonicalLiteral(): void
    {
        $result = $this->scorer->score(2, 'pass', 'clear', 90);

        $this->assertSame('atlas.aemor.quality_score.v1', $result['schema_version']);
    }

    public function testBreakdownEchoesInputs(): void
    {
        $result = $this->scorer->score(2, 'blocked_for_learning', 'clear', 90);

        $this->assertSame(2, $result['breakdown']['evidence_coverage']);
        $this->assertSame('blocked_for_learning', $result['breakdown']['false_learning_gate']);
        $this->assertSame('clear', $result['breakdown']['repeated_failure']);
        $this->assertSame(90, $result['breakdown']['context_roi']);
    }

    public function testNegativeEvidenceRefsCountAndOutOfRangeRoiAreClampedBeforeScoring(): void
    {
        $result = $this->scorer->score(-5, 'pass', 'clear', 500);

        $this->assertSame(0, $result['breakdown']['evidence_coverage']);
        $this->assertSame(100, $result['breakdown']['context_roi']);
        $this->assertContains('no_evidence_refs', $result['negative_drivers']);
    }

    public function testNegativeEvidenceRefsWithNegativeRoiAreBothClampedToZero(): void
    {
        $result = $this->scorer->score(-1, 'pass', 'clear', -20);

        $this->assertSame(0, $result['breakdown']['evidence_coverage']);
        $this->assertSame(0, $result['breakdown']['context_roi']);
    }

    public function testFalseLearningFailureCapsStatusBelowStrongEvenWithGoodRoiAndClearFailure(): void
    {
        $result = $this->scorer->score(2, 'blocked_for_learning', 'clear', 90);

        $this->assertNotSame('strong', $result['status']);
        $this->assertSame('watch', $result['status']);
        $this->assertContains('false_learning_status_caps_below_strong', $result['negative_drivers']);
    }

    public function testPositiveAndNegativeDriversExplainScoreDeterministically(): void
    {
        $result = $this->scorer->score(2, 'pass', 'clear', 90);

        $this->assertSame(
            ['evidence_refs_present', 'false_learning_gate_passed', 'repeated_failure_clear', 'high_context_roi'],
            $result['positive_drivers'],
        );
        $this->assertSame([], $result['negative_drivers']);

        $second = $this->scorer->score(2, 'pass', 'clear', 90);
        $this->assertSame($result['positive_drivers'], $second['positive_drivers']);
        $this->assertSame($result['negative_drivers'], $second['negative_drivers']);
    }

    public function testAllNegativeDriversPresentForFullPenaltyInput(): void
    {
        $result = $this->scorer->score(0, 'blocked_for_learning', 'blocked', 10);

        $this->assertSame(
            ['no_evidence_refs', 'false_learning_gate_not_passed', 'repeated_failure_not_clear', 'low_context_roi', 'false_learning_status_caps_below_strong'],
            $result['negative_drivers'],
        );
        $this->assertSame([], $result['positive_drivers']);
    }
}
