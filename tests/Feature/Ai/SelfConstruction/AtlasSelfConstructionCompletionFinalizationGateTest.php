<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionFinalizationGateService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
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
            'completion_audit' => $this->auditAllPassedExcept([]),
            'completion_evidence' => $this->evidenceAllGreen(),
        ]);

        $this->assertSame('passed', $gate['status']);
        $this->assertTrue((bool) $gate['completion_claim_allowed']);
        $this->assertTrue((bool) $gate['next_stage_allowed']);
        $this->assertTrue((bool) $gate['evidence_hashes_match_completion_audit']);
        $this->assertSame([], (array) $gate['next_stage_blockers']);
        $this->assertSame([], (array) $gate['failed_check_ids']);
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

    public function test_finalization_gate_never_mutates_or_promotes_completion(): void
    {
        $gate = $this->service()->evaluate();

        $this->assertFalse((bool) $gate['execution_allowed']);
        $this->assertFalse((bool) $gate['dispatch_allowed']);
        $this->assertFalse((bool) $gate['provider_call_allowed']);
        $this->assertFalse((bool) $gate['token_spend_allowed']);
        $this->assertFalse((bool) $gate['self_programming_allowed']);
        $this->assertContains('completion_finalization_gate_does_not_promote_completion', (array) $gate['non_execution_guarantees']);
        $this->assertContains('completion_finalization_gate_does_not_persist_receipts', (array) $gate['non_execution_guarantees']);
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
    private function auditAllPassedExcept(array $failed): array
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

        return [
            'status' => $failed === [] ? 'complete' : 'incomplete',
            'completion_allowed' => $failed === [],
            'completion_claim_allowed' => $failed === [],
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'failed_count' => count($failed),
            'passed_count' => count($ids) - count($failed),
            'criteria' => $criteria,
        ];
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
