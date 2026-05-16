<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashComposerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceHashComposerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
    }

    public function test_composer_computes_canonical_hashes_without_persisting_or_promoting(): void
    {
        $runtime = $this->runtimePromotionReceipt();
        $human = $this->humanCompletionReceipt();
        $smoke = $this->realProviderSmoke();
        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'runtime_promotion_receipt' => $runtime,
            'completion_receipt' => $human,
            'real_provider_smoke' => $smoke,
        ]);

        $hashes = new AtlasSelfConstructionCompletionEvidenceHashService;

        $this->assertSame('atlas.self_construction.completion_evidence_hash_composer.v1', $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame($hashes->runtimePromotionReceiptHash($runtime), data_get($payload, 'runtime_promotion_receipt.computed_hash'));
        $this->assertSame($hashes->humanCompletionReceiptHash($human), data_get($payload, 'human_completion_receipt.computed_hash'));
        $this->assertSame($hashes->realProviderSmokeHash($smoke), data_get($payload, 'real_provider_smoke.computed_hash'));
        $this->assertFalse($payload['ledger_write_allowed']);
        $this->assertFalse($payload['runtime_write_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertArrayNotHasKey('persist_verified_evidence', $payload['commands_after_composition']);
        $this->assertArrayHasKey('persist_runtime_promotion_receipt', $payload['commands_after_composition']);
        $this->assertArrayHasKey('persist_real_provider_smoke', $payload['commands_after_composition']);
        $this->assertArrayHasKey('persist_human_completion_receipt', $payload['commands_after_composition']);
        $this->assertStringNotContainsString('--completion-receipt-json', $payload['commands_after_composition']['persist_real_provider_smoke']);
        $this->assertStringNotContainsString('--real-provider-smoke-json', $payload['commands_after_composition']['persist_human_completion_receipt']);
        $this->assertFalse(Storage::disk('local')->exists('atlas/self-construction/os-completion/human-signed-receipts/registry.json'));
    }

    public function test_composer_reports_existing_hash_matches_and_payload_with_computed_hash(): void
    {
        $human = $this->humanCompletionReceipt();
        $human['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($human);
        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'completion_receipt' => $human,
        ]);

        $this->assertTrue(data_get($payload, 'human_completion_receipt.input_hash_matches_computed_hash'));
        $this->assertSame($human['receipt_hash'], data_get($payload, 'human_completion_receipt.payload_with_computed_hash.receipt_hash'));
    }

    public function test_composer_flags_placeholders_and_runtime_enabling_flags(): void
    {
        $smoke = $this->realProviderSmoke([
            'provider_run_id' => '<provider_run_id>',
            'self_programming_allowed' => true,
        ]);
        $payload = (new AtlasSelfConstructionCompletionEvidenceHashComposerService)->compose([
            'real_provider_smoke' => $smoke,
        ]);

        $this->assertContains('provider_run_id', data_get($payload, 'real_provider_smoke.placeholder_fields'));
        $this->assertContains('self_programming_allowed', data_get($payload, 'real_provider_smoke.runtime_enabling_flags_true'));
        $this->assertFalse($payload['provider_call_allowed']);
        $this->assertFalse($payload['token_spend_allowed']);
    }

    public function test_readiness_status_and_cli_contract_are_exposed(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionCompletionEvidenceHashComposerStatus([
            'completion_receipt' => $this->humanCompletionReceipt(),
        ]);

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_completion_evidence_hash_composer_status.v1', $status['schema_version']);
        $this->assertSame('available', $status['status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_hash_composer_status.human_completion_receipt_hash'));
        $this->assertFalse($status['execution_allowed']);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-completion-evidence-hash-composer-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_completion_evidence_hash_composer_contract.v1', $payload['schema_version']);
        $this->assertFalse($payload['dispatch_allowed']);
    }

    public function test_agent_control_plane_lists_hash_composer_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_construction_completion_evidence_hash_composer_contract', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_hash_composer_preflight', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_hash_composer_implementation_packet', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_hash_composer_service', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_hash_composer_status_projection', $capabilities);
    }

    /** @param array<string, mixed> $overrides */
    private function runtimePromotionReceipt(array $overrides = []): array
    {
        return array_merge([
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
    }

    /** @param array<string, mixed> $overrides */
    private function humanCompletionReceipt(array $overrides = []): array
    {
        return array_merge([
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
    }

    /** @param array<string, mixed> $overrides */
    private function realProviderSmoke(array $overrides = []): array
    {
        return array_merge([
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
    }
}
