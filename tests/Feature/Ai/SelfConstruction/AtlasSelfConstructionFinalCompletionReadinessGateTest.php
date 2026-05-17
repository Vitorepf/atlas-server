<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

final class AtlasSelfConstructionFinalCompletionReadinessGateTest extends TestCase
{
    public function test_gate_is_incomplete_in_current_real_state(): void
    {
        $gate = $this->gate()->evaluate();

        $this->assertSame('atlas.self_construction.final_completion_readiness_gate.v1', $gate['schema_version']);
        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
        $this->assertSame('', (string) $gate['next_stage_name']);
        $this->assertSame('blocked', (string) $gate['self_programming_os_transition_status']);
        $this->assertContains('self_construction_os_not_complete', (array) $gate['self_programming_os_transition_blockers']);
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.runtime_activation_allowed'));
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.self_programming_allowed'));
        $this->assertGreaterThan(0, (int) $gate['blocker_count']);
        $this->assertContains('final_completion_readiness_gate_does_not_promote_completion', (array) $gate['non_execution_guarantees']);
    }

    public function test_gate_returns_complete_candidate_when_only_human_receipt_missing(): void
    {
        $audit = $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('complete_candidate', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertContains('human_signed_os_complete_receipt_present', (array) $gate['blockers']);
    }

    public function test_gate_returns_incomplete_when_runtime_or_smoke_missing(): void
    {
        $auditRuntimeMissing = $this->auditAllPassedExcept(['runtime_gap_matrix_all_runtime_y', 'human_signed_os_complete_receipt_present']);
        $gateRuntime = $this->gate()->evaluate(['completion_audit' => $auditRuntimeMissing]);
        $this->assertSame('incomplete', $gateRuntime['status']);
        $this->assertFalse((bool) $gateRuntime['runtime_green']);

        $auditSmokeMissing = $this->auditAllPassedExcept(['end_to_end_real_provider_smoke_green', 'human_signed_os_complete_receipt_present']);
        $gateSmoke = $this->gate()->evaluate(['completion_audit' => $auditSmokeMissing]);
        $this->assertSame('incomplete', $gateSmoke['status']);
        $this->assertFalse((bool) $gateSmoke['smoke_green']);
    }

    public function test_gate_returns_complete_only_when_audit_complete_and_all_criteria_passed(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('complete', $gate['status']);
        $this->assertTrue((bool) $gate['completion_allowed']);
        $this->assertTrue((bool) $gate['completion_claim_allowed']);
        $this->assertTrue((bool) $gate['next_stage_allowed']);
        $this->assertTrue((bool) $gate['material_completion_evidence_green']);
        $this->assertSame('Atlas Self-Programming OS', (string) $gate['next_stage_name']);
        $this->assertSame([], (array) $gate['next_stage_blocked_by']);
        $this->assertSame('ready_for_safety_contract_design', (string) $gate['self_programming_os_transition_status']);
        $this->assertSame([], (array) $gate['self_programming_os_transition_blockers']);
        $this->assertTrue((bool) data_get($gate, 'self_programming_os_transition_readiness.contract_design_allowed'));
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.runtime_activation_allowed'));
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.self_programming_allowed'));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $gate['self_programming_safety_contract_hash']);
    }

    public function test_gate_blocks_complete_claim_when_complete_audit_lacks_material_hashes(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true, includeMaterialEvidence: false);
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
        $this->assertFalse((bool) $gate['material_completion_evidence_green']);
        $this->assertContains('material_completion_evidence_hashes_missing_or_invalid', (array) $gate['next_stage_blocked_by']);
    }

    public function test_gate_blocks_next_stage_when_completion_allowed_false_in_audit(): void
    {
        $audit = $this->auditAllPassedExcept([], complete: true);
        $audit['completion_allowed'] = false;
        $gate = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame('incomplete', $gate['status']);
        $this->assertFalse((bool) $gate['completion_allowed']);
        $this->assertFalse((bool) $gate['completion_claim_allowed']);
        $this->assertFalse((bool) $gate['next_stage_allowed']);
    }

    public function test_gate_never_persists_or_promotes_completion(): void
    {
        $gate = $this->gate()->evaluate();

        $this->assertFalse((bool) $gate['execution_allowed']);
        $this->assertFalse((bool) $gate['dispatch_allowed']);
        $this->assertFalse((bool) $gate['provider_call_allowed']);
        $this->assertFalse((bool) $gate['token_spend_allowed']);
        $this->assertFalse((bool) $gate['adapter_execution_allowed']);
        $this->assertFalse((bool) $gate['self_programming_allowed']);
        $this->assertContains('final_completion_readiness_gate_does_not_persist_evidence', (array) $gate['non_execution_guarantees']);
        $this->assertFalse((bool) data_get($gate, 'self_programming_os_transition_readiness.self_programming_allowed'));
        $this->assertContains('self_programming_transition_readiness_does_not_enable_self_programming', (array) data_get($gate, 'self_programming_os_transition_readiness.non_execution_guarantees'));
    }

