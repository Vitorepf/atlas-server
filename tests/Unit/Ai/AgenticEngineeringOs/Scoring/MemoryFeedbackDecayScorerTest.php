<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AgenticEngineeringOs\Scoring;

use App\Services\Ai\AgenticEngineeringOs\Scoring\MemoryFeedbackDecayScorer;
use PHPUnit\Framework\TestCase;

final class MemoryFeedbackDecayScorerTest extends TestCase
{
    private MemoryFeedbackDecayScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new MemoryFeedbackDecayScorer();
    }

    public function testSchemaVersionMatchesContract(): void
    {
        $result = $this->scorer->score([]);

        $this->assertSame('atlas.aaeos.memory_feedback_decay.v1', $result['schema_version']);
    }

    public function testStaleFeedbackThresholdArchivesAtTwoButNotAtOne(): void
    {
        $archived = $this->scorer->score([
            'stale_count' => 2,
        ]);

        $this->assertSame('archive', $archived['lifecycle_action']);
        $this->assertContains('archived_by_stale_feedback', $archived['threshold_reasons']);

        $notArchived = $this->scorer->score([
            'stale_count' => 1,
        ]);

        $this->assertNotSame('archive', $notArchived['lifecycle_action']);
        $this->assertNotContains('archived_by_stale_feedback', $notArchived['threshold_reasons']);
    }

    public function testConjunctiveInactivateRequiresNegativeAndLowHealth(): void
    {
        $inactivated = $this->scorer->score([
            'negative_count' => 3,
            'wrong_context_count' => 1,
        ]);

        $this->assertLessThanOrEqual(40, $inactivated['health_score']);
        $this->assertSame('inactivate', $inactivated['lifecycle_action']);

        $degraded = $this->scorer->score([
            'negative_count' => 3,
            'positive_count' => 2,
        ]);

        $this->assertGreaterThan(40, $degraded['health_score']);
        $this->assertSame('degrade', $degraded['lifecycle_action']);

        // AND-not-OR, second direction: low health alone (negative below the
        // threshold) must NOT inactivate. health_score = 100 - 2*18 - 3*10 = 34
        // (<=40) but negative_count = 2 (< 3), so the conjunctive gate stays
        // closed and the action degrades instead of inactivating.
        $lowHealthFewNegatives = $this->scorer->score([
            'negative_count' => 2,
            'wrong_context_count' => 3,
        ]);

        $this->assertLessThanOrEqual(40, $lowHealthFewNegatives['health_score']);
        $this->assertSame('degrade', $lowHealthFewNegatives['lifecycle_action']);
        $this->assertNotSame('inactivate', $lowHealthFewNegatives['lifecycle_action']);
    }

    public function testHealthScoreClampsHighAndLow(): void
    {
        $high = $this->scorer->score([
            'positive_count' => 5,
            'negative_count' => 0,
        ]);

        $this->assertSame(100, $high['health_score']);

        $low = $this->scorer->score([
            'negative_count' => 10,
        ]);

        $this->assertSame(0, $low['health_score']);
    }

    public function testDecayOverlayLowersPriorityMonotonicallyWithAge(): void
    {
        $fresh = $this->scorer->score([
            'base_priority' => 50,
            'recorded_at_age_days' => 10,
        ]);

        $aged = $this->scorer->score([
            'base_priority' => 50,
            'recorded_at_age_days' => 200,
        ]);

        $this->assertLessThan($fresh['effective_priority'], $aged['effective_priority']);
        $this->assertSame('stale_review_recommended', $aged['staleness']);
        $this->assertContains('stale_age_exceeds_180d', $aged['threshold_reasons']);
        $this->assertSame('fresh', $fresh['staleness']);
    }

    public function testNullRecordedAgeYieldsFreshWithoutThrowing(): void
    {
        $result = $this->scorer->score([
            'recorded_at_age_days' => null,
        ]);

        $this->assertSame('fresh', $result['staleness']);
        $this->assertNull($result['age_days']);
    }

    public function testSoftStaleBandEmitsSoftReason(): void
    {
        $result = $this->scorer->score([
            'recorded_at_age_days' => 100,
        ]);

        $this->assertContains('soft_stale_age_exceeds_45d', $result['threshold_reasons']);
        $this->assertSame('fresh', $result['staleness']);
        $this->assertSame(100, $result['age_days']);
    }

    public function testSoftStaleExcludesBoundaryAtFortyFiveAndAboveOneEighty(): void
    {
        $atFortyFive = $this->scorer->score([
            'recorded_at_age_days' => 45,
        ]);

        $this->assertNotContains('soft_stale_age_exceeds_45d', $atFortyFive['threshold_reasons']);

        $atOneEighty = $this->scorer->score([
            'recorded_at_age_days' => 180,
        ]);

        $this->assertContains('soft_stale_age_exceeds_45d', $atOneEighty['threshold_reasons']);
        $this->assertNotContains('stale_age_exceeds_180d', $atOneEighty['threshold_reasons']);
        $this->assertSame('fresh', $atOneEighty['staleness']);
    }

    public function testBothAgesPastHardThresholdEscalatesToInactiveCandidate(): void
    {
        $result = $this->scorer->score([
            'base_priority' => 70,
            'recorded_at_age_days' => 400,
            'last_used_at_age_days' => 300,
        ]);

        $this->assertSame('stale_inactive_candidate', $result['staleness']);
        $this->assertSame('inactivate', $result['lifecycle_action']);
        $this->assertLessThan(70, $result['effective_priority']);
    }

    public function testEffectivePriorityClampsAtFloorWithHeavyNegativeFeedback(): void
    {
        $result = $this->scorer->score([
            'base_priority' => 50,
            'negative_count' => 10,
        ]);

        $this->assertSame(0, $result['effective_priority']);
    }

    public function testInputsEchoReflectsNormalizedSignals(): void
    {
        $result = $this->scorer->score([
            'positive_count' => 4,
            'negative_count' => 1,
            'wrong_context_count' => 2,
            'stale_count' => 0,
            'base_priority' => 73,
            'recorded_at_age_days' => 12,
            'last_used_at_age_days' => 5,
            'recall_eval_hit_rate' => 0.8,
        ]);

        $this->assertSame(4, $result['inputs_echo']['positive_count']);
        $this->assertSame(1, $result['inputs_echo']['negative_count']);
        $this->assertSame(2, $result['inputs_echo']['wrong_context_count']);
        $this->assertSame(73, $result['inputs_echo']['base_priority']);
        $this->assertSame(12, $result['inputs_echo']['recorded_at_age_days']);
        $this->assertSame(0.8, $result['inputs_echo']['recall_eval_hit_rate']);
    }

    public function testNegativeCountsAndHitRateAreClampedIntoValidRange(): void
    {
        $result = $this->scorer->score([
            'positive_count' => -5,
            'base_priority' => 250,
            'recall_eval_hit_rate' => 1.7,
        ]);

        $this->assertSame(0, $result['inputs_echo']['positive_count']);
        $this->assertSame(100, $result['inputs_echo']['base_priority']);
        $this->assertSame(1.0, $result['inputs_echo']['recall_eval_hit_rate']);
    }

    public function testCleanSignalKeepsMemorySelectable(): void
    {
        $result = $this->scorer->score([
            'positive_count' => 1,
            'base_priority' => 60,
            'recorded_at_age_days' => 5,
            'last_used_at_age_days' => 2,
        ]);

        $this->assertSame('keep', $result['lifecycle_action']);
        $this->assertSame('fresh', $result['staleness']);
        $this->assertGreaterThan(40, $result['health_score']);
    }

    public function testIdenticalInputIsDeterministic(): void
    {
        $signals = [
            'positive_count' => 2,
            'negative_count' => 1,
            'wrong_context_count' => 1,
            'stale_count' => 0,
            'base_priority' => 55,
            'recorded_at_age_days' => 90,
            'last_used_at_age_days' => 30,
            'recall_eval_hit_rate' => 0.5,
        ];

        $first = $this->scorer->score($signals);
        $second = $this->scorer->score($signals);

        $this->assertSame($first, $second);
    }
}
