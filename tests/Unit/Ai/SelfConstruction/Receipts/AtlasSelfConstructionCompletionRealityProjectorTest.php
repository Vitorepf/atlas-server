<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Receipts;

use App\Services\Ai\SelfConstruction\Receipts\AtlasSelfConstructionCompletionRealityProjector;
use PHPUnit\Framework\TestCase;

/**
 * Proves AtlasSelfConstructionCompletionRealityProjector: verification.passed + merge.admitted ⇒
 * reality=completed; verification.failed ⇒ rejected; rollback.performed ⇒ rolled_back; worker_claim
 * without verification ⇒ unverified (NOT completed); empty input ⇒ unknown; missing proofs surface
 * as residual risk / missing_proof entries.
 */
final class AtlasSelfConstructionCompletionRealityProjectorTest extends TestCase
{
    public function test_passed_verification_plus_admitted_merge_yields_completed(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([
            'verification_receipt' => ['verdict' => 'passed'],
            'merge_receipt' => ['decision' => 'admitted'],
            'knowledge_sync_receipt' => ['conformant' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionCompletionRealityProjector::REALITY_COMPLETED, $r['reality']);
    }

    public function test_failed_verification_yields_rejected(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([
            'verification_receipt' => ['verdict' => 'failed'],
            'merge_receipt' => ['decision' => 'admitted'],
        ]);
        $this->assertSame(AtlasSelfConstructionCompletionRealityProjector::REALITY_REJECTED, $r['reality']);
    }

    public function test_rollback_performed_yields_rolled_back(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([
            'verification_receipt' => ['verdict' => 'passed'],
            'merge_receipt' => ['decision' => 'admitted'],
            'rollback_receipt' => ['performed' => true],
        ]);
        $this->assertSame(AtlasSelfConstructionCompletionRealityProjector::REALITY_ROLLED_BACK, $r['reality']);
        $this->assertContains('residual_risk:passed_but_rolled_back', $r['residual_risks']);
    }

    public function test_worker_claim_without_verification_yields_unverified(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([
            'worker_claim' => 'I am done',
        ]);
        $this->assertSame(AtlasSelfConstructionCompletionRealityProjector::REALITY_UNVERIFIED, $r['reality']);
        $this->assertContains('missing_proof:verification', $r['missing_proofs']);
    }

    public function test_empty_input_yields_unknown_with_missing_proofs(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([]);
        $this->assertSame(AtlasSelfConstructionCompletionRealityProjector::REALITY_UNKNOWN, $r['reality']);
        $this->assertContains('missing_proof:verification', $r['missing_proofs']);
        $this->assertContains('missing_proof:merge', $r['missing_proofs']);
        $this->assertContains('missing_proof:knowledge_sync', $r['missing_proofs']);
    }

    public function test_completed_without_knowledge_sync_proof_surfaces_residual_risk(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([
            'verification_receipt' => ['verdict' => 'passed'],
            'merge_receipt' => ['decision' => 'admitted'],
            // knowledge_sync_receipt missing
        ]);
        $this->assertSame(AtlasSelfConstructionCompletionRealityProjector::REALITY_COMPLETED, $r['reality']);
        $this->assertContains('residual_risk:knowledge_sync_proof_absent', $r['residual_risks']);
    }

    public function test_knowledge_sync_not_conformant_surfaces_missing_proof_string(): void
    {
        $r = (new AtlasSelfConstructionCompletionRealityProjector)->project([
            'verification_receipt' => ['verdict' => 'passed'],
            'merge_receipt' => ['decision' => 'admitted'],
            'knowledge_sync_receipt' => ['conformant' => false],
        ]);
        $this->assertContains('knowledge_sync_not_conformant', $r['missing_proofs']);
    }
}
