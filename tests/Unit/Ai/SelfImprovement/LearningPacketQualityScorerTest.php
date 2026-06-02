<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\LearningPacketQualityScorer;
use PHPUnit\Framework\TestCase;

final class LearningPacketQualityScorerTest extends TestCase
{
    private LearningPacketQualityScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new LearningPacketQualityScorer();
    }

    public function testCleanHighConfidencePacketIsPublishableWithHighScore(): void
    {
        $result = $this->scorer->score([
            'confidence' => 0.9,
            'evidence_supporting_improvement' => ['ref-a', 'ref-b', 'ref-c'],
            'what_failed_or_was_missing' => [],
            'new_rule_candidate' => 'prefer_bounded_retry_on_timeout',
            'rollback_recommendation' => null,
        ]);

        // (a) clean high-conf packet (3 evidence + rule + no failures) -> publishable AND score>=0.9.
        $this->assertSame('publishable', $result['band']);
        $this->assertGreaterThanOrEqual(0.9, $result['score']);
        $this->assertSame(1.0, $result['score']);
    }

    public function testRuleWithFailureScoresLowerAndIsNotPublishable(): void
    {
        $base = [
            'confidence' => 0.9,
            'evidence_supporting_improvement' => [],
            'new_rule_candidate' => 'prefer_bounded_retry_on_timeout',
            'rollback_recommendation' => null,
        ];

        $noFailure = $this->scorer->score($base + ['what_failed_or_was_missing' => []]);
        $oneFailure = $this->scorer->score($base + ['what_failed_or_was_missing' => ['missing_invariant_lock']]);

        // (b) conf 0.9 with rule AND one failure scores strictly LOWER than same packet no failure AND NOT publishable.
        $this->assertLessThan($noFailure['score'], $oneFailure['score']);
        $this->assertNotSame('publishable', $oneFailure['band']);
        $this->assertSame(0.95, $noFailure['score']);
        $this->assertSame(0.6, $oneFailure['score']);

        // (e, case b) contradiction reason present in case b.
        $this->assertContains('rule_failure_contradiction_penalty', $oneFailure['reasons']);
    }

    public function testLowConfidenceWithRollbackIsDiscarded(): void
    {
        $result = $this->scorer->score([
            'confidence' => 0.2,
            'evidence_supporting_improvement' => [],
            'what_failed_or_was_missing' => [],
            'new_rule_candidate' => null,
            'rollback_recommendation' => 'revert_obra_to_prior_snapshot',
        ]);

        // (c) conf 0.2 with rollback_recommendation -> discard AND score<=0.2.
        $this->assertSame('discard', $result['band']);
        $this->assertLessThanOrEqual(0.2, $result['score']);
        $this->assertSame(0.0, $result['score']);
    }

    public function testManyEvidenceItemsNeverExceedScoreCeiling(): void
    {
        $result = $this->scorer->score([
            'confidence' => 0.9,
            'evidence_supporting_improvement' => [
                'e1', 'e2', 'e3', 'e4', 'e5', 'e6', 'e7', 'e8', 'e9', 'e10',
            ],
            'what_failed_or_was_missing' => [],
            'new_rule_candidate' => 'prefer_bounded_retry_on_timeout',
            'rollback_recommendation' => null,
        ]);

        // (d) 10 evidence + rule -> score never exceeds 1.0 (clamp).
        $this->assertLessThanOrEqual(1.0, $result['score']);
        $this->assertSame(1.0, $result['score']);
    }

    public function testReasonsAreNonEmptyWheneverAnyRuleFires(): void
    {
        $fired = $this->scorer->score([
            'confidence' => 0.7,
            'evidence_supporting_improvement' => ['ref-a', 'ref-b'],
            'what_failed_or_was_missing' => ['gap_one', 'gap_two', 'gap_three'],
            'new_rule_candidate' => 'prefer_bounded_retry_on_timeout',
            'rollback_recommendation' => 'revert_obra_to_prior_snapshot',
        ]);

        // (e) reasons non-empty whenever any rule fired.
        $this->assertNotEmpty($fired['reasons']);
        $this->assertContains('evidence_bonus_applied', $fired['reasons']);
        $this->assertContains('rule_failure_contradiction_penalty', $fired['reasons']);
        $this->assertContains('rollback_recommendation_penalty', $fired['reasons']);
        $this->assertContains('excess_failure_penalty', $fired['reasons']);
    }
}
