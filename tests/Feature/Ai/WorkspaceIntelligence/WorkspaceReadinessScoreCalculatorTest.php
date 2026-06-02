<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\WorkspaceReadinessScoreCalculator;
use Tests\TestCase;

final class WorkspaceReadinessScoreCalculatorTest extends TestCase
{
    public function test_all_five_true_yields_max_thirteen(): void
    {
        $calculator = new WorkspaceReadinessScoreCalculator();

        $score = $calculator->score([
            'workspace_binding' => true,
            'artifacts_at_minimum' => true,
            'contracts_certified' => true,
            'next_session_brain_ready' => true,
            'raw_conversation_excluded' => true,
        ]);

        $this->assertSame(13, $score);
    }

    public function test_empty_contracts_yield_zero(): void
    {
        $calculator = new WorkspaceReadinessScoreCalculator();

        $this->assertSame(0, $calculator->score([]));
    }

    public function test_only_workspace_binding_true_yields_four(): void
    {
        $calculator = new WorkspaceReadinessScoreCalculator();

        $score = $calculator->score([
            'workspace_binding' => true,
            'artifacts_at_minimum' => false,
            'contracts_certified' => false,
            'next_session_brain_ready' => false,
            'raw_conversation_excluded' => false,
        ]);

        $this->assertSame(4, $score);
    }

    public function test_binding_artifacts_contracts_true_rest_false_yields_ten(): void
    {
        $calculator = new WorkspaceReadinessScoreCalculator();

        $score = $calculator->score([
            'workspace_binding' => true,
            'artifacts_at_minimum' => true,
            'contracts_certified' => true,
            'next_session_brain_ready' => false,
            'raw_conversation_excluded' => false,
        ]);

        $this->assertSame(10, $score);
    }

    public function test_truthy_non_boolean_values_contribute_zero(): void
    {
        $calculator = new WorkspaceReadinessScoreCalculator();

        $score = $calculator->score([
            'workspace_binding' => 1,
            'artifacts_at_minimum' => 'yes',
            'contracts_certified' => 1,
            'next_session_brain_ready' => 'yes',
            'raw_conversation_excluded' => 1,
        ]);

        $this->assertSame(0, $score);
    }
}
