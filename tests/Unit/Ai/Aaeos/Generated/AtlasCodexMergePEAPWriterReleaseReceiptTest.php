<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasCodexMergePEAPWriterReleaseReceiptService;
use Tests\TestCase;

/**
 * Pins the documented Writer Release RECEIPT (unsigned DRAFT) contract: the hard
 * upstream "blocked_before_writer_release_preflight" rule, the eleven future
 * receipt fields (each bound=false), the all-false boundary in BOTH the blocked
 * and the ready branch, the deterministic non-signature receipt hash, and the
 * structurally "no" released answer.
 *
 * @see docs/engineering-knowledge-base/self-construction/codex-merge-post-execution-action-persistence-writer-release-receipt.md
 */
class AtlasCodexMergePEAPWriterReleaseReceiptTest extends TestCase
{
    private function service(): AtlasCodexMergePEAPWriterReleaseReceiptService
    {
        return new AtlasCodexMergePEAPWriterReleaseReceiptService();
    }

    /**
     * Doc "Required Upstream Contract": "If the preflight is not ready, this
     * surface must return blocked_before_writer_release_preflight." Default input
     * (and any non-strict-true readiness) must block, withhold all fields, and
     * produce no hash — while keeping the boundary all-false.
     */
    public function test_blocks_before_writer_release_preflight_by_default(): void
    {
        $svc = $this->service();

        // Empty input => not ready => blocked.
        $blocked = $svc->receiptDraft([]);
        $this->assertSame('blocked_before_writer_release_preflight', $blocked['status']);
        $this->assertTrue($blocked['blocked']);
        $this->assertFalse($blocked['preflight_ready']);
        $this->assertSame('blocked_before_writer_release_preflight', $blocked['blocked_reason']);
        $this->assertSame([], $blocked['receipt_fields']);
        $this->assertNull($blocked['receipt_hash']);

        // Fail-closed: a truthy-but-not-strict-true value still blocks.
        $this->assertTrue($svc->receiptDraft(['writer_release_preflight_ready' => 1])['blocked']);
        $this->assertTrue($svc->receiptDraft(['writer_release_preflight_ready' => 'yes'])['blocked']);
        $this->assertFalse($svc->preflightReady(['writer_release_preflight_ready' => false]));
    }

    /**
     * Doc "Required Upstream Contract": once the writer release preflight is
     * asserted ready (strict bool true), the draft is constructed — it names the
     * receipt fields and produces a hash — but still never releases anything.
     */
    public function test_constructs_draft_once_preflight_is_ready(): void
    {
        $draft = $this->service()->receiptDraft(['writer_release_preflight_ready' => true]);

        $this->assertSame('writer_release_receipt_draft_constructed', $draft['status']);
        $this->assertFalse($draft['blocked']);
        $this->assertTrue($draft['preflight_ready']);
        $this->assertNull($draft['blocked_reason']);
        $this->assertCount(11, $draft['receipt_fields']);
        $this->assertNotNull($draft['receipt_hash']);
    }

    /**
     * Doc "Receipt Fields": the future release receipt must bind EXACTLY these
     * eleven fields, in order — and this draft names them but binds none
     * (bound=false for each).
     */
    public function test_lists_exactly_eleven_unbound_receipt_fields(): void
    {
        $fields = $this->service()->receiptFields();

        $this->assertCount(11, $fields);
        $this->assertSame([
            'writer_release_preflight_hash',
            'writer_release_authorization_signed_receipt_template_hash',
            'validated_writer_release_authorization_signature_hash',
            'release_actor_identity',
            'writer_contract_hash_recheck',
            'hot_scope_recheck',
            'writer_capability_test_run',
            'no_merge_authority_evidence',
            'no_dispatch_authority_evidence',
            'release_decision',
            'release_rationale',
        ], array_column($fields, 'field'));

        foreach ($fields as $field) {
            $this->assertFalse($field['bound']);
        }
    }

