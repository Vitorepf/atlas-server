<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthorizationService;
use Tests\TestCase;

/**
 * Pins the documented bare Writer Release Authorization TEMPLATE contract: the
 * eight-key boundary, the nine named required-evidence items, the nine required
 * checks, the never-authorize invariant (even when every check is proven), the
 * six-action future-authorized writer scope with the one-event append-only cap,
 * and the structurally-no implementation-status question.
 *
 * Pure, deterministic, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthorizationTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterReleaseAuthorizationService
    {
        return new AtlasCodexMergePEAPWriterReleaseAuthorizationService();
    }

    /**
     * Doc "Boundary": the template must keep exactly these EIGHT keys false —
     * including signature_valid AND receipt_persisted, but NOT receipt_signed
     * (which belongs to the signature-request sibling).
     */
    public function test_boundary_holds_all_eight_documented_keys_false(): void
    {
        $boundary = $this->service()->boundary();

        $this->assertSame([
            'execution_allowed',
            'writer_file_creation_allowed',
            'ledger_write_allowed',
            'dispatch_allowed',
            'approval_granted',
            'merge_allowed',
            'signature_valid',
            'receipt_persisted',
        ], array_keys($boundary));

        foreach ($boundary as $key => $value) {
            $this->assertFalse($value, "boundary key {$key} must be false");
        }

        // The signature-request sibling's extra key must NOT appear here.
        $this->assertArrayNotHasKey('receipt_signed', $boundary);
    }

    /**
     * Doc "Required Evidence": a future authorization must collect exactly these
     * nine items — this template NAMES them and collects none.
     */
    public function test_required_evidence_names_exactly_nine_items_and_collects_none(): void
    {
        $evidence = $this->service()->requiredEvidence();

        $this->assertSame([
            'writer_implementation_patch_hash',
            'writer_contract_template_hash',
            'writer_implementation_preflight_hash',
            'writer_capability_test_output_hash',
            'append_only_guard_test_output_hash',
            'merge_authority_absence_test_output_hash',
            'dispatch_authority_absence_test_output_hash',
            'hot_scope_recheck_output_hash',
            'human_writer_release_confirmation_hash',
        ], $evidence['required_evidence']);

        $this->assertSame(9, $evidence['evidence_item_count']);
        $this->assertFalse($evidence['any_evidence_collected']);

        foreach ($evidence['evidence'] as $item) {
            $this->assertFalse($item['collected'], "evidence {$item['item']} must be collected=false");
        }
    }

    /**
     * Doc "Required Checks" + decisions: with NO proof signals (safe defaults),
     * all nine checks are unproven, status is blocked, and the template
     * authorizes nothing.
     */
    public function test_safe_defaults_leave_all_nine_checks_unproven_and_unauthorized(): void
    {
        $result = $this->service()->authorize([]);

        $this->assertCount(9, $result['unproven_checks']);
        $this->assertSame(9, $result['unproven_check_count']);
        $this->assertFalse($result['all_required_checks_proven']);
        $this->assertSame('writer_release_authorization_blocked', $result['status']);
        $this->assertFalse($result['authorization_granted']);
        $this->assertFalse($result['eligible_for_separate_authorization']);
        $this->assertFalse($result['can_writer_be_implemented_or_executed_now']);
    }

    /**
     * Doc "Required Checks" + decision "must not authorize writer creation":
     * even when ALL nine proof signals are true, the template still authorizes
     * nothing — it only flips the SECONDARY eligible_for_separate_authorization
     * signal. This is the load-bearing never-authorize invariant.
     */
    public function test_all_checks_proven_still_never_authorizes_only_eligible(): void
    {
        $allTrue = [
            'writer_implementation_preflight_ready' => true,
            'writer_patch_reviewed_by_principal_integrator' => true,
            'writer_contract_hash_matches_patch' => true,
            'required_capability_tests_pass' => true,
            'append_only_guard_passes' => true,
            'merge_authority_absent' => true,
            'dispatch_authority_absent' => true,
            'hot_scope_clean_at_release_time' => true,
            'human_writer_release_confirmation_present' => true,
        ];

        $result = $this->service()->authorize($allTrue);

        $this->assertSame([], $result['unproven_checks']);
        $this->assertTrue($result['all_required_checks_proven']);
        $this->assertSame('writer_release_eligible_for_separate_authorization', $result['status']);
        // The whole point of the template: eligible, but NEVER authorized.
        $this->assertTrue($result['eligible_for_separate_authorization']);
        $this->assertFalse($result['authorization_granted']);
        $this->assertFalse($result['can_writer_be_implemented_or_executed_now']);
    }

    /**
     * Doc "Required Checks": signals are fail-closed — only an exact boolean true
     * proves a check. Truthy-but-not-true values (1, "true") leave it unproven.
     */
    public function test_required_check_signals_are_fail_closed(): void
    {
        $result = $this->service()->authorize([
            'append_only_guard_passes' => 1,        // not boolean true
            'merge_authority_absent' => 'true',     // not boolean true
            'dispatch_authority_absent' => true,    // the only genuinely proven one
        ]);

        $this->assertContains('append_only_guard_passes', $result['unproven_checks']);
        $this->assertContains('merge_authority_absent', $result['unproven_checks']);
        $this->assertNotContains('dispatch_authority_absent', $result['unproven_checks']);
        $this->assertFalse($result['authorization_granted']);
    }

    /**
     * Doc "Future Authorized Writer Scope": only a separately authorized future
     * writer may take these six actions; the final one is capped to ONE
     * append-only persistence event after all checks pass, and the template
     * grants none of it.
     */
    public function test_future_authorized_writer_scope_names_six_actions_capped_at_one_event(): void
    {
        $scope = $this->service()->futureAuthorizedWriterScope();

        $this->assertSame([
            'validate_non_null_payload_fields',
            'recompute_payload_hash',
            'enforce_source_hash_match',
            'enforce_hot_scope_recheck',
            'require_human_confirmation_hash',
            'write_one_append_only_persistence_event_after_all_checks_pass',
        ], $scope['future_authorized_writer_scope']);

        // Doc: "write ONE append-only persistence event" — exactly one.
        $this->assertSame(1, $scope['append_only_event_cap']);
        // Doc: "This template does not grant that authority."
        $this->assertFalse($scope['granted_to_template']);
        $this->assertSame('this_template_does_not_grant_that_authority', $scope['note']);

        foreach ($scope['scope'] as $action) {
            $this->assertFalse($action['granted_to_template'], "{$action['action']} must not be granted to the template");
        }
    }

    /**
     * Doc "Human Meaning": the surface answers the evidence question but never
     * "can the writer be implemented or executed now?" — that stays no, and the
     * composite evaluate() proves the boundary held across every sub-result.
     */
    public function test_implementation_status_is_structurally_no_and_boundary_holds(): void
    {
        $service = $this->service();

        $status = $service->implementationStatus();
        $this->assertFalse($status['can_writer_be_implemented_or_executed_now']);
        $this->assertFalse($status['authorization_granted']);

        // Even feeding every proof signal true, the composite never authorizes
        // and the boundary holds with zero violations.
        $composite = $service->evaluate([
            'writer_implementation_preflight_ready' => true,
            'writer_patch_reviewed_by_principal_integrator' => true,
            'writer_contract_hash_matches_patch' => true,
            'required_capability_tests_pass' => true,
            'append_only_guard_passes' => true,
            'merge_authority_absent' => true,
            'dispatch_authority_absent' => true,
            'hot_scope_clean_at_release_time' => true,
            'human_writer_release_confirmation_present' => true,
        ]);

        $this->assertTrue($composite['boundary_held']);
        $this->assertSame([], $composite['boundary_violations']);
        $this->assertFalse($composite['authorization_granted']);
    }
}
