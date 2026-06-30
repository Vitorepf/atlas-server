<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPromptContractRegressionSuite;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPromptContractRegressionSuiteTest extends TestCase
{
    private function suite(): AtlasExternalBrainPromptContractRegressionSuite
    {
        return new AtlasExternalBrainPromptContractRegressionSuite;
    }

    /** A prompt that satisfies every required clause and carries no quota-farm risk. */
    private function validPrompt(): string
    {
        return <<<'PROMPT'
            Keep running until the target is reached; do not stop early. If a cycle returns
            no_proposal, do not stop — keep trying on the next cycle. Always prefer creating a
            macro-task or high-value task over a micro-edit. Before proposing any candidate,
            verify implementability against a concrete file. Never require a human or operator —
            this prompt is atlas-native end to end. Dedup every proposal against prior runs and
            avoid templated repeats. Reward proposals for their value, diversity, and evidence,
            not for raw count alone.
            PROMPT;
    }

    // ── AC: missing persistence contract → missing_persistence_contract ──────

    public function test_prompt_missing_persistence_language_fails_with_missing_persistence_contract(): void
    {
        $prompt = str_replace(
            ['Keep running until the target is reached; do not stop early. '],
            [''],
            $this->validPrompt(),
        );

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['pass']);
        $this->assertContains('missing_persistence_contract', $r['failed_clauses']);
    }

    // ── AC: rewards raw count without value/diversity/evidence → quota_farm_risk ──

    public function test_raw_count_reward_without_value_signal_fails_with_quota_farm_risk(): void
    {
        $prompt = 'Keep running until the target is reached; do not stop early. '
            .'Maximize the task count as much as possible. Generate as many proposals as possible.';

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['pass']);
        $this->assertContains('quota_farm_risk', $r['failed_clauses']);
    }

    public function test_raw_count_language_mitigated_by_value_signal_does_not_fail_quota_farm_risk(): void
    {
        $r = $this->suite()->validate($this->validPrompt());
        $this->assertNotContains('quota_farm_risk', $r['failed_clauses']);
    }

    // ── AC: prompt with implementability, dedup, macro-task, anti-template clauses passes ──

    public function test_complete_prompt_passes(): void
    {
        $r = $this->suite()->validate($this->validPrompt());

        $this->assertTrue($r['pass']);
        $this->assertSame([], $r['failed_clauses']);
        $this->assertSame(1.0, $r['score']);
    }

    // ── AC: output shape — pass, score, failed_clauses, required_patch_notes ─

    public function test_output_has_required_keys(): void
    {
        $r = $this->suite()->validate('');

        $this->assertArrayHasKey('pass', $r);
        $this->assertArrayHasKey('score', $r);
        $this->assertArrayHasKey('failed_clauses', $r);
        $this->assertArrayHasKey('required_patch_notes', $r);
    }

    public function test_empty_prompt_fails_all_clauses_with_low_score(): void
    {
        $r = $this->suite()->validate('');

        $this->assertFalse($r['pass']);
        $this->assertContains('missing_persistence_contract', $r['failed_clauses']);
        $this->assertContains('missing_macro_task_creation', $r['failed_clauses']);
        $this->assertContains('missing_implementability_verification', $r['failed_clauses']);
        $this->assertContains('missing_anti_provider_human_dependency', $r['failed_clauses']);
        $this->assertContains('missing_dedup_anti_template', $r['failed_clauses']);
        $this->assertLessThan(1.0, $r['score']);
    }

    public function test_failed_clauses_carry_matching_patch_notes(): void
    {
        $r = $this->suite()->validate('');
        $this->assertSame(count($r['failed_clauses']), count($r['required_patch_notes']));
        $this->assertNotEmpty($r['required_patch_notes'][0]);
    }

    public function test_missing_no_premature_stop_clause_is_detected(): void
    {
        $prompt = (string) preg_replace(
            '/If a cycle returns\s+no_proposal, do not stop — keep trying on the next cycle\.\s*/',
            '',
            $this->validPrompt(),
        );

        $r = $this->suite()->validate($prompt);
        $this->assertContains('missing_no_premature_stop', $r['failed_clauses']);
    }

    public function test_validate_is_deterministic(): void
    {
        $prompt = $this->validPrompt();
        $a = $this->suite()->validate($prompt);
        $b = $this->suite()->validate($prompt);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
