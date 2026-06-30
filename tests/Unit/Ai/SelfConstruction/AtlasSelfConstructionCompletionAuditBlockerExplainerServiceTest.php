<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionCompletionAuditBlockerExplainerService;
use Tests\TestCase;

final class AtlasSelfConstructionCompletionAuditBlockerExplainerServiceTest extends TestCase
{
    private function service(): AtlasSelfConstructionCompletionAuditBlockerExplainerService
    {
        return new AtlasSelfConstructionCompletionAuditBlockerExplainerService;
    }

    /** @param array<int, string> $failedCriteria */
    private function audit(array $failedCriteria): array
    {
        $criteria = array_map(fn (string $criterion): array => [
            'id' => $criterion,
            'passed' => false,
            'evidence' => [],
        ], $failedCriteria);

        return [
            'status' => 'blocked',
            'completion_audit_hash' => 'deadbeef',
            'failed_criteria' => $failedCriteria,
            'criteria' => $criteria,
        ];
    }

    public function test_human_signed_receipt_blockers_are_operator_required(): void
    {
        $payload = $this->service()->build($this->audit([
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
        ]));

        $this->assertSame(
            AtlasSelfConstructionCompletionAuditBlockerExplainerService::RESOLUTION_CLASS_OPERATOR_REQUIRED,
            $payload['blocker_resolution_classes']['runtime_gap_matrix_all_runtime_y'],
        );
        $this->assertSame(
            AtlasSelfConstructionCompletionAuditBlockerExplainerService::RESOLUTION_CLASS_OPERATOR_REQUIRED,
            $payload['blocker_resolution_classes']['human_signed_os_complete_receipt_present'],
        );
        $this->assertSame('operator_required', data_get($payload, 'blockers.0.resolution_class'));
        $this->assertSame('operator_required', data_get($payload, 'blockers.1.resolution_class'));
    }

    public function test_real_provider_smoke_blocker_is_real_provider_required(): void
    {
        $payload = $this->service()->build($this->audit(['end_to_end_real_provider_smoke_green']));

        $this->assertSame(
            AtlasSelfConstructionCompletionAuditBlockerExplainerService::RESOLUTION_CLASS_REAL_PROVIDER_REQUIRED,
            $payload['blocker_resolution_classes']['end_to_end_real_provider_smoke_green'],
        );
        $this->assertSame('real_provider_required', data_get($payload, 'blockers.0.resolution_class'));
    }

    public function test_release_dossier_blocker_is_worker_resolvable(): void
    {
        $payload = $this->service()->build($this->audit(['release_dossier_green']));

        $this->assertSame(
            AtlasSelfConstructionCompletionAuditBlockerExplainerService::RESOLUTION_CLASS_WORKER_RESOLVABLE,
            $payload['blocker_resolution_classes']['release_dossier_green'],
        );
    }

    public function test_unknown_blocker_is_evidence_only(): void
    {
        $payload = $this->service()->build($this->audit(['some_unmapped_blocker']));

        $this->assertSame(
            AtlasSelfConstructionCompletionAuditBlockerExplainerService::RESOLUTION_CLASS_EVIDENCE_ONLY,
            $payload['blocker_resolution_classes']['some_unmapped_blocker'],
        );
    }

    public function test_closure_artifact_sequence_rows_carry_resolution_class(): void
    {
        $payload = $this->service()->build($this->audit([]));

        $byArtifact = [];
        foreach ($payload['closure_artifact_sequence'] as $row) {
            $byArtifact[$row['artifact']] = $row['resolution_class'];
        }

        $this->assertSame('operator_required', $byArtifact['runtime_promotion_receipt']);
        $this->assertSame('real_provider_required', $byArtifact['real_provider_smoke']);
        $this->assertSame('operator_required', $byArtifact['human_completion_receipt']);
        $this->assertSame('evidence_only', $byArtifact['final_completion_audit']);
    }

    public function test_build_passes_runnable_evidence(): void
    {
        $payload = $this->service()->build($this->audit(['runtime_gap_matrix_all_runtime_y']));

        $this->assertNotEmpty($payload['explainer_hash']);
        $this->assertArrayHasKey('blocker_resolution_classes', $payload);
    }
}
