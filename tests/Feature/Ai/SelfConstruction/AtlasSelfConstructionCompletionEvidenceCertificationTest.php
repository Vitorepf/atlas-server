<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanSignedCompletionReceiptService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeCertificationService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceCertificationTest extends TestCase
{
    public function test_human_signed_completion_receipt_blocks_without_operator_receipt(): void
    {
        $result = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->verify();

        $this->assertSame('atlas.self_construction.human_signed_completion_receipt.v1', $result['schema_version']);
        $this->assertSame('blocked_missing_operator_receipt', $result['status']);
        $this->assertFalse($result['completion_claim_allowed']);
        $this->assertFalse($result['execution_allowed']);
        $this->assertGreaterThan(0, $result['violation_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['receipt_verification_hash']);
    }

    public function test_human_signed_completion_receipt_accepts_explicit_operator_receipt_shape(): void
    {
        $hash = str_repeat('a', 64);
        $receipt = [
            'receipt_id' => 'os-complete-receipt-001',
            'signed_by' => 'operator',
            'reason' => 'Reviewed current completion audit evidence.',
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
            'receipt_hash' => $hash,
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        $result = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->verify($receipt);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['completion_claim_allowed']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue($result['receipt_hash_matches_payload']);
    }

    public function test_human_signed_completion_receipt_can_be_persisted_and_loaded_as_latest(): void
    {
        Storage::fake('local');
        $hash = str_repeat('c', 64);
        $service = new AtlasSelfConstructionHumanSignedCompletionReceiptService;

        $receipt = [
            'receipt_id' => 'os-complete-receipt-002',
            'signed_by' => 'operator',
            'reason' => 'Reviewed current completion audit evidence.',
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
            'receipt_hash' => $hash,
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        $persisted = $service->persist($receipt);
        $latest = $service->verify();

        $this->assertTrue($persisted['persisted']);
        $this->assertSame('passed', $latest['status']);
        $this->assertSame('os-complete-receipt-002', $latest['receipt_id']);
    }

    public function test_human_signed_completion_receipt_rejects_hash_that_does_not_match_payload(): void
    {
        $hash = str_repeat('a', 64);
        $result = (new AtlasSelfConstructionHumanSignedCompletionReceiptService)->verify([
            'receipt_id' => 'os-complete-receipt-mismatch',
            'signed_by' => 'operator',
            'reason' => 'Reviewed current completion audit evidence.',
            'completion_audit_hash' => $hash,
            'release_dossier_hash' => $hash,
            'replay_diff_hash' => $hash,
            'runtime_gap_matrix_hash' => $hash,
            'runtime_promotion_receipt_hash' => $hash,
            'real_provider_smoke_hash' => $hash,
            'certification_status_batch_hash' => $hash,
            'receipt_hash' => str_repeat('1', 64),
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ]);

        $this->assertSame('blocked_missing_operator_receipt', $result['status']);
        $this->assertFalse($result['receipt_hash_matches_payload']);
        $this->assertContains('receipt_hash_mismatch', array_column($result['violations'], 'code'));
    }

    public function test_real_provider_smoke_blocks_without_real_smoke_receipts(): void
    {
        $result = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify();

        $this->assertSame('atlas.self_construction.real_provider_smoke_certification.v1', $result['schema_version']);
        $this->assertSame('blocked_missing_real_provider_smoke', $result['status']);
        $this->assertFalse($result['completion_criterion_green']);
        $this->assertFalse($result['self_programming_allowed']);
        $this->assertGreaterThan(0, $result['violation_count']);
    }

    public function test_real_provider_smoke_accepts_explicit_observed_smoke_receipt_shape(): void
    {
        $hash = str_repeat('b', 64);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-001',
            'task_packet_id' => 'task-packet-smoke-001',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator approved real provider smoke.',
            'smoke_hash' => $hash,
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
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);
        $result = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($smoke);

        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['completion_criterion_green']);
        $this->assertSame(0, $result['violation_count']);
        $this->assertTrue($result['smoke_hash_matches_payload']);
    }

    public function test_real_provider_smoke_rejects_missing_operator_observation_acknowledgements(): void
    {
        $hash = str_repeat('b', 64);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-missing-ack',
            'task_packet_id' => 'task-packet-smoke-missing-ack',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator approved real provider smoke.',
            'smoke_hash' => $hash,
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
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);
        $result = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify($smoke);

        $this->assertSame('blocked_missing_real_provider_smoke', $result['status']);
        $this->assertContains('required_operator_real_smoke_ack_missing', array_column($result['violations'], 'code'));
    }

    public function test_real_provider_smoke_can_be_persisted_and_loaded_as_latest(): void
    {
        Storage::fake('local');
        $hash = str_repeat('d', 64);
        $service = new AtlasSelfConstructionRealProviderSmokeCertificationService;

        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-002',
            'task_packet_id' => 'task-packet-smoke-002',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator approved real provider smoke.',
            'smoke_hash' => $hash,
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
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);
        $persisted = $service->persist($smoke);
        $latest = $service->certify();

        $this->assertTrue($persisted['persisted']);
        $this->assertSame('passed', $latest['status']);
        $this->assertSame($smoke['smoke_hash'], $latest['smoke_hash']);
    }

    public function test_real_provider_smoke_rejects_smoke_hash_that_does_not_match_payload(): void
    {
        $hash = str_repeat('b', 64);
        $result = (new AtlasSelfConstructionRealProviderSmokeCertificationService)->certify([
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-mismatch',
            'task_packet_id' => 'task-packet-smoke-mismatch',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator approved real provider smoke.',
            'smoke_hash' => str_repeat('2', 64),
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
        ]);

        $this->assertSame('blocked_missing_real_provider_smoke', $result['status']);
        $this->assertFalse($result['smoke_hash_matches_payload']);
        $this->assertContains('smoke_hash_mismatch', array_column($result['violations'], 'code'));
    }

    public function test_forge_self_improvement_integration_smoke_is_green_without_creating_obra(): void
    {
        $result = (new AtlasSelfConstructionForgeSelfImprovementIntegrationSmokeService)->certify();

        $this->assertSame('atlas.self_construction.forge_self_improvement_integration_smoke.v1', $result['schema_version']);
        $this->assertSame('forge_self_improvement_control_plane_integration', $result['kind']);
        $this->assertSame('passed', $result['status']);
        $this->assertTrue($result['invariants_all_true']);
        $this->assertNull($result['created_obra_id']);
        $this->assertFalse($result['external_provider_call']);
        $this->assertFalse($result['provider_tokens_spent']);
        $this->assertFalse($result['auto_fast_path_executed']);
        $this->assertFalse($result['completion_claim_promoted']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $result['smoke_hash']);
    }

    public function test_completion_evidence_status_command_reports_missing_final_artifacts(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_completion_evidence_status.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertContains('human_signed_os_complete_receipt_present', $payload['failed_checks']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $payload['failed_checks']);
        $this->assertSame('atlas.self_construction.completion_operator_action_packet.v1', data_get($payload, 'operator_action_packet.schema_version'));
        $this->assertSame('operator_action_required', data_get($payload, 'operator_action_packet.status'));
        $this->assertContains('runtime_promotion_receipt', data_get($payload, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertContains('human_signed_os_complete_receipt', data_get($payload, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertContains('real_provider_claim_to_completion_smoke', data_get($payload, 'operator_action_packet.missing_operator_artifacts'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.template_hashes.runtime_promotion_receipt_template_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.runtime_promotion_receipt_template.runtime_promotion_basis_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.runtime_promotion_receipt_template.runtime_promotion_closure_basis_hash'));
        $this->assertSame('atlas.self_construction.runtime_promotion_receipt_runbook.v1', data_get($payload, 'operator_action_packet.runtime_promotion_receipt_runbook.schema_version'));
        $this->assertSame('operator_runtime_promotion_receipt_required', data_get($payload, 'operator_action_packet.runtime_promotion_receipt_runbook.status'));
        $this->assertContains('runtime_promotion_basis_hash', data_get($payload, 'operator_action_packet.runtime_promotion_receipt_runbook.required_evidence_fields'));
        $this->assertContains('runtime_promotion_closure_basis_hash', data_get($payload, 'operator_action_packet.runtime_promotion_receipt_runbook.required_evidence_fields'));
        $this->assertContains('runtime_promotion_receipt_runbook_does_not_enable_runtime', data_get($payload, 'operator_action_packet.runtime_promotion_receipt_runbook.non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.runtime_promotion_receipt_runbook.runbook_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.template_hashes.human_completion_receipt_template_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.human_completion_receipt_template.certification_status_batch_hash'));
        $this->assertSame('<64_hex_runtime_promotion_receipt_hash_after_persistence>', data_get($payload, 'operator_action_packet.human_completion_receipt_template.runtime_promotion_receipt_hash'));
        $this->assertSame('<64_hex_real_provider_smoke_hash_after_persistence>', data_get($payload, 'operator_action_packet.human_completion_receipt_template.real_provider_smoke_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.template_hashes.real_provider_smoke_template_hash'));
        $this->assertSame('atlas.self_construction.human_completion_receipt_runbook.v1', data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.schema_version'));
        $this->assertSame('operator_human_completion_receipt_required', data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.status'));
        $this->assertContains('certification_status_batch_hash', data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.required_evidence_fields'));
        $this->assertContains('runtime_promotion_receipt_hash', data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.required_evidence_fields'));
        $this->assertContains('real_provider_smoke_hash', data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.required_evidence_fields'));
        $this->assertContains('human_completion_receipt_runbook_does_not_sign_for_operator', data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.human_completion_receipt_runbook.runbook_hash'));
        $this->assertSame('atlas.self_construction.real_provider_smoke_runbook.v1', data_get($payload, 'operator_action_packet.real_provider_smoke_runbook.schema_version'));
        $this->assertSame('operator_real_provider_smoke_required', data_get($payload, 'operator_action_packet.real_provider_smoke_runbook.status'));
        $this->assertContains('provider_run_id', data_get($payload, 'operator_action_packet.real_provider_smoke_runbook.required_evidence_fields'));
        $this->assertContains('real_provider_smoke_runbook_does_not_call_provider', data_get($payload, 'operator_action_packet.real_provider_smoke_runbook.non_execution_guarantees'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.real_provider_smoke_runbook.runbook_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_action_packet.operator_action_packet_hash'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['completion_evidence_status_hash']);
    }

    public function test_completion_evidence_status_command_does_not_persist_human_receipt_before_runtime_promotion(): void
    {
        Storage::fake('local');
        $smokeHash = str_repeat('f', 64);

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--json' => true,
        ]);
        $templatePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $receipt = (array) data_get($templatePayload, 'operator_action_packet.human_completion_receipt_template', []);

        $receipt = array_merge($receipt, [
            'receipt_id' => 'os-complete-receipt-cli',
            'signed_by' => 'Vitorepf Completion Operator',
            'reason' => 'CLI supplied completion evidence after reviewing current hashes.',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ]);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-cli',
            'task_packet_id' => 'task-packet-smoke-cli',
            'observed_by' => 'operator',
            'approval_reason' => 'CLI supplied real provider smoke evidence.',
            'smoke_hash' => $smokeHash,
            'operator_approval_receipt_hash' => $smokeHash,
            'evidence_ledger_hash' => $smokeHash,
            'work_product_manifest_hash' => $smokeHash,
            'cost_event_hash' => $smokeHash,
            'continuation_summary_hash' => $smokeHash,
            'provider_response_hash' => $smokeHash,
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--completion-receipt-json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            '--real-provider-smoke-json' => json_encode($smoke, JSON_THROW_ON_ERROR),
            '--persist-completion-evidence' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['persist_completion_evidence_requested']);
        $this->assertFalse(data_get($payload, 'human_signed_completion_receipt.persisted'));
        $this->assertTrue(data_get($payload, 'real_provider_smoke.persisted'));
        $this->assertSame('blocked', data_get($payload, 'human_signed_completion_receipt.status'));
        $this->assertSame('human_completion_receipt_prerequisites_not_green', data_get($payload, 'human_signed_completion_receipt.persistence_blocker'));
        $this->assertContains('runtime_promotion_receipt_present', data_get($payload, 'human_signed_completion_receipt.missing_persistence_prerequisites'));
        $this->assertSame('passed', data_get($payload, 'real_provider_smoke.status'));
    }

    public function test_completion_evidence_status_requires_real_provider_smoke_to_be_persisted_before_human_receipt_command(): void
    {
        Storage::fake('local');
        $readiness = app(AtlasSelfConstructionReadinessService::class);

        $hash = str_repeat('f', 64);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-same-command',
            'task_packet_id' => 'task-packet-smoke-same-command',
            'observed_by' => 'operator',
            'approval_reason' => 'Operator supplied real provider smoke evidence before human receipt persistence.',
            'smoke_hash' => $hash,
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
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);

        $contextPayload = $readiness->atlasSelfConstructionOsCompletionEvidenceStatus();
        $receipt = array_merge((array) data_get($contextPayload, 'operator_action_packet.human_completion_receipt_template', []), [
            'receipt_id' => 'os-complete-receipt-same-command-blocked',
            'signed_by' => 'Vitorepf Completion Operator',
            'reason' => 'Operator reviewed final completion evidence after runtime promotion and real provider smoke.',
            'completion_audit_hash' => str_repeat('a', 64),
            'runtime_promotion_receipt_hash' => str_repeat('b', 64),
            'real_provider_smoke_hash' => $smoke['smoke_hash'],
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ]);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $payload = $readiness->atlasSelfConstructionOsCompletionEvidenceStatus([
            'real_provider_smoke' => $smoke,
            'completion_receipt' => $receipt,
            'persist_completion_evidence' => true,
        ]);

        $this->assertTrue(data_get($payload, 'real_provider_smoke.persisted'));
        $this->assertFalse(data_get($payload, 'human_signed_completion_receipt.persisted'));
        $this->assertSame('human_completion_receipt_prerequisites_not_green', data_get($payload, 'human_signed_completion_receipt.persistence_blocker'));
        $this->assertContains(
            'real_provider_smoke_persisted_before_human_receipt_command',
            data_get($payload, 'human_signed_completion_receipt.missing_persistence_prerequisites'),
        );
    }

    public function test_completion_evidence_status_loads_canonical_submissions_only_when_explicit_persist_flag_is_supplied(): void
    {
        Storage::fake('local');
        $smokeHash = str_repeat('f', 64);

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--json' => true,
        ]);
        $templatePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $receipt = array_merge((array) data_get($templatePayload, 'operator_action_packet.human_completion_receipt_template', []), [
            'receipt_id' => 'os-complete-receipt-canonical-submission',
            'signed_by' => 'Vitorepf Completion Operator',
            'reason' => 'Canonical submission receipt loaded only when explicit persistence is requested.',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ]);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        $smoke = [
            'kind' => 'real_provider_packet_claim_to_completion',
            'status' => 'passed',
            'provider_run_id' => 'provider-run-smoke-canonical',
            'task_packet_id' => 'task-packet-smoke-canonical',
            'observed_by' => 'operator',
            'approval_reason' => 'Canonical submission real provider smoke evidence.',
            'smoke_hash' => $smokeHash,
            'operator_approval_receipt_hash' => $smokeHash,
            'evidence_ledger_hash' => $smokeHash,
            'work_product_manifest_hash' => $smokeHash,
            'cost_event_hash' => $smokeHash,
            'continuation_summary_hash' => $smokeHash,
            'provider_response_hash' => $smokeHash,
            'provider_call_observed' => true,
            'token_spend_observed' => true,
            'claim_to_completion_observed' => true,
            'work_product_collected' => true,
            'operator_supplied_evidence' => true,
            'real_provider_run_observed_by_operator' => true,
        ];
        $smoke['smoke_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->realProviderSmokeHash($smoke);

        Storage::disk('local')->put('atlas/self-construction/operator-submissions/completion-receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/real-provider-smoke.json', json_encode($smoke, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--json' => true,
        ]);
        $withoutFlag = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame('canonical_submission_not_loaded_without_explicit_persist_flag', data_get($withoutFlag, 'operator_submission_input.real_provider_smoke.status'));
        $this->assertSame('canonical_submission_not_loaded_without_explicit_persist_flag', data_get($withoutFlag, 'operator_submission_input.completion_receipt.status'));
        $this->assertFalse(data_get($withoutFlag, 'operator_submission_input.real_provider_smoke.payload_present'));
        $this->assertFalse(data_get($withoutFlag, 'operator_submission_input.completion_receipt.payload_present'));

        Storage::disk('local')->put('atlas/self-construction/operator-submissions/completion-receipt.json', json_encode($receipt, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
        Storage::disk('local')->put('atlas/self-construction/operator-submissions/real-provider-smoke.json', json_encode($smoke, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--persist-completion-evidence' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertTrue($payload['persist_completion_evidence_requested']);
        $this->assertSame('canonical_submission', data_get($payload, 'operator_submission_input.real_provider_smoke.source'));
        $this->assertSame('canonical_submission', data_get($payload, 'operator_submission_input.completion_receipt.source'));
        $this->assertTrue(data_get($payload, 'operator_submission_input.real_provider_smoke.canonical_loaded'));
        $this->assertTrue(data_get($payload, 'operator_submission_input.completion_receipt.canonical_loaded'));
        $this->assertTrue(data_get($payload, 'real_provider_smoke.persisted'));
        $this->assertFalse(data_get($payload, 'human_signed_completion_receipt.persisted'));
        $this->assertSame('human_completion_receipt_prerequisites_not_green', data_get($payload, 'human_signed_completion_receipt.persistence_blocker'));
        $this->assertContains('runtime_promotion_receipt_present', data_get($payload, 'human_signed_completion_receipt.missing_persistence_prerequisites'));
    }

    public function test_completion_evidence_status_command_rejects_fake_human_completion_signer(): void
    {
        Storage::fake('local');

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--json' => true,
        ]);
        $templatePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $receipt = array_merge((array) data_get($templatePayload, 'operator_action_packet.human_completion_receipt_template', []), [
            'receipt_id' => 'os-complete-receipt-fake-signer',
            'signed_by' => 'codex',
            'reason' => 'Fake signer should never satisfy the human completion receipt.',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ]);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--completion-receipt-json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            '--persist-completion-evidence' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertFalse(data_get($payload, 'human_signed_completion_receipt.persisted'));
        $this->assertSame('blocked', data_get($payload, 'human_signed_completion_receipt.status'));
        $this->assertContains('human_completion_receipt_signer_invalid_or_placeholder', array_column((array) data_get($payload, 'human_signed_completion_receipt.violations', []), 'code'));
    }

    public function test_completion_evidence_status_command_rejects_stale_material_hashes_in_human_receipt(): void
    {
        Storage::fake('local');

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--json' => true,
        ]);
        $templatePayload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $receipt = array_merge((array) data_get($templatePayload, 'operator_action_packet.human_completion_receipt_template', []), [
            'receipt_id' => 'os-complete-receipt-stale-material-hash',
            'signed_by' => 'Vitorepf Completion Operator',
            'reason' => 'This receipt intentionally references stale material evidence and must be rejected.',
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ]);

        $currentReleaseHash = (string) data_get($templatePayload, 'operator_action_packet.human_completion_receipt_template.release_dossier_hash');
        $receipt['release_dossier_hash'] = $currentReleaseHash === str_repeat('9', 64)
            ? str_repeat('8', 64)
            : str_repeat('9', 64);
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-evidence-status' => true,
            '--completion-receipt-json' => json_encode($receipt, JSON_THROW_ON_ERROR),
            '--persist-completion-evidence' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $violations = (array) data_get($payload, 'human_signed_completion_receipt.violations', []);

        $this->assertSame(0, $exit);
        $this->assertFalse(data_get($payload, 'human_signed_completion_receipt.persisted'));
        $this->assertSame('blocked', data_get($payload, 'human_signed_completion_receipt.status'));
        $this->assertContains('context_hash_mismatch', array_column($violations, 'code'));
        $this->assertContains('release_dossier_hash', array_column($violations, 'field'));
    }

    public function test_completion_operator_action_packet_command_exposes_direct_status(): void
    {
        Storage::fake('local');

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-os-completion-operator-action-packet-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_status.v1', $payload['schema_version']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertSame('operator_action_required', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_status.status'));
        $this->assertContains('runtime_promotion_receipt', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_status.missing_operator_artifacts'));
        $this->assertArrayHasKey('draft_runtime_promotion_receipt', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('draft_human_completion_receipt', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('prepare_real_provider_smoke_offline_harness', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('draft_real_provider_smoke', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('compose_completion_evidence_hashes', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('persist_real_provider_smoke', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('persist_human_completion_receipt', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('refresh_terminal_loop_operational_proof', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayHasKey('run_completion_audit_with_terminal_loop_operational_proof', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertArrayNotHasKey('persist_completion_evidence', data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands'));
        $this->assertStringNotContainsString(
            '--completion-receipt-json',
            (string) data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands.persist_real_provider_smoke'),
        );
        $this->assertStringNotContainsString(
            '--real-provider-smoke-json',
            (string) data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.commands.persist_human_completion_receipt'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.template_hashes.runtime_promotion_receipt_template_hash'));
        $this->assertTrue((bool) data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.terminal_loop_operational_proof_required_before_final_audit'));
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet.terminal_loop_operational_proof_expected_binding_schema'),
        );
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_status.operator_action_packet_hash'));
    }

    public function test_completion_operator_action_packet_command_exposes_quartet(): void
    {
        foreach ([
            '--atlas-self-construction-os-completion-operator-action-packet-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_contract.v1',
            '--atlas-self-construction-os-completion-operator-action-packet-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_preflight.v1',
            '--atlas-self-construction-os-completion-operator-action-packet-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_os_completion_operator_action_packet_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }
    }

    public function test_human_completion_receipt_runbook_command_exposes_direct_status_and_quartet(): void
    {
        Storage::fake('local');

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-human-completion-receipt-runbook-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_status.v1', $payload['schema_version']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertSame('operator_human_completion_receipt_required', data_get($payload, 'agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_status.status'));
        $this->assertSame(11, data_get($payload, 'agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_status.required_evidence_field_count'));
        $this->assertSame(3, data_get($payload, 'agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_status.required_acknowledgement_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_status.runbook_hash'));

        foreach ([
            '--atlas-self-construction-human-completion-receipt-runbook-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_contract.v1',
            '--atlas-self-construction-human-completion-receipt-runbook-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_preflight.v1',
            '--atlas-self-construction-human-completion-receipt-runbook-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_human_completion_receipt_runbook_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }
    }

    public function test_runtime_promotion_receipt_runbook_command_exposes_direct_status_and_quartet(): void
    {
        Storage::fake('local');

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-runtime-promotion-receipt-runbook-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_status.v1', $payload['schema_version']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertSame('operator_runtime_promotion_receipt_required', data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_status.status'));
        $this->assertSame(9, data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_status.required_evidence_field_count'));
        $this->assertSame(3, data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_status.required_acknowledgement_count'));
        $this->assertSame(6, data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_status.forbidden_flag_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_status.runbook_hash'));

        foreach ([
            '--atlas-self-construction-runtime-promotion-receipt-runbook-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_contract.v1',
            '--atlas-self-construction-runtime-promotion-receipt-runbook-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_preflight.v1',
            '--atlas-self-construction-runtime-promotion-receipt-runbook-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_runtime_promotion_receipt_runbook_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }
    }

    public function test_real_provider_smoke_runbook_command_exposes_direct_status_and_quartet(): void
    {
        Storage::fake('local');

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-real-provider-smoke-runbook-status' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_status.v1', $payload['schema_version']);
        $this->assertFalse($payload['execution_allowed']);
        $this->assertFalse($payload['dispatch_allowed']);
        $this->assertSame('operator_real_provider_smoke_required', data_get($payload, 'agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_status.status'));
        $this->assertSame(11, data_get($payload, 'agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_status.required_evidence_field_count'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_status.runbook_hash'));

        foreach ([
            '--atlas-self-construction-real-provider-smoke-runbook-contract' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_contract.v1',
            '--atlas-self-construction-real-provider-smoke-runbook-preflight' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_preflight.v1',
            '--atlas-self-construction-real-provider-smoke-runbook-implementation-packet' => 'atlas.self_construction_agent_control_plane_atlas_self_construction_real_provider_smoke_runbook_implementation_packet.v1',
        ] as $flag => $schema) {
            $exit = Artisan::call('atlas:ai:self-construction', [$flag => true, '--json' => true]);
            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            $this->assertSame(0, $exit);
            $this->assertSame($schema, $payload['schema_version']);
            $this->assertFalse($payload['execution_allowed']);
            $this->assertFalse($payload['dispatch_allowed']);
        }
    }
}
