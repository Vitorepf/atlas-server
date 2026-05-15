<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalCompletionReadinessGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
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
