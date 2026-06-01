<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseExecContractPreflightService;
use Tests\TestCase;

/**
 * Pins the read-only execution-contract-preflight contract from the doc:
 *   - exactly NINE boundary keys, always false (doc "Boundary");
 *   - upstream gate: template not ready => literal
 *     `writer_release_signed_receipt_template_not_ready`, not ready, no checks
 *     evaluated (doc "Required Upstream Contract");
 *   - exactly NINE required checks, fail-closed by default (doc "Required Checks");
 *   - even with template ready AND every check passing, the surface only becomes
 *     ELIGIBLE for a separate governed surface — execution_contract_allowed and
 *     "can release/execute now" stay false (doc "Human Meaning").
 * Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-execution-contract-preflight.md
 */
class AtlasCodexMergeReleaseExecContractPreflightTest extends TestCase
{
    private function service(): AtlasCodexMergeReleaseExecContractPreflightService
    {
        return new AtlasCodexMergeReleaseExecContractPreflightService;
    }

    public function test_boundary_is_exactly_nine_keys_all_false(): void
    {
        $boundary = $this->service()->boundary();

        $this->assertCount(9, $boundary);
        $this->assertSame([
            'execution_allowed',
            'writer_file_creation_allowed',
            'ledger_write_allowed',
            'dispatch_allowed',
            'approval_granted',
            'merge_allowed',
            'signature_valid',
            'receipt_signed',
            'receipt_persisted',
        ], array_keys($boundary));

        foreach ($boundary as $value) {
            $this->assertFalse($value);
        }
    }

    public function test_upstream_template_not_ready_short_circuits_with_literal_token(): void
    {
        // Default input: template not proven ready. Doc "Required Upstream
        // Contract" mandates the literal token and a NOT-ready report.
        $result = $this->service()->preflight([]);

        $this->assertSame(
            'writer_release_signed_receipt_template_not_ready',
            $result['status'],
        );
        $this->assertSame(
            'writer_release_signed_receipt_template_not_ready',
            $result['upstream_not_ready_token'],
        );
        $this->assertFalse($result['signed_receipt_template_ready']);
        $this->assertSame(['writer_release_signed_receipt_template_not_ready'], $result['remaining_blockers']);
        // The upstream gate short-circuits before any required check is scored.
        $this->assertFalse($result['required_checks']['evaluated']);
        $this->assertSame([], $result['required_checks']['checks']);
        // And it never allows / answers yes.
        $this->assertFalse($result['execution_contract_allowed']);
        $this->assertFalse($result['can_release_or_execute_writer_now']);
    }

    public function test_required_checks_are_exactly_nine_and_fail_closed_when_template_ready(): void
    {
        // Template ready but no proof signals => all nine checks still block.
        $result = $this->service()->preflight(['signed_receipt_template_ready' => true]);

        $this->assertSame('writer_release_execution_contract_blocked', $result['status']);
        $this->assertTrue($result['signed_receipt_template_ready']);
        $this->assertNull($result['upstream_not_ready_token']);
        $this->assertCount(9, $result['required_checks']['required_checks']);
        $this->assertSame(9, $result['remaining_blocker_count']);
        $this->assertContains('external_validated_signature_evidence_missing', $result['remaining_blockers']);
        $this->assertContains('rollback_and_disable_path_not_defined', $result['remaining_blockers']);
        $this->assertFalse($result['all_required_checks_pass']);
        $this->assertFalse($result['execution_contract_allowed']);
    }

    public function test_truthy_non_boolean_proof_signals_do_not_clear_a_check(): void
    {
        // Fail-closed: only an exact boolean true clears a check. "true"/1 do not.
        $result = $this->service()->preflight([
            'signed_receipt_template_ready' => true,
            'hot_scope_clean' => 'true',
            'writer_has_no_merge_authority' => 1,
        ]);

        $this->assertContains('hot_scope_not_clean', $result['remaining_blockers']);
        $this->assertContains('writer_has_merge_authority', $result['remaining_blockers']);
        $this->assertSame(9, $result['remaining_blocker_count']);
    }

    public function test_all_checks_passing_yields_eligible_but_never_allows_or_executes(): void
    {
        // Template ready AND every documented required check proven true.
        $result = $this->service()->preflight([
            'signed_receipt_template_ready' => true,
            'external_validated_signature_evidence_present' => true,
            'selected_decision_equals_authorize_writer_release' => true,
            'writer_contract_hash_matches_patch' => true,
            'hot_scope_clean' => true,
            'writer_capability_tests_pass' => true,
            'writer_has_no_merge_authority' => true,
            'writer_has_no_dispatch_authority' => true,
            'execution_scope_is_writer_release_only' => true,
            'rollback_and_disable_path_defined' => true,
        ]);

        $this->assertTrue($result['all_required_checks_pass']);
        $this->assertSame([], $result['remaining_blockers']);
        $this->assertSame(
            'writer_release_execution_contract_eligible_for_separate_governed_surface',
            $result['status'],
        );
        $this->assertTrue($result['execution_contract_eligible_for_separate_governed_surface']);
        // Doc "Human Meaning": the answer remains no even when fully unblocked.
        $this->assertFalse($result['execution_contract_allowed']);
        $this->assertFalse($result['can_release_or_execute_writer_now']);

        // Boundary held across the fully-unblocked composite, all nine keys false.
        $evaluate = $this->service()->evaluate([
            'signed_receipt_template_ready' => true,
            'external_validated_signature_evidence_present' => true,
            'selected_decision_equals_authorize_writer_release' => true,
            'writer_contract_hash_matches_patch' => true,
            'hot_scope_clean' => true,
            'writer_capability_tests_pass' => true,
            'writer_has_no_merge_authority' => true,
            'writer_has_no_dispatch_authority' => true,
            'execution_scope_is_writer_release_only' => true,
            'rollback_and_disable_path_defined' => true,
        ]);
        $this->assertTrue($evaluate['boundary_held']);
        $this->assertSame([], $evaluate['boundary_violations']);
        $this->assertFalse($evaluate['execution_contract_allowed']);
    }
}
