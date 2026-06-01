<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeExecutorContractService;
use Tests\TestCase;

/**
 * Pins the documented Codex Merge Executor Contract boundary + readiness rules.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-executor-contract.md
 */
class AtlasCodexMergeExecutorContractTest extends TestCase
{
    private function service(): AtlasCodexMergeExecutorContractService
    {
        return new AtlasCodexMergeExecutorContractService();
    }

    /**
     * A fully-proven release preflight input: all 10 required hashes present and
     * every documented gate cleared (no hot scope touched).
     *
     * @return array<string,mixed>
     */
    private function provenPreflightInput(): array
    {
        $input = [];
        foreach (AtlasCodexMergeExecutorContractService::PREFLIGHT_REQUIRED_HASHES as $key) {
            $input[$key] = 'sha256:'.$key;
        }

        return $input + [
            'persisted_signed_final_receipt_present' => true,
            'append_only_receipt_event_present' => true,
            'receipt_source_hash_matches' => true,
            'executor_contract_hash_matches' => true,
            'final_diff_passed' => true,
            'hot_voice_or_kernel_scope_touched' => false,
            'docs_health_passed' => true,
            'architecture_validation_passed' => true,
            'focused_test_matrix_passed' => true,
            'human_executor_release_confirmation_present' => true,
        ];
    }

    /**
     * A post-execution receipt where every documented merge-block condition is
     * cleared and every required evidence field is present.
     *
     * @return array<string,mixed>
     */
    private function cleanReceipt(): array
    {
        $receipt = [];
        foreach (AtlasCodexMergeExecutorContractService::RECEIPT_REQUIRED_FIELDS as $field) {
            $receipt[$field] = in_array($field, ['files_changed', 'commands_run'], true)
                ? ['x']
                : 'sha256:'.$field;
        }

        return $receipt + [
            'applied_patch_hash_matches_receipt_bound_set' => true,
            'changed_files_within_signed_receipt_scope' => true,
            'hot_scope_check_passed_after_execution' => true,
            'docs_health_passed_after_execution' => true,
            'architecture_validation_passed_after_execution' => true,
            'focused_tests_passed_after_execution' => true,
            'rollback_plan_present_after_execution' => true,
        ];
    }

    /**
     * Doc "Boundary": every surface must keep all nine keys false, and the empty
     * (safe-default) input must NEVER make the chain ready while keeping the
     * boundary intact.
     */
    public function test_empty_input_keeps_boundary_and_is_not_ready(): void
    {
        $r = $this->service()->contract([]);

        // All nine boundary keys present and false on the preflight surface.
        foreach (AtlasCodexMergeExecutorContractService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $r['release_preflight']['boundary']);
            $this->assertFalse($r['release_preflight']['boundary'][$key], "boundary $key must be false");
        }

        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['chain_ready']);
        // With no proven evidence the preflight is not ready.
        $this->assertFalse($r['release_preflight']['ready']);
    }

    /**
     * Doc "It blocks on:" — a single missing/failed gate must drop readiness and
     * surface the EXACT documented blocker, while the others stay clear.
     */
    public function test_single_failed_gate_blocks_preflight_with_named_reason(): void
    {
        $input = $this->provenPreflightInput();
        // Flip exactly one documented gate: final diff fails.
        $input['final_diff_passed'] = false;

        $pre = $this->service()->releasePreflight($input);

        $this->assertFalse($pre['ready']);
        $this->assertContains('final_diff_failure', $pre['blockers']);
        $this->assertNotContains('docs_health_failure', $pre['blockers']);
        $this->assertSame([], $pre['missing_hashes']);
    }

    /**
     * Doc "hot Voice or Kernel scope touch" — this blocker is INVERTED: presence
     * of a hot touch fires it even when everything else is green.
     */
    public function test_hot_scope_touch_blocks_preflight(): void
    {
        $input = $this->provenPreflightInput();
        $input['hot_voice_or_kernel_scope_touched'] = true;

        $pre = $this->service()->releasePreflight($input);

        $this->assertFalse($pre['ready']);
        $this->assertContains('hot_voice_or_kernel_scope_touch', $pre['blockers']);
    }

    /**
     * Doc "It may become ready only after executor release preflight is ready."
     * The contract template must NOT be ready while the preflight is not ready,
     * and the receipt template (which depends on the contract template) must
     * also be gated shut.
     */
    public function test_contract_and_receipt_are_gated_until_preflight_ready(): void
    {
        $svc = $this->service();
        $notReady = $svc->releasePreflight([]); // empty => not ready

        $contract = $svc->executorContractTemplate($notReady);
        $this->assertFalse($contract['ready']);
        $this->assertSame('executor_release_preflight_not_ready', $contract['gated_reason']);

        $receipt = $svc->executionReceiptTemplate($contract, $this->cleanReceipt());
        $this->assertFalse($receipt['ready']);
        $this->assertSame('executor_contract_template_not_ready', $receipt['gated_reason']);
        // Even fully gated, the boundary restatement keeps merge disallowed.
        $this->assertFalse($receipt['merge_allowed']);
        $this->assertFalse($receipt['patch_executed']);
    }

    /**
     * Doc full chain: with all preflight evidence proven AND a clean receipt,
     * every surface becomes ready in dependency order, the chain is ready, the
     * receipt would NOT block a future merge — yet merge_allowed stays false and
     * the boundary still holds (the surfaces never execute or merge themselves).
     */
    public function test_full_proven_chain_becomes_ready_but_never_merges(): void
    {
        $svc = $this->service();
        $r = $svc->contract([
            'release_preflight' => $this->provenPreflightInput(),
            'execution_receipt' => $this->cleanReceipt(),
        ]);

        $this->assertTrue($r['release_preflight']['ready']);
        $this->assertTrue($r['executor_contract_template']['ready']);
        $this->assertTrue($r['execution_receipt_template']['ready']);
        $this->assertTrue($r['chain_ready']);

        // Clean receipt => no documented merge-block reason fires.
        $this->assertFalse($r['execution_receipt_template']['would_block_future_merge']);
        $this->assertSame([], $r['execution_receipt_template']['merge_block_reasons']);
        $this->assertSame([], $r['execution_receipt_template']['missing_fields']);

        // Boundary is sacred even when fully ready.
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['execution_receipt_template']['merge_allowed']);
    }

    /**
     * Doc "It must block future merge if:" — a single post-execution failure
     * (patch hash mismatch) must flip would_block_future_merge with the exact
     * documented reason, and merge_allowed must remain false.
     */
    public function test_receipt_blocks_future_merge_on_applied_patch_hash_mismatch(): void
    {
        $svc = $this->service();
        $contract = $svc->executorContractTemplate($svc->releasePreflight($this->provenPreflightInput()));

        $receipt = $this->cleanReceipt();
        $receipt['applied_patch_hash_matches_receipt_bound_set'] = false; // the one breach

        $res = $svc->executionReceiptTemplate($contract, $receipt);

        $this->assertTrue($res['ready']); // template itself is ready to evaluate
        $this->assertTrue($res['would_block_future_merge']);
        $this->assertSame(['applied_patch_hash_mismatch'], $res['merge_block_reasons']);
        $this->assertFalse($res['merge_allowed']);
    }

    /**
     * Guard: assertBoundaryHeld must actually catch a flipped key (proves the
     * check is real, not vacuous).
     */
    public function test_boundary_assertion_catches_a_flipped_key(): void
    {
        $svc = $this->service();
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['execution_allowed' => true] + $svc->boundary(),
        ];

        $violations = $svc->assertBoundaryHeld([$tampered]);

        $this->assertContains('tampered.execution_allowed', $violations);
    }
}
