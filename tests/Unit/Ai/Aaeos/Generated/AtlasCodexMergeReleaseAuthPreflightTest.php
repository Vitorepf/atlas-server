<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergeReleaseAuthPreflightService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release Authorization Preflight boundary, the
 * twelve blocking conditions, the never-authorize invariant and the
 * defines-but-never-creates future outputs.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-preflight.md
 */
class AtlasCodexMergeReleaseAuthPreflightTest extends TestCase
{
    private function service(): AtlasCodexMergeReleaseAuthPreflightService
    {
        return new AtlasCodexMergeReleaseAuthPreflightService();
    }

    /**
     * Doc "Blocking Conditions": with no clearing evidence (safe-default input),
     * the preflight must list ALL TWELVE documented blockers, report the count,
     * and emit the documented blocked status.
     */
    public function test_lists_all_twelve_blockers_when_no_evidence_present(): void
    {
        $r = $this->service()->preflight([]);

        $this->assertSame('writer_release_authorization_blocked', $r['status']);
        $this->assertSame(12, $r['remaining_blocker_count']);
        $this->assertCount(12, $r['remaining_blockers']);
        $this->assertFalse($r['all_blocking_conditions_cleared']);
        // Spot-check both a missing-hash gate and a verification gate are present.
        $this->assertContains('missing_writer_implementation_patch_hash', $r['remaining_blockers']);
        $this->assertContains('writer_release_not_separately_authorized', $r['remaining_blockers']);
    }

    /**
     * Doc "must not authorize writer release": release_authorization_granted is
     * structurally false even when EVERY clearing signal is supplied. Clearing
     * the blockers only flips the surface to "eligible for a separate review";
     * it never authorizes and never releases.
     */
    public function test_never_authorizes_even_when_all_conditions_cleared(): void
    {
        $allCleared = [
            'writer_implementation_patch_hash_present' => true,
            'writer_contract_template_hash_present' => true,
            'writer_implementation_preflight_hash_present' => true,
            'writer_capability_test_output_hash_present' => true,
            'append_only_guard_test_output_hash_present' => true,
            'merge_authority_absence_test_output_hash_present' => true,
            'dispatch_authority_absence_test_output_hash_present' => true,
            'hot_scope_recheck_output_hash_present' => true,
            'human_writer_release_confirmation_hash_present' => true,
            'writer_patch_reviewed_by_principal_integrator' => true,
            'writer_contract_hash_verified_against_patch' => true,
            'writer_release_separately_authorized' => true,
        ];

        $r = $this->service()->preflight($allCleared);

        // All blockers gone => eligible for a SEPARATE review, not authorized.
        $this->assertSame([], $r['remaining_blockers']);
        $this->assertTrue($r['all_blocking_conditions_cleared']);
        $this->assertSame('writer_release_authorization_eligible_for_separate_review', $r['status']);
        $this->assertTrue($r['release_authorization_eligible_for_review']);
        // HARD invariant: still never authorized, still cannot release now.
        $this->assertFalse($r['release_authorization_granted']);
        $this->assertFalse($r['can_writer_be_released_now']);
    }

    /**
     * Fail-closed: a non-strict-true clearing signal (string "true", 1, "yes")
     * must NOT clear its blocker — only an exact boolean true does. Here only one
     * gate is truly cleared, so exactly eleven blockers must remain.
     */
    public function test_clearing_gate_is_fail_closed_and_partial_clearing_is_exact(): void
    {
        $r = $this->service()->preflight([
            'writer_implementation_patch_hash_present' => true,   // real clear
            'writer_contract_template_hash_present' => 'true',    // loose -> ignored
            'writer_implementation_preflight_hash_present' => 1,  // loose -> ignored
        ]);

        $this->assertSame('writer_release_authorization_blocked', $r['status']);
        $this->assertSame(11, $r['remaining_blocker_count']);
        // The genuinely-cleared gate is gone; the loosely-"cleared" ones remain.
        $this->assertNotContains('missing_writer_implementation_patch_hash', $r['remaining_blockers']);
        $this->assertContains('missing_writer_contract_template_hash', $r['remaining_blockers']);
        $this->assertContains('missing_writer_implementation_preflight_hash', $r['remaining_blockers']);
    }

    /**
     * Doc "Future Outputs": the preflight only DEFINES the four later-flow
     * outputs — it creates none of them. Each output is named with created=false.
     */
    public function test_future_outputs_are_defined_but_never_created(): void
    {
        $f = $this->service()->futureOutputs();

        $this->assertSame([
            'writer_release_authorization_receipt_hash',
            'writer_release_authorization_signature_request_hash',
            'writer_release_signable_payload_hash',
            'writer_release_runbook_hash',
        ], $f['defines_outputs']);
        $this->assertFalse($f['any_output_created']);
        foreach ($f['outputs'] as $output) {
            $this->assertFalse($output['created']);
        }
    }

    /**
     * Doc "Boundary": across the full composite (preflight + future outputs +
     * release decision), even with every clearing signal supplied, all eight
     * boundary keys stay false and the boundary holds with zero violations.
     */
    public function test_boundary_holds_across_composite_with_all_signals(): void
    {
        $service = $this->service();
        $r = $service->evaluate([
            'writer_implementation_patch_hash_present' => true,
            'writer_contract_template_hash_present' => true,
            'writer_implementation_preflight_hash_present' => true,
            'writer_capability_test_output_hash_present' => true,
            'append_only_guard_test_output_hash_present' => true,
            'merge_authority_absence_test_output_hash_present' => true,
            'dispatch_authority_absence_test_output_hash_present' => true,
            'hot_scope_recheck_output_hash_present' => true,
            'human_writer_release_confirmation_hash_present' => true,
            'writer_patch_reviewed_by_principal_integrator' => true,
            'writer_contract_hash_verified_against_patch' => true,
            'writer_release_separately_authorized' => true,
        ]);

        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertFalse($r['release_authorization_granted']);

        // Every documented boundary key is present and false on the preflight.
        $expectedBoundary = [
            'execution_allowed' => false,
            'writer_file_creation_allowed' => false,
            'ledger_write_allowed' => false,
            'dispatch_allowed' => false,
            'approval_granted' => false,
            'merge_allowed' => false,
            'signature_valid' => false,
            'receipt_persisted' => false,
        ];
        $this->assertSame($expectedBoundary, $r['preflight']['boundary']);
    }

    /**
     * Doc "Human Meaning": the surface explicitly does NOT answer "Can the
     * writer be released now?" with a yes — the answer is structurally no.
     */
    public function test_release_decision_is_structurally_no(): void
    {
        $d = $this->service()->releaseDecision();

        $this->assertSame('can_the_writer_be_released_now', $d['question']);
        $this->assertFalse($d['can_writer_be_released_now']);
        $this->assertFalse($d['release_authorization_granted']);
    }
}
