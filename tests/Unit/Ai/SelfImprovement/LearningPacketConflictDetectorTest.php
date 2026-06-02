<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfImprovement;

use App\Services\Ai\SelfImprovement\LearningPacketConflictDetector;
use PHPUnit\Framework\TestCase;

final class LearningPacketConflictDetectorTest extends TestCase
{
    private LearningPacketConflictDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new LearningPacketConflictDetector();
    }

    public function testPositiveThenLaterNegativeFlagsConflictWithLaterStaleWinner(): void
    {
        $result = $this->detector->detect([
            [
                'new_rule_candidate' => 'Cache TTL = 300s',
                'what_changed' => 'set cache ttl',
                'confidence' => 0.85,
                'rollback_recommendation' => null,
                'recorded_at' => 'day-1',
            ],
            [
                'new_rule_candidate' => 'cache ttl = 300s',
                'what_changed' => 'set cache ttl',
                'confidence' => 0.9,
                'rollback_recommendation' => 'revert ttl change, caused stale reads',
                'recorded_at' => 'day-2',
            ],
        ]);

        $this->assertTrue($result['has_conflict']);
        $this->assertCount(1, $result['conflicts']);

        $conflict = $result['conflicts'][0];
        $this->assertSame('cache ttl = 300s', $conflict['change_key']);
        $this->assertSame(0, $conflict['positive_index']);
        $this->assertSame(1, $conflict['negative_index']);
        $this->assertSame(1, $conflict['stale_winner_index']);
        $this->assertSame(1, $conflict['negative_index'], 'stale winner must point to the later (negative) packet');
        $this->assertSame('most_recent_wins', $conflict['verdict']);
    }

    public function testNegativeFirstThenPositiveLaterMakesPositiveTheStaleWinner(): void
    {
        $result = $this->detector->detect([
            [
                'new_rule_candidate' => 'retry-policy',
                'confidence' => 0.95,
                'rollback_recommendation' => 'roll back retries, thundering herd',
            ],
            [
                'new_rule_candidate' => 'retry-policy',
                'confidence' => 0.8,
                'rollback_recommendation' => null,
            ],
        ]);

        $this->assertTrue($result['has_conflict']);
        $conflict = $result['conflicts'][0];
        $this->assertSame('retry-policy', $conflict['change_key']);
        $this->assertSame(1, $conflict['positive_index']);
        $this->assertSame(0, $conflict['negative_index']);
        $this->assertSame(1, $conflict['stale_winner_index']);
        $this->assertSame(1, $conflict['positive_index'], 'stale winner must point to the later (positive) packet by recency');
        $this->assertSame('most_recent_wins', $conflict['verdict']);
    }

    public function testTwoPositivesNoNegativeHasNoConflict(): void
    {
        $result = $this->detector->detect([
            [
                'new_rule_candidate' => 'index-orders-table',
                'confidence' => 0.9,
                'rollback_recommendation' => null,
            ],
            [
                'new_rule_candidate' => 'index-orders-table',
                'confidence' => 0.75,
                'rollback_recommendation' => '',
            ],
        ]);

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['conflicts']);
    }

    public function testDifferentNormalizedKeysNeverConflict(): void
    {
        $result = $this->detector->detect([
            [
                'new_rule_candidate' => 'feature-a',
                'confidence' => 0.9,
                'rollback_recommendation' => null,
            ],
            [
                'new_rule_candidate' => 'feature-b',
                'confidence' => 0.1,
                'rollback_recommendation' => 'undo feature-b, regression',
            ],
        ]);

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['conflicts']);
    }

    public function testNeutralPacketDoesNotFlagAgainstPositiveOfSameKey(): void
    {
        $result = $this->detector->detect([
            [
                'new_rule_candidate' => 'timeout-rule',
                'confidence' => 0.9,
                'rollback_recommendation' => null,
            ],
            [
                'new_rule_candidate' => 'timeout-rule',
                'confidence' => 0.5,
                'rollback_recommendation' => null,
            ],
        ]);

        $this->assertFalse($result['has_conflict']);
        $this->assertSame([], $result['conflicts']);
    }
}
