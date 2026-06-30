<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashComposerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceHashComposerServiceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_all_artifacts_clean_reports_ready_and_aggregate_ready(): void
    {
        $runtime = $this->runtimePromotionReceipt();
        $human = $this->humanCompletionReceipt();
        $smoke = $this->realProviderSmoke();

        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => $runtime,
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
        ]);

        $this->assertTrue($payload['aggregate_all_evidence_ready']);
        foreach (['runtime_promotion_receipt', 'human_completion_receipt', 'real_provider_smoke'] as $kind) {
            $this->assertTrue(data_get($payload, "{$kind}.persist_readiness.ready"), $kind.' must be ready');
            $this->assertSame([], data_get($payload, "{$kind}.persist_readiness.blockers"));
        }
        $this->assertFalse($payload['ledger_write_allowed']);
        $this->assertFalse($payload['runtime_write_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
        $this->assertArrayHasKey('payload_with_computed_hash', $payload['human_completion_receipt']);
        $this->assertSame(
            (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($human),
            data_get($payload, 'human_completion_receipt.payload_with_computed_hash.receipt_hash'),
        );
    }

    public function test_no_artifacts_provided_reports_aggregate_not_ready(): void
    {
        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([]);

        $this->assertFalse($payload['aggregate_all_evidence_ready']);
        $this->assertFalse(data_get($payload, 'human_completion_receipt.persist_readiness.ready'));
        $this->assertContains('missing_input', data_get($payload, 'human_completion_receipt.persist_readiness.blockers'));
    }

    public function test_hash_mismatch_blocks_persist_readiness(): void
    {
        $human = $this->humanCompletionReceipt(['receipt_hash' => str_repeat('9', 64)]);

        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'completion_receipt' => $human,
        ]);

        $this->assertFalse($payload['aggregate_all_evidence_ready']);
        $this->assertFalse(data_get($payload, 'human_completion_receipt.persist_readiness.ready'));
        $this->assertContains('hash_mismatch', data_get($payload, 'human_completion_receipt.persist_readiness.blockers'));
    }

    public function test_placeholder_field_blocks_persist_readiness(): void
    {
        $smoke = $this->realProviderSmoke(['provider_run_id' => '<provider_run_id>']);

        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'real_provider_smoke' => $smoke,
        ]);

        $this->assertFalse($payload['aggregate_all_evidence_ready']);
        $this->assertContains('placeholder_fields_present', data_get($payload, 'real_provider_smoke.persist_readiness.blockers'));
    }

    public function test_runtime_enabling_flag_blocks_persist_readiness(): void
    {
        $smoke = $this->realProviderSmoke(['self_programming_allowed' => true]);

        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'real_provider_smoke' => $smoke,
        ]);

        $this->assertFalse($payload['aggregate_all_evidence_ready']);
        $this->assertContains('runtime_enabling_flags_true', data_get($payload, 'real_provider_smoke.persist_readiness.blockers'));
        $this->assertFalse($payload['self_programming_allowed']);
    }

    /** @param array<string, mixed> $overrides */
    private function runtimePromotionReceipt(array $overrides = []): array
    {
        $receipt = array_merge([
            'receipt_id' => 'runtime-promotion-receipt-001',
            'signed_by' => 'Vitorepf Runtime Operator',
            'reason' => 'Reviewed runtime graduation candidates and approved runtime gap promotion.',
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_basis_hash' => str_repeat('b', 64),
            'promoted_gap_ids' => ['adapter_execution_runtime'],
            'graduation_evidence_hashes' => ['adapter_execution_runtime' => str_repeat('c', 64)],
            'receipt_hash' => '',
            'runtime_promotion_approved' => true,
            'operator_reviewed_runtime_graduations' => true,
            'no_runtime_autopromotion_acknowledged' => true,
        ], $overrides);
        if (($overrides['receipt_hash'] ?? '') === '') {
            $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->runtimePromotionReceiptHash($receipt);
        }

        return $receipt;
    }

    /** @param array<string, mixed> $overrides */
    private function humanCompletionReceipt(array $overrides = []): array
    {
        $receipt = array_merge([
            'receipt_id' => 'operator-os-complete-001',
            'signed_by' => 'Vitorepf Runtime Operator',
            'reason' => 'Reviewed the current completion audit and approved the OS-complete receipt after evidence verification.',
            'completion_audit_hash' => str_repeat('d', 64),
            'release_dossier_hash' => str_repeat('e', 64),
            'replay_diff_hash' => str_repeat('f', 64),
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'certification_status_batch_hash' => str_repeat('1', 64),
            'receipt_hash' => '',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ], $overrides);
        if (($overrides['receipt_hash'] ?? '') === '') {
            $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        }

        return $receipt;
    }

    /** @param array<string, mixed> $overrides */
    private function realProviderSmoke(array $overrides = []): array
    {
        $smoke = array_merge([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-001',
            'task_packet_id' => 'task-packet-001',
            'observed_by' => 'Vitorepf Runtime Operator',
            'approval_reason' => 'Operator approved a bounded real provider claim-to-completion smoke and verified generated evidence.',
            'smoke_hash' => '',
            'operator_approval_receipt_hash' => str_repeat('2', 64),
            'evidence_ledger_hash' => str_repeat('3', 64),
            'work_product_manifest_hash' => str_repeat('4', 64),
            'cost_event_hash' => str_repeat('5', 64),
            'continuation_summary_hash' => str_repeat('6', 64),
            'provider_response_hash' => str_repeat('7', 64),
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ], $overrides);
        if (($overrides['smoke_hash'] ?? '') === '') {
            $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);
        }

        return $smoke;
    }
}
