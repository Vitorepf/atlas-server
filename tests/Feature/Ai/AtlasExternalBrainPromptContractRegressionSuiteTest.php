<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPromptContractRegressionSuite;
use Tests\TestCase;

final class AtlasExternalBrainPromptContractRegressionSuiteTest extends TestCase
{
    private function suite(): AtlasExternalBrainPromptContractRegressionSuite
    {
        return new AtlasExternalBrainPromptContractRegressionSuite;
    }

    private function strongPrompt(): string
    {
        return <<<'PROMPT'
            Keep running until the target quota is reached; do not stop early.
            If a cycle returns no_proposal, do not stop — keep trying next cycle.
            Prefer creating macro-task or high-value task chains, not only micro-edits.
            Before proposing a candidate, verify implementability against a concrete file delta.
            Never require a human, operator, or external provider as the runtime owner — stay atlas-native.
            Dedup against prior proposals and avoid templated repeats.
            PROMPT;
    }

    public function test_strong_prompt_passes_with_full_score_and_no_quota_farm_risk(): void
    {
        $result = $this->suite()->validate($this->strongPrompt());

        self::assertTrue($result['pass']);
        self::assertSame(1.0, $result['score']);
        self::assertFalse($result['quota_farm_risk']);
        foreach (['has_persistence_contract', 'has_no_premature_stop', 'has_macro_task_creation', 'has_implementability_verification', 'has_anti_provider_human_dependency', 'has_dedup_anti_template', 'no_quota_farm_risk'] as $strength) {
            self::assertContains($strength, $result['detected_strengths']);
        }
    }

    public function test_prompt_missing_persistence_clause_fails_with_patch_note(): void
    {
        $prompt = str_replace('Keep running until the target quota is reached; do not stop early.', '', $this->strongPrompt());

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertContains('missing_persistence_contract', $result['failed_clauses']);
        self::assertNotEmpty($result['required_patch_notes']);
    }

    public function test_prompt_missing_no_premature_stop_clause_fails_with_patch_note(): void
    {
        $prompt = str_replace('If a cycle returns no_proposal, do not stop — keep trying next cycle.', '', $this->strongPrompt());

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertContains('missing_no_premature_stop', $result['failed_clauses']);
    }

    public function test_prompt_missing_macro_task_language_fails_with_patch_note(): void
    {
        $prompt = str_replace('Prefer creating macro-task or high-value task chains, not only micro-edits.', '', $this->strongPrompt());

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertContains('missing_macro_task_creation', $result['failed_clauses']);
    }

    public function test_prompt_missing_implementability_verification_fails_with_patch_note(): void
    {
        $prompt = str_replace('Before proposing a candidate, verify implementability against a concrete file delta.', '', $this->strongPrompt());

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertContains('missing_implementability_verification', $result['failed_clauses']);
    }

    public function test_prompt_missing_anti_human_provider_dependency_fails_with_patch_note(): void
    {
        $prompt = str_replace('Never require a human, operator, or external provider as the runtime owner — stay atlas-native.', '', $this->strongPrompt());

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertContains('missing_anti_provider_human_dependency', $result['failed_clauses']);
    }

    public function test_prompt_missing_dedup_anti_template_clause_fails_with_patch_note(): void
    {
        $prompt = str_replace('Dedup against prior proposals and avoid templated repeats.', '', $this->strongPrompt());

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertContains('missing_dedup_anti_template', $result['failed_clauses']);
    }

    public function test_raw_count_language_without_mitigation_fails_as_quota_farm_risk(): void
    {
        $prompt = 'Maximize the task count as many tasks as possible.';

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['pass']);
        self::assertTrue($result['quota_farm_risk']);
        self::assertContains('quota_farm_risk', $result['failed_clauses']);
    }

    public function test_raw_count_language_with_value_mitigation_does_not_trigger_quota_farm_risk(): void
    {
        $prompt = $this->strongPrompt().' Maximize the task count as many tasks as possible, but always require real value and evidence.';

        $result = $this->suite()->validate($prompt);

        self::assertFalse($result['quota_farm_risk']);
    }
}
