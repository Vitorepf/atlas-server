<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasDevPolicyService;
use Tests\TestCase;

/**
 * Pins the documented Atlas Dev Policy invariants (P1–P17).
 *
 * @see docs/engineering-knowledge-base/atlas-dev-policy.md
 */
class AtlasDevPolicyTest extends TestCase
{
    private function service(): AtlasDevPolicyService
    {
        return new AtlasDevPolicyService();
    }

    /** P17.1 — a forbidden leakage string in AtlasDev/** is a hard policy_wins, not overridable. */
    public function test_leakage_string_in_atlasdev_is_hard_policy_wins(): void
    {
        $r = $this->service()->evaluate([
            'kind' => 'apply_patch',
            'target_path' => 'app/Services/Ai/Programming/AtlasDev/Pipeline/SpecComposer.php',
            'contains_strings' => ['cost_normalized_score'],
            // even with a valid receipt the leakage must still lose:
            'decision_receipt_v2' => [
                'envelope_hash' => 'e', 'prompt_projection_hash' => 'p', 'task_contract_hash' => 't',
            ],
        ]);

        $this->assertSame(AtlasDevPolicyService::POLICY_WINS, $r['verdict']);
        $this->assertSame('P17', $r['violated_invariant']);
        $this->assertTrue($r['policy_wins_over_operator']);
        $this->assertFalse($r['override_possible']);
    }

    /** P1.4 — out-of-scope request delegates to another flow with a suggested_flow. */
    public function test_out_of_scope_request_delegates(): void
    {
        $r = $this->service()->evaluate([
            'kind' => 'edit_doc',
            'out_of_scope_kind' => 'standalone_debug',
        ]);

        $this->assertSame(AtlasDevPolicyService::DELEGATE_TO_OTHER_FLOW, $r['verdict']);
        $this->assertSame('P1', $r['violated_invariant']);
        $this->assertSame('debug_flow', $r['detail']['suggested_flow']);
    }

    /** P5 — run gate returns the documented HTTP codes: 400 no-confirm, 403 bad token, 422 bad hash. */
    public function test_run_gate_http_codes(): void
    {
        $svc = $this->service();

        $this->assertSame(400, $svc->runGate(['operator_confirmed' => false])['http']);
        $this->assertSame(403, $svc->runGate([
            'operator_confirmed' => true, 'confirmation_token_state' => 'reused',
        ])['http']);
        $this->assertSame(422, $svc->runGate([
            'operator_confirmed' => true, 'confirmation_token_state' => 'valid',
            'task_contract_hash_valid' => false,
        ])['http']);
        $this->assertSame(200, $svc->runGate([
            'operator_confirmed' => true, 'confirmation_token_state' => 'valid',
            'task_contract_hash_valid' => true,
        ])['http']);
    }

    /** P11.3 — gemini_cli in write is a hard violation; P11.1 — fallback is overridable via receipt. */
    public function test_provider_lock_rules(): void
    {
        $gemini = $this->service()->evaluate([
            'kind' => 'apply_patch',
            'run' => ['provider' => 'gemini_cli', 'write' => true],
            'decision_receipt_v2' => [
                'envelope_hash' => 'e', 'prompt_projection_hash' => 'p', 'task_contract_hash' => 't',
            ],
        ]);
        $this->assertSame(AtlasDevPolicyService::POLICY_WINS, $gemini['verdict']);
        $this->assertSame('P11', $gemini['violated_invariant']);

        $fallback = $this->service()->evaluate([
            'kind' => 'plan',
            'run' => ['provider' => 'claude_cli', 'fallback_allowed' => true],
        ]);
        $this->assertSame(AtlasDevPolicyService::NEEDS_DECISION_RECEIPT, $fallback['verdict']);
        $this->assertTrue($fallback['override_possible']);
    }

    /** P8.2/P8.3 — repair caps by R-level; repeated signature forces escalate; R4 never patches. */
    public function test_repair_budget_caps_and_escalation(): void
    {
        $svc = $this->service();

        // R1 cap is 1: attempt 0 may repair, attempt 1 must escalate.
        $this->assertSame(AtlasDevPolicyService::REPAIR_ATTEMPT, $svc->repairDecision(['r_level' => 'R1', 'attempt' => 0])['action']);
        $this->assertSame(AtlasDevPolicyService::REPAIR_ESCALATE, $svc->repairDecision(['r_level' => 'R1', 'attempt' => 1])['action']);

        // R3 cap is 2.
        $r3 = $svc->repairDecision(['r_level' => 'R3', 'attempt' => 1]);
        $this->assertSame(2, $r3['cap']);
        $this->assertSame(AtlasDevPolicyService::REPAIR_ATTEMPT, $r3['action']);

        // R4 never patches at Dev tier.
        $this->assertSame(AtlasDevPolicyService::REPAIR_ESCALATE, $svc->repairDecision(['r_level' => 'R4', 'attempt' => 0])['action']);

        // Repeated failure signature => abort + escalate regardless of budget.
        $this->assertSame(AtlasDevPolicyService::REPAIR_ESCALATE, $svc->repairDecision([
            'r_level' => 'R2', 'attempt' => 0, 'repeated_failure_signature' => true,
        ])['action']);
    }

