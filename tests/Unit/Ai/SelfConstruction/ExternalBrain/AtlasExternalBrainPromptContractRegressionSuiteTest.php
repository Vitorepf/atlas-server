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
            not for raw count alone. When local candidates dry up, escalate to research, a second
            pass, architecture review, or simplification work instead of stopping.
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

    // ── AC1: detected_strengths + quota_farm_risk boolean ─────────────────────

    public function test_output_has_detected_strengths_and_quota_farm_risk_keys(): void
    {
        $r = $this->suite()->validate('');

        $this->assertArrayHasKey('detected_strengths', $r);
        $this->assertArrayHasKey('quota_farm_risk', $r);
        $this->assertIsBool($r['quota_farm_risk']);
    }

    public function test_complete_prompt_has_no_quota_farm_risk_and_all_strengths_detected(): void
    {
        $r = $this->suite()->validate($this->validPrompt());

        $this->assertFalse($r['quota_farm_risk']);
        $this->assertCount(11, $r['detected_strengths']);
        $this->assertContains('has_persistence_contract', $r['detected_strengths']);
        $this->assertContains('has_escalation_beyond_local_candidates', $r['detected_strengths']);
        $this->assertContains('no_quota_farm_risk', $r['detected_strengths']);
        $this->assertContains('no_comfortable_queue_stop_risk', $r['detected_strengths']);
        $this->assertContains('no_proxy_task_risk', $r['detected_strengths']);
        $this->assertContains('no_vague_evidence_risk', $r['detected_strengths']);
    }

    public function test_quota_farm_risk_true_for_raw_count_without_mitigation(): void
    {
        $prompt = 'Keep running until the target is reached; do not stop early. '
            .'Maximize the task count as much as possible. Generate as many proposals as possible.';

        $r = $this->suite()->validate($prompt);

        $this->assertTrue($r['quota_farm_risk']);
    }

    public function test_empty_prompt_only_passes_the_defect_checks(): void
    {
        $r = $this->suite()->validate('');
        $this->assertSame(
            ['no_quota_farm_risk', 'no_comfortable_queue_stop_risk', 'no_proxy_task_risk', 'no_vague_evidence_risk'],
            $r['detected_strengths'],
        );
    }

    // ── AC2: stop-when-queue-comfortable language fails with comfortable_queue_stop_risk ──

    public function test_stop_when_queue_is_comfortable_fails_with_comfortable_queue_stop_risk(): void
    {
        $prompt = $this->validPrompt().' Stop the run when the queue depth is comfortable.';

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['pass']);
        $this->assertContains('comfortable_queue_stop_risk', $r['failed_clauses']);
        $this->assertTrue($r['quota_farm_risk'] === false); // unrelated defect stays independent
    }

    public function test_pause_when_queue_is_sufficient_fails_with_comfortable_queue_stop_risk(): void
    {
        $prompt = $this->validPrompt().' Pause when the queue is sufficient.';

        $r = $this->suite()->validate($prompt);

        $this->assertContains('comfortable_queue_stop_risk', $r['failed_clauses']);
    }

    public function test_valid_prompt_has_no_comfortable_queue_stop_risk(): void
    {
        $r = $this->suite()->validate($this->validPrompt());

        $this->assertNotContains('comfortable_queue_stop_risk', $r['failed_clauses']);
    }

    // ── AC3: prompt must require escalation beyond local candidates ──────────

    public function test_missing_escalation_clause_fails_with_missing_escalation_beyond_local_candidates(): void
    {
        $prompt = (string) preg_replace(
            '/When local candidates dry up.*?instead of stopping\.\s*/s',
            '',
            $this->validPrompt(),
        );

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['pass']);
        $this->assertContains('missing_escalation_beyond_local_candidates', $r['failed_clauses']);
    }

    public function test_escalation_via_research_satisfies_the_clause(): void
    {
        $r = $this->suite()->validate('Escalate to research when local candidates run out.');
        $this->assertNotContains('missing_escalation_beyond_local_candidates', $r['failed_clauses']);
    }

    public function test_escalation_via_architecture_satisfies_the_clause(): void
    {
        $r = $this->suite()->validate('When candidates dry up, expand scope into architecture work.');
        $this->assertNotContains('missing_escalation_beyond_local_candidates', $r['failed_clauses']);
    }

    // ── AC4: quota/count language with value+evidence mitigation still passes quota-farm check ──

    public function test_quota_language_with_value_and_evidence_mitigation_passes_quota_farm_check(): void
    {
        $prompt = 'Maximize the task count, but only when backed by real value and evidence.';

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['quota_farm_risk']);
        $this->assertNotContains('quota_farm_risk', $r['failed_clauses']);
    }

    // ── AC5: comfortable-queue-stop mitigated by verified target exhaustion ──

    public function test_stop_when_queue_comfortable_mitigated_by_verified_target_exhaustion_does_not_fail(): void
    {
        $prompt = $this->validPrompt()
            .' Stop the run when the queue depth is comfortable, only after verified target exhaustion.';

        $r = $this->suite()->validate($prompt);

        $this->assertNotContains('comfortable_queue_stop_risk', $r['failed_clauses']);
    }

    // ── AC6: proxy-task regression — cosmetic/renaming-only tasks counted as progress ──

    public function test_proxy_task_language_fails_with_proxy_task_risk(): void
    {
        $prompt = $this->validPrompt()
            .' Cosmetic refactors and formatting-only churn count as progress toward the target.';

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['pass']);
        $this->assertContains('proxy_task_risk', $r['failed_clauses']);
    }

    public function test_proxy_task_language_mitigated_by_genuine_value_requirement_does_not_fail(): void
    {
        $prompt = $this->validPrompt()
            .' Cosmetic refactors only count toward the target when they carry genuine value.';

        $r = $this->suite()->validate($prompt);

        $this->assertNotContains('proxy_task_risk', $r['failed_clauses']);
    }

    // ── AC7: vague-evidence regression — low-evidence origination permitted ──

    public function test_vague_evidence_language_fails_with_vague_evidence_risk(): void
    {
        $prompt = $this->validPrompt().' Speculative candidates are fine — no evidence is required.';

        $r = $this->suite()->validate($prompt);

        $this->assertFalse($r['pass']);
        $this->assertContains('vague_evidence_risk', $r['failed_clauses']);
    }

    public function test_vague_evidence_language_mitigated_by_concrete_evidence_requirement_does_not_fail(): void
    {
        $prompt = $this->validPrompt()
            .' Speculative candidates are fine as long as they are backed by concrete evidence.';

        $r = $this->suite()->validate($prompt);

        $this->assertNotContains('vague_evidence_risk', $r['failed_clauses']);
    }

    // ── AC: defect_risk_summary grouped by quota_farm/stop_condition/proxy_task/vague_evidence ──

    public function test_output_has_defect_risk_summary_with_all_four_keys(): void
    {
        $r = $this->suite()->validate($this->validPrompt());

        $this->assertArrayHasKey('defect_risk_summary', $r);
        foreach (['quota_farm', 'stop_condition', 'proxy_task', 'vague_evidence'] as $key) {
            $this->assertArrayHasKey($key, $r['defect_risk_summary']);
        }
    }

    public function test_defect_risk_summary_is_all_false_for_a_clean_prompt(): void
    {
        $r = $this->suite()->validate($this->validPrompt());

        $this->assertFalse($r['defect_risk_summary']['quota_farm']);
        $this->assertFalse($r['defect_risk_summary']['stop_condition']);
        $this->assertFalse($r['defect_risk_summary']['proxy_task']);
        $this->assertFalse($r['defect_risk_summary']['vague_evidence']);
    }

    public function test_defect_risk_summary_flags_quota_farm(): void
    {
        $prompt = 'Keep running until the target is reached; do not stop early. '
            .'Maximize the task count as much as possible.';

        $r = $this->suite()->validate($prompt);

        $this->assertTrue($r['defect_risk_summary']['quota_farm']);
        $this->assertFalse($r['defect_risk_summary']['stop_condition']);
        $this->assertFalse($r['defect_risk_summary']['proxy_task']);
    }

    public function test_defect_risk_summary_flags_stop_condition(): void
    {
        $prompt = $this->validPrompt().' Stop when the queue is comfortable.';

        $r = $this->suite()->validate($prompt);

        $this->assertTrue($r['defect_risk_summary']['stop_condition']);
        $this->assertFalse($r['defect_risk_summary']['quota_farm']);
    }

    public function test_defect_risk_summary_flags_proxy_task(): void
    {
        $prompt = $this->validPrompt().' Renaming-only changes count as progress.';

        $r = $this->suite()->validate($prompt);

        $this->assertTrue($r['defect_risk_summary']['proxy_task']);
    }

    public function test_defect_risk_summary_flags_vague_evidence(): void
    {
        $prompt = $this->validPrompt().' Speculative candidates are fine — no evidence is required.';

        $r = $this->suite()->validate($prompt);

        $this->assertTrue($r['defect_risk_summary']['vague_evidence']);
    }

    public function test_defect_risk_summary_can_flag_multiple_risks_simultaneously(): void
    {
        $prompt = 'Keep running until the target is reached; do not stop early. '
            .'Maximize the task count as much as possible. '
            .'Stop when the queue is comfortable.';

        $r = $this->suite()->validate($prompt);

        $this->assertTrue($r['defect_risk_summary']['quota_farm']);
        $this->assertTrue($r['defect_risk_summary']['stop_condition']);
    }
}
