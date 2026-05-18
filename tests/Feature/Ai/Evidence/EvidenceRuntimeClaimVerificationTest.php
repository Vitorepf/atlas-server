<?php

namespace Tests\Feature\Ai\Evidence;

use App\Services\Ai\Evidence\ClaimVerificationService;
use Tests\Concerns\CreatesEvidenceRuntimeTables;
use Tests\TestCase;

class EvidenceRuntimeClaimVerificationTest extends TestCase
{
    use CreatesEvidenceRuntimeTables;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createEvidenceRuntimeTables();
    }

    protected function tearDown(): void
    {
        $this->dropEvidenceRuntimeTables();
        parent::tearDown();
    }

    public function test_claim_without_evidence_is_unverified(): void
    {
        /** @var ClaimVerificationService $svc */
        $svc = app(ClaimVerificationService::class);
        $claim = $svc->register([
            'claim_text' => 'X works.',
            'claim_type' => ClaimVerificationService::TYPE_INFERRED,
        ]);
        $this->assertSame(ClaimVerificationService::STATUS_UNVERIFIED, $claim->verification_status);
    }

    public function test_high_risk_claim_without_evidence_is_insufficient(): void
    {
        /** @var ClaimVerificationService $svc */
        $svc = app(ClaimVerificationService::class);
        $claim = $svc->register([
            'claim_text' => 'Critical system X is secure.',
            'claim_type' => ClaimVerificationService::TYPE_INFERRED,
            'risk_level' => ClaimVerificationService::RISK_HIGH,
        ]);
        $this->assertSame(ClaimVerificationService::STATUS_INSUFFICIENT, $claim->verification_status);
    }

    public function test_claim_with_evidence_becomes_supported(): void
    {
        /** @var ClaimVerificationService $svc */
        $svc = app(ClaimVerificationService::class);
        $claim = $svc->register([
            'claim_text' => 'Migration applied successfully.',
            'claim_type' => ClaimVerificationService::TYPE_SUPPORTED,
            'evidence_refs' => [
                ['kind' => 'test_result', 'id' => 'tr-123'],
            ],
        ]);
        $this->assertSame(ClaimVerificationService::STATUS_SUPPORTED, $claim->verification_status);
    }

    public function test_superiority_claim_without_benchmark_is_blocked_or_insufficient(): void
    {
        /** @var ClaimVerificationService $svc */
        $svc = app(ClaimVerificationService::class);

        $withoutEvidence = $svc->register([
            'claim_text' => 'Atlas beats Claude Code.',
            'claim_type' => ClaimVerificationService::TYPE_SUPERIORITY,
            'risk_level' => ClaimVerificationService::RISK_HIGH,
        ]);
        $this->assertSame(ClaimVerificationService::STATUS_BLOCKED, $withoutEvidence->verification_status);

        $withNonBenchmarkEvidence = $svc->register([
            'claim_text' => 'Atlas beats Claude Code on workflow X.',
            'claim_type' => ClaimVerificationService::TYPE_SUPERIORITY,
            'evidence_refs' => [['kind' => 'opinion', 'note' => 'user feedback']],
        ]);
        $this->assertSame(
            ClaimVerificationService::STATUS_INSUFFICIENT,
            $withNonBenchmarkEvidence->verification_status,
        );
    }

    public function test_superiority_claim_with_benchmark_evidence_becomes_supported(): void
    {
        /** @var ClaimVerificationService $svc */
        $svc = app(ClaimVerificationService::class);
        $claim = $svc->register([
            'claim_text' => 'Atlas certification rate exceeds rival.',
            'claim_type' => ClaimVerificationService::TYPE_SUPERIORITY,
            'evidence_refs' => [
                ['kind' => 'benchmark', 'id' => 'bench-001'],
            ],
        ]);
        $this->assertSame(ClaimVerificationService::STATUS_SUPPORTED, $claim->verification_status);
    }
}