    /** P9.4 — Forge promotion needs score>=7 OR risk>=R4; below floor with signals => human review. */
    public function test_escalation_forge_gate(): void
    {
        $svc = $this->service();

        $forge = $svc->escalationDecision(['file_count' => 8, 'score' => 8, 'risk_level' => 'R3']);
        $this->assertTrue($forge['should_escalate']);
        $this->assertSame('forge', $forge['target']);

        $riskForge = $svc->escalationDecision(['layers' => 3, 'score' => 2, 'risk_level' => 'R4']);
        $this->assertSame('forge', $riskForge['target']);

        $review = $svc->escalationDecision(['file_count' => 8, 'score' => 3, 'risk_level' => 'R2']);
        $this->assertSame('review', $review['target']);

        $none = $svc->escalationDecision(['file_count' => 2, 'layers' => 1, 'score' => 1, 'risk_level' => 'R1']);
        $this->assertFalse($none['should_escalate']);
        $this->assertSame('dev', $none['target']);
    }

    /** P7.1/P7.3 — unverified never flips to passed; non-empty honesty_flags caps at needs_review. */
    public function test_verification_honesty_gate(): void
    {
        $svc = $this->service();

        $receipt = ['envelope_hash' => 'e', 'prompt_projection_hash' => 'p', 'task_contract_hash' => 't'];

        $unverified = $svc->evaluate([
            'kind' => 'run',
            'decision_receipt_v2' => $receipt,
            'run' => [
                'operator_confirmed' => true, 'confirmation_token_state' => 'valid', 'task_contract_hash_valid' => true,
                'claimed_completion' => 'passed', 'current_state' => 'unverified',
            ],
        ]);
        $this->assertSame(AtlasDevPolicyService::POLICY_WINS, $unverified['verdict']);
        $this->assertSame('P7', $unverified['violated_invariant']);
        $this->assertSame('needs_review', $unverified['detail']['max_allowed']);

        $honesty = $svc->evaluate([
            'kind' => 'run',
            'decision_receipt_v2' => $receipt,
            'run' => [
                'operator_confirmed' => true, 'confirmation_token_state' => 'valid', 'task_contract_hash_valid' => true,
                'claimed_completion' => 'passed', 'current_state' => 'needs_review',
                'honesty_flags' => ['stale_lockfile'], 'scope_guard_status' => 'passed',
                'required_gates_passed' => true, 'tests_ok' => true,
            ],
        ]);
        $this->assertSame(AtlasDevPolicyService::POLICY_WINS, $honesty['verdict']);
        $this->assertSame('P7', $honesty['violated_invariant']);
    }

    /** P10.1 — runtime execution without a co-validated receipt triple is policy_wins. */
    public function test_runtime_requires_decision_receipt(): void
    {
        $missing = $this->service()->evaluate([
            'kind' => 'runtime_execute',
            'run' => ['operator_confirmed' => true, 'confirmation_token_state' => 'valid', 'task_contract_hash_valid' => true],
            'decision_receipt_v2' => ['envelope_hash' => 'e'], // incomplete triple
        ]);
        $this->assertSame(AtlasDevPolicyService::POLICY_WINS, $missing['verdict']);
        $this->assertSame('P10', $missing['violated_invariant']);
    }

    /** Clean, in-scope, fully-confirmed action with no violations => allow. */
    public function test_clean_action_allows(): void
    {
        $r = $this->service()->evaluate([
            'kind' => 'plan',
            'target_path' => 'app/Services/Ai/Programming/AtlasDev/Pipeline/SpecComposer.php',
            'run' => ['provider' => 'claude_cli', 'write' => false, 'fallback_allowed' => false],
        ]);
        $this->assertSame(AtlasDevPolicyService::ALLOW, $r['verdict']);
        $this->assertNull($r['violated_invariant']);
        $this->assertFalse($r['policy_wins_over_operator']);
    }

    /** Sanity: the decider declares coverage of all 17 invariants P1–P17. */
    public function test_covers_seventeen_invariants(): void
    {
        $ids = $this->service()->invariantIds();
        $this->assertCount(17, $ids);
        $this->assertSame('P1', $ids[0]);
        $this->assertSame('P17', $ids[16]);
    }
}
