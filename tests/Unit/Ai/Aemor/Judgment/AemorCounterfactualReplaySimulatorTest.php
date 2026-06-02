<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aemor\Judgment;

use App\Services\Ai\Aemor\Judgment\AemorCounterfactualReplaySimulator;
use Tests\TestCase;

final class AemorCounterfactualReplaySimulatorTest extends TestCase
{
    public function test_missing_sources_failed_tests_and_watch_status_emit_three_hypotheses(): void
    {
        $result = (new AemorCounterfactualReplaySimulator())->simulate(
            'context_missing_required_sources',
            2,
            false,
            'watch'
        );

        $ifValues = array_column($result['hypotheses'], 'if');

        self::assertSame(3, count($result['hypotheses']));
        self::assertContains('mandatory_retrieval_included_missing_sources', $ifValues);
        self::assertContains('targeted_tests_were_required_before_completion', $ifValues);
        self::assertContains('negative_knowledge_was_injected_before_execution', $ifValues);

        $missingSourcesEntry = $this->hypothesisFor($result['hypotheses'], 'mandatory_retrieval_included_missing_sources');
        self::assertSame(0.72, $missingSourcesEntry['confidence']);
        self::assertSame('context_missing_required_sources', $result['primary_cause']);
    }

    public function test_clean_inputs_fall_back_to_single_stable_hypothesis(): void
    {
        $result = (new AemorCounterfactualReplaySimulator())->simulate(
            'execution_strategy_likely_succeeded',
            0,
            true,
            'clear'
        );

        self::assertSame(1, count($result['hypotheses']));
        self::assertSame('same_strategy_replayed_with_current_evidence', $result['hypotheses'][0]['if']);
        self::assertSame(0.55, $result['hypotheses'][0]['confidence']);
    }

    public function test_null_tests_passed_counts_as_not_true_without_triggering_fallback(): void
    {
        $result = (new AemorCounterfactualReplaySimulator())->simulate(
            'tests_failed',
            0,
            null,
            'clear'
        );

        $confidences = array_column($result['hypotheses'], 'confidence');
        $ifValues = array_column($result['hypotheses'], 'if');

        self::assertContains(0.80, $confidences);
        self::assertNotContains('same_strategy_replayed_with_current_evidence', $ifValues);
    }

    public function test_replay_never_executes_provider_or_tools_across_branches(): void
    {
        $simulator = new AemorCounterfactualReplaySimulator();

        $branches = [
            $simulator->simulate('all_clear', 0, true, 'clear'),
            $simulator->simulate('missing_only', 5, true, 'clear'),
            $simulator->simulate('tests_and_failures', 0, false, 'blocked'),
        ];

        foreach ($branches as $branch) {
            self::assertFalse($branch['executes_provider']);
            self::assertFalse($branch['executes_tools']);
            self::assertSame('simulated', $branch['status']);
            self::assertSame('atlas.aemor.counterfactual_replay.v1', $branch['schema_version']);
        }
    }

    public function test_blocked_repeated_failure_injects_negative_knowledge_only(): void
    {
        $result = (new AemorCounterfactualReplaySimulator())->simulate(
            'x',
            0,
            true,
            'blocked'
        );

        $ifValues = array_column($result['hypotheses'], 'if');

        self::assertContains('negative_knowledge_was_injected_before_execution', $ifValues);
        self::assertNotContains('mandatory_retrieval_included_missing_sources', $ifValues);

        $negativeKnowledgeEntry = $this->hypothesisFor($result['hypotheses'], 'negative_knowledge_was_injected_before_execution');
        self::assertSame(0.68, $negativeKnowledgeEntry['confidence']);
    }

    /**
     * @param  list<array{if: string, then: string, confidence: float}>  $hypotheses
     * @return array{if: string, then: string, confidence: float}
     */
    private function hypothesisFor(array $hypotheses, string $if): array
    {
        foreach ($hypotheses as $hypothesis) {
            if ($hypothesis['if'] === $if) {
                return $hypothesis;
            }
        }

        self::fail("Hypothesis with if='{$if}' was not present.");
    }
}
