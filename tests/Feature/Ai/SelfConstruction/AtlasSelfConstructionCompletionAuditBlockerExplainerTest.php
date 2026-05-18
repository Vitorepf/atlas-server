<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionAuditBlockerExplainerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptVerifierService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionAuditBlockerExplainerTest extends TestCase
{
    public function test_blocker_explainer_maps_known_final_blockers_to_services_and_runbooks(): void
    {
        $payload = $this->service()->build($this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]));

        $this->assertSame('atlas.self_construction.completion_audit_blocker_explainer.v1', $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertSame(3, $payload['remaining_blocker_count']);
        $this->assertTrue($payload['machine_status']['human_required']);
        $this->assertTrue($payload['machine_status']['real_provider_required']);
        $this->assertSame(AtlasSelfConstructionRuntimePromotionReceiptService::class, data_get($payload, 'blockers.0.existing_service_that_validates_it'));
        $this->assertSame(AtlasSelfConstructionHumanCompletionReceiptVerifierService::class, data_get($payload, 'blockers.1.existing_service_that_validates_it'));
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeCertificationService::class, data_get($payload, 'blockers.2.existing_service_that_validates_it'));
    }

    public function test_blocker_explainer_dependency_graph_uses_safe_closure_order(): void
    {
        $payload = $this->service()->build($this->audit(['runtime_gap_matrix_all_runtime_y']));

        $this->assertSame([
            'capture_fresh_snapshot_if_release_dossier_stale',
            'draft_runtime_promotion_receipt',
            'persist_runtime_promotion_receipt',
            'prepare_real_provider_smoke_offline_harness',
            'draft_real_provider_smoke_certification',
            'persist_real_provider_smoke_certification',
            'compose_completion_evidence_hashes',
            'draft_human_completion_receipt',
            'persist_human_completion_receipt',
            'rerun_completion_audit',
            'refresh_terminal_loop_operational_proof',
            'persist_terminal_loop_operational_proof_binding',
            'capture_replay_snapshot_after_terminal_loop_operational_proof',
            'rerun_completion_audit_with_canonical_terminal_loop_operational_proof',
            'promote_next_stage_only_after_all_criteria_green',
        ], $payload['dependency_graph']['ordered_closure_path']);
        $this->assertContains('completion_claim_depends_on_completion_audit_status_complete', $payload['dependency_graph']['hard_dependencies']);
    }

    public function test_blocker_explainer_contains_anti_cheat_policy_for_final_evidence(): void
    {
        $payload = $this->service()->build($this->audit(['end_to_end_real_provider_smoke_green']));

        $this->assertContains('reject_fake_real_provider_smoke', $payload['anti_cheat_policy']);
        $this->assertContains('reject_receipt_hash_mismatch', $payload['anti_cheat_policy']);
        $this->assertContains('reject_runtime_autopromotion', $payload['anti_cheat_policy']);
        $this->assertContains('reject_completion_claim_without_human_receipt', $payload['anti_cheat_policy']);
        $this->assertContains('reject_stale_release_dossier', $payload['anti_cheat_policy']);
    }

    public function test_blocker_explainer_unknown_blocker_cannot_auto_close(): void
    {
        $payload = $this->service()->build($this->audit(['unknown_final_blocker']));

        $this->assertSame('unknown_final_blocker', data_get($payload, 'blockers.0.blocker_id'));
        $this->assertSame('unknown', data_get($payload, 'blockers.0.owner'));
        $this->assertContains('unknown_blocker_cannot_be_auto_closed', data_get($payload, 'blockers.0.safety_constraints'));
        $this->assertFalse($payload['machine_status']['can_close_automatically']);
        $this->assertFalse($payload['machine_status']['closure_ready']);
    }

    public function test_blocker_explainer_marks_closure_ready_only_when_no_blockers_exist(): void
    {
        $payload = $this->service()->build($this->audit([]));

        $this->assertSame([], $payload['blockers']);
        $this->assertSame(0, $payload['remaining_blocker_count']);
        $this->assertSame(4, $payload['closure_artifact_sequence_count']);
        $this->assertSame(4, $payload['prompt_to_artifact_checklist_count']);
        $this->assertSame(4, $payload['prompt_to_artifact_checklist_passed_count']);
        $this->assertTrue($payload['machine_status']['closure_ready']);
        $this->assertFalse($payload['machine_status']['can_close_automatically']);
        $this->assertFalse($payload['completion_claim_allowed']);
    }

    public function test_blocker_explainer_exposes_compact_closure_artifact_map(): void
    {
        $payload = $this->service()->build($this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]));

        $this->assertSame(4, $payload['closure_artifact_sequence_count']);
        $this->assertSame(4, $payload['prompt_to_artifact_checklist_count']);
        $this->assertSame(0, $payload['prompt_to_artifact_checklist_passed_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['closure_artifact_sequence_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $payload['prompt_to_artifact_checklist_hash']);

        $this->assertSame('runtime_promotion_receipt', data_get($payload, 'closure_artifact_sequence.0.artifact'));
        $this->assertSame('runtime_gap_matrix_all_runtime_y', data_get($payload, 'closure_artifact_sequence.0.requirement'));
        $this->assertSame('atlas.self_construction.runtime_promotion_receipt.v1', data_get($payload, 'closure_artifact_sequence.0.expected_receipt_schema'));
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', data_get($payload, 'closure_artifact_sequence.0.draft_command'));
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', data_get($payload, 'closure_artifact_sequence.0.persist_command'));

        $this->assertSame('real_provider_smoke', data_get($payload, 'closure_artifact_sequence.1.artifact'));
        $this->assertTrue(data_get($payload, 'closure_artifact_sequence.1.requires_provider_call'));
        $this->assertSame('human_completion_receipt', data_get($payload, 'closure_artifact_sequence.2.artifact'));
        $this->assertTrue(data_get($payload, 'closure_artifact_sequence.2.requires_operator_signature'));
        $this->assertSame('final_completion_audit', data_get($payload, 'closure_artifact_sequence.3.artifact'));
        $this->assertStringContainsString('@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', data_get($payload, 'closure_artifact_sequence.3.draft_command'));

        $this->assertSame('runtime_gap_matrix_all_runtime_y', data_get($payload, 'prompt_to_artifact_checklist.0.requirement'));
        $this->assertFalse(data_get($payload, 'prompt_to_artifact_checklist.0.passed'));
    }

    public function test_blocker_explainer_command_plan_lists_required_commands(): void
    {
        $payload = $this->service()->build($this->audit(['human_signed_os_complete_receipt_present']));

        $this->assertArrayHasKey('completion_evidence_status', $payload['command_plan']);
        $this->assertArrayHasKey('draft_runtime_promotion_receipt', $payload['command_plan']);
        $this->assertArrayHasKey('persist_runtime_promotion_receipt', $payload['command_plan']);
        $this->assertArrayHasKey('prepare_real_provider_smoke_offline_harness', $payload['command_plan']);
        $this->assertArrayHasKey('draft_real_provider_smoke', $payload['command_plan']);
        $this->assertArrayHasKey('persist_real_provider_smoke', $payload['command_plan']);
        $this->assertArrayHasKey('compose_completion_evidence_hashes', $payload['command_plan']);
        $this->assertArrayHasKey('draft_human_completion_receipt', $payload['command_plan']);
        $this->assertArrayHasKey('persist_human_completion_receipt', $payload['command_plan']);
        $this->assertArrayHasKey('terminal_loop_operational_proof', $payload['command_plan']);
        $this->assertArrayHasKey('persist_terminal_loop_operational_proof_binding', $payload['command_plan']);
        $this->assertArrayHasKey('terminal_loop_operational_proof_canonical_binding_path', $payload['command_plan']);
        $this->assertArrayHasKey('completion_audit', $payload['command_plan']);
        $this->assertArrayHasKey('completion_audit_with_terminal_loop_operational_proof', $payload['command_plan']);
        $this->assertArrayHasKey('effective_completion_audit_with_canonical_terminal_loop_operational_proof', $payload['command_plan']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', $payload['command_plan']['terminal_loop_operational_proof']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', $payload['command_plan']['persist_terminal_loop_operational_proof_binding']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', $payload['command_plan']['completion_audit_with_terminal_loop_operational_proof']);
        $this->assertStringContainsString('@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $payload['command_plan']['effective_completion_audit_with_canonical_terminal_loop_operational_proof']);
        $this->assertContains('capture_replay_snapshot_after_terminal_loop_operational_proof', $payload['dependency_graph']['ordered_closure_path']);
        $this->assertContains('rerun_completion_audit_with_canonical_terminal_loop_operational_proof', $payload['dependency_graph']['ordered_closure_path']);
    }

    public function test_blocker_explainer_status_projection_exposes_effective_terminal_loop_commands(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)
            ->atlasSelfConstructionCompletionAuditBlockerExplainerStatus([
                'completion_audit' => $this->audit(['human_signed_os_complete_receipt_present']),
            ]);

        $block = (array) data_get($status, 'agent_control_plane_atlas_self_construction_completion_audit_blocker_explainer_status', []);

        $this->assertSame('available', $status['status']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', (string) $block['terminal_loop_operational_proof_command']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) $block['terminal_loop_operational_proof_binding_persist_command']);
        $this->assertSame('storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $block['terminal_loop_operational_proof_canonical_binding_path']);
        $this->assertStringContainsString('@/path/to/terminal-loop-operational-proof-binding.json', (string) $block['completion_audit_command_with_terminal_loop_operational_proof']);
        $this->assertStringContainsString('@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', (string) $block['effective_completion_audit_with_canonical_terminal_loop_operational_proof_command']);
        $this->assertSame(4, $block['closure_artifact_sequence_count']);
        $this->assertSame(4, $block['prompt_to_artifact_checklist_count']);
        $this->assertSame(0, $block['prompt_to_artifact_checklist_passed_count']);
        $this->assertSame('runtime_promotion_receipt', data_get($block, 'closure_artifact_sequence.0.artifact'));
        $this->assertSame('human_signed_os_complete_receipt_present', data_get($block, 'prompt_to_artifact_checklist.2.requirement'));
    }

    public function test_blocker_explainer_human_output_exposes_closure_artifacts_and_terminal_proof(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-completion-audit-blocker-explainer-status' => true,
        ]);

        $this->assertSame(0, $exit);
        $output = Artisan::output();

        $this->assertStringContainsString('Remaining blockers', $output);
        $this->assertStringContainsString('Human required', $output);
        $this->assertStringContainsString('Real provider required', $output);
        $this->assertStringContainsString('Can close automatically', $output);
        $this->assertStringContainsString('Closure artifact sequence:', $output);
        $this->assertStringContainsString('runtime_promotion_receipt', $output);
        $this->assertStringContainsString('real_provider_smoke', $output);
        $this->assertStringContainsString('human_completion_receipt', $output);
        $this->assertStringContainsString('Prompt-to-artifact checklist:', $output);
        $this->assertStringContainsString('runtime_gap_matrix_all_runtime_y', $output);
        $this->assertStringContainsString('end_to_end_real_provider_smoke_green', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $output);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', $output);
        $this->assertStringContainsString('Effective canonical audit', $output);
        $this->assertStringContainsString('Explainer hash', $output);
    }

    public function test_blocker_explainer_closure_plan_requires_drafts_before_persistence(): void
    {
        $payload = $this->service()->build($this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]));

        $phases = collect($payload['closure_plan'])->keyBy('phase');

        $this->assertContains('runtime_promotion_receipt_draft_ready', data_get($phases, 'runtime_promotion.acceptance_criteria'));
        $this->assertContains('offline_harness_prepared', data_get($phases, 'real_provider_smoke.acceptance_criteria'));
        $this->assertContains('real_provider_smoke_draft_ready', data_get($phases, 'real_provider_smoke.acceptance_criteria'));
        $this->assertContains('human_completion_receipt_draft_ready', data_get($phases, 'human_completion_receipt.acceptance_criteria'));
        $this->assertSame('stop_if_any_payload_contains_placeholders_or_runtime_enabling_flags', data_get($phases, 'completion_evidence_hash_composition.stop_condition'));
    }

    public function test_blocker_explainer_exposes_current_evidence_context_for_operator_artifacts(): void
    {
        $payload = $this->service()->build($this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]));

        $runtimeContext = data_get($payload, 'blockers.0.current_evidence_context');
        $humanContext = data_get($payload, 'blockers.1.current_evidence_context');
        $smokeContext = data_get($payload, 'blockers.2.current_evidence_context');

        $this->assertSame(str_repeat('1', 64), $runtimeContext['runtime_gap_matrix_hash']);
        $this->assertSame(str_repeat('2', 64), $runtimeContext['runtime_promotion_basis_hash']);
        $this->assertSame(str_repeat('9', 64), $runtimeContext['runtime_promotion_closure_basis_hash']);
        $this->assertSame(['adapter_execution_runtime'], $runtimeContext['blocked_gap_ids']);
        $this->assertSame(['adapter_execution_runtime'], $runtimeContext['promoted_gap_ids_template']);
        $this->assertSame(str_repeat('3', 64), $runtimeContext['graduation_evidence_hashes_template']['adapter_execution_runtime']);
        $this->assertTrue($runtimeContext['operator_artifact_missing']);

        $this->assertSame(str_repeat('b', 64), $humanContext['completion_audit_hash']);
        $this->assertSame(str_repeat('4', 64), $humanContext['receipt_verification_hash']);
        $this->assertSame(str_repeat('6', 64), $humanContext['human_completion_receipt_template_hash']);
        $this->assertTrue($humanContext['operator_artifact_missing']);

        $this->assertSame(str_repeat('7', 64), $smokeContext['certification_hash']);
        $this->assertSame(str_repeat('8', 64), $smokeContext['real_provider_smoke_template_hash']);
        $this->assertContains('provider_call_observed', $smokeContext['required_observation_flags']);
        $this->assertTrue($smokeContext['operator_artifact_missing']);
    }

    public function test_blocker_explainer_hash_is_deterministic(): void
    {
        $audit = $this->audit(['runtime_gap_matrix_all_runtime_y']);
        $first = $this->service()->build($audit);
        $second = $this->service()->build($audit);

        $this->assertSame($first['explainer_hash'], $second['explainer_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['explainer_hash']);
    }

    public function test_blocker_explainer_hash_changes_when_blockers_change(): void
    {
        $first = $this->service()->build($this->audit(['runtime_gap_matrix_all_runtime_y']));
        $second = $this->service()->build($this->audit(['human_signed_os_complete_receipt_present']));

        $this->assertNotSame($first['explainer_hash'], $second['explainer_hash']);
    }

    public function test_blocker_explainer_never_executes_or_promotes_completion(): void
    {
        $payload = $this->service()->build($this->audit(['runtime_gap_matrix_all_runtime_y']));

        $this->assertContains('blocker_explainer_does_not_persist_receipts', $payload['non_execution_guarantees']);
        $this->assertContains('blocker_explainer_does_not_call_provider', $payload['non_execution_guarantees']);
        $this->assertContains('blocker_explainer_does_not_spend_tokens', $payload['non_execution_guarantees']);
        $this->assertContains('blocker_explainer_does_not_promote_completion', $payload['non_execution_guarantees']);
        $this->assertFalse($payload['completion_claim_allowed']);
    }

    public function test_blocker_explainer_is_json_serializable(): void
    {
        $payload = $this->service()->build($this->audit(['release_dossier_green']));

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    private function service(): AtlasSelfConstructionCompletionAuditBlockerExplainerService
    {
        return new AtlasSelfConstructionCompletionAuditBlockerExplainerService;
    }

    /** @param array<int, string> $failedCriteria */
    private function audit(array $failedCriteria): array
    {
        $criteria = array_map(fn (string $criterion): array => [
            'id' => $criterion,
            'passed' => false,
            'evidence' => $this->evidenceFor($criterion),
        ], $failedCriteria);

        return [
            'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
            'status' => $failedCriteria === [] ? 'complete' : 'incomplete',
            'completion_allowed' => $failedCriteria === [],
            'completion_audit_hash' => str_repeat('b', 64),
            'failed_criteria' => $failedCriteria,
            'criteria' => $criteria,
            'operator_action_packet' => [
                'missing_operator_artifacts' => [
                    'runtime_promotion_receipt',
                    'human_signed_os_complete_receipt',
                    'real_provider_claim_to_completion_smoke',
                ],
                'runtime_promotion_receipt_template' => [
                    'runtime_gap_matrix_hash' => str_repeat('1', 64),
                    'runtime_promotion_basis_hash' => str_repeat('2', 64),
                    'runtime_promotion_closure_basis_hash' => str_repeat('9', 64),
                    'promoted_gap_ids' => ['adapter_execution_runtime'],
                    'graduation_evidence_hashes' => [
                        'adapter_execution_runtime' => str_repeat('3', 64),
                    ],
                ],
                'template_hashes' => [
                    'runtime_promotion_receipt_template_hash' => str_repeat('5', 64),
                    'human_completion_receipt_template_hash' => str_repeat('6', 64),
                    'real_provider_smoke_template_hash' => str_repeat('8', 64),
                ],
            ],
        ];
    }

    private function evidenceFor(string $criterion): array
    {
        return match ($criterion) {
            'runtime_gap_matrix_all_runtime_y' => [
                'status' => 'blocked',
                'runtime_gap_matrix_hash' => str_repeat('1', 64),
                'runtime_gap_count' => 1,
                'blocked_gap_ids' => ['adapter_execution_runtime'],
                'not_yet_runtime_capable' => ['adapter_execution_runtime'],
            ],
            'human_signed_os_complete_receipt_present' => [
                'status' => 'blocked_missing_operator_receipt',
                'receipt_id' => '',
                'receipt_hash' => '',
                'receipt_verification_hash' => str_repeat('4', 64),
                'violation_count' => 14,
            ],
            'end_to_end_real_provider_smoke_green' => [
                'status' => 'blocked_missing_real_provider_smoke',
                'smoke_hash' => '',
                'certification_hash' => str_repeat('7', 64),
                'violation_count' => 18,
            ],
            default => [
                'status' => 'blocked',
            ],
        };
    }
}
