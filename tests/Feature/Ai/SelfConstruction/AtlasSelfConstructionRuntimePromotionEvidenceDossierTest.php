<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionRuntimePromotionEvidenceDossierService;
use Tests\TestCase;

final class AtlasSelfConstructionRuntimePromotionEvidenceDossierTest extends TestCase
{
    public function test_runtime_promotion_evidence_dossier_exposes_candidate_matrix_without_enabling_runtime(): void
    {
        $payload = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);

        $this->assertSame('atlas.self_construction.runtime_promotion_evidence_dossier.v1', $payload['schema_version']);
        $this->assertSame('available', $payload['status']);
        $this->assertSame(2, $payload['runtime_gap_matrix_snapshot']['runtime_gap_count']);
        $this->assertSame(0, $payload['runtime_gap_matrix_snapshot']['runtime_enabled_count']);
        $this->assertFalse($payload['machine_verification']['completion_claim_allowed']);
        $this->assertTrue($payload['machine_verification']['promotion_receipt_required']);
        $this->assertTrue($payload['machine_verification']['safe_to_sign_when_operator_accepts']);
    }

    public function test_runtime_promotion_evidence_dossier_preimage_contains_required_fields_and_forbidden_flags_false(): void
    {
        $payload = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);
        $preimage = $payload['promotion_receipt_preimage'];

        foreach (['receipt_id', 'signed_by', 'reason', 'runtime_gap_matrix_hash', 'runtime_promotion_basis_hash', 'promoted_gap_ids', 'graduation_evidence_hashes', 'receipt_hash'] as $field) {
            $this->assertArrayHasKey($field, $preimage);
        }
        foreach (['execution_allowed', 'dispatch_allowed', 'provider_call_allowed', 'token_spend_allowed', 'adapter_execution_allowed', 'self_programming_allowed'] as $flag) {
            $this->assertFalse($preimage[$flag]);
        }
    }

    public function test_runtime_promotion_evidence_dossier_lists_graduation_hashes_by_gap(): void
    {
        $payload = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);

        $this->assertSame(['adapter_execution_runtime', 'automatic_cost_import_runtime'], $payload['runtime_gap_matrix_snapshot']['blocked_gap_ids']);
        $this->assertSame(str_repeat('1', 64), data_get($payload, 'promotion_receipt_preimage.graduation_evidence_hashes.adapter_execution_runtime'));
        $this->assertSame(str_repeat('2', 64), data_get($payload, 'promotion_receipt_preimage.graduation_evidence_hashes.automatic_cost_import_runtime'));
    }

    public function test_runtime_promotion_evidence_dossier_blocks_when_not_all_gap_rows_are_candidates(): void
    {
        $matrix = $this->matrix();
        $matrix['rows'][1]['runtime_y_candidate'] = false;
        $payload = $this->service()->build(['runtime_gap_matrix' => $matrix]);

        $this->assertSame('blocked', $payload['status']);
        $this->assertFalse($payload['machine_verification']['safe_to_sign_when_operator_accepts']);
        $this->assertSame(1, $payload['machine_verification']['candidate_count']);
    }

    public function test_runtime_promotion_evidence_dossier_hash_is_deterministic(): void
    {
        $service = $this->service();
        $first = $service->build(['runtime_gap_matrix' => $this->matrix()]);
        $second = $service->build(['runtime_gap_matrix' => $this->matrix()]);

        $this->assertSame($first['machine_verification']['dossier_hash'], $second['machine_verification']['dossier_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['machine_verification']['dossier_hash']);
    }

    public function test_runtime_promotion_evidence_dossier_hash_changes_when_evidence_changes(): void
    {
        $first = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);
        $matrix = $this->matrix();
        $matrix['rows'][0]['graduation_evidence_hash'] = str_repeat('9', 64);
        $second = $this->service()->build(['runtime_gap_matrix' => $matrix]);

        $this->assertNotSame($first['machine_verification']['dossier_hash'], $second['machine_verification']['dossier_hash']);
    }

    public function test_runtime_promotion_evidence_dossier_references_canonical_hash_service(): void
    {
        $payload = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);

        $this->assertSame(AtlasSelfConstructionCompletionEvidenceHashService::class, $payload['receipt_hash_instructions']['canonical_hash_service']);
        $this->assertSame('runtimePromotionReceiptHash', $payload['receipt_hash_instructions']['method']);
        $this->assertTrue($payload['receipt_hash_instructions']['operator_must_replace_placeholders']);
        $this->assertTrue($payload['receipt_hash_instructions']['receipt_hash_is_not_generated_for_placeholder_payload']);
    }

    public function test_runtime_promotion_evidence_dossier_operator_checklist_contains_persist_and_rerun_steps(): void
    {
        $payload = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);

        $this->assertContains('persist_runtime_promotion_receipt_through_verifier', $payload['operator_checklist']);
        $this->assertContains('rerun_completion_audit', $payload['operator_checklist']);
        $this->assertContains('runtime_promotion_evidence_dossier_does_not_enable_runtime', $payload['non_execution_guarantees']);
    }

    public function test_runtime_promotion_evidence_dossier_is_json_serializable(): void
    {
        $payload = $this->service()->build(['runtime_gap_matrix' => $this->matrix()]);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    private function service(): AtlasSelfConstructionRuntimePromotionEvidenceDossierService
    {
        return new AtlasSelfConstructionRuntimePromotionEvidenceDossierService(app(AtlasSelfConstructionReadinessService::class));
    }

    /** @return array<string, mixed> */
    private function matrix(): array
    {
        return [
            'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
            'status' => 'blocked',
            'runtime_gap_matrix_hash' => str_repeat('a', 64),
            'runtime_promotion_basis_hash' => str_repeat('b', 64),
            'runtime_y_candidate_count' => 2,
            'rows' => [
                [
                    'gap_id' => 'adapter_execution_runtime',
                    'runtime_y' => false,
                    'runtime_y_candidate' => true,
                    'runtime_enabled' => false,
                    'graduation_status' => 'passed',
                    'graduation_schema' => 'atlas.test.graduation.v1',
                    'graduation_evidence_hash' => str_repeat('1', 64),
                    'blockers' => ['runtime_not_promoted_even_though_graduation_candidate_may_exist'],
                ],
                [
                    'gap_id' => 'automatic_cost_import_runtime',
                    'runtime_y' => false,
                    'runtime_y_candidate' => true,
                    'runtime_enabled' => false,
                    'graduation_status' => 'passed',
                    'graduation_schema' => 'atlas.test.graduation.v1',
                    'graduation_evidence_hash' => str_repeat('2', 64),
                    'blockers' => ['runtime_not_promoted_even_though_graduation_candidate_may_exist'],
                ],
            ],
        ];
    }
}
