<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
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

        foreach (['completion_evidence_lockfile', 'completion_anti_fraud_matrix', 'runtime_promotion_closure_pack', 'human_completion_receipt_preflight', 'real_provider_smoke_offline_harness', 'completion_operator_action_packet', 'completion_audit_status', 'runtime_gap_matrix'] as $component) {
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
        $this->assertArrayHasKey('completion_audit', $bundle['final_operator_packet']['commands_to_rerun']);
        $this->assertArrayHasKey('capture_snapshot_if_stale', $bundle['final_operator_packet']['commands_to_rerun']);
    }

    public function test_final_evidence_bundle_marks_completion_allowed_only_when_all_real_evidence_and_audit_are_green(): void
    {
        $options = $this->baseOptions([
            'runtime_all_y' => true,
            'human_receipt_passed' => true,
            'real_provider_smoke_passed' => true,
            'completion_audit_complete' => true,
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
                'failed_criteria' => $failedCriteria,
                'criteria' => [
                    ['id' => 'release_dossier_green', 'passed' => true],
                ],
            ],
        ];
    }
}
