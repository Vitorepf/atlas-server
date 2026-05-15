<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionAuditBlockerExplainerService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanSignedCompletionReceiptService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRealProviderSmokeCertificationService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionReceiptService;
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
        $this->assertSame(AtlasSelfConstructionHumanSignedCompletionReceiptService::class, data_get($payload, 'blockers.1.existing_service_that_validates_it'));
        $this->assertSame(AtlasSelfConstructionRealProviderSmokeCertificationService::class, data_get($payload, 'blockers.2.existing_service_that_validates_it'));
    }

    public function test_blocker_explainer_dependency_graph_uses_safe_closure_order(): void
    {
        $payload = $this->service()->build($this->audit(['runtime_gap_matrix_all_runtime_y']));

        $this->assertSame([
            'capture_fresh_snapshot_if_release_dossier_stale',
            'persist_runtime_promotion_receipt',
            'persist_real_provider_smoke_certification',
            'persist_human_completion_receipt',
            'rerun_completion_audit',
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
        $this->assertTrue($payload['machine_status']['closure_ready']);
        $this->assertFalse($payload['machine_status']['can_close_automatically']);
        $this->assertFalse($payload['completion_claim_allowed']);
    }

    public function test_blocker_explainer_command_plan_lists_required_commands(): void
    {
        $payload = $this->service()->build($this->audit(['human_signed_os_complete_receipt_present']));

        $this->assertArrayHasKey('completion_evidence_status', $payload['command_plan']);
        $this->assertArrayHasKey('persist_runtime_promotion_receipt', $payload['command_plan']);
        $this->assertArrayHasKey('persist_real_provider_smoke', $payload['command_plan']);
        $this->assertArrayHasKey('persist_human_completion_receipt', $payload['command_plan']);
        $this->assertArrayHasKey('completion_audit', $payload['command_plan']);
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
        $criteria = array_map(static fn (string $criterion): array => [
            'id' => $criterion,
            'passed' => false,
            'evidence' => [
                'status' => str_contains($criterion, 'human')
                    ? 'blocked_missing_operator_receipt'
                    : (str_contains($criterion, 'provider') ? 'blocked_missing_real_provider_smoke' : 'blocked'),
            ],
        ], $failedCriteria);

        return [
            'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
            'status' => $failedCriteria === [] ? 'complete' : 'incomplete',
            'completion_audit_hash' => str_repeat('b', 64),
            'failed_criteria' => $failedCriteria,
            'criteria' => $criteria,
        ];
    }
}
