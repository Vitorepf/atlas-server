<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Context;

use App\Services\Ai\Context\RecallContextBudgetSplitScorer;
use PHPUnit\Framework\TestCase;

final class RecallContextBudgetSplitScorerTest extends TestCase
{
    private RecallContextBudgetSplitScorer $scorer;

    protected function setUp(): void
    {
        $this->scorer = new RecallContextBudgetSplitScorer();
    }

    public function testNoFlagsYieldsBaseRatioAndConservedSplit(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 1000,
            'task_type' => 'coding',
            'risk_level' => 'low',
            'conversation_depth' => 1,
            'has_prior_episode' => false,
        ]);

        $this->assertSame(0.4, $result['recall_ratio']);
        $this->assertSame(400, $result['recall_chars']);
        $this->assertSame(600, $result['context_chars']);
        $this->assertSame(1000, $result['recall_chars'] + $result['context_chars']);
    }

    public function testAllPositiveFlagsExceedCeilingThenClampToMax(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 1000,
            'task_type' => 'coding',
            'risk_level' => 'low',
            'conversation_depth' => 8,
            'has_prior_episode' => true,
        ]);

        $this->assertSame(0.7, $result['recall_ratio']);
        $this->assertSame(700, $result['recall_chars']);
        $this->assertSame(300, $result['context_chars']);
        $this->assertSame(1000, $result['recall_chars'] + $result['context_chars']);
    }

    public function testIrreversibleRiskCapsFinalRatioAtHalfAfterClamp(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 1000,
            'task_type' => 'coding',
            'risk_level' => 'irreversible',
            'conversation_depth' => 7,
            'has_prior_episode' => true,
        ]);

        $this->assertSame(0.5, $result['recall_ratio']);
        $this->assertSame(500, $result['recall_chars']);
        $this->assertSame(500, $result['context_chars']);
    }

    public function testResearchTaskTypeAlonePushesRatioToTwoTenths(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 1000,
            'task_type' => 'research',
            'risk_level' => 'low',
            'conversation_depth' => 1,
            'has_prior_episode' => false,
        ]);

        $this->assertSame(0.2, $result['recall_ratio']);
        $this->assertSame(200, $result['recall_chars']);
        $this->assertSame(800, $result['context_chars']);
    }

    public function testOddTotalConservesSumAfterRounding(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 999,
            'task_type' => 'coding',
            'risk_level' => 'low',
            'conversation_depth' => 1,
            'has_prior_episode' => false,
        ]);

        $this->assertSame(400, $result['recall_chars']);
        $this->assertSame(599, $result['context_chars']);
        $this->assertSame(999, $result['recall_chars'] + $result['context_chars']);
    }

    public function testNonPositiveTotalProducesZeroCharsButKeepsRatio(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 0,
            'task_type' => 'research',
            'risk_level' => 'low',
            'conversation_depth' => 1,
            'has_prior_episode' => false,
        ]);

        $this->assertSame(0, $result['recall_chars']);
        $this->assertSame(0, $result['context_chars']);
        $this->assertSame(0.2, $result['recall_ratio']);
    }

    public function testReasonsAreOrderedRuleTokens(): void
    {
        $result = $this->scorer->split([
            'total_budget_chars' => 2000,
            'task_type' => 'coding',
            'risk_level' => 'high',
            'conversation_depth' => 6,
            'has_prior_episode' => true,
        ]);

        $this->assertSame(
            ['base_ratio', 'prior_episode_bonus', 'deep_conversation_bonus', 'clamped_to_ceiling', 'risk_capped'],
            $result['reasons'],
        );
        $this->assertSame(0.5, $result['recall_ratio']);
        $this->assertSame(1000, $result['recall_chars']);
        $this->assertSame(1000, $result['context_chars']);
    }
}