    /**
     * Doc body: the constructed draft is UNSIGNED. Its receipt hash must be
     * deterministic (same advisory input => same hash), sensitive (a different
     * advisory actor changes it), 64-char sha256, and is_signature=false.
     */
    public function test_receipt_hash_is_deterministic_and_is_not_a_signature(): void
    {
        $svc = $this->service();

        $a = $svc->receiptDraft(['writer_release_preflight_ready' => true, 'release_actor_identity' => 'integrator-a']);
        $b = $svc->receiptDraft(['writer_release_preflight_ready' => true, 'release_actor_identity' => 'integrator-a']);
        $c = $svc->receiptDraft(['writer_release_preflight_ready' => true, 'release_actor_identity' => 'integrator-b']);

        $this->assertSame($a['receipt_hash'], $b['receipt_hash']);
        $this->assertSame(64, strlen($a['receipt_hash']));
        $this->assertNotSame($a['receipt_hash'], $c['receipt_hash']);
        $this->assertFalse($a['is_signature']);
        $this->assertSame('sha256', $a['receipt_hash_algo']);
    }

    /**
     * Doc "Boundary": every result keeps all nine keys false — in BOTH the blocked
     * branch and the ready branch. Drafting a receipt is never signing, approving,
     * persisting or merging.
     */
    public function test_boundary_stays_all_false_in_both_branches(): void
    {
        $svc = $this->service();

        foreach ([$svc->receiptDraft([]), $svc->receiptDraft(['writer_release_preflight_ready' => true])] as $draft) {
            foreach (AtlasCodexMergePEAPWriterReleaseReceiptService::BOUNDARY_KEYS as $key) {
                $this->assertArrayHasKey($key, $draft['boundary']);
                $this->assertFalse($draft['boundary'][$key], "boundary $key must be false");
            }
            $this->assertFalse($draft['signature_valid']);
            $this->assertFalse($draft['receipt_signed']);
            $this->assertFalse($draft['receipt_persisted']);
            $this->assertFalse($draft['accepts_or_validates_signature']);
        }
    }

    /**
     * Doc "Human Meaning": the surface does NOT answer "Has the writer been
     * released?" with a yes — "The answer remains no. This draft is an unsigned
     * contract input for a later signature request and execution contract."
     * Structural regardless of input.
     */
    public function test_release_status_always_answers_no(): void
    {
        $s = $this->service()->releaseStatus();

        $this->assertSame('has_the_writer_been_released', $s['question']);
        $this->assertFalse($s['released']);
        $this->assertSame(
            'unsigned_contract_input_for_a_later_signature_request_and_execution_contract',
            $s['reason'],
        );
    }

    /**
     * Composite entrypoint holds the boundary in both branches, reports the
     * blocked flag, and the boundary check is real: a tampered key is actually
     * caught (non-vacuous assertion).
     */
    public function test_evaluate_holds_boundary_and_catches_a_flip(): void
    {
        $svc = $this->service();

        $blocked = $svc->evaluate([]);
        $this->assertTrue($blocked['boundary_held']);
        $this->assertSame([], $blocked['boundary_violations']);
        $this->assertTrue($blocked['blocked']);
        $this->assertTrue($blocked['receipt_hash_deterministic']); // vacuously true (null hash)

        $ready = $svc->evaluate(['writer_release_preflight_ready' => true]);
        $this->assertTrue($ready['boundary_held']);
        $this->assertFalse($ready['blocked']);
        $this->assertTrue($ready['receipt_hash_deterministic']);

        // Guard: a flipped boundary key must be detected.
        $tampered = [
            'surface' => 'tampered',
            'boundary' => ['merge_allowed' => true] + $svc->boundary(),
        ];
        $violations = $svc->assertBoundaryHeld([$tampered]);
        $this->assertContains('tampered.merge_allowed', $violations);
    }
}
