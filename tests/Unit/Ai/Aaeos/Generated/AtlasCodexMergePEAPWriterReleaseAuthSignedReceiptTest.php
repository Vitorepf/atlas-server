<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release AUTHORIZATION SIGNED RECEIPT TEMPLATE
 * contract: the six required future evidence fields (present=false), the six
 * future release preconditions (proven=false) including the pivotal
 * "selected_decision_equals_authorize_writer_release", the structurally
 * not-ready release readiness, the deterministic non-signature template hash, the
 * all-false boundary, and the structurally "no" released/persisted answer.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-authorization-signed-receipt.md
 */
class AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService
    {
        return new AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService();
    }

    /**
     * Doc "Future Evidence": a LATER signed receipt path MUST require EXACTLY
     * these SIX fields, in the documented order — and this read-only template
     * carries none of them (present=false for each).
     */
    public function test_required_future_evidence_is_exactly_the_six_documented_and_unmet(): void
    {
        $evidence = $this->service()->requiredFutureEvidence();

        $this->assertCount(6, $evidence);
        $this->assertSame([
            'external_writer_release_signature_value',
            'signature_validator_identity',
            'signature_validation_timestamp',
            'validated_writer_release_authorization_signature_hash',
            'validated_receipt_hash',
            'validated_signable_payload_hash',
        ], array_column($evidence, 'field'));

        foreach ($evidence as $field) {
            $this->assertFalse($field['present'], "evidence {$field['field']} must be present=false");
        }
    }

    /**
     * Doc "Future Release Preconditions": before any writer release can move
     * forward a later preflight must prove EXACTLY these SIX preconditions, in
     * order — including the pivotal selected_decision_equals_authorize_writer_release
     * — and this template proves none (proven=false for each).
     */
    public function test_future_release_preconditions_are_exactly_the_six_documented_and_unproven(): void
    {
        $svc = $this->service();
        $conditions = $svc->futureReleasePreconditions();

        $this->assertCount(6, $conditions);
        $this->assertSame([
            'signed_receipt_template_is_ready',
            'external_signed_receipt_evidence_is_present',
            'selected_decision_equals_authorize_writer_release',
            'writer_contract_hash_still_matches_the_patch',
            'hot_scope_is_still_clean',
            'writer_capability_tests_still_pass',
        ], array_column($conditions, 'precondition'));

        foreach ($conditions as $condition) {
            $this->assertFalse($condition['proven'], "precondition {$condition['precondition']} must be proven=false");
        }

        // The doc fixes the required selected decision the preflight must observe.
        $this->assertSame('authorize_writer_release', $svc::REQUIRED_SELECTED_DECISION);
    }

    /**
     * Doc frontmatter ("non-authorizing by itself") + "Future Release
     * Preconditions": a writer release is ready ONLY when all six evidence
     * fields are present AND all six preconditions proven. This read-only
     * template carries/proves none, so ready is structurally false and the unmet
     * sets enumerate every field and precondition.
     */
    public function test_release_is_never_ready_and_enumerates_every_gap(): void
    {
        $ready = $this->service()->releaseReady();

        $this->assertFalse($ready['ready']);
        $this->assertSame(0, $ready['evidence_present_count']);
        $this->assertSame(6, $ready['evidence_required_count']);
        $this->assertSame(0, $ready['preconditions_proven_count']);
        $this->assertSame(6, $ready['preconditions_required_count']);
        $this->assertCount(6, $ready['missing_evidence']);
        $this->assertCount(6, $ready['unproven_preconditions']);
        $this->assertContains('selected_decision_equals_authorize_writer_release', $ready['unproven_preconditions']);
        $this->assertContains('validated_signable_payload_hash', $ready['missing_evidence']);
    }

    /**
     * Doc body: this is a TEMPLATE, not a signed receipt. The template hash must
     * be deterministic (same input => same hash), 64-char sha256, and
     * is_signature=false. The status names the template, never a signature.
     */
    public function test_template_hash_is_deterministic_and_is_not_a_signature(): void
    {
        $svc = $this->service();

        $a = $svc->template();
        $b = $svc->template();

        $this->assertSame($a['template_hash'], $b['template_hash']);
        $this->assertSame(64, strlen($a['template_hash']));
        $this->assertFalse($a['is_signature']);
        $this->assertSame('sha256', $a['template_hash_algo']);
        $this->assertSame(
            'writer_release_authorization_signed_receipt_template_constructed',
            $a['status'],
        );
    }

    /**
     * Doc "Boundary": every result keeps all nine keys false. Building a
     * signed-receipt template is never signing, accepting, validating, approving,
     * persisting or merging.
     */
    public function test_boundary_stays_all_false(): void
    {
        $template = $this->service()->template();

        foreach (AtlasCodexMergePEAPWriterReleaseAuthSignedReceiptService::BOUNDARY_KEYS as $key) {
            $this->assertArrayHasKey($key, $template['boundary']);
            $this->assertFalse($template['boundary'][$key], "boundary $key must be false");
        }
        $this->assertCount(9, $template['boundary']);
        $this->assertFalse($template['signature_valid']);
        $this->assertFalse($template['receipt_signed']);
        $this->assertFalse($template['receipt_persisted']);
        $this->assertFalse($template['accepts_or_validates_signature']);
    }

    /**
     * Doc "Human Meaning": the surface does NOT answer "Has the writer been
     * released or persisted?" with a yes — it "remains blocked until a separate
     * release preflight and persistence path exist." Structural regardless of
     * input.
     */
    public function test_release_status_always_answers_no(): void
    {
        $s = $this->service()->releaseStatus();

        $this->assertSame('has_the_writer_been_released_or_persisted', $s['question']);
        $this->assertFalse($s['released']);
        $this->assertFalse($s['persisted']);
        $this->assertSame(
            'writer_release_remains_blocked_until_a_separate_release_preflight_and_persistence_path_exist',
            $s['reason'],
        );
    }

    /**
     * Composite entrypoint holds the boundary and proves determinism + the
     * not-ready release flag, and the boundary check is real: a tampered key is
     * actually caught (non-vacuous assertion).
     */
    public function test_evaluate_holds_boundary_is_deterministic_and_catches_a_flip(): void
    {
        $svc = $this->service();

        $r = $svc->evaluate();
        $this->assertTrue($r['boundary_held']);
        $this->assertSame([], $r['boundary_violations']);
        $this->assertTrue($r['template_hash_deterministic']);
        $this->assertFalse($r['release_ready_flag']);

        // Guard: a flipped boundary key must be detected (assertion is non-vacuous).
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['receipt_persisted' => true] + $svc->boundary(),
        ];
        $violations = $svc->assertBoundaryHeld([$tampered]);
        $this->assertContains('tampered.receipt_persisted', $violations);
    }
}
