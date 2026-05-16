<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionEvidenceSubmissionPreflightTest extends TestCase
{
    public function test_submission_preflight_reports_first_missing_runtime_receipt_without_persisting(): void
    {
        $payload = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
            completionAudit: $this->completionAudit(['runtime_gap_matrix_all_runtime_y', 'end_to_end_real_provider_smoke_green']),
            completionEvidence: $this->completionEvidence(),
            blockerExplainer: $this->blockerExplainer(),
        );

        $this->assertSame('atlas.self_construction.completion_evidence_submission_preflight.v1', $payload['schema_version']);
        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('runtime_promotion_receipt', $payload['next_required_submission']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $payload['next_required_command']);
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertFalse((bool) data_get($payload, 'operator_execution_plan.parallel_submission_allowed'));
        $this->assertContains('stop_if_receipt_hash_does_not_match_payload', data_get($payload, 'operator_execution_plan.stop_conditions'));
        $this->assertCount(5, data_get($payload, 'operator_execution_plan.ordered_command_queue'));
        $this->assertSame('atlas.self_construction.operator_final_evidence_handoff_packet.v1', data_get($payload, 'operator_handoff_packet.schema_version'));
        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertSame(['adapter_execution_runtime'], data_get($payload, 'operator_handoff_packet.current_blockers'));
        $this->assertContains('operator_signed_runtime_promotion_receipt_json', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertContains('stop_if_any_required_input_is_placeholder', data_get($payload, 'operator_handoff_packet.handoff_stop_conditions'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) data_get($payload, 'operator_handoff_packet.handoff_packet_hash'));
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertContains('completion_evidence_submission_preflight_does_not_persist_receipts', $payload['non_execution_guarantees']);
    }

    public function test_submission_preflight_advances_to_real_provider_smoke_after_runtime_receipt(): void
    {
        $evidence = $this->completionEvidence();
        data_set($evidence, 'runtime_gap_matrix.all_runtime_y', true);
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'passed');
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', str_repeat('a', 64));

        $payload = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
            completionAudit: $this->completionAudit(['end_to_end_real_provider_smoke_green']),
            completionEvidence: $evidence,
            blockerExplainer: $this->blockerExplainer(),
        );

        $this->assertSame('real_provider_smoke', $payload['next_required_submission']);
        $this->assertSame('real_provider_smoke', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertSame('real_provider_smoke', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertContains('provider_run_id', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertContains('runtime_promotion_receipt', data_get($payload, 'operator_handoff_packet.required_before_current_step'));
        $this->assertStringContainsString('--atlas-self-construction-real-provider-smoke-draft-status', $payload['next_required_command']);
        $this->assertStringContainsString('--persist-completion-evidence', $payload['next_required_persist_command']);
    }

    public function test_submission_preflight_marks_hash_composition_ready_after_runtime_and_smoke(): void
    {
        $evidence = $this->completionEvidence();
        data_set($evidence, 'runtime_gap_matrix.all_runtime_y', true);
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.status', 'passed');
        data_set($evidence, 'runtime_gap_matrix.runtime_promotion_receipt.receipt_hash', str_repeat('a', 64));
        data_set($evidence, 'real_provider_smoke.status', 'passed');
        data_set($evidence, 'real_provider_smoke.smoke_hash', str_repeat('b', 64));
        data_set($evidence, 'real_provider_smoke.violations', []);

        $payload = (new AtlasSelfConstructionCompletionEvidenceSubmissionPreflightService)->build(
            completionAudit: $this->completionAudit(['human_signed_os_complete_receipt_present']),
            completionEvidence: $evidence,
            blockerExplainer: $this->blockerExplainer(),
        );

        $steps = collect($payload['ordered_steps'])->keyBy('id');

        $this->assertTrue(data_get($steps, 'completion_evidence_hash_composition.ready'));
        $this->assertSame('ready_for_operator_hash_composition', data_get($steps, 'completion_evidence_hash_composition.status'));
        $this->assertSame('human_completion_receipt', $payload['next_required_submission']);
        $this->assertSame('human_completion_receipt', data_get($payload, 'operator_execution_plan.current_step'));
        $this->assertSame('human_completion_receipt', data_get($payload, 'operator_handoff_packet.current_step'));
        $this->assertContains('operator_signed_human_completion_receipt_json', data_get($payload, 'operator_handoff_packet.required_operator_inputs'));
        $this->assertFalse((bool) data_get($payload, 'operator_handoff_packet.parallel_submission_allowed'));
        $this->assertSame('completion_audit.status=complete AND completion_allowed=true AND failed_count=0', data_get($payload, 'operator_execution_plan.final_success_predicate'));
    }

    public function test_submission_preflight_exposes_readiness_and_cli_surface(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionCompletionEvidenceSubmissionPreflightStatus();

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.v1', $status['schema_version']);
        $this->assertSame('blocked', $status['status']);
        $this->assertSame('runtime_promotion_receipt', data_get($status, 'agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_status.next_required_submission'));
        $this->assertFalse($status['execution_allowed']);

        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-completion-evidence-submission-preflight-contract' => true,
            '--json' => true,
        ]);
        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        $this->assertSame(0, $exit);
        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_construction_completion_evidence_submission_preflight_contract.v1', $payload['schema_version']);
        $this->assertFalse($payload['dispatch_allowed']);
    }

    public function test_agent_control_plane_lists_submission_preflight_capabilities(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)->agentControlPlane();
        $capabilities = data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_contract', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_preflight', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_implementation_packet', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_service', $capabilities);
        $this->assertContains('atlas_self_construction_completion_evidence_submission_preflight_status_projection', $capabilities);
    }

    /** @param list<string> $failedCriteria */
    private function completionAudit(array $failedCriteria): array
    {
        return [
            'status' => $failedCriteria === [] ? 'complete' : 'incomplete',
            'completion_allowed' => $failedCriteria === [],
            'completion_audit_hash' => str_repeat('1', 64),
            'failed_criteria' => $failedCriteria,
        ];
    }

    /** @return array<string, mixed> */
    private function completionEvidence(): array
    {
        return [
            'completion_evidence_status_hash' => str_repeat('2', 64),
            'runtime_gap_matrix' => [
                'all_runtime_y' => false,
                'blocked_gap_ids' => ['adapter_execution_runtime'],
                'runtime_promotion_receipt' => [
                    'status' => 'blocked',
                    'receipt_hash' => '',
                ],
            ],
            'real_provider_smoke' => [
                'status' => 'blocked_missing_real_provider_smoke',
                'smoke_hash' => '',
                'violations' => [['code' => 'required_evidence_missing']],
            ],
            'human_signed_completion_receipt' => [
                'status' => 'blocked',
                'receipt_hash' => '',
                'violations' => [['code' => 'required_receipt_missing']],
            ],
        ];
    }

    /** @return array<string, mixed> */
    private function blockerExplainer(): array
    {
        return [
            'explainer_hash' => str_repeat('3', 64),
            'command_plan' => [
                'draft_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-runtime-promotion-receipt-draft-status --json',
                'persist_runtime_promotion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --persist-runtime-promotion-receipt --json',
                'draft_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-real-provider-smoke-draft-status --json',
                'persist_real_provider_smoke' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --persist-completion-evidence --json',
                'compose_completion_evidence_hashes' => 'php artisan atlas:ai:self-construction --atlas-self-construction-completion-evidence-hash-composer-status --json',
                'draft_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-human-completion-receipt-draft-status --json',
                'persist_human_completion_receipt' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-evidence-status --persist-completion-evidence --json',
                'completion_audit' => 'php artisan atlas:ai:self-construction --atlas-self-construction-os-completion-audit-status --json',
            ],
        ];
    }
}
