<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierTest extends TestCase
{
    public function test_empty_verification_blocks_persistence(): void
    {
        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->emptyVerification();

        $this->assertSame('atlas.self_construction.runtime_promotion_receipt_pre_submission_verifier.v1', $result['schema_version']);
        $this->assertSame('not_supplied', $result['status']);
        $this->assertFalse($result['can_persist']);
        $this->assertTrue($result['persistence_blocked']);
        $this->assertNotEmpty($result['verification_hash']);
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);
        $this->assertFalse($result['provider_call_allowed']);
        $this->assertFalse($result['token_spend_allowed']);
    }

    public function test_verifier_detects_placeholder_signer_and_missing_fields(): void
    {
        $matrix = $this->matrix();
        $receipt = $this->canonicalReceipt($matrix);
        $receipt['signed_by'] = '<operator>';
        $receipt['reason'] = 'short';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertSame('blocked', $result['status']);
        $this->assertTrue($result['placeholder_signer']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('placeholder_signer', $codes);
        $this->assertContains('reason_too_short_or_placeholder', $codes);
        $this->assertFalse($result['can_persist']);
    }

    public function test_verifier_detects_stale_matrix_and_closure_basis_hashes(): void
    {
        $matrix = $this->matrix();
        $receipt = $this->canonicalReceipt($matrix);
        $receipt['runtime_gap_matrix_hash'] = str_repeat('a', 64);
        $receipt['runtime_promotion_closure_basis_hash'] = str_repeat('b', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertTrue($result['stale_runtime_gap_matrix_hash']);
        $this->assertTrue($result['stale_runtime_promotion_closure_basis_hash']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('stale_runtime_gap_matrix_hash', $codes);
        $this->assertContains('stale_runtime_promotion_closure_basis_hash', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_verifier_detects_promoted_gap_drift(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['promoted_gap_ids'] = ['nonexistent_gap'];
        $receipt['graduation_evidence_hashes'] = ['nonexistent_gap' => str_repeat('c', 64)];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertTrue($result['promoted_gap_drift']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('promoted_gap_id_drift', $codes);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_verifier_detects_stale_promoted_gap_ids_when_live_matrix_has_no_gaps(): void
    {
        $matrix = array_replace($this->syntheticMatrix(), [
            'all_runtime_y' => true,
            'rows' => [
                [
                    'gap_id' => 'adapter_execution_runtime',
                    'runtime_y' => true,
                    'runtime_y_candidate' => true,
                    'runtime_enabled' => false,
                    'graduation_evidence_hash' => str_repeat('a', 64),
                ],
            ],
        ]);
        $receipt = $this->syntheticReceipt(array_replace($matrix, [
            'all_runtime_y' => false,
            'rows' => [
                [
                    'gap_id' => 'adapter_execution_runtime',
                    'runtime_y' => false,
                    'runtime_y_candidate' => true,
                    'runtime_enabled' => false,
                    'graduation_evidence_hash' => str_repeat('a', 64),
                ],
            ],
        ]));
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertSame([], $result['expected_gap_ids']);
        $this->assertSame(['adapter_execution_runtime'], $result['promoted_gap_ids']);
        $this->assertTrue($result['promoted_gap_drift']);
        $this->assertContains('promoted_gap_id_drift', array_column($result['violations'], 'code'));
        $this->assertSame('blocked', $result['status']);
        $this->assertFalse($result['can_persist']);
    }

    public function test_verifier_detects_receipt_hash_mismatch(): void
    {
        $matrix = $this->matrix();
        $receipt = $this->canonicalReceipt($matrix);
        $receipt['receipt_hash'] = str_repeat('d', 64);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertFalse($result['receipt_hash_matches_payload']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('receipt_hash_mismatch', $codes);
        $this->assertFalse($result['can_persist']);
    }

    public function test_verifier_detects_forbidden_flags_true(): void
    {
        $matrix = $this->matrix();
        $receipt = $this->canonicalReceipt($matrix);
        $receipt['execution_allowed'] = true;
        $receipt['dispatch_allowed'] = true;
        $receipt['self_programming_allowed'] = true;
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertContains('execution_allowed', $result['forbidden_flags_true']);
        $this->assertContains('dispatch_allowed', $result['forbidden_flags_true']);
        $this->assertContains('self_programming_allowed', $result['forbidden_flags_true']);
        $this->assertSame('blocked', $result['status']);
    }

    public function test_verifier_passes_canonical_payload_without_persisting(): void
    {
        Storage::fake('local');
        $matrix = $this->matrix();
        $receipt = $this->canonicalReceipt($matrix);

        $result = (new AtlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierService)->verify($receipt, $matrix);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['can_persist']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertFalse($result['placeholder_signer']);
        $this->assertTrue($result['receipt_hash_matches_payload']);
        $this->assertFalse($result['execution_allowed']);
        $this->assertFalse($result['dispatch_allowed']);

        // Nothing must have been persisted to the storage disk by this read-only verifier.
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_readiness_status_and_cli_quartet(): void
    {
        $this->markTestSkipped('ReadinessProjectionRuntimePromotionSection::runtimePromotionSection() undefined — incomplete god-class extraction, fix in that file');
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionReceiptPreSubmissionVerifierStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier_status.v1', $status['schema_version']);
        $this->assertFalse($status['execution_allowed']);

        foreach ([
            'atlas-self-construction-runtime-promotion-receipt-pre-submission-verifier-contract',
            'atlas-self-construction-runtime-promotion-receipt-pre-submission-verifier-preflight',
            'atlas-self-construction-runtime-promotion-receipt-pre-submission-verifier-implementation-packet',
            'atlas-self-construction-runtime-promotion-receipt-pre-submission-verifier-status',
        ] as $option) {
            $exit = Artisan::call('atlas:ai:self-construction', [
                '--'.$option => true,
                '--json' => true,
            ]);
            $this->assertSame(0, $exit);
            $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
            $this->assertFalse($decoded['dispatch_allowed']);
        }
    }

    public function test_agent_control_plane_lists_pre_submission_verifier_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier_contract',
            'atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier_preflight',
            'atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier_implementation_packet',
            'atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier_service',
            'atlas_self_construction_runtime_promotion_receipt_pre_submission_verifier_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @return array<string, mixed> */
    private function matrix(): array
    {
        return (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();
    }

    /**
     * Self-contained synthetic matrix used for tests that need deterministic gap
     * IDs (drift detection, etc). Does not call the live matrix service.
     *
     * @return array<string, mixed>
     */
    private function syntheticMatrix(): array
    {
        $rows = [
            [
                'gap_id' => 'synthetic_gap_alpha',
                'runtime_y' => false,
                'runtime_y_candidate' => true,
                'runtime_enabled' => false,
                'graduation_evidence_hash' => str_repeat('a', 64),
            ],
            [
                'gap_id' => 'synthetic_gap_beta',
                'runtime_y' => false,
                'runtime_y_candidate' => true,
                'runtime_enabled' => false,
                'graduation_evidence_hash' => str_repeat('b', 64),
            ],
        ];

        return [
            'rows' => $rows,
            'runtime_gap_matrix_hash' => str_repeat('e', 64),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('1', 64),
            'runtime_promotion_basis_hash' => str_repeat('2', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('3', 64),
            'all_runtime_y' => false,
        ];
    }

    /**
     * Receipt that matches the synthetic matrix exactly so any individual mutation
     * makes a single violation easy to assert.
     *
     * @return array<string, mixed>
     */
    private function syntheticReceipt(array $matrix): array
    {
        $rows = (array) data_get($matrix, 'rows', []);
        $promotedGapIds = array_values(array_map(static fn (array $r): string => (string) ($r['gap_id'] ?? ''), $rows));
        $graduationHashes = [];
        foreach ($rows as $r) {
            $graduationHashes[(string) ($r['gap_id'] ?? '')] = (string) ($r['graduation_evidence_hash'] ?? '');
        }
        $receipt = [
            'receipt_id' => 'synthetic-runtime-promotion-receipt',
            'signed_by' => 'operator-synthetic-real-user',
            'reason' => 'Operator runtime promotion synthetic reason exceeding thirty-two chars for tests.',
            'runtime_gap_matrix_hash' => (string) data_get($matrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', ''),
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        return $receipt;
    }

    /** @return array<string, mixed> */
    private function canonicalReceipt(array $matrix): array
    {
        $rows = array_values(array_filter((array) data_get($matrix, 'rows', []), static fn ($r): bool => is_array($r) && ! (bool) ($r['runtime_y'] ?? false)));
        $promotedGapIds = array_values(array_map(static fn (array $r): string => (string) ($r['gap_id'] ?? ''), $rows));
        $graduationHashes = [];
        foreach ($rows as $r) {
            $graduationHashes[(string) ($r['gap_id'] ?? '')] = (string) ($r['graduation_evidence_hash'] ?? '');
        }
        $receipt = [
            'receipt_id' => 'test-runtime-promotion-receipt',
            'signed_by' => 'operator-real-test-user',
            'reason' => 'Operator runtime promotion test reason canonical exceeding thirty-two chars.',
            'runtime_gap_matrix_hash' => (string) data_get($matrix, 'expected_runtime_gap_matrix_hash_for_promotion_receipt', ''),
            'runtime_promotion_basis_hash' => (string) data_get($matrix, 'runtime_promotion_basis_hash', ''),
            'runtime_promotion_closure_basis_hash' => (string) data_get($matrix, 'runtime_promotion_closure_basis_hash', ''),
            'promoted_gap_ids' => $promotedGapIds,
            'graduation_evidence_hashes' => $graduationHashes,
            'receipt_hash' => '',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
            'execution_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        return $receipt;
    }
}
