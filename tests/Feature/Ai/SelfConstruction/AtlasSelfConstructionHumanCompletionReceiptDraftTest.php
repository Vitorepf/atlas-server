<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptDraftService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptDraftTest extends TestCase
{
    public function test_receipt_draft_blocks_without_operator_identity_and_final_evidence(): void
    {
        $result = (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build($this->completionAudit(), $this->completionEvidence([
            'all_runtime_y' => false,
            'runtime_promotion_status' => 'blocked',
            'real_provider_smoke_status' => 'blocked_missing_real_provider_smoke',
        ]));

        $this->assertSame('blocked_operator_or_evidence_input_required', $result['status']);
        $this->assertContains('signed_by', $result['missing_operator_inputs']);
        $this->assertContains('reason', $result['missing_operator_inputs']);
        $this->assertContains('runtime_gap_matrix_all_runtime_y', $result['failed_prerequisites']);
        $this->assertContains('runtime_promotion_receipt_present', $result['failed_prerequisites']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $result['failed_prerequisites']);
        $this->assertFalse((bool) $result['completion_claim_allowed']);
    }

    public function test_receipt_draft_rejects_placeholder_operator_identity(): void
    {
        $result = (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build($this->completionAudit(), $this->completionEvidence(), [
            'signed_by' => 'codex',
            'reason' => 'Operator reviewed all completion evidence and approves the OS completion receipt.',
        ]);

        $this->assertSame('blocked_operator_or_evidence_input_required', $result['status']);
        $this->assertContains('signed_by', $result['missing_operator_inputs']);
        $this->assertContains('human_completion_receipt_signer_invalid_or_placeholder', array_column((array) data_get($result, 'verification.violations', []), 'code'));
    }

    public function test_receipt_draft_rejects_portuguese_operator_placeholders(): void
    {
        $result = (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build($this->completionAudit(), $this->completionEvidence(), [
            'signed_by' => 'SEU_NOME',
            'reason' => 'MOTIVO REAL COM PELO MENOS 32 CARACTERES',
            'persist_completion_evidence' => true,
        ]);

        $this->assertSame('blocked_operator_or_evidence_input_required', $result['status']);
        $this->assertSame('human_completion_receipt_draft_not_ready_for_persistence', $result['persistence_blocker']);
        $this->assertContains('signed_by', $result['missing_operator_inputs']);
        $this->assertContains('reason', $result['missing_operator_inputs']);
        $this->assertContains('human_completion_receipt_signer_invalid_or_placeholder', array_column((array) data_get($result, 'verification.violations', []), 'code'));
        $this->assertContains('human_completion_receipt_reason_placeholder', array_column((array) data_get($result, 'verification.violations', []), 'code'));
        $this->assertFalse((bool) $result['persisted']);
        $this->assertFalse((bool) $result['completion_claim_allowed']);
        $this->assertFalse((bool) $result['self_programming_allowed']);
    }

    public function test_receipt_draft_builds_verifier_ready_payload_with_real_operator_inputs(): void
    {
        $result = (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build($this->completionAudit(), $this->completionEvidence(), [
            'signed_by' => 'Vitore Operator',
            'reason' => 'Operator reviewed the final audit, runtime promotion receipt, and real provider smoke evidence.',
        ]);

        $this->assertSame('ready_for_operator_persistence', $result['status']);
        $this->assertSame('passed', data_get($result, 'verification.status'));
        $this->assertSame([], $result['missing_operator_inputs']);
        $this->assertSame([], $result['missing_evidence_hashes']);
        $this->assertSame([], $result['failed_prerequisites']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $result['receipt_hash']);
        $this->assertFalse((bool) $result['execution_allowed']);
    }

    public function test_receipt_draft_persists_only_when_ready_and_requested(): void
    {
        Storage::fake('local');

        $blocked = (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build($this->completionAudit(), $this->completionEvidence(), [
            'signed_by' => 'codex',
            'reason' => 'Operator reviewed the final audit, runtime promotion receipt, and real provider smoke evidence.',
            'persist_completion_evidence' => true,
        ]);

        $this->assertFalse((bool) $blocked['persisted']);
        $this->assertSame('human_completion_receipt_draft_not_ready_for_persistence', $blocked['persistence_blocker']);
        Storage::disk('local')->assertMissing('atlas/self-construction/os-completion/human-signed-receipts/registry.json');

        $persisted = (new AtlasSelfConstructionHumanCompletionReceiptDraftService)->build($this->completionAudit(), $this->completionEvidence(), [
            'signed_by' => 'Vitore Operator',
            'reason' => 'Operator reviewed the final audit, runtime promotion receipt, and real provider smoke evidence.',
            'persist_completion_evidence' => true,
        ]);

        $this->assertSame('persisted', $persisted['status']);
        $this->assertTrue((bool) $persisted['persisted']);
        Storage::disk('local')->assertExists((string) $persisted['receipt_path']);
    }

    public function test_command_exposes_status_and_quartet(): void
    {
        $payload = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-human-completion-receipt-draft-status' => true,
            '--json' => true,
        ]);

        $this->assertSame(0, $payload);
        $decoded = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $summary = (array) data_get($decoded, 'agent_control_plane_atlas_self_construction_human_completion_receipt_draft_status', []);

        $this->assertSame('runtime_promotion_receipt', $summary['current_required_operator_artifact']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $summary['next_required_command']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $summary['next_required_persist_command']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['runtime_promotion_closure_basis_hash']);
        $this->assertFalse((bool) $summary['execution_allowed']);
        $this->assertFalse((bool) $summary['dispatch_allowed']);
        $this->assertFalse((bool) $summary['provider_call_allowed']);
        $this->assertFalse((bool) $summary['token_spend_allowed']);
        $this->assertFalse((bool) $summary['adapter_execution_allowed']);
        $this->assertFalse((bool) $summary['completion_allowed']);
        $this->assertFalse((bool) $summary['completion_claim_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);

        foreach ([
            '--atlas-self-construction-human-completion-receipt-draft-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_draft_contract.v1',
            '--atlas-self-construction-human-completion-receipt-draft-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_draft_preflight.v1',
            '--atlas-self-construction-human-completion-receipt-draft-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_draft_implementation_packet.v1',
        ] as $option => $schema) {
            $this->artisan('atlas:ai:self-construction', [$option => true, '--json' => true])
                ->assertExitCode(0)
                ->expectsOutputToContain($schema);
        }
    }

    public function test_command_threads_operator_inputs_without_persisting(): void
    {
        $runtimeDraft = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionRuntimePromotionReceiptDraftStatus([
                'signed_by' => 'Vitore Operator',
                'reason' => 'Operator reviewed runtime graduation evidence and approves runtime promotion without enabling execution.',
            ]);
        $runtimeReceipt = (array) data_get($runtimeDraft, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_draft.receipt_payload', []);
        $smoke = $this->realProviderSmoke();

        $this->artisan('atlas:ai:self-construction', [
            '--atlas-self-construction-human-completion-receipt-draft-status' => true,
            '--runtime-promotion-receipt-json' => json_encode($runtimeReceipt, JSON_THROW_ON_ERROR),
            '--real-provider-smoke-json' => json_encode($smoke, JSON_THROW_ON_ERROR),
            '--signed-by' => 'Vitore Operator',
            '--reason' => 'Operator reviewed the final audit, runtime promotion receipt, and real provider smoke evidence.',
            '--json' => true,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Vitore Operator');
    }

    /** @param array<string, mixed> $overrides */
    private function completionEvidence(array $overrides = []): array
    {
        $hash = str_repeat('a', 64);

        return [
            'status' => 'blocked',
            'runtime_gap_matrix' => [
                'all_runtime_y' => (bool) ($overrides['all_runtime_y'] ?? true),
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_receipt' => [
                    'status' => (string) ($overrides['runtime_promotion_status'] ?? 'passed'),
                    'receipt_hash' => $hash,
                ],
            ],
            'real_provider_smoke' => [
                'status' => (string) ($overrides['real_provider_smoke_status'] ?? 'passed'),
                'smoke_hash' => $hash,
            ],
            'operator_action_packet' => [
                'human_completion_receipt_template' => [
                    'certification_status_batch_hash' => $hash,
                ],
            ],
        ];
    }

    private function completionAudit(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'status' => 'incomplete',
            'completion_audit_hash' => $hash,
            'criteria' => [
                ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => true, 'evidence' => ['runtime_gap_matrix_hash' => $hash]],
                ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
                ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
                ['id' => 'promotion_gate_green', 'passed' => true, 'evidence' => []],
                ['id' => 'mutation_guard_green', 'passed' => true, 'evidence' => []],
                ['id' => 'human_signed_os_complete_receipt_present', 'passed' => false, 'evidence' => []],
                ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => true, 'evidence' => ['smoke_hash' => $hash]],
                ['id' => 'forge_self_improvement_integration_smoke_green', 'passed' => true, 'evidence' => []],
                ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function realProviderSmoke(): array
    {
        $hash = str_repeat('b', 64);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-001',
            'task_packet_id' => 'task-packet-001',
            'observed_by' => 'Vitore Operator',
            'approval_reason' => 'Operator approved a bounded real provider smoke and verified the resulting evidence.',
            'smoke_hash' => '',
            'operator_approval_receipt_hash' => $hash,
            'evidence_ledger_hash' => $hash,
            'work_product_manifest_hash' => $hash,
            'cost_event_hash' => $hash,
            'continuation_summary_hash' => $hash,
            'provider_response_hash' => $hash,
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
            'provider_called_by_atlas' => false,
            'token_spent_by_atlas' => false,
            'dispatch_allowed' => false,
            'adapter_execution_allowed' => false,
            'self_programming_allowed' => false,
            'completion_claim_promoted_without_receipt' => false,
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);

        return $smoke;
    }
}
