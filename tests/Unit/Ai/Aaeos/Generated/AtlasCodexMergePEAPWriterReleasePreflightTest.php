<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleasePreflightService;
use Tests\TestCase;

/**
 * Pins the read-only writer-release-preflight contract straight from the doc:
 *   - exactly NINE boundary keys, always false (doc "Boundary");
 *   - upstream gate: template not ready => literal
 *     `writer_release_authorization_signed_receipt_template_not_ready`, not ready,
 *     no checks evaluated (doc "Required Upstream Contract");
 *   - exactly NINE release checks, fail-closed by default (doc "Release Checks");
 *   - even with template ready AND every check passing, the surface only becomes
 *     ELIGIBLE for a separate signed release path — writer_released and
 *     "has the writer been released" stay false (doc "Human Meaning").
 * Pure, no DB, no RefreshDatabase.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-preflight.md
 */
class AtlasCodexMergePEAPWriterReleasePreflightTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterReleasePreflightService
    {
        return new AtlasCodexMergePEAPWriterReleasePreflightService;
    }

    /** @return array<string,bool> Every documented release-check clearing signal set true. */
    private function allChecksProven(): array
    {
        return [
            'writer_release_authorization_signed_receipt_template_ready' => true,
            'external_signed_receipt_evidence_present' => true,
            'selected_decision_equals_authorize_writer_release' => true,
            'writer_contract_hash_still_matches_patch' => true,
            'hot_scope_still_clean' => true,
            'writer_capability_tests_still_pass' => true,
            'writer_has_no_merge_authority' => true,
            'writer_has_no_dispatch_authority' => true,
            'release_actor_identity_present' => true,
            'receipt_persistence_plan_exists' => true,
        ];
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
            'writer_release_authorization_signed_receipt_template_not_ready',
            $result['status'],
        );
        $this->assertSame(
            'writer_release_authorization_signed_receipt_template_not_ready',
            $result['upstream_not_ready_token'],
        );
        $this->assertFalse($result['signed_receipt_template_ready']);
        $this->assertSame(
            ['writer_release_authorization_signed_receipt_template_not_ready'],
            $result['remaining_blockers'],
        );
        // The upstream gate short-circuits before any release check is scored.
        $this->assertFalse($result['release_checks']['evaluated']);
        $this->assertSame([], $result['release_checks']['checks']);
        // And it never releases / answers yes.
        $this->assertFalse($result['writer_released']);
        $this->assertFalse($result['has_the_writer_been_released']);
    }

    public function test_release_checks_are_exactly_nine_and_fail_closed_when_template_ready(): void
    {
        // Template ready but no proof signals => all nine checks still block.
        $result = $this->service()->preflight([
            'writer_release_authorization_signed_receipt_template_ready' => true,
        ]);

        $this->assertSame('writer_release_blocked', $result['status']);
        $this->assertTrue($result['signed_receipt_template_ready']);
        $this->assertNull($result['upstream_not_ready_token']);
        $this->assertCount(9, $result['release_checks']['release_checks']);
        $this->assertSame(9, $result['remaining_blocker_count']);
        $this->assertContains('missing_external_signed_writer_release_authorization_evidence', $result['remaining_blockers']);
        $this->assertContains('writer_release_actor_identity_missing', $result['remaining_blockers']);
        $this->assertContains('writer_release_receipt_persistence_plan_missing', $result['remaining_blockers']);
        $this->assertFalse($result['all_release_checks_pass']);
        $this->assertFalse($result['writer_released']);
    }

    public function test_truthy_non_boolean_proof_signals_do_not_clear_a_check(): void
    {
        // Fail-closed: only an exact boolean true clears a check. "true"/1 do not.
        $result = $this->service()->preflight([
            'writer_release_authorization_signed_receipt_template_ready' => true,
            'hot_scope_still_clean' => 'true',
            'writer_has_no_merge_authority' => 1,
        ]);

        $this->assertContains('hot_scope_recheck_missing', $result['remaining_blockers']);
        $this->assertContains('writer_merge_authority_absence_not_verified', $result['remaining_blockers']);
        $this->assertSame(9, $result['remaining_blocker_count']);
    }

    public function test_all_checks_passing_yields_eligible_but_never_releases(): void
    {
        // Template ready AND every documented release check proven true.
        $result = $this->service()->preflight($this->allChecksProven());

        $this->assertTrue($result['all_release_checks_pass']);
        $this->assertSame([], $result['remaining_blockers']);
        $this->assertSame(
            'writer_release_eligible_for_separate_signed_release_path',
            $result['status'],
        );
        $this->assertTrue($result['eligible_for_separate_signed_release_path']);
        // Doc "Human Meaning": the answer remains no even when fully unblocked.
        $this->assertFalse($result['writer_released']);
        $this->assertFalse($result['has_the_writer_been_released']);
    }

    public function test_boundary_holds_across_fully_unblocked_composite(): void
    {
        // Even at the most-permissive input the nine-key boundary stays intact
        // and the writer is never released.
        $evaluate = $this->service()->evaluate($this->allChecksProven());

        $this->assertTrue($evaluate['boundary_held']);
        $this->assertSame([], $evaluate['boundary_violations']);
        $this->assertFalse($evaluate['writer_released']);
        $this->assertFalse($evaluate['preflight']['has_the_writer_been_released']);
    }
}