    public function test_gate_hash_is_deterministic_with_fabricated_audit(): void
    {
        $audit = $this->auditAllPassedExcept(['human_signed_os_complete_receipt_present']);
        $first = $this->gate()->evaluate(['completion_audit' => $audit]);
        $second = $this->gate()->evaluate(['completion_audit' => $audit]);

        $this->assertSame($first['gate_hash'], $second['gate_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $first['gate_hash']);
    }

    public function test_gate_payload_is_json_serializable(): void
    {
        $gate = $this->gate()->evaluate();
        $encoded = json_encode($gate, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    public function test_gate_exposes_command_to_rerun_audit(): void
    {
        $gate = $this->gate()->evaluate();
        $this->assertStringContainsString('atlas:ai:self-construction', (string) $gate['command_to_rerun_audit']);
        $this->assertStringContainsString('completion-audit-status', (string) $gate['command_to_rerun_audit']);
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $gate['command_to_refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $gate['command_to_rerun_audit_with_terminal_loop_operational_proof']);
        $this->assertTrue((bool) $gate['terminal_loop_operational_proof_required_before_completion_claim']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            $gate['terminal_loop_operational_proof_expected_binding_schema'],
        );
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.completion_claim_requires_terminal_loop_operational_proof_binding'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.self_programming_transition_requires_self_construction_complete'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.self_programming_transition_requires_safety_contract'));
        $this->assertTrue((bool) data_get($gate, 'safety_invariants.self_programming_transition_does_not_enable_runtime'));
    }

    public function test_status_projection_exposes_terminal_loop_operational_proof_commands(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfConstructionFinalCompletionReadinessGateStatus();

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_construction_final_completion_readiness_gate_status', []);

        $this->assertTrue((bool) $summary['terminal_loop_operational_proof_required_before_completion_claim']);
        $this->assertSame(
            'atlas.self_construction.agent_control_plane_terminal_loop_operational_proof_audit_binding_packet.v1',
            $summary['terminal_loop_operational_proof_expected_binding_schema'],
        );
        $this->assertStringContainsString('terminal-loop-operational-proof-status', (string) $summary['command_to_refresh_terminal_loop_operational_proof']);
        $this->assertStringContainsString('--agent-control-plane-terminal-loop-operational-proof-json=', (string) $summary['command_to_rerun_audit_with_terminal_loop_operational_proof']);
        $this->assertSame('blocked', (string) $summary['self_programming_os_transition_status']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['self_programming_os_transition_blockers']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['self_programming_safety_contract_hash']);
        $this->assertFalse((bool) $summary['self_programming_runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
    }

    public function test_self_programming_os_transition_readiness_projection_is_blocked_until_self_construction_complete(): void
    {
        $status = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->atlasSelfProgrammingOsTransitionReadinessStatus();

        $summary = (array) data_get($status, 'agent_control_plane_atlas_self_programming_os_transition_readiness_status', []);

        $this->assertSame('atlas.self_construction_agent_control_plane_atlas_self_programming_os_transition_readiness_status.v1', $status['schema_version']);
        $this->assertSame('blocked', $status['status']);
        $this->assertSame('blocked', $summary['status']);
        $this->assertSame('Atlas Self-Programming OS', $summary['next_stage_name']);
        $this->assertFalse((bool) $summary['self_construction_complete']);
        $this->assertFalse((bool) $summary['contract_design_allowed']);
        $this->assertFalse((bool) $summary['runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
        $this->assertFalse((bool) $summary['provider_call_allowed']);
        $this->assertFalse((bool) $summary['token_spend_allowed']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['blockers']);
        $this->assertSame('docs/engineering-knowledge-base/self-construction/self-programming-safety-contract.md', $summary['safety_contract_path']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['safety_contract_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', (string) $summary['source_final_completion_readiness_gate_hash']);
    }

    public function test_cli_exposes_self_programming_os_transition_readiness_quartet(): void
    {
        foreach ([
            '--atlas-self-programming-os-transition-readiness-contract',
            '--atlas-self-programming-os-transition-readiness-preflight',
            '--atlas-self-programming-os-transition-readiness-implementation-packet',
        ] as $option) {
            Artisan::call('atlas:ai:self-construction', [
                $option => true,
                '--json' => true,
            ]);

            $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            $this->assertStringContainsString('atlas.self_programming.transition_readiness.v1', json_encode($payload, JSON_THROW_ON_ERROR));
            $this->assertFalse((bool) $payload['execution_allowed']);
            $this->assertFalse((bool) $payload['dispatch_allowed']);
        }

        Artisan::call('atlas:ai:self-construction', [
            '--atlas-self-programming-os-transition-readiness-status' => true,
            '--json' => true,
        ]);

        $payload = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        $summary = (array) data_get($payload, 'agent_control_plane_atlas_self_programming_os_transition_readiness_status', []);

        $this->assertSame('blocked', $payload['status']);
        $this->assertSame('blocked', $summary['status']);
        $this->assertFalse((bool) $summary['runtime_activation_allowed']);
        $this->assertFalse((bool) $summary['self_programming_allowed']);
        $this->assertContains('self_construction_os_not_complete', (array) $summary['blockers']);
    }

    public function test_agent_control_plane_lists_self_programming_transition_readiness_capabilities(): void
    {
        $payload = (new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository))
            ->agentControlPlane();
        $capabilities = (array) data_get($payload, 'control_plane.current_capability', []);

        $this->assertContains('atlas_self_programming_os_transition_readiness_contract', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_preflight', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_implementation_packet', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_service', $capabilities);
        $this->assertContains('atlas_self_programming_os_transition_readiness_status_projection', $capabilities);
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
    private function auditAllPassedExcept(array $failed, bool $complete = false, bool $includeMaterialEvidence = true): array
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

        return [
            'status' => $failed === [] && $complete ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($ids) - count($failed),
            'completion_allowed' => $failed === [] && $complete,
            'completion_claim_allowed' => $failed === [] && $complete,
            'criteria' => $criteria,
        ];
    }
}
