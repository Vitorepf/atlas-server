<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimeGapMatrixService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptDraftService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionReceiptDraftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_receipt_draft_blocks_without_operator_identity_and_reason(): void
    {
        $draft = $this->draft([]);

        $this->assertSame('atlas.self_construction.runtime_promotion_receipt_draft.v1', $draft['schema_version']);
        $this->assertSame('blocked_operator_input_required', $draft['status']);
        $this->assertContains('signed_by', $draft['missing_operator_inputs']);
        $this->assertContains('reason', $draft['missing_operator_inputs']);
        $this->assertSame('blocked_missing_runtime_promotion_receipt', data_get($draft, 'verification.status'));
        $this->assertFalse($draft['execution_allowed']);
        $this->assertFalse($draft['runtime_write_allowed']);
        $this->assertContains('runtime_promotion_receipt_draft_does_not_persist_receipt', $draft['non_execution_guarantees']);
    }

    public function test_receipt_draft_rejects_placeholder_operator_identity(): void
    {
        $draft = $this->draft([
            'signed_by' => 'codex',
            'reason' => 'Operator reviewed the current runtime promotion candidates and approves the receipt draft.',
        ]);

        $this->assertSame('blocked_operator_input_required', $draft['status']);
        $this->assertContains('signed_by', $draft['missing_operator_inputs']);
        $this->assertContains('runtime_promotion_receipt_signer_must_be_real_operator', array_column((array) data_get($draft, 'verification.violations', []), 'code'));
    }

    public function test_receipt_draft_builds_verifier_ready_payload_with_real_operator_inputs(): void
    {
        $draft = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($this->candidateMatrix(), [
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
        ]);

        $this->assertSame('ready_for_operator_persistence', $draft['status'], json_encode(data_get($draft, 'verification.violations'), JSON_THROW_ON_ERROR));
        $this->assertSame('passed', data_get($draft, 'verification.status'));
        $this->assertTrue(data_get($draft, 'verification.runtime_promotion_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $draft['receipt_hash']);
        $this->assertSame($draft['receipt_hash'], data_get($draft, 'receipt_payload.receipt_hash'));
        $this->assertSame($draft['runtime_promotion_basis_hash'], data_get($draft, 'receipt_payload.runtime_promotion_basis_hash'));
        $this->assertSame($draft['runtime_promotion_closure_basis_hash'], data_get($draft, 'receipt_payload.runtime_promotion_closure_basis_hash'));
        $this->assertSame($draft['expected_runtime_gap_matrix_hash_for_promotion_receipt'], data_get($draft, 'receipt_payload.runtime_gap_matrix_hash'));
        $this->assertSame($draft['blocked_gap_ids'], data_get($draft, 'receipt_payload.promoted_gap_ids'));
        $this->assertFalse(data_get($draft, 'receipt_payload.execution_allowed'));
        $this->assertFalse(data_get($draft, 'receipt_payload.dispatch_allowed'));
        $this->assertFalse(data_get($draft, 'receipt_payload.self_programming_allowed'));
        $this->assertFalse($draft['persisted']);
        $this->assertFalse($draft['persistence_requested']);
    }

    public function test_receipt_draft_persists_only_when_ready_and_requested(): void
    {
        $blocked = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($this->candidateMatrix(), [
            'signed_by' => 'codex',
            'reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
            'persist_runtime_promotion_receipt' => true,
        ]);
        $ready = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($this->candidateMatrix(), [
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
            'persist_runtime_promotion_receipt' => true,
        ]);

        $this->assertSame('blocked_operator_input_required', $blocked['status']);
        $this->assertTrue($blocked['persistence_requested']);
        $this->assertFalse($blocked['persisted']);
        $this->assertSame('runtime_promotion_receipt_draft_not_ready_for_persistence', $blocked['persistence_blocker']);
        $this->assertSame('persisted', $ready['status']);
        $this->assertTrue($ready['persistence_requested']);
        $this->assertTrue($ready['persisted']);
        $this->assertMatchesRegularExpression('/runtime-promotion-receipts\/[a-f0-9]{64}\.json$/', $ready['receipt_path']);
        $this->assertTrue(Storage::disk('local')->exists($ready['receipt_path']));
        $this->assertTrue(Storage::disk('local')->exists('atlas/self-construction/os-completion/runtime-promotion-receipts/registry.json'));
        $this->assertFalse($ready['execution_allowed']);
        $this->assertFalse($ready['runtime_write_allowed']);
    }

    public function test_receipt_draft_promotes_all_blocked_runtime_rows_not_only_candidates(): void
    {
        $draft = (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($this->matrixWithBlockedNonCandidate(), [
            'signed_by' => 'vitorepf',
            'reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
        ]);

        $this->assertSame('ready_for_operator_persistence', $draft['status'], json_encode(data_get($draft, 'verification.violations'), JSON_THROW_ON_ERROR));
        $this->assertSame([
            'adapter_execution_runtime',
            'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
        ], $draft['blocked_gap_ids']);
        $this->assertSame(['adapter_execution_runtime'], $draft['candidate_gap_ids']);
        $this->assertSame($draft['blocked_gap_ids'], data_get($draft, 'receipt_payload.promoted_gap_ids'));
        $this->assertArrayHasKey('automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime', data_get($draft, 'receipt_payload.graduation_evidence_hashes'));
    }

    public function test_receipt_draft_command_exposes_status_and_quartet(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-runtime-promotion-receipt-draft-status' => true,
            '--signed-by' => 'vitorepf',
            '--reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_status.v1', $payload['schema_version']);
        $this->assertContains(data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_status.status'), [
            'ready_for_operator_persistence',
            'blocked_operator_input_required',
        ]);
        $this->assertSame(0, data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_status.missing_operator_input_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_status.receipt_hash'));
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);

        foreach ([
            '--atlas-self-construction-runtime-promotion-receipt-draft-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_contract.v1',
            '--atlas-self-construction-runtime-promotion-receipt-draft-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_preflight.v1',
            '--atlas-self-construction-runtime-promotion-receipt-draft-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }
    }

    public function test_receipt_draft_human_output_exposes_operator_inputs_and_gap_hashes(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-runtime-promotion-receipt-draft-status' => true,
            '--signed-by' => '<operator>',
            '--reason' => '<operator reason with at least 32 chars>',
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();

        $this->assertStringContainsString('Draft status', $output);
        $this->assertStringContainsString('Candidate gaps', $output);
        $this->assertStringContainsString('Blocked gaps', $output);
        $this->assertStringContainsString('Missing operator inputs:', $output);
        $this->assertStringContainsString('signed_by', $output);
        $this->assertStringContainsString('Verification status', $output);
        $this->assertStringContainsString('Verification violations:', $output);
        $this->assertStringContainsString('runtime_promotion_receipt_signer_must_be_real_operator', $output);
        $this->assertStringContainsString('Runtime promotion allowed', $output);
        $this->assertStringContainsString('Persistence command', $output);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $output);
        $this->assertStringContainsString('Next action', $output);
        $this->assertStringContainsString('Runtime gap matrix hash', $output);
        $this->assertStringContainsString('Receipt hash', $output);
    }

    public function test_receipt_draft_command_threads_persistence_request_with_explicit_operator_inputs(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-runtime-promotion-receipt-draft-status' => true,
            '--signed-by' => 'vitorepf',
            '--reason' => 'Operator reviewed the current runtime promotion candidates and approves this receipt without enabling execution directly.',
            '--persist-runtime-promotion-receipt' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue(data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_status.persistence_requested'));
        $this->assertTrue(data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.persistence_requested'));
        $this->assertContains(data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft_status.status'), [
            'persisted',
            'ready_for_operator_persistence',
            'blocked_operator_input_required',
        ]);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['runtime_write_allowed']);
    }

    /** @param array<string, mixed> $options */
    private function draft(array $options): array
    {
        $matrix = (new AtlasSelfConstructionRuntimeGapMatrixService(app(AtlasSelfConstructionReadinessService::class)))->matrix();

        return (new AtlasSelfConstructionRuntimePromotionReceiptDraftService)->build($matrix, $options);
    }

    /** @return array<string, mixed> */
    private function candidateMatrix(): array
    {
        $rows = [
            [
                'gap_id' => 'adapter_execution_runtime',
                'runtime_y' => false,
                'runtime_y_candidate' => true,
                'runtime_enabled' => false,
                'graduation_evidence_hash' => str_repeat('a', 64),
            ],
            [
                'gap_id' => 'automatic_cost_import_runtime',
                'runtime_y' => false,
                'runtime_y_candidate' => true,
                'runtime_enabled' => false,
                'graduation_evidence_hash' => str_repeat('b', 64),
            ],
        ];

        return [
            'rows' => $rows,
            'runtime_gap_matrix_hash' => str_repeat('1', 64),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('2', 64),
            'runtime_promotion_basis_hash' => str_repeat('3', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('4', 64),
        ];
    }

    /** @return array<string, mixed> */
    private function matrixWithBlockedNonCandidate(): array
    {
        return [
            'rows' => [
                [
                    'gap_id' => 'adapter_execution_runtime',
                    'runtime_y' => false,
                    'runtime_y_candidate' => true,
                    'runtime_enabled' => false,
                    'graduation_evidence_hash' => str_repeat('a', 64),
                ],
                [
                    'gap_id' => 'automatic_dispatch_scheduler_codex_real_invoker_post_start_receipt_contract_runtime',
                    'runtime_y' => false,
                    'runtime_y_candidate' => false,
                    'runtime_enabled' => false,
                    'graduation_evidence_hash' => str_repeat('b', 64),
                ],
            ],
            'runtime_gap_matrix_hash' => str_repeat('1', 64),
            'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('2', 64),
            'runtime_promotion_basis_hash' => str_repeat('3', 64),
            'runtime_promotion_closure_basis_hash' => str_repeat('4', 64),
        ];
    }
}
