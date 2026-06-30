<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Tests\TestCase;

/**
 * Proves AtlasSelfConstructionFinalCompletionReadinessGateService::evaluate() exposes a
 * blocker_action_map: every active blocker (completion audit, material evidence hashes,
 * terminal loop proof, human receipt, runtime, real provider smoke) maps to a concrete
 * command_or_evidence_hint, never a bare "incomplete" with no path forward.
 */
final class AtlasSelfConstructionFinalCompletionReadinessGateServiceTest extends TestCase
{
    public function test_complete_payload_has_empty_blocker_action_map(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('complete', $gate['status']);
        $this->assertSame([], $gate['blocker_action_map']);
    }

    public function test_incomplete_default_state_has_action_hint_for_every_blocker(): void
    {
        $gate = $this->gate()->evaluate();

        $this->assertSame('incomplete', $gate['status']);
        $this->assertNotEmpty($gate['blocker_action_map']);
        foreach (array_merge((array) $gate['blockers'], (array) $gate['next_stage_blocked_by']) as $blocker) {
            $this->assertArrayHasKey($blocker, $gate['blocker_action_map'], "missing action map entry for blocker: {$blocker}");
            $this->assertNotEmpty((string) $gate['blocker_action_map'][$blocker]['command_or_evidence_hint']);
            $this->assertNotEmpty((string) $gate['blocker_action_map'][$blocker]['evidence_family']);
        }
    }

    public function test_completion_audit_not_complete_maps_to_rerun_audit_command(): void
    {
        $gate = $this->gate()->evaluate();

        $hint = $gate['blocker_action_map']['completion_audit_not_status_complete'];
        $this->assertStringContainsString('atlas:ai:self-construction', $hint['command_or_evidence_hint']);
        $this->assertSame('completion_audit', $hint['evidence_family']);
    }

    public function test_material_evidence_hash_gap_maps_to_named_missing_fields(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true, includeMaterialEvidence: false);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $hint = $gate['blocker_action_map']['material_completion_evidence_hashes_missing_or_invalid'];
        $this->assertStringContainsString('sha256', $hint['command_or_evidence_hint']);
        $this->assertSame('material_completion_evidence', $hint['evidence_family']);
    }

    public function test_terminal_loop_proof_gap_maps_to_persist_binding_command(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true, includeTerminalLoopProof: false);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $hint = $gate['blocker_action_map']['terminal_loop_operational_proof_binding_missing_or_invalid'];
        $this->assertStringContainsString('atlas:ai:self-construction', $hint['command_or_evidence_hint']);
        $this->assertSame('agent_control_plane_terminal_loop_operational_proof', $hint['evidence_family']);
    }

    public function test_human_receipt_gap_maps_to_human_evidence_family(): void
    {
        $audit = $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $hint = $gate['blocker_action_map']['human_signed_os_complete_receipt_present'];
        $this->assertSame('human_signed_completion_receipt', $hint['evidence_family']);
        $this->assertNotEmpty($hint['command_or_evidence_hint']);
    }

    public function test_runtime_gap_maps_to_runtime_promotion_evidence_family(): void
    {
        $audit = $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y', 'human_signed_os_complete_receipt_present']);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $hint = $gate['blocker_action_map']['runtime_gap_matrix_all_runtime_y'];
        $this->assertSame('runtime_promotion_receipt', $hint['evidence_family']);
        $this->assertNotEmpty($hint['command_or_evidence_hint']);
    }

    public function test_real_provider_smoke_gap_maps_to_smoke_evidence_family(): void
    {
        $audit = $this->auditAllPassedExcept(['end_to_end_real_provider_smoke_green', 'human_signed_os_complete_receipt_present']);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $hint = $gate['blocker_action_map']['end_to_end_real_provider_smoke_green'];
        $this->assertSame('real_provider_smoke', $hint['evidence_family']);
        $this->assertNotEmpty($hint['command_or_evidence_hint']);
    }

    private function gate(): AtlasSelfConstructionFinalCompletionReadinessGateService
    {
        return new AtlasSelfConstructionFinalCompletionReadinessGateService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /**
     * @param  list<string>  $failed
     * @return array<string, mixed>
     */
    private function auditAllPassedExcept(array $failed, bool $complete = false, bool $includeMaterialEvidence = true, bool $includeTerminalLoopProof = true): array
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
        ];
        $criteria = [];
        foreach ($ids as $id) {
            $evidence = [];
            if ($includeMaterialEvidence) {
                $evidence = match ($id) {
                    'runtime_gap_matrix_all_runtime_y' => [
                        'runtime_gap_matrix_hash' => $hash,
                        'runtime_promotion_receipt_hash' => $hash,
                    ],
                    'human_signed_os_complete_receipt_present' => [
                        'receipt_hash' => $hash,
                    ],
                    'end_to_end_real_provider_smoke_green' => [
                        'smoke_hash' => $hash,
                    ],
                    'release_dossier_green' => [
                        'hash' => $hash,
                    ],
                    'replay_diff_against_completion_snapshot_green' => [
                        'diff_hash' => $hash,
                    ],
                    'certification_status_batch_green' => [
                        'hash' => $hash,
                    ],
                    default => [],
                };
            }
            $criteria[] = ['id' => $id, 'passed' => ! in_array($id, $failed, true), 'evidence' => $evidence];
        }

        $audit = [
            'status' => $failed === [] && $complete ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($ids) - count($failed),
            'completion_allowed' => $failed === [] && $complete,
            'completion_claim_allowed' => $failed === [] && $complete,
            'criteria' => $criteria,
        ];

        if ($includeTerminalLoopProof) {
            $audit['agent_control_plane_terminal_loop_operational_proof_evidence'] = [
                'status' => 'passed',
                'supplied' => true,
                'passed' => true,
                'proof_hash' => $hash,
                'validation_violation_count' => 0,
                'post_cycle_cleanup_state' => [
                    'claimed_task_count' => 0,
                    'active_lease_count' => 0,
                    'recoverable_lease_count' => 0,
                ],
                'dispatch_allowed' => false,
                'adapter_execution_allowed' => false,
                'self_programming_allowed' => false,
            ];
        }

        return $audit;
    }
}
