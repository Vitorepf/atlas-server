<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainPromptGapContract;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainPromptGapContractTest extends TestCase
{
    private function svc(): AtlasExternalBrainPromptGapContract
    {
        return new AtlasExternalBrainPromptGapContract;
    }

    /** A prompt that satisfies every required clause and carries no forbidden phrase. */
    private function compliantPrompt(): string
    {
        return 'Keep originating high-value tasks continuously. '
            .'Prioritize integration gap closure above cosmetic work. '
            .'Every delivery must carry closed-loop proof from outcome to learning update. '
            .'Reject anti-proxy task quality violations — a task that only touches whitespace is not real work. '
            .'If no local high-value task is found, escalate to a deeper search across research sources or a design-path escalation before considering the queue drained.';
    }

    // ── AC: rejects comfortable-queue stopping ────────────────────────────────

    public function test_rejects_prompt_that_allows_stopping_because_queue_depth_is_sufficient(): void
    {
        $prompt = $this->compliantPrompt().' It is fine to stop once the queue depth is sufficient.';

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertTrue($this->hasBlockerType($r['blockers'], AtlasExternalBrainPromptGapContract::BLOCKER_COMFORTABLE_QUEUE_STOP));
    }

    public function test_rejects_prompt_with_sufficient_depth_token(): void
    {
        $prompt = $this->compliantPrompt().' Stop when sufficient_depth is reached.';

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertTrue($this->hasBlockerType($r['blockers'], AtlasExternalBrainPromptGapContract::BLOCKER_COMFORTABLE_QUEUE_STOP));
    }

    public function test_rejects_prompt_that_frames_healthy_queue_as_a_reason_to_stop(): void
    {
        $prompt = $this->compliantPrompt().' It is ok to stop once the queue is healthy.';

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
    }

    public function test_compliant_prompt_with_no_comfortable_wait_language_is_accepted(): void
    {
        $r = $this->svc()->evaluate($this->compliantPrompt());

        $this->assertTrue($r['accepted']);
        $this->assertSame([], $r['blockers']);
    }

    // ── AC: requires explicit priority clauses ────────────────────────────────

    public function test_missing_integration_gap_priority_is_rejected(): void
    {
        $prompt = str_replace('Prioritize integration gap', 'Prioritize whatever seems interesting', $this->compliantPrompt());

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertContains('integration_gap_priority', $r['missing_required_clauses']);
    }

    public function test_missing_closed_loop_proof_clause_is_rejected(): void
    {
        $prompt = str_replace('closed-loop proof', 'some proof', $this->compliantPrompt());

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertContains('closed_loop_proof', $r['missing_required_clauses']);
    }

    public function test_missing_anti_proxy_task_quality_clause_is_rejected(): void
    {
        $prompt = str_replace('anti-proxy', 'low-quality', $this->compliantPrompt());

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertContains('anti_proxy_task_quality', $r['missing_required_clauses']);
    }

    public function test_all_three_priority_clauses_present_satisfies_each(): void
    {
        $r = $this->svc()->evaluate($this->compliantPrompt());

        $this->assertNotContains('integration_gap_priority', $r['missing_required_clauses']);
        $this->assertNotContains('closed_loop_proof', $r['missing_required_clauses']);
        $this->assertNotContains('anti_proxy_task_quality', $r['missing_required_clauses']);
    }

    // ── AC: requires deeper-search / design-path escalation on local exhaustion ──

    public function test_missing_escalation_clause_entirely_is_rejected(): void
    {
        $prompt = str_replace(
            'escalate to a deeper search across research sources or a design-path escalation before considering the queue drained.',
            'stop looking.',
            $this->compliantPrompt(),
        );

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertContains('deeper_search_escalation', $r['missing_required_clauses']);
    }

    public function test_escalation_action_without_local_exhaustion_trigger_is_rejected(): void
    {
        // Mentions "deeper search" but never ties it to local candidates running out.
        $prompt = str_replace(
            'If no local high-value task is found, escalate to a deeper search',
            'Always consider doing a deeper search',
            $this->compliantPrompt(),
        );

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertContains('deeper_search_escalation', $r['missing_required_clauses']);
    }

    public function test_local_exhaustion_trigger_without_escalation_action_is_rejected(): void
    {
        $prompt = str_replace(
            'escalate to a deeper search across research sources or a design-path escalation',
            'just wait',
            $this->compliantPrompt(),
        );

        $r = $this->svc()->evaluate($prompt);

        $this->assertFalse($r['accepted']);
        $this->assertContains('deeper_search_escalation', $r['missing_required_clauses']);
    }

    public function test_design_path_escalation_variant_satisfies_escalation_action(): void
    {
        $prompt = 'If no local high-value task is found, trigger a design-path escalation immediately. '
            .'Prioritize integration gap closure. Closed-loop proof is mandatory. Reject anti-proxy work.';

        $r = $this->svc()->evaluate($prompt);

        $this->assertNotContains('deeper_search_escalation', $r['missing_required_clauses']);
    }

    // ── schema / determinism ───────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->evaluate($this->compliantPrompt());

        $this->assertSame(AtlasExternalBrainPromptGapContract::SCHEMA, $r['schema']);
    }

    public function test_evaluate_is_deterministic_for_identical_text(): void
    {
        $prompt = $this->compliantPrompt();
        $a      = $this->svc()->evaluate($prompt);
        $b      = $this->svc()->evaluate($prompt);

        $this->assertSame($a, $b);
    }

    public function test_case_insensitive_matching(): void
    {
        $prompt = strtoupper($this->compliantPrompt());

        $r = $this->svc()->evaluate($prompt);

        $this->assertTrue($r['accepted']);
    }

    /** @param list<string> $blockers */
    private function hasBlockerType(array $blockers, string $type): bool
    {
        foreach ($blockers as $blocker) {
            if (str_starts_with($blocker, $type)) {
                return true;
            }
        }

        return false;
    }
}
