<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionEndgameVerifierService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionEndgameVerifierTest extends TestCase
{
    public function test_empty_verification_blocks_persistence(): void
    {
        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->emptyVerification();

        $this->assertSame('atlas.self_construction.runtime_promotion_endgame_verifier.v1', $result['schema_version']);
        $this->assertSame('not_supplied', $result['status']);
        $this->assertFalse($result['can_persist']);
        $this->assertTrue($result['persistence_blocked']);
        $this->assertNotEmpty($result['verifier_hash']);
    }

    public function test_verifier_detects_missing_fields(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = ['receipt_id' => '', 'signed_by' => '', 'reason' => ''];

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertSame('blocked', $result['status']);
        $this->assertContains('receipt_id', $result['missing_fields']);
        $this->assertContains('signed_by', $result['missing_fields']);
        $this->assertContains('reason', $result['missing_fields']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('required_field_missing', $codes);
    }

    public function test_verifier_detects_placeholder_signer(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['signed_by'] = '<operator>';
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertTrue($result['placeholder_signer']);
        $this->assertSame('blocked', $result['status']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('placeholder_signer', $codes);
    }

    public function test_verifier_detects_stale_matrix_hash(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['runtime_gap_matrix_hash'] = str_repeat('a', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertTrue($result['stale_runtime_gap_matrix_hash']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('stale_runtime_gap_matrix_hash', $codes);
    }

    public function test_verifier_detects_stale_closure_basis_hash(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['runtime_promotion_closure_basis_hash'] = str_repeat('b', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertTrue($result['stale_runtime_promotion_closure_basis_hash']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('stale_runtime_promotion_closure_basis_hash', $codes);
    }

    public function test_verifier_detects_promoted_gap_drift(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['promoted_gap_ids'] = ['nonexistent_gap'];
        $receipt['graduation_evidence_hashes'] = ['nonexistent_gap' => str_repeat('c', 64)];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertTrue($result['promoted_gap_id_drift']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('promoted_gap_id_drift', $codes);
    }

    public function test_verifier_detects_graduation_hash_mismatch(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['graduation_evidence_hashes']['synthetic_gap_alpha'] = str_repeat('d', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertContains('synthetic_gap_alpha', $result['graduation_hash_mismatches']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('graduation_hash_mismatch', $codes);
    }

    public function test_verifier_detects_forbidden_flags_true(): void
    {
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);
        $receipt['execution_allowed'] = true;
        $receipt['self_programming_allowed'] = true;
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertContains('execution_allowed', $result['forbidden_flags_true']);
        $this->assertContains('self_programming_allowed', $result['forbidden_flags_true']);
        $codes = array_column($result['violations'], 'code');
        $this->assertContains('forbidden_flag_true', $codes);
    }

    public function test_verifier_passes_canonical_payload(): void
    {
        Storage::fake('local');
        $matrix = $this->syntheticMatrix();
        $receipt = $this->syntheticReceipt($matrix);

        $result = (new AtlasSelfConstructionRuntimePromotionEndgameVerifierService)->verify($receipt, $matrix);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['can_persist']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertFalse($result['receipt_hash_mismatch']);
        $this->assertFalse($result['placeholder_signer']);
        $this->assertSame([], Storage::disk('local')->allFiles('atlas/self-construction/os-completion/runtime-promotion-receipts'));
    }

    public function test_readiness_status_and_cli_quartet(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionRuntimePromotionEndgameVerifierStatus();
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_endgame_verifier_status.v1', $status['schema_version']);

        foreach ([
            'atlas-self-construction-runtime-promotion-endgame-verifier-contract',
            'atlas-self-construction-runtime-promotion-endgame-verifier-preflight',
            'atlas-self-construction-runtime-promotion-endgame-verifier-implementation-packet',
            'atlas-self-construction-runtime-promotion-endgame-verifier-status',
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

    public function test_agent_control_plane_lists_endgame_verifier_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_runtime_promotion_endgame_verifier_contract',
            'atlas_self_construction_runtime_promotion_endgame_verifier_preflight',
            'atlas_self_construction_runtime_promotion_endgame_verifier_implementation_packet',
            'atlas_self_construction_runtime_promotion_endgame_verifier_service',
            'atlas_self_construction_runtime_promotion_endgame_verifier_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }

    /** @return array<string, mixed> */
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

    /** @return array<string, mixed> */
    private function syntheticReceipt(array $matrix): array
    {
        $rows = (array) data_get($matrix, 'rows', []);
        $promotedGapIds = array_values(array_map(static fn (array $r): string => (string) ($r['gap_id'] ?? ''), $rows));
        $graduationHashes = [];
        foreach ($rows as $r) {
            $graduationHashes[(string) ($r['gap_id'] ?? '')] = (string) ($r['graduation_evidence_hash'] ?? '');
        }
        $receipt = [
            'receipt_id' => 'endgame-synthetic-receipt',
            'signed_by' => 'operator-endgame-synthetic-user',
            'reason' => 'Operator endgame synthetic reason exceeding thirty-two chars for verifier tests.',
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
