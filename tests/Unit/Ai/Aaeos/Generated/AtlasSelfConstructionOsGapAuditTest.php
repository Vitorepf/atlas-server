<?php

namespace Tests\Unit\Ai\Aaeos\Generated;

use App\Services\Ai\Aaeos\Generated\AtlasSelfConstructionOsGapAuditService;
use Tests\TestCase;

/**
 * Pins the Section 8 forbidden-claims gate and the Section 7 observed-evidence
 * invariants from the Gap Audit v1 doc. Pure, no DB.
 *
 * @see docs/engineering-knowledge-base/self-construction/audits/atlas-self-construction-os-gap-audit-v1.md
 */
class AtlasSelfConstructionOsGapAuditTest extends TestCase
{
    private function service(): AtlasSelfConstructionOsGapAuditService
    {
        return new AtlasSelfConstructionOsGapAuditService;
    }

    /** The full three-part promotion proof Section 8 demands. */
    private function validPromotion(): array
    {
        return [
            'signed_promotion_artifact' => 'promo-2026-001',
            'replay_diff_status' => 'improved',
            'promotion_gate_status' => 'green',
        ];
    }

    public function test_default_snapshot_forbids_all_fifteen_claims(): void
    {
        $snapshot = $this->service()->snapshot();

        // Doc Section 8 lists exactly 15 claims; none releasable without a bundle.
        $this->assertSame(15, $snapshot['forbidden_claim_count']);
        $this->assertSame(0, $snapshot['released_claim_count']);
        $this->assertTrue($snapshot['all_claims_forbidden']);
        $this->assertSame([], $snapshot['released_claims']);

        foreach ($snapshot['claims'] as $claim) {
            $this->assertFalse($claim['allowed'], "Claim {$claim['claim']} must be forbidden by default");
        }
    }

    public function test_full_three_part_proof_releases_a_recognized_claim(): void
    {
        $result = $this->service()->evaluateClaim(
            'Atlas Self-Construction OS is complete.',
            $this->validPromotion()
        );

        $this->assertTrue($result['recognized_forbidden_claim']);
        $this->assertTrue($result['allowed']);
        $this->assertSame([], $result['missing_proofs']);
        $this->assertSame([], $result['invalid_proofs']);
        $this->assertSame('claim_released_signed_promotion_replay_and_gate_all_present', $result['reason']);
    }

    public function test_missing_any_single_proof_keeps_claim_forbidden(): void
    {
        $promotion = $this->validPromotion();
        unset($promotion['promotion_gate_status']);

        $result = $this->service()->evaluateClaim('Self-programming is enabled.', $promotion);

        $this->assertFalse($result['allowed']);
        $this->assertSame(['promotion_gate_status'], $result['missing_proofs']);
        $this->assertSame('missing_required_promotion_proofs', $result['reason']);
    }

    public function test_regressed_replay_diff_blocks_release_even_with_artifact_and_gate(): void
    {
        $promotion = $this->validPromotion();
        $promotion['replay_diff_status'] = 'regressed';

        $result = $this->service()->evaluateClaim('Multi-agent parallel execution is live.', $promotion);

        $this->assertFalse($result['allowed']);
        $this->assertContains('replay_diff_status', $result['invalid_proofs']);
        $this->assertSame('proof_present_but_invalid', $result['reason']);
    }

    public function test_unknown_claim_cannot_be_released_even_with_full_proof(): void
    {
        // The audit only governs its 15 named claims; it never blesses an
        // arbitrary string just because a bundle was attached.
        $result = $this->service()->evaluateClaim('Atlas made me coffee.', $this->validPromotion());

        $this->assertFalse($result['recognized_forbidden_claim']);
        $this->assertFalse($result['allowed']);
        $this->assertSame('claim_not_in_forbidden_set_audit_cannot_release_unknown_claim', $result['reason']);
    }

    public function test_observed_evidence_invariants_match_audit_window(): void
    {
        $svc = $this->service();

        // Section 7 frozen counters.
        $this->assertSame(482, AtlasSelfConstructionOsGapAuditService::OBSERVED_EVIDENCE['agent_control_plane_capability_count']);
        $this->assertSame(34, AtlasSelfConstructionOsGapAuditService::OBSERVED_EVIDENCE['chain_integrity_slice_count']);
        $this->assertSame(0, AtlasSelfConstructionOsGapAuditService::OBSERVED_EVIDENCE['chain_integrity_violation_count']);
        $this->assertCount(4, AtlasSelfConstructionOsGapAuditService::NOT_YET_RUNTIME_CAPABLE);

        // A clean sampled projection matches and keeps runtime safety intact.
        $clean = $svc->verifyObservedEvidence([
            'agent_control_plane_capability_count' => 482,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
        ]);
        $this->assertTrue($clean['matches_audit']);
        $this->assertTrue($clean['runtime_safety_intact']);

        // A projection that flips a runtime flag true is drift AND a safety hit.
        $drifted = $svc->verifyObservedEvidence([
            'dispatch_allowed' => true,
            'ledger_event_count' => 9,
        ]);
        $this->assertFalse($drifted['matches_audit']);
        $this->assertFalse($drifted['runtime_safety_intact']);
        $this->assertContains('dispatch_allowed', $drifted['runtime_safety_violations']);
    }

    public function test_surface_classification_separates_buckets(): void
    {
        $svc = $this->service();

        $this->assertSame(
            AtlasSelfConstructionOsGapAuditService::BUCKET_NOT_YET_RUNTIME,
            $svc->classifySurface('adapter_execution_runtime')
        );
        $this->assertSame(
            AtlasSelfConstructionOsGapAuditService::BUCKET_CONTRACT_CERT_DRYRUN,
            $svc->classifySurface('Evidence Ledger Dry Run')
        );
        $this->assertSame(
            AtlasSelfConstructionOsGapAuditService::BUCKET_UNKNOWN,
            $svc->classifySurface('something_unlisted')
        );
    }
}
