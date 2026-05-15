<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionOperatorEvidenceSubmissionReadinessTest extends TestCase
{
    public function test_no_input_reports_runtime_promotion_receipt_as_next_required(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build();

        $this->assertSame('atlas.self_construction.operator_evidence_submission_readiness.v1', $payload['schema_version']);
        $this->assertSame('read_only_operator_evidence_submission_readiness', $payload['mode']);
        $this->assertSame('no_input', $payload['status']);
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $payload['next_required_command']);
        $this->assertFalse($payload['completion_allowed']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertFalse($payload['runtime_promotion_receipt_passed']);
        $this->assertFalse($payload['real_provider_smoke_passed']);
        $this->assertFalse($payload['human_completion_receipt_passed']);
        $this->assertFalse($payload['human_receipt_out_of_order']);
        $this->assertNotEmpty($payload['submission_readiness_hash']);
    }

    public function test_invalid_runtime_receipt_keeps_next_required_at_runtime_promotion_with_violations(): void
    {
        Storage::fake('local');
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'rp-invalid',
                'signed_by' => '<operator>',
                'reason' => 'short',
                'runtime_gap_matrix_hash' => str_repeat('0', 64),
                'runtime_promotion_basis_hash' => str_repeat('0', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('0', 64),
                'promoted_gap_ids' => [],
                'graduation_evidence_hashes' => [],
                'receipt_hash' => str_repeat('0', 64),
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
            ],
        ]);

        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
        $this->assertFalse($payload['runtime_promotion_receipt_passed']);
        $this->assertGreaterThan(0, (int) data_get($payload, 'diagnostics.runtime_promotion_receipt.violation_count', 0));
        $this->assertTrue(data_get($payload, 'diagnostics.runtime_promotion_receipt.supplied'));
        $this->assertFalse(data_get($payload, 'diagnostics.runtime_promotion_receipt.ready'));
    }

    public function test_invalid_real_provider_smoke_detects_missing_provider_cost_and_observation_flags(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'real_provider_smoke' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => '<missing>',
                'task_packet_id' => '<missing>',
                'observed_by' => '<missing>',
                'approval_reason' => '<missing>',
                'smoke_hash' => str_repeat('0', 64),
                'operator_approval_receipt_hash' => '<missing>',
                'evidence_ledger_hash' => '<missing>',
                'work_product_manifest_hash' => '<missing>',
                'cost_event_hash' => '<missing>',
                'continuation_summary_hash' => '<missing>',
                'provider_response_hash' => '<missing>',
                'provider_call_observed' => false,
                'token_spend_observed' => false,
                'claim_to_completion_observed' => false,
                'work_product_collected' => false,
                'operator_supplied_evidence' => false,
                'real_provider_run_observed_by_operator' => false,
            ],
        ]);

        $errors = (array) data_get($payload, 'diagnostics.real_provider_smoke.errors', []);
        $this->assertContains('missing_or_placeholder_provider_run_id', $errors);
        $this->assertContains('missing_or_placeholder_cost_event_hash', $errors);
        $this->assertContains('missing_or_placeholder_work_product_manifest_hash', $errors);
        $this->assertContains('missing_or_placeholder_continuation_summary_hash', $errors);
        $this->assertContains('missing_observation_flag_provider_call_observed', $errors);
        $this->assertContains('missing_observation_flag_token_spend_observed', $errors);
        $this->assertContains('missing_observation_flag_work_product_collected', $errors);
        $this->assertFalse($payload['real_provider_smoke_passed']);
    }

    public function test_human_receipt_supplied_before_runtime_and_smoke_is_marked_out_of_order(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'completion_receipt' => [
                'receipt_id' => 'human-early',
                'signed_by' => 'operator-name-real',
                'reason' => 'Operator reviewed everything as if it was green.',
                'completion_audit_hash' => str_repeat('1', 64),
                'release_dossier_hash' => str_repeat('1', 64),
                'replay_diff_hash' => str_repeat('1', 64),
                'runtime_gap_matrix_hash' => str_repeat('1', 64),
                'runtime_promotion_receipt_hash' => str_repeat('1', 64),
                'real_provider_smoke_hash' => str_repeat('1', 64),
                'certification_status_batch_hash' => str_repeat('1', 64),
                'receipt_hash' => str_repeat('1', 64),
                'os_complete_approved' => true,
                'operator_reviewed_completion_audit' => true,
                'no_autopromotion_acknowledged' => true,
            ],
        ]);

        $this->assertTrue($payload['human_receipt_out_of_order']);
        $this->assertFalse(data_get($payload, 'diagnostics.human_completion_receipt.ready'));
        $this->assertContains(
            'human_completion_receipt_supplied_before_runtime_and_smoke_green',
            (array) data_get($payload, 'diagnostics.human_completion_receipt.errors', []),
        );
        $this->assertSame('runtime_promotion_receipt', $payload['next_required']);
    }

    public function test_forbidden_flags_in_real_provider_smoke_payload_are_flagged(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'real_provider_smoke' => [
                'kind' => 'real_provider_packet_claim_to_completion',
                'status' => 'passed',
                'provider_run_id' => 'pr-1',
                'task_packet_id' => 'tp-1',
                'observed_by' => 'operator-real',
                'approval_reason' => 'Operator approved this and observed it.',
                'smoke_hash' => str_repeat('0', 64),
                'operator_approval_receipt_hash' => str_repeat('0', 64),
                'evidence_ledger_hash' => str_repeat('0', 64),
                'work_product_manifest_hash' => str_repeat('0', 64),
                'cost_event_hash' => str_repeat('0', 64),
                'continuation_summary_hash' => str_repeat('0', 64),
                'provider_response_hash' => str_repeat('0', 64),
                'provider_call_observed' => true,
                'token_spend_observed' => true,
                'claim_to_completion_observed' => true,
                'work_product_collected' => true,
                'operator_supplied_evidence' => true,
                'real_provider_run_observed_by_operator' => true,
                'dispatch_allowed' => true,
                'self_programming_allowed' => true,
                'completion_claim_promoted_without_receipt' => true,
            ],
        ]);

        $flagsTrue = (array) data_get($payload, 'diagnostics.real_provider_smoke.forbidden_flags_true', []);
        $this->assertContains('dispatch_allowed', $flagsTrue);
        $this->assertContains('self_programming_allowed', $flagsTrue);
        $this->assertContains('completion_claim_promoted_without_receipt', $flagsTrue);
    }

    public function test_stale_runtime_context_hashes_are_detected_when_receipt_drifted(): void
    {
        $payload = (new AtlasSelfConstructionOperatorEvidenceSubmissionReadinessService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_promotion_receipt' => [
                'receipt_id' => 'rp-stale',
                'signed_by' => 'operator-real',
                'reason' => 'Operator reviewed and signed under stale hashes.',
                'runtime_gap_matrix_hash' => str_repeat('f', 64),
                'runtime_promotion_basis_hash' => str_repeat('e', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('d', 64),
                'promoted_gap_ids' => [],
                'graduation_evidence_hashes' => [],
                'receipt_hash' => str_repeat('c', 64),
                'runtime_promotion_approved' => true,
                'operator_reviewed_runtime_graduations' => true,
                'no_runtime_autopromotion_acknowledged' => true,
            ],
        ]);

        $stale = (array) $payload['stale_context_hashes'];
        $this->assertContains('runtime_gap_matrix_hash', $stale);
        $this->assertContains('runtime_promotion_basis_hash', $stale);
        $this->assertContains('runtime_promotion_closure_basis_hash', $stale);
    }

    public function test_readiness_status_and_cli_quartet_exist(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionOperatorEvidenceSubmissionReadinessStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_operator_evidence_submission_readiness_status.v1', $status['schema_version']);
        $this->assertSame('no_input', $status['status']);
        $this->assertFalse($status['execution_allowed']);

        foreach ([
            'atlas-self-construction-operator-evidence-submission-readiness-contract',
            'atlas-self-construction-operator-evidence-submission-readiness-preflight',
            'atlas-self-construction-operator-evidence-submission-readiness-implementation-packet',
            'atlas-self-construction-operator-evidence-submission-readiness-status',
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

    public function test_agent_control_plane_lists_submission_readiness_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        foreach ([
            'atlas_self_construction_operator_evidence_submission_readiness_contract',
            'atlas_self_construction_operator_evidence_submission_readiness_preflight',
            'atlas_self_construction_operator_evidence_submission_readiness_implementation_packet',
            'atlas_self_construction_operator_evidence_submission_readiness_service',
            'atlas_self_construction_operator_evidence_submission_readiness_status_projection',
        ] as $capability) {
            $this->assertContains($capability, $capabilities);
        }
    }
}
