<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionFinalizationGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionFinalizationGateTest extends TestCase
{
    public function test_finalization_gate_is_blocked_in_current_real_state(): void
    {
        $gate = $this->service()->evaluate();

        $this->assertSame('atlas.self_construction.completion_finalization_gate.v1', $gate['schema_version']);
        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
        $this->assertNotEmpty((array) $gate['next_stage_blockers']);
        $this->assertGreaterThan(0, count((array) $gate['failed_check_ids']));
        $this->assertNotEmpty((string) $gate['completion_evidence_status_hash']);
        $this->assertSame(
            'atlas.self_construction.completion_finalization_operator_handoff.v1',
            data_get($gate, 'completion_finalization_operator_handoff.schema_version'),
        );
        $this->assertSame(
            'blocked_operator_or_provider_evidence_required',
            data_get($gate, 'completion_finalization_operator_handoff.status'),
        );
        $this->assertContains(
            data_get($gate, 'completion_finalization_operator_handoff.current_required_operator_artifact'),
            ['runtime_promotion_receipt', 'real_provider_smoke_certification', 'human_signed_completion_receipt', 'technical_completion_audit_repair', 'completion_finalization_gate_repair'],
        );
        $this->assertFalse((bool) data_get($gate, 'completion_finalization_operator_handoff.can_execute_from_handoff'));
        $this->assertFalse((bool) data_get($gate, 'completion_finalization_operator_handoff.can_persist_from_handoff'));
        $this->assertFalse((bool) data_get($gate, 'completion_finalization_operator_handoff.can_promote_completion_from_handoff'));
        $this->assertSame(
            'atlas.self_construction.completion_finalization_next_action_shell_packet.v1',
            data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.schema_version'),
        );
        $this->assertSame(
            'copy_ready_after_fresh_status_review',
            data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.status'),
        );
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-submission-readiness-status',
            (string) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.exact_command'),
        );
        $this->assertSame(0, (int) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.placeholder_count'));
        $this->assertTrue((bool) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.copy_safe'));
        $this->assertTrue((bool) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.requires_fresh_finalization_gate_status_before_copy'));
        $this->assertTrue((bool) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.can_resume_without_chat_history'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.shell_packet_hash'),
        );
        $this->assertTrue((bool) data_get($gate, 'terminal_loop_operational_proof_required_before_completion_claim'));
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) data_get($gate, 'command_to_refresh_terminal_loop_operational_proof'));
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) data_get($gate, 'command_to_persist_terminal_loop_operational_proof_binding'));
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', (string) data_get($gate, 'command_to_capture_snapshot_after_terminal_loop_operational_proof'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) data_get($gate, 'command_to_rerun_audit_with_terminal_loop_operational_proof'));
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) data_get($gate, 'completion_finalization_operator_handoff.terminal_loop_operational_proof_command'));
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', (string) data_get($gate, 'completion_finalization_operator_handoff.terminal_loop_operational_proof_binding_persist_command'));
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', (string) data_get($gate, 'completion_finalization_operator_handoff.capture_snapshot_after_terminal_loop_operational_proof_command'));
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) data_get($gate, 'completion_finalization_operator_handoff.completion_audit_with_terminal_loop_operational_proof_command'));
        $this->assertSame([
            'refresh_terminal_loop_operational_proof_and_export_binding',
            'capture_replay_snapshot_after_terminal_loop_operational_proof',
            'rerun_completion_audit_with_terminal_loop_operational_proof_binding',
        ], array_column((array) data_get($gate, 'final_verification_sequence', []), 'id'));
        $this->assertSame(
            'capture_replay_snapshot_after_terminal_loop_operational_proof',
            data_get($gate, 'completion_finalization_operator_handoff.final_verification_sequence.1.id'),
        );
        $this->assertTrue((bool) data_get($gate, 'completion_audit_green_requires_current_snapshot_after_terminal_loop_proof'));
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($gate, 'completion_finalization_operator_handoff.completion_finalization_operator_handoff_hash'),
        );
        $this->assertMatchesRegularExpression(
            '/^[a-f0-9]{64}$/',
            (string) data_get($gate, 'checks.evidence_hashes_match_completion_audit.evidence.actual_runtime_gap_matrix_hash'),
        );
        $this->assertNotSame('', (string) data_get($gate, 'checks.runtime_all_y.evidence.runtime_gap_matrix_status'));
    }

    public function test_finalization_gate_returns_completion_claim_allowed_false_with_incomplete_audit(): void
    {
        $gate = $this->service()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']),
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['human_receipt_green']);
        $this->assertContains('finalization_gate_blocked_by_human_receipt_green', (array) $gate['next_stage_blockers']);
    }

    public function test_finalization_gate_returns_completion_claim_allowed_true_only_when_every_criterion_passed_in_test_audit(): void
    {
        $gate = $this->service()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept([], withOperationalProof: true),
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame('passed', $gate['status']);
        $this->assertTrue((bool) $gate['completion_claim_allowed']);
        $this->assertTrue((bool) $gate['next_stage_allowed']);
        $this->assertTrue((bool) $gate['evidence_hashes_match_completion_audit']);
        $this->assertSame([], (array) $gate['next_stage_blockers']);
        $this->assertSame([], (array) $gate['failed_check_ids']);
        $this->assertSame(
            'ready_for_final_operator_review',
            data_get($gate, 'completion_finalization_operator_handoff.status'),
        );
        $this->assertSame(
            'final_operator_review',
            data_get($gate, 'completion_finalization_operator_handoff.current_required_operator_artifact'),
        );
        $this->assertTrue((bool) data_get($gate, 'completion_finalization_operator_handoff.required_success_predicate.completion_claim_allowed_must_be_true'));
        $this->assertTrue((bool) data_get($gate, 'completion_finalization_operator_handoff.required_success_predicate.terminal_loop_operational_proof_must_be_bound_and_passed'));
        $this->assertTrue((bool) data_get($gate, 'completion_finalization_operator_handoff.required_success_predicate.release_snapshot_must_be_current_after_terminal_loop_operational_proof'));
        $this->assertStringContainsString(
            '--atlas-self-construction-completion-finalization-gate-status',
            (string) data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.exact_command'),
        );
        $this->assertSame(
            'completion_finalization_gate.status=passed AND completion_claim_allowed=true AND next_stage_allowed=true',
            data_get($gate, 'completion_finalization_operator_handoff.next_action_shell_packet.success_check'),
        );
    }

    public function test_finalization_gate_blocks_green_audit_without_terminal_loop_operational_proof_binding(): void
    {
        $gate = $this->service()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept([]),
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['terminal_loop_green']);
        $this->assertContains('finalization_gate_blocked_by_terminal_loop_green', (array) $gate['next_stage_blockers']);
        $this->assertSame('not_supplied_to_read_only_audit', data_get($gate, 'checks.terminal_loop_green.evidence.operational_proof_status'));
    }

    public function test_finalization_gate_blocks_green_audit_with_weak_terminal_loop_operational_proof_binding(): void
    {
        $audit = $this->auditAllPassedExcept([], withOperationalProof: true);
        $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['post_cycle_cleanup_state']['active_lease_count'] = 1;

        $gate = $this->service()->evaluate([
            'completion_audit' => $audit,
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['terminal_loop_green']);
        $this->assertContains('finalization_gate_blocked_by_terminal_loop_green', (array) $gate['next_stage_blockers']);
        $this->assertSame(1, data_get($gate, 'checks.terminal_loop_green.evidence.post_cycle_cleanup_state.active_lease_count'));
    }

    public function test_finalization_gate_blocks_green_audit_with_legacy_terminal_loop_proof_without_end_to_end_contract(): void
    {
        $audit = $this->auditAllPassedExcept([], withOperationalProof: true);
        unset(
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['post_cycle_end_to_end_contract_status'],
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['post_cycle_end_to_end_contract_all_required_surfaces_present'],
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['post_cycle_end_to_end_contract_failed_check_ids'],
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['post_cycle_end_to_end_contract_missing_required_capabilities'],
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence']['post_cycle_end_to_end_contract_hash'],
        );

        $gate = $this->service()->evaluate([
            'completion_audit' => $audit,
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['terminal_loop_green']);
        $this->assertContains('finalization_gate_blocked_by_terminal_loop_green', (array) $gate['next_stage_blockers']);
        $this->assertSame('', data_get($gate, 'checks.terminal_loop_green.evidence.post_cycle_end_to_end_contract_status'));
    }

    public function test_finalization_gate_status_projection_accepts_terminal_loop_operational_proof_json(): void
    {
        $proof = [
            'schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            'proof_payload' => [
                'status' => 'passed',
                'invariants_all_true' => true,
                'operational_readiness_matrix' => ['all_true' => true],
                'terminal_loop_operational_proof_hash' => str_repeat('a', 64),
                'post_cycle_cycle_supervisor' => [
                    'status' => 'cycle_evidence_review_ready',
                    'cycle_state' => 'review_evidence',
                    'next_command_purpose' => 'review_completed_dry_run_evidence_and_rerun_digest',
                    'hash' => str_repeat('b', 64),
                ],
                'post_cycle_cleanup_state' => [
                    'claimed_task_count' => 0,
                    'active_lease_count' => 0,
                    'recoverable_lease_count' => 0,
                ],
                'post_cycle_end_to_end_contract' => [
                    'status' => 'terminal_loop_end_to_end_contract_available',
                    'all_required_surfaces_present' => true,
                    'covered_capabilities' => [
                        'auto_replenishment',
                        'validation',
                        'leases',
                        'evidence',
                        'retomada',
                        'lane_isolation',
                        'cycle_supervision',
                        'operator_handoff',
                    ],
                    'failed_check_ids' => [],
                    'hash' => str_repeat('c', 64),
                ],
                'completion_real_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ],
        ];

        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionCompletionFinalizationGateStatus([
                'agent_control_plane_terminal_loop_operational_proof_json' => json_encode($proof, JSON_THROW_ON_ERROR),
            ]);

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_completion_finalization_gate_status', []);

        $this->assertSame('blocked', $status['status']);
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_green']);
        $this->assertSame('passed', $summary['terminal_loop_operational_proof_status']);
        $this->assertSame(str_repeat('a', 64), $summary['terminal_loop_operational_proof_hash']);
        $this->assertStringContainsString(
            '--atlas-self-construction-operator-evidence-submission-readiness-status',
            (string) $summary['completion_finalization_operator_handoff_operator_evidence_readiness_command'],
        );
        $this->assertGreaterThanOrEqual(3, (int) $summary['completion_audit_failed_count']);
        $this->assertSame(
            $summary['completion_audit_failed_criteria'],
            $summary['failed_criteria'],
        );
        $this->assertSame(
            (int) $summary['completion_audit_failed_count'],
            (int) $summary['failed_count'],
        );
        $this->assertContains(
            (string) $summary['current_required_operator_artifact'],
            [
                'runtime_promotion_receipt',
                'real_provider_smoke_certification',
                'human_signed_completion_receipt',
                'technical_completion_audit_repair',
                'completion_finalization_gate_repair',
            ],
        );
        $this->assertContainsOnlyString((array) $summary['human_blockers']);
        $this->assertSame(count((array) $summary['human_blockers']), (int) $summary['human_blocker_count']);
        $this->assertEmpty(array_diff((array) $summary['human_blockers'], [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ]));
        $this->assertContainsOnlyString((array) $summary['real_provider_blockers']);
        $this->assertSame(count((array) $summary['real_provider_blockers']), (int) $summary['real_provider_blocker_count']);
        $this->assertEmpty(array_diff((array) $summary['real_provider_blockers'], [
            'end_to_end_real_provider_smoke_green',
        ]));
        $this->assertContainsOnlyString((array) $summary['technical_blockers']);
        $this->assertSame(count((array) $summary['technical_blockers']), (int) $summary['technical_blocker_count']);
        $this->assertGreaterThanOrEqual(1, (int) $summary['next_stage_blocker_count']);
        $this->assertGreaterThanOrEqual(6, (int) $summary['completion_finalization_operator_handoff_ordered_next_command_count']);
        $this->assertSame(3, (int) $summary['completion_finalization_operator_handoff_final_verification_sequence_count']);
        $this->assertSame(4, (int) $summary['closure_artifact_sequence_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['closure_artifact_sequence_hash']);
        $closureSequence = collect((array) $summary['closure_artifact_sequence'])->keyBy('artifact');
        $this->assertSame('runtime_gap_matrix_all_runtime_y', $closureSequence['runtime_promotion_receipt']['requirement']);
        $this->assertSame('end_to_end_real_provider_smoke_green', $closureSequence['real_provider_smoke']['requirement']);
        $this->assertSame('human_signed_os_complete_receipt_present', $closureSequence['human_completion_receipt']['requirement']);
        $this->assertSame('completion_audit_authorizes_completion_claim', $closureSequence['final_completion_audit']['requirement']);
        $this->assertSame(4, (int) $summary['prompt_to_artifact_checklist_count']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['prompt_to_artifact_checklist_hash']);
        $checklist = collect((array) $summary['prompt_to_artifact_checklist'])->keyBy('requirement');
        $this->assertSame('runtime_promotion_receipt', $checklist['runtime_gap_matrix_all_runtime_y']['artifact']);
        $this->assertSame('real_provider_smoke', $checklist['end_to_end_real_provider_smoke_green']['artifact']);
        $this->assertSame('human_completion_receipt', $checklist['human_signed_os_complete_receipt_present']['artifact']);
        $this->assertSame('final_completion_audit', $checklist['completion_audit_authorizes_completion_claim']['artifact']);
        $this->assertSame('terminal_loop_end_to_end_contract_available', $summary['terminal_loop_operational_proof_post_cycle_end_to_end_contract_status']);
        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_post_cycle_end_to_end_contract_all_required_surfaces_present']);
        $this->assertSame([], $summary['terminal_loop_operational_proof_post_cycle_end_to_end_contract_missing_required_capabilities']);
        $this->assertSame(str_repeat('c', 64), $summary['terminal_loop_operational_proof_post_cycle_end_to_end_contract_hash']);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', (string) $summary['command_to_capture_snapshot_after_terminal_loop_operational_proof']);
        $this->assertSame(3, (int) $summary['final_verification_sequence_step_count']);
        $this->assertSame('capture_replay_snapshot_after_terminal_loop_operational_proof', $summary['final_verification_sequence'][1]['id']);
        $this->assertTrue((bool) $summary['completion_audit_green_requires_current_snapshot_after_terminal_loop_proof']);
        $this->assertSame('copy_ready_after_fresh_status_review', $summary['completion_finalization_next_action_shell_packet_status']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $summary['completion_finalization_next_action_shell_packet_hash']);
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-submission-readiness-status', $summary['completion_finalization_next_action_exact_command']);
        $this->assertSame(0, (int) $summary['completion_finalization_next_action_placeholder_count']);
        $this->assertTrue((bool) $summary['completion_finalization_next_action_copy_safe']);
        $this->assertSame(
            (string) $summary['current_required_operator_artifact'],
            (string) data_get($summary, 'completion_finalization_next_action_shell_packet.current_required_operator_artifact'),
        );
    }

    public function test_finalization_gate_status_projection_mirrors_completion_claim_when_all_evidence_is_green(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionCompletionFinalizationGateStatus([
                'completion_audit' => $this->auditAllPassedExcept([], withOperationalProof: true),
                'completion_evidence' => $this->evidenceAllGreen(),
            ]);

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_completion_finalization_gate_status', []);

        $this->assertSame('passed', $status['status']);
        $this->assertFalse((bool) $summary['completion_allowed']);
        $this->assertTrue((bool) $summary['completion_claim_allowed']);
        $this->assertTrue((bool) $summary['next_stage_allowed']);
        $this->assertSame([], (array) $summary['failed_criteria']);
        $this->assertSame(0, (int) $summary['failed_count']);
        $this->assertSame('final_operator_review', (string) $summary['current_required_operator_artifact']);
        $this->assertSame([], (array) $summary['next_stage_blockers']);
        $this->assertSame(0, (int) $summary['next_stage_blocker_count']);
    }

    public function test_finalization_handoff_preserves_terminal_loop_proof_path_in_next_commands(): void
    {
        $reference = '@/tmp/terminal-loop-operational-proof-binding.json';
        $gate = $this->service()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y'], withOperationalProof: true),
            'completion_evidence' => $this->evidenceAllGreen(),
            'agent_control_plane_terminal_loop_operational_proof_json' => $reference,
        ]);

        $this->assertSame($reference, $gate['terminal_loop_operational_proof_json_reference']);
        $this->assertSame($reference, data_get($gate, 'completion_finalization_operator_handoff.terminal_loop_operational_proof_json_reference'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) $gate['command_to_rerun_audit_with_terminal_loop_operational_proof'],
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) data_get($gate, 'completion_finalization_operator_handoff.completion_audit_with_terminal_loop_operational_proof_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) data_get($gate, 'completion_finalization_operator_handoff.finalization_gate_command'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) data_get($gate, 'completion_finalization_operator_handoff.ordered_next_commands.4'),
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) data_get($gate, 'completion_finalization_operator_handoff.ordered_next_commands.5'),
        );
    }

    public function test_finalization_gate_uses_canonical_terminal_loop_binding_reference_when_persisted(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put(
            'atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json',
            json_encode(['schema_version' => 'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1'], JSON_THROW_ON_ERROR),
        );

        $reference = '@storage/app/private/atlas/self-construction/operator-submissions/terminal-loop-operational-proof-binding.json';
        $gate = $this->service()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y'], withOperationalProof: true),
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame($reference, $gate['terminal_loop_operational_proof_json_reference']);
        $this->assertSame($reference, data_get($gate, 'completion_finalization_operator_handoff.terminal_loop_operational_proof_json_reference'));
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) $gate['command_to_rerun_audit_with_terminal_loop_operational_proof'],
        );
        $this->assertStringContainsString(
            '--agent-control-plane-terminal-loop-operational-proof-json='.$reference,
            (string) data_get($gate, 'completion_finalization_operator_handoff.finalization_gate_command'),
        );
    }

    public function test_finalization_gate_blocks_when_green_audit_and_completion_evidence_hashes_drift(): void
    {
        $evidence = $this->evidenceAllGreen();
        $evidence['real_provider_smoke']['smoke_hash'] = str_repeat('b', 64);

        $gate = $this->service()->evaluate([
            'completion_audit' => $this->auditAllPassedExcept([]),
            'completion_evidence' => $evidence,
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['evidence_hashes_match_completion_audit']);
        $this->assertContains('finalization_gate_blocked_by_evidence_hashes_match_completion_audit', (array) $gate['next_stage_blockers']);
    }

    public function test_finalization_gate_blocks_when_terminal_loop_criterion_is_missing_or_not_green(): void
    {
        $audit = $this->auditAllPassedExcept([]);
        $audit['criteria'] = array_values(array_filter(
            $audit['criteria'],
            static fn (array $criterion): bool => (string) ($criterion['id'] ?? '') !== 'agent_control_plane_terminal_loop_certification_green',
        ));

        $gate = $this->service()->evaluate([
            'completion_audit' => $audit,
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame('blocked', $gate['status']);
        $this->assertFalse((bool) $gate['terminal_loop_green']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertContains('finalization_gate_blocked_by_terminal_loop_green', (array) $gate['next_stage_blockers']);
    }

    public function test_finalization_gate_never_mutates_or_promotes_completion(): void
    {
        $gate = $this->service()->evaluate();

        $this->assertFalse((bool) $gate['execution_allowed']);
        $this->assertFalse((bool) $gate['dispatch_allowed']);
        $this->assertFalse((bool) $gate['provider_call_allowed']);
        $this->assertFalse((bool) $gate['token_spend_allowed']);
        $this->assertFalse((bool) $gate['self_programming_allowed']);
        $this->assertFalse((bool) data_get($gate, 'completion_finalization_operator_handoff.can_call_provider_from_handoff'));
        $this->assertFalse((bool) data_get($gate, 'completion_finalization_operator_handoff.can_sign_from_handoff'));
        $this->assertContains('completion_finalization_gate_does_not_promote_completion', (array) $gate['non_execution_guarantees']);
        $this->assertContains('completion_finalization_gate_does_not_persist_receipts', (array) $gate['non_execution_guarantees']);
        $this->assertContains('handoff_does_not_promote_os_completion', (array) data_get($gate, 'completion_finalization_operator_handoff.non_execution_guarantees', []));
    }

    public function test_finalization_gate_hash_is_deterministic(): void
    {
        $first = $this->service()->evaluate(['completion_audit' => $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']), 'completion_evidence' => $this->evidenceAllGreen()]);
        $second = $this->service()->evaluate(['completion_audit' => $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']), 'completion_evidence' => $this->evidenceAllGreen()]);

        $this->assertSame($first['completion_finalization_gate_hash'], $second['completion_finalization_gate_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['completion_finalization_gate_hash']);
    }

    public function test_finalization_gate_payload_is_json_serializable(): void
    {
        $gate = $this->service()->evaluate();
        $encoded = json_encode($gate, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    public function test_completion_finalization_gate_human_output_exposes_operator_handoff(): void
    {
        $exit = Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-construction-completion-finalization-gate-status' => true,
        ]);
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Failed criteria', $output);
        $this->assertStringContainsString('Human blockers', $output);
        $this->assertStringContainsString('Real provider blockers', $output);
        $this->assertStringContainsString('Technical blockers', $output);
        $this->assertStringContainsString('Current required artifact', $output);
        $this->assertStringContainsString('Operator handoff status', $output);
        $this->assertStringContainsString('Operator handoff failed checks', $output);
        $this->assertStringContainsString('Can execute', $output);
        $this->assertStringContainsString('Can persist', $output);
        $this->assertStringContainsString('Can promote completion', $output);
        $this->assertStringContainsString('Operator readiness command', $output);
        $this->assertStringContainsString('--atlas-self-construction-operator-evidence-submission-readiness-status', $output);
        $this->assertStringContainsString('Terminal proof green', $output);
        $this->assertStringContainsString('Terminal proof source', $output);
        $this->assertStringContainsString('Refresh proof command', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-status', $output);
        $this->assertStringContainsString('Persist proof command', $output);
        $this->assertStringContainsString('--persist-terminal-loop-operational-proof-binding', $output);
        $this->assertStringContainsString('Snapshot command', $output);
        $this->assertStringContainsString('--agent-control-plane-replay-snapshot-store-capture', $output);
        $this->assertStringContainsString('Rerun audit command', $output);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', $output);
        $this->assertStringContainsString('Completion claim allowed', $output);
        $this->assertStringContainsString('Final verification sequence:', $output);
        $this->assertStringContainsString('refresh_terminal_loop_operational_proof_and_export_binding', $output);
        $this->assertStringContainsString('capture_replay_snapshot_after_terminal_loop_operational_proof', $output);
        $this->assertStringContainsString('rerun_completion_audit_with_terminal_loop_operational_proof_binding', $output);
    }

    private function service(): AtlasSelfConstructionCompletionFinalizationGateService
    {
        return new AtlasSelfConstructionCompletionFinalizationGateService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /**
     * @param  list<string>  $failed
     * @return array<string, mixed>
     */
    private function auditAllPassedExcept(array $failed, bool $withOperationalProof = false): array
    {
        $hash = str_repeat('a', 64);
        $ids = [
            'runtime_gap_matrix_all_runtime_y',
            'release_dossier_green',
            'replay_diff_against_completion_snapshot_green',
            'promotion_gate_green',
            'mutation_guard_green',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
            'forge_self_improvement_integration_smoke_green',
            'certification_status_batch_green',
            'agent_control_plane_terminal_loop_certification_green',
        ];
        $criteria = [];
        foreach ($ids as $id) {
            $evidence = match ($id) {
                'runtime_gap_matrix_all_runtime_y' => [
                    'runtime_gap_matrix_hash' => $hash,
                    'runtime_promotion_receipt_hash' => $hash,
                ],
                'end_to_end_real_provider_smoke_green' => [
                    'smoke_hash' => $hash,
                ],
                'human_signed_os_complete_receipt_present' => [
                    'receipt_hash' => $hash,
                ],
                default => [],
            };
            $criteria[] = ['id' => $id, 'passed' => ! in_array($id, $failed, true), 'evidence' => $evidence];
        }

        $audit = [
            'status' => $failed === [] ? 'complete' : 'incomplete',
            'completion_allowed' => $failed === [],
            'completion_claim_allowed' => $failed === [],
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($ids) - count($failed),
            'criteria' => $criteria,
        ];
        if ($withOperationalProof) {
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence'] = [
                'status' => 'passed',
                'supplied' => true,
                'passed' => true,
                'proof_hash' => $hash,
                'validation_violation_count' => 0,
                'post_cycle_cycle_supervisor_status' => 'cycle_evidence_review_ready',
                'post_cycle_end_to_end_contract_status' => 'terminal_loop_end_to_end_contract_available',
                'post_cycle_end_to_end_contract_all_required_surfaces_present' => true,
                'post_cycle_end_to_end_contract_failed_check_ids' => [],
                'post_cycle_end_to_end_contract_missing_required_capabilities' => [],
                'post_cycle_end_to_end_contract_hash' => $hash,
                'post_cycle_cleanup_state' => [
                    'claimed_task_count' => 0,
                    'active_lease_count' => 0,
                    'recoverable_lease_count' => 0,
                ],
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ];
        } else {
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence'] = [
                'status' => 'not_supplied_to_read_only_audit',
                'passed' => false,
            ];
        }

        return $audit;
    }

    /** @return array<string, mixed> */
    private function evidenceAllGreen(): array
    {
        $hash = str_repeat('a', 64);

        return [
            'runtime_gap_matrix' => [
                'status' => 'passed',
                'all_runtime_y' => true,
                'runtime_gap_matrix_hash' => $hash,
                'runtime_promotion_receipt' => ['status' => 'passed', 'receipt_hash' => $hash],
            ],
            'real_provider_smoke' => ['status' => 'passed', 'smoke_hash' => $hash],
            'human_signed_completion_receipt' => ['status' => 'passed', 'receipt_hash' => $hash, 'completion_claim_allowed' => true],
        ];
    }
}
