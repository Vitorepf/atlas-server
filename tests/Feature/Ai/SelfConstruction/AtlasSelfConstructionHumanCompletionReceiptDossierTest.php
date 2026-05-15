<?php

namespace Tests\Feature\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionEvidenceHashService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptDossierService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptDossierTest extends TestCase
{
    public function test_human_completion_receipt_dossier_prepares_receipt_without_signing_or_promoting(): void
    {
        $payload = $this->service()->build(['completion_audit' => $this->audit()]);

        $this->assertSame('atlas.self_construction.human_completion_receipt_dossier.v1', $payload['schema_version']);
        $this->assertSame('operator_receipt_required', $payload['status']);
        $this->assertFalse($payload['completion_claim_allowed']);
        $this->assertSame('<operator>', $payload['human_receipt_preimage']['signed_by']);
        $this->assertSame('<operator_generated_64_hex_receipt_hash>', $payload['human_receipt_preimage']['receipt_hash']);
        $this->assertContains('human_completion_receipt_dossier_does_not_sign_for_operator', $payload['non_execution_guarantees']);
    }

    public function test_human_completion_receipt_dossier_maps_blockers_and_checklist(): void
    {
        $payload = $this->service()->build(['completion_audit' => $this->audit()]);

        $this->assertSame('persist_runtime_promotion_receipt_first', $payload['blocker_bridge']['runtime_gap_matrix_all_runtime_y']);
        $this->assertSame('persist_real_provider_smoke_certification_first', $payload['blocker_bridge']['end_to_end_real_provider_smoke_green']);
        $this->assertContains('persist_runtime_promotion_receipt', $payload['operator_signing_checklist']);
        $this->assertContains('persist_real_provider_smoke', $payload['operator_signing_checklist']);
        $this->assertContains('persist_human_completion_receipt', $payload['operator_signing_checklist']);
    }

    public function test_human_completion_receipt_dossier_uses_canonical_hash_service(): void
    {
        $payload = $this->service()->build(['completion_audit' => $this->audit()]);

        $this->assertSame(AtlasSelfConstructionCompletionEvidenceHashService::class, $payload['canonical_hash_preview']['canonical_hash_service']);
        $this->assertSame('humanCompletionReceiptHash', $payload['canonical_hash_preview']['method']);
        $this->assertTrue($payload['canonical_hash_preview']['operator_must_replace_placeholders']);
        $this->assertTrue($payload['canonical_hash_preview']['hash_changes_when_signed_by_or_reason_changes']);
    }

    public function test_human_completion_receipt_dossier_detects_stale_release_snapshot(): void
    {
        $audit = $this->audit();
        $audit['criteria'][0]['evidence']['baseline_snapshot_capture_required'] = true;
        $payload = $this->service()->build(['completion_audit' => $audit]);

        $this->assertTrue($payload['release_dossier_snapshot']['stale_snapshot_detected']);
        $this->assertSame('capture_fresh_replay_snapshot', $payload['release_dossier_snapshot']['action_required']);
    }

    public function test_human_completion_receipt_dossier_hash_is_deterministic(): void
    {
        $first = $this->service()->build(['completion_audit' => $this->audit()]);
        $second = $this->service()->build(['completion_audit' => $this->audit()]);

        $this->assertSame($first['dossier_hash'], $second['dossier_hash']);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $first['dossier_hash']);
    }

    public function test_human_completion_receipt_dossier_accepts_valid_receipt_as_present_but_still_does_not_promote(): void
    {
        $receipt = [
            'receipt_id' => 'receipt-1',
            'signed_by' => 'operator',
            'reason' => 'Reviewed evidence.',
            'completion_audit_hash' => str_repeat('a', 64),
            'release_dossier_hash' => str_repeat('1', 64),
            'replay_diff_hash' => str_repeat('2', 64),
            'runtime_gap_matrix_hash' => str_repeat('3', 64),
            'runtime_promotion_receipt_hash' => str_repeat('5', 64),
            'real_provider_smoke_hash' => str_repeat('6', 64),
            'certification_status_batch_hash' => str_repeat('4', 64),
            'receipt_hash' => str_repeat('c', 64),
            'os_complete_approved' => true,
            'operator_reviewed_completion_audit' => true,
            'no_autopromotion_acknowledged' => true,
        ];
        $receipt['receipt_hash'] = (new AtlasSelfConstructionCompletionEvidenceHashService)->humanCompletionReceiptHash($receipt);
        $payload = $this->service()->build(['completion_audit' => $this->audit(), 'completion_receipt' => $receipt]);

        $this->assertSame('receipt_present', $payload['status']);
        $this->assertSame('passed', $payload['receipt_verification']['status']);
        $this->assertFalse($payload['completion_claim_allowed']);
    }

    public function test_human_completion_receipt_dossier_is_json_serializable(): void
    {
        $payload = $this->service()->build(['completion_audit' => $this->audit()]);

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertJson($encoded);
    }

    private function service(): AtlasSelfConstructionHumanCompletionReceiptDossierService
    {
        return new AtlasSelfConstructionHumanCompletionReceiptDossierService(app(AtlasSelfConstructionReadinessService::class));
    }

    /** @return array<string, mixed> */
    private function audit(): array
    {
        return [
            'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
            'status' => 'incomplete',
            'passed_count' => 6,
            'failed_count' => 3,
            'completion_audit_hash' => str_repeat('a', 64),
            'failed_criteria' => [
                'runtime_gap_matrix_all_runtime_y',
                'human_signed_os_complete_receipt_present',
                'end_to_end_real_provider_smoke_green',
            ],
            'criteria' => [
                ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => str_repeat('1', 64), 'baseline_snapshot_capture_required' => false]],
                ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => str_repeat('2', 64)]],
                ['id' => 'runtime_gap_matrix_all_runtime_y', 'passed' => false, 'evidence' => ['runtime_gap_matrix_hash' => str_repeat('3', 64), 'runtime_promotion_receipt_hash' => str_repeat('5', 64)]],
                ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => false, 'evidence' => ['smoke_hash' => str_repeat('6', 64)]],
                ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['checked_count' => 49, 'failed_count' => 0, 'hash' => str_repeat('4', 64)]],
            ],
        ];
    }
}
