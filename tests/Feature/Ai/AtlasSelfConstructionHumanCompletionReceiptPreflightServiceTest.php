<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionHumanCompletionReceiptPreflightService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReservationRepository;
use Tests\TestCase;

final class AtlasSelfConstructionHumanCompletionReceiptPreflightServiceTest extends TestCase
{
    public function test_evidence_readiness_matrix_covers_every_upstream_artifact(): void
    {
        $preflight = $this->preflight()->build(['completion_audit' => $this->audit([
            'human_signed_os_complete_receipt_present',
        ])]);

        $rowNames = array_column($preflight['evidence_readiness_matrix'], 'name');
        $this->assertSame([
            'release_dossier',
            'replay_diff',
            'runtime_gap_matrix',
            'runtime_promotion_receipt',
            'real_provider_smoke',
            'certification_batch',
            'human_receipt',
        ], $rowNames);
    }

    public function test_ready_for_human_signature_only_when_every_upstream_row_is_green(): void
    {
        $preflight = $this->preflight()->build(['completion_audit' => $this->audit([
            'human_signed_os_complete_receipt_present',
        ])]);

        $this->assertSame('ready_for_human_signature', $preflight['status']);
        foreach ($preflight['evidence_readiness_matrix'] as $row) {
            if ($row['name'] === 'human_receipt') {
                continue;
            }
            $this->assertSame('green', $row['readiness_status'], "row {$row['name']} should be green");
        }
    }

    public function test_blocked_when_upstream_row_is_missing_evidence_hash_even_if_criterion_passed(): void
    {
        $audit = $this->audit(['human_signed_os_complete_receipt_present']);
        $audit['criteria'][0]['evidence']['hash'] = '';

        $preflight = $this->preflight()->build(['completion_audit' => $audit]);

        $this->assertSame('blocked', $preflight['status']);
        $releaseRow = collect($preflight['evidence_readiness_matrix'])->firstWhere('name', 'release_dossier');
        $this->assertSame('missing_evidence_hash', $releaseRow['readiness_status']);
    }

    public function test_blocked_when_other_criteria_fail_alongside_human_receipt(): void
    {
        $preflight = $this->preflight()->build(['completion_audit' => $this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ])]);

        $this->assertSame('blocked', $preflight['status']);
        $this->assertSame(['runtime_gap_matrix_all_runtime_y'], $preflight['blocking_failures_before_human_signature']);
    }

    public function test_next_operator_actions_distinguish_placeholder_hash_and_blocked_upstream(): void
    {
        $blocked = $this->preflight()->build(['completion_audit' => $this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ])]);
        $actions = array_column($blocked['next_operator_actions'], 'action');

        $this->assertContains('replace_placeholders', $actions);
        $this->assertContains('compute_canonical_receipt_hash', $actions);
        $this->assertContains('rerun_blocked_upstream_evidence', $actions);
        $this->assertNotContains('persist_through_verifier', $actions);
    }

    public function test_next_operator_actions_recommend_persistence_when_ready(): void
    {
        $ready = $this->preflight()->build(['completion_audit' => $this->audit([
            'human_signed_os_complete_receipt_present',
        ])]);
        $actions = array_column($ready['next_operator_actions'], 'action');

        $this->assertContains('persist_through_verifier', $actions);
        $this->assertNotContains('rerun_blocked_upstream_evidence', $actions);
    }

    private function preflight(): AtlasSelfConstructionHumanCompletionReceiptPreflightService
    {
        return new AtlasSelfConstructionHumanCompletionReceiptPreflightService(
            new AtlasSelfConstructionReadinessService(new AtlasSelfConstructionReservationRepository),
        );
    }

    /** @param list<string> $failed */
    private function audit(array $failed): array
    {
        $hash = str_repeat('a', 64);

        return [
            'schema_version' => 'atlas.self_construction.os_completion_audit.v1',
            'status' => $failed === [] ? 'complete' : 'incomplete',
            'completion_audit_hash' => $hash,
            'failed_criteria' => $failed,
            'criteria' => [
                ['id' => 'release_dossier_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
                ['id' => 'replay_diff_against_completion_snapshot_green', 'passed' => true, 'evidence' => ['diff_hash' => $hash]],
                [
                    'id' => 'runtime_gap_matrix_all_runtime_y',
                    'passed' => ! in_array('runtime_gap_matrix_all_runtime_y', $failed, true),
                    'evidence' => [
                        'runtime_gap_matrix_hash' => $hash,
                        'runtime_promotion_receipt_hash' => $hash,
                        'runtime_promotion_receipt_status' => 'passed',
                    ],
                ],
                ['id' => 'end_to_end_real_provider_smoke_green', 'passed' => ! in_array('end_to_end_real_provider_smoke_green', $failed, true), 'evidence' => ['smoke_hash' => $hash]],
                ['id' => 'certification_status_batch_green', 'passed' => true, 'evidence' => ['hash' => $hash]],
                [
                    'id' => 'human_signed_os_complete_receipt_present',
                    'passed' => ! in_array('human_signed_os_complete_receipt_present', $failed, true),
                    'evidence' => ['receipt_hash' => $hash],
                ],
            ],
        ];
    }
}
