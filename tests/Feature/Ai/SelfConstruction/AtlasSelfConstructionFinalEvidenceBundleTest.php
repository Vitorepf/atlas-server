<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionFinalEvidenceBundleTest extends TestCase
{
    public function test_final_evidence_bundle_is_available_but_blocks_completion_until_real_evidence_is_present(): void
    {
        $bundle = $this->bundle()->build($this->baseOptions());

        $this->assertSame('atlas.self_construction.final_evidence_bundle.v1', $bundle['schema_version']);
        $this->assertSame('read_only_final_evidence_bundle', $bundle['mode']);
        $this->assertSame('available', $bundle['status']);
        $this->assertFalse($bundle['machine_status']['completion_claim_allowed']);
        $this->assertFalse($bundle['final_readiness_map']['final_completion_allowed']);
        $this->assertSame(0, $bundle['machine_status']['missing_component_count']);
        $this->assertSame([], $bundle['evidence_dependencies']['missing_components']);
    }

    public function test_final_evidence_bundle_registers_base_components_and_hashes_them(): void
    {
        $bundle = $this->bundle()->build($this->baseOptions());

        foreach (['completion_evidence_lockfile', 'completion_anti_fraud_matrix', 'runtime_promotion_closure_pack', 'human_completion_receipt_preflight', 'real_provider_smoke_offline_harness', 'completion_operator_action_packet', 'completion_evidence_hash_composer', 'completion_audit_status', 'runtime_gap_matrix'] as $component) {
            $this->assertTrue($bundle['component_registry'][$component]['available']);
            $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bundle['component_registry'][$component]['hash']);
            $this->assertSame('', $bundle['component_registry'][$component]['missing_reason']);
        }
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $bundle['bundle_identity']['bundle_hash']);
        $this->assertSame($bundle['bundle_identity']['bundle_hash'], $bundle['machine_status']['final_bundle_hash']);
    }

    public function test_final_evidence_bundle_hash_is_deterministic_for_same_inputs(): void
    {
        $service = $this->bundle();
        $first = $service->build($this->baseOptions());
        $second = $service->build($this->baseOptions());

        $this->assertSame($first['bundle_identity']['bundle_hash'], $second['bundle_identity']['bundle_hash']);
        $this->assertSame($first['bundle_identity']['bundle_id'], $second['bundle_identity']['bundle_id']);
    }

    public function test_final_evidence_bundle_hash_changes_when_evidence_changes(): void
    {
        $first = $this->bundle()->build($this->baseOptions());
        $changed = $this->baseOptions();
        $changed['runtime_gap_matrix']['runtime_gap_matrix_hash'] = str_repeat('9', 64);

        $second = $this->bundle()->build($changed);

        $this->assertNotSame($first['bundle_identity']['bundle_hash'], $second['bundle_identity']['bundle_hash']);
    }

    public function test_final_evidence_bundle_keeps_safety_invariants_true_when_audit_is_incomplete(): void
    {
        $bundle = $this->bundle()->build($this->baseOptions());

        $this->assertTrue($bundle['safety_invariants']['no_execution']);
        $this->assertTrue($bundle['safety_invariants']['no_provider_call']);
        $this->assertTrue($bundle['safety_invariants']['no_token_spend']);
        $this->assertTrue($bundle['safety_invariants']['no_dispatch']);
        $this->assertTrue($bundle['safety_invariants']['no_adapter_execution']);
        $this->assertTrue($bundle['safety_invariants']['no_self_programming']);
        $this->assertTrue($bundle['safety_invariants']['no_completion_claim']);
    }

    public function test_final_evidence_bundle_maps_missing_real_evidence(): void
    {
        $bundle = $this->bundle()->build($this->baseOptions());

        $this->assertContains('runtime_promotion_receipt', $bundle['evidence_dependencies']['missing_real_evidence']);
        $this->assertContains('real_provider_claim_to_completion_smoke', $bundle['evidence_dependencies']['missing_real_evidence']);
        $this->assertContains('human_signed_os_complete_receipt', $bundle['evidence_dependencies']['missing_real_evidence']);
        $this->assertTrue($bundle['evidence_dependencies']['human_required']);
        $this->assertTrue($bundle['evidence_dependencies']['real_provider_required']);
        $this->assertSame(3, $bundle['machine_status']['blocker_count']);
    }

    public function test_final_evidence_bundle_operator_packet_exposes_stop_conditions_and_commands(): void
    {
        $bundle = $this->bundle()->build($this->baseOptions());

        $this->assertContains('do_not_accept_placeholder_receipts', $bundle['final_operator_packet']['stop_conditions']);
        $this->assertContains('do_not_accept_fake_provider_smoke', $bundle['final_operator_packet']['stop_conditions']);
        $this->assertContains('verify_runtime_promotion_closure_pack', $bundle['evidence_dependencies']['ordered_closure_path']);
        $this->assertContains('refresh_terminal_loop_operational_proof', $bundle['evidence_dependencies']['ordered_closure_path']);
        $this->assertContains('capture_replay_snapshot_after_terminal_loop_operational_proof', $bundle['evidence_dependencies']['ordered_closure_path']);
        $this->assertContains('rerun_completion_audit_with_terminal_loop_operational_proof', $bundle['evidence_dependencies']['ordered_closure_path']);
        $this->assertArrayHasKey('completion_evidence_hash_composer', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertArrayHasKey('completion_audit', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json',
            $bundle['final_operator_packet']['commands_to_rerun']['completion_audit'],
        );
        $this->assertArrayHasKey('completion_audit_diagnostic', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertArrayHasKey('terminal_loop_operational_proof', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertArrayHasKey('terminal_loop_operational_proof_binding_export', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertArrayHasKey('completion_audit_with_terminal_loop_operational_proof', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertArrayHasKey('completion_audit_with_canonical_terminal_loop_operational_proof', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertSame(
            'storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            $bundle['final_operator_packet']['terminal_loop_operational_proof_canonical_binding_path'],
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            $bundle['final_operator_packet']['commands_to_rerun']['completion_audit_with_canonical_terminal_loop_operational_proof'],
        );
        $this->assertTrue((bool) $bundle['final_operator_packet']['terminal_loop_operational_proof_required_before_completion_claim']);
        $this->assertArrayHasKey('capture_snapshot_if_stale', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertTrue($bundle['final_operator_packet']['completion_audit_green_requires_current_snapshot_after_terminal_loop_proof']);
        $this->assertSame([
            'refresh_terminal_loop_operational_proof_and_export_binding',
            'capture_replay_snapshot_after_terminal_loop_operational_proof',
            'rerun_completion_audit_with_terminal_loop_operational_proof_binding',
        ], array_column($bundle['final_operator_packet']['final_verification_sequence'], 'id'));
        $shellPacket = (array) $bundle['final_operator_packet']['next_action_shell_packet'];
        $this->assertSame('atlas.self_construction.final_evidence_bundle_next_action_shell_packet.v1', $shellPacket['schema_version']);
        $this->assertSame('blocked_placeholder_replacement_required', $shellPacket['status']);
        $this->assertSame('runtime_promotion_receipt', $shellPacket['current_required_operator_artifact']);
        $this->assertSame('runtime_gap_matrix_all_runtime_y', $shellPacket['current_requirement']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $shellPacket['exact_command']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $shellPacket['persist_command']);
        $this->assertFalse($shellPacket['copy_safe']);
        $this->assertContains('<operator>', $shellPacket['placeholders']);
        $this->assertContains('@/path/to/runtime-promotion.json', $shellPacket['placeholders']);
        $this->assertTrue($shellPacket['requires_fresh_status_before_copy']);
        $this->assertTrue($shellPacket['requires_fresh_preflight_before_persist']);
        $this->assertTrue($shellPacket['do_not_run_persist_command_until_verifier_green']);
        $this->assertSame('runtime_gap_matrix.runtime_promotion_receipt.status=passed AND runtime_gap_matrix.all_runtime_y=true', $shellPacket['success_check']);
        $this->assertArrayHasKey('completion_audit_with_canonical_terminal_loop_operational_proof', $shellPacket['post_action_proof_commands']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $shellPacket['shell_packet_hash']);
        $this->assertTrue($bundle['final_operator_packet']['final_verification_sequence'][0]['may_make_release_snapshot_stale']);
        $this->assertSame('capture_snapshot_if_stale', $bundle['final_operator_packet']['final_verification_sequence'][1]['command_key']);
        $this->assertSame('completion_audit_with_canonical_terminal_loop_operational_proof', $bundle['final_operator_packet']['final_verification_sequence'][2]['command_key']);
    }

    public function test_final_evidence_bundle_marks_completion_allowed_only_when_all_real_evidence_and_audit_are_green(): void
    {
        $options = $this->baseOptions([
            'runtime_all_y' => true,
            'human_receipt_passed' => true,
            'real_provider_smoke_passed' => true,
            'completion_audit_complete' => true,
            'terminal_loop_operational_proof_passed' => true,
            'failed_criteria' => [],
        ]);
        $bundle = $this->bundle()->build($options);

        $this->assertTrue($bundle['final_readiness_map']['runtime_promotion_ready']);
        $this->assertTrue($bundle['final_readiness_map']['real_provider_smoke_ready']);
        $this->assertTrue($bundle['final_readiness_map']['human_completion_receipt_ready']);
        $this->assertTrue($bundle['final_readiness_map']['release_dossier_ready']);
        $this->assertTrue($bundle['final_readiness_map']['completion_audit_green']);
        $this->assertTrue($bundle['final_readiness_map']['final_completion_allowed']);
        $this->assertTrue($bundle['machine_status']['completion_claim_allowed']);
        $this->assertFalse($bundle['safety_invariants']['no_completion_claim']);
    }

    public function test_final_evidence_bundle_stays_blocked_when_audit_has_failed_criteria_even_with_receipts(): void
    {
        $options = $this->baseOptions([
            'runtime_all_y' => true,
            'human_receipt_passed' => true,
            'real_provider_smoke_passed' => true,
            'completion_audit_complete' => false,
            'failed_criteria' => ['end_to_end_real_provider_smoke_green'],
        ]);
        $bundle = $this->bundle()->build($options);

        $this->assertFalse($bundle['final_readiness_map']['completion_audit_green']);
        $this->assertFalse($bundle['final_readiness_map']['final_completion_allowed']);
        $this->assertFalse($bundle['machine_status']['completion_claim_allowed']);
    }

    public function test_final_evidence_bundle_is_json_serializable(): void
    {
        $bundle = $this->bundle()->build($this->baseOptions());

        $encoded = json_encode($bundle, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    public function test_final_evidence_bundle_status_surfaces_operator_blockers_and_proof_commands(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionFinalEvidenceBundleStatus($this->baseOptions());
        $block = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_evidence_bundle_status', []);

        $this->assertSame('available', $status['status']);
        $this->assertSame(3, $block['blocker_count']);
        $this->assertSame('collect_missing_real_evidence_and_rerun_completion_audit', $block['next_action']);
        $this->assertContains('release_dossier', $block['what_is_ready']);
        $this->assertContains('runtime_gap_matrix_all_runtime_y', $block['what_is_blocked']);
        $this->assertContains('human_signed_os_complete_receipt_present', $block['what_is_blocked']);
        $this->assertContains('end_to_end_real_provider_smoke_green', $block['what_is_blocked']);
        $this->assertContains('runtime_promotion_receipt', $block['what_must_be_signed']);
        $this->assertContains('human_signed_os_complete_receipt', $block['what_must_be_signed']);
        $this->assertContains('real_provider_claim_to_completion_smoke', $block['what_must_be_run_with_real_provider']);
        $this->assertSame('runtime_promotion_receipt', $block['current_required_operator_artifact']);
        $this->assertSame('blocked_placeholder_replacement_required', $block['next_action_shell_packet_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $block['next_action_shell_packet_hash']);
        $this->assertStringContainsString('--atlas-self-construction-runtime-promotion-receipt-draft-status', $block['next_action_exact_command']);
        $this->assertStringContainsString('--persist-runtime-promotion-receipt', $block['next_action_persist_command']);
        $this->assertSame($block['next_action_exact_command'], $block['next_required_command']);
        $this->assertSame($block['next_action_persist_command'], $block['next_required_persist_command']);
        $this->assertSame(str_repeat('a', 64), $block['runtime_gap_matrix_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $block['expected_runtime_gap_matrix_hash_for_promotion_receipt']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $block['runtime_promotion_basis_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $block['runtime_promotion_closure_basis_hash']);
        $this->assertGreaterThanOrEqual(2, $block['next_action_placeholder_count']);
        $this->assertFalse($block['next_action_copy_safe']);
        $this->assertSame('runtime_promotion_receipt', data_get($block, 'next_action_shell_packet.current_required_operator_artifact'));
        $this->assertSame(4, (int) $block['closure_artifact_sequence_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $block['closure_artifact_sequence_hash']);
        $closureSequence = collect((array) $block['closure_artifact_sequence'])->keyBy('artifact');
        $this->assertSame('runtime_gap_matrix_all_runtime_y', $closureSequence['runtime_promotion_receipt']['requirement']);
        $this->assertSame('end_to_end_real_provider_smoke_green', $closureSequence['real_provider_smoke']['requirement']);
        $this->assertSame('human_signed_os_complete_receipt_present', $closureSequence['human_completion_receipt']['requirement']);
        $this->assertSame('completion_audit_authorizes_completion_claim', $closureSequence['final_completion_audit']['requirement']);
        $this->assertSame(4, (int) $block['prompt_to_artifact_checklist_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $block['prompt_to_artifact_checklist_hash']);
        $checklist = collect((array) $block['prompt_to_artifact_checklist'])->keyBy('requirement');
        $this->assertSame('runtime_promotion_receipt', $checklist['runtime_gap_matrix_all_runtime_y']['artifact']);
        $this->assertSame('real_provider_smoke', $checklist['end_to_end_real_provider_smoke_green']['artifact']);
        $this->assertSame('human_completion_receipt', $checklist['human_signed_os_complete_receipt_present']['artifact']);
        $this->assertSame('final_completion_audit', $checklist['completion_audit_authorizes_completion_claim']['artifact']);
        $this->assertSame(2, $block['human_blocker_count']);
        $this->assertSame([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ], $block['human_blockers']);
        $this->assertSame(1, $block['real_provider_blocker_count']);
        $this->assertSame(['end_to_end_real_provider_smoke_green'], $block['real_provider_blockers']);
        $this->assertSame(0, $block['technical_blocker_count']);
        $this->assertSame([], $block['technical_blockers']);
        $this->assertFalse($block['release_dossier_refresh_required']);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', $block['release_dossier_refresh_command']);
        $this->assertFalse($block['certification_status_batch_refresh_required']);
        $this->assertStringContainsString('--agent-control-plane-certification-status-batch-status', $block['certification_status_batch_command']);
        $this->assertFalse($block['runtime_promotion_ready']);
        $this->assertFalse($block['real_provider_smoke_ready']);
        $this->assertFalse($block['human_completion_receipt_ready']);
        $this->assertTrue($block['release_dossier_ready']);
        $this->assertFalse($block['terminal_loop_operational_proof_ready']);
        $this->assertFalse($block['completion_audit_green']);
        $this->assertFalse($block['self_programming_allowed']);
        $this->assertStringContainsString('--atlas-self-construction-os-completion-evidence-status', $block['completion_evidence_status_command']);
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-submission-readiness-status', $block['operator_evidence_readiness_command']);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', $block['capture_snapshot_if_stale_command']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $block['terminal_loop_operational_proof_command']);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', $block['terminal_loop_operational_proof_binding_export_command']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@/path/to/terminal-loop-operational-proof-binding.json', $block['completion_audit_command_with_terminal_loop_operational_proof']);
        $this->assertSame('storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $block['terminal_loop_operational_proof_canonical_binding_path']);
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            $block['completion_audit_command_with_canonical_terminal_loop_operational_proof'],
        );
        $this->assertSame(3, $block['final_verification_sequence_step_count']);
        $this->assertSame('capture_replay_snapshot_after_terminal_loop_operational_proof', $block['final_verification_sequence'][1]['id']);
        $this->assertTrue($block['completion_audit_green_requires_current_snapshot_after_terminal_loop_proof']);
        $this->assertFalse($block['completion_claim_allowed']);
        $this->assertFalse($block['final_completion_allowed']);
    }

    public function test_final_evidence_bundle_status_mirrors_green_completion_readiness(): void
    {
        $status = app(AtlasSelfConstructionReadinessService::class)->atlasSelfConstructionFinalEvidenceBundleStatus($this->baseOptions([
            'runtime_all_y' => true,
            'human_receipt_passed' => true,
            'real_provider_smoke_passed' => true,
            'completion_audit_complete' => true,
            'terminal_loop_operational_proof_passed' => true,
            'failed_criteria' => [],
        ]));
        $block = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_evidence_bundle_status', []);

        $this->assertSame('available', $status['status']);
        $this->assertSame(0, $block['blocker_count']);
        $this->assertSame([], $block['what_is_blocked']);
        $this->assertSame('none', $block['current_required_operator_artifact']);
        $this->assertSame(0, $block['human_blocker_count']);
        $this->assertSame(0, $block['real_provider_blocker_count']);
        $this->assertSame(0, $block['technical_blocker_count']);
        $this->assertTrue($block['runtime_promotion_ready']);
        $this->assertTrue($block['real_provider_smoke_ready']);
        $this->assertTrue($block['human_completion_receipt_ready']);
        $this->assertTrue($block['terminal_loop_operational_proof_ready']);
        $this->assertTrue($block['completion_audit_green']);
        $this->assertTrue($block['completion_claim_allowed']);
        $this->assertTrue($block['final_completion_allowed']);
    }

    public function test_final_evidence_bundle_human_output_exposes_closure_artifacts(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-final-evidence-bundle-status' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Bundle hash', $output);
        $this->assertStringContainsString('Blockers', $output);
        $this->assertStringContainsString('Human blockers', $output);
        $this->assertStringContainsString('Real provider blockers', $output);
        $this->assertStringContainsString('Technical blockers', $output);
        $this->assertStringContainsString('Current required artifact', $output);
        $this->assertStringContainsString('Next action shell packet', $output);
        $this->assertStringContainsString('Next action exact command', $output);
        $this->assertStringContainsString('Release dossier ready', $output);
        $this->assertStringContainsString('Release dossier refresh required', $output);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', $output);
        $this->assertStringContainsString('Terminal proof ready', $output);
        $this->assertStringContainsString('Completion audit green', $output);
        $this->assertStringContainsString('Completion evidence command', $output);
        $this->assertStringContainsString('--atlas-self-construction-os-completion-evidence-status', $output);
        $this->assertStringContainsString('Operator readiness command', $output);
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-submission-readiness-status', $output);
        $this->assertStringContainsString('Canonical audit command', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json', $output);
        $this->assertStringContainsString('Closure artifact sequence:', $output);
        $this->assertStringContainsString('runtime_promotion_receipt', $output);
        $this->assertStringContainsString('real_provider_smoke', $output);
        $this->assertStringContainsString('human_completion_receipt', $output);
        $this->assertStringContainsString('Final verification sequence:', $output);
        $this->assertStringContainsString('rerun_completion_audit_with_terminal_loop_operational_proof_binding', $output);
    }

    private function bundle(): AtlasSelfConstructionFinalEvidenceBundleService
    {
        return new AtlasSelfConstructionFinalEvidenceBundleService(app(AtlasSelfConstructionReadinessService::class));
    }

    /** @param array<string, mixed> $overrides */
    private function baseOptions(array $overrides = []): array
    {
        $hash = str_repeat('a', 64);
        $runtimeAllY = (bool) ($overrides['runtime_all_y'] ?? false);
        $humanReceiptPassed = (bool) ($overrides['human_receipt_passed'] ?? false);
        $realProviderSmokePassed = (bool) ($overrides['real_provider_smoke_passed'] ?? false);
        $completionAuditComplete = (bool) ($overrides['completion_audit_complete'] ?? false);
        $failedCriteria = (array) ($overrides['failed_criteria'] ?? [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]);

        return [
            'runtime_gap_matrix' => [
                'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
                'status' => $runtimeAllY ? 'passed' : 'blocked',
                'all_runtime_y' => $runtimeAllY,
                'runtime_gap_matrix_hash' => $hash,
                'expected_runtime_gap_matrix_hash_for_promotion_receipt' => str_repeat('b', 64),
                'runtime_promotion_basis_hash' => str_repeat('c', 64),
                'runtime_promotion_closure_basis_hash' => str_repeat('d', 64),
                'runtime_gap_count' => $runtimeAllY ? 0 : 3,
                'blocked_gap_ids' => $runtimeAllY ? [] : [
                    'adapter_execution_runtime',
                    'automatic_cost_import_runtime',
                    'automatic_work_product_collection_runtime',
                ],
            ],
            'human_receipt' => [
                'schema_version' => 'atlas.self_construction.human_signed_completion_receipt.v1',
                'status' => $humanReceiptPassed ? 'passed' : 'blocked_missing_operator_receipt',
                'completion_claim_allowed' => $humanReceiptPassed,
            ],
            'real_provider_smoke_result' => [
                'schema_version' => 'atlas.self_construction.real_provider_smoke_certification.v1',
                'status' => $realProviderSmokePassed ? 'passed' : 'blocked_missing_real_provider_smoke',
                'completion_criterion_green' => $realProviderSmokePassed,
            ],
            'operator_action_packet' => [
                'schema_version' => 'atlas.self_construction.completion_operator_action_packet.v1',
                'status' => $failedCriteria === [] ? 'ready_for_operator_final_review' : 'operator_action_required',
                'missing_operator_artifacts' => $failedCriteria,
            ],
            'completion_audit' => [
                'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
                'status' => $completionAuditComplete ? 'complete' : 'incomplete',
                'completion_allowed' => $completionAuditComplete,
                'agent_control_plane_terminal_loop_operational_proof_evidence' => [
                    'status' => ((bool) ($overrides['terminal_loop_operational_proof_passed'] ?? false)) ? 'passed' : 'not_supplied_to_read_only_audit',
                    'passed' => (bool) ($overrides['terminal_loop_operational_proof_passed'] ?? false),
                ],
                'failed_criteria' => $failedCriteria,
                'criteria' => [
                    ['id' => 'release_dossier_green', 'passed' => true],
                ],
            ],
        ];
    }
}
