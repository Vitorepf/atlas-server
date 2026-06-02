<?php

declare(strict_types=1);

namespace App\Services\Ai\Aemor\Judgment;

/**
 * Pure-logic projection of AtlasAemorJudgmentService::counterfactualReplay.
 *
 * Simulates the counterfactual replay hypotheses for an AEMOR outcome without
 * touching providers, tools, persistence or any side effect. Every returned
 * field is computed from the method inputs via the same ordered rules used by
 * the runtime judgment service.
 */
final class AemorCounterfactualReplaySimulator
{
    private const SCHEMA_VERSION = 'atlas.aemor.counterfactual_replay.v1';

    /**
     * @return array{
     *     schema_version: string,
     *     status: string,
     *     primary_cause: string,
     *     hypotheses: list<array{if: string, then: string, confidence: float}>,
     *     executes_provider: bool,
     *     executes_tools: bool
     * }
     */
    public function simulate(
        string $primaryCause,
        int $missingSourcesCount,
        ?bool $testsPassed,
        string $repeatedFailureStatus
    ): array {
        $hypotheses = [];

        if ($missingSourcesCount > 0) {
            $hypotheses[] = [
                'if' => 'mandatory_retrieval_included_missing_sources',
                'then' => 'execution_plan_would_have_lower_context_risk',
                'confidence' => 0.72,
            ];
        }

        if ($testsPassed !== true) {
            $hypotheses[] = [
                'if' => 'targeted_tests_were_required_before_completion',
                'then' => 'false_success_or_repeat_failure_would_be_blocked',
                'confidence' => 0.80,
            ];
        }

        if ($repeatedFailureStatus !== 'clear') {
            $hypotheses[] = [
                'if' => 'negative_knowledge_was_injected_before_execution',
                'then' => 'same_failure_strategy_would_be_suppressed',
                'confidence' => 0.68,
            ];
        }

        if ($hypotheses === []) {
            $hypotheses[] = [
                'if' => 'same_strategy_replayed_with_current_evidence',
                'then' => 'expected_outcome_remains_stable',
                'confidence' => 0.55,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => 'simulated',
            'primary_cause' => $primaryCause,
            'hypotheses' => $hypotheses,
            'executes_provider' => false,
            'executes_tools' => false,
        ];
    }
}
