<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceBundleService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionFinalEvidenceReplayService;
use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use Tests\TestCase;

final class AtlasSelfConstructionFinalEvidenceReplayServiceTest extends TestCase
{
    private const REQUIRED_COMPONENTS = [
        'runtime_gap_matrix',
        'completion_audit_status',
        'completion_operator_action_packet',
        'completion_audit_blocker_explainer',
    ];

    public function test_replay_includes_component_status_map_for_all_required_components(): void
    {
        $bundle = $this->bundle();

        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertArrayHasKey('component_status_map', $replay);
        $rows = collect($replay['component_status_map'])->keyBy('component');
        foreach (self::REQUIRED_COMPONENTS as $component) {
            $this->assertTrue($rows->has($component), "missing component_status_map row for {$component}");
            $this->assertTrue($rows[$component]['present']);
            $this->assertNull($rows[$component]['violation_code']);
        }
    }

    public function test_missing_required_component_produces_violation_and_status_map_entry(): void
    {
        $bundle = $this->bundle();
        $bundle['component_registry']['runtime_gap_matrix']['available'] = false;
        $this->rehashBundle($bundle);

        $replay = (new AtlasSelfConstructionFinalEvidenceReplayService)->replay($bundle);

        $this->assertSame('blocked', $replay['status']);
        $this->assertContains('required_final_bundle_component_missing', array_column($replay['violations'], 'code'));

        $row = collect($replay['component_status_map'])->firstWhere('component', 'runtime_gap_matrix');
        $this->assertNotNull($row);
        $this->assertFalse($row['present']);
        $this->assertSame('required_final_bundle_component_missing', $row['violation_code']);
        $this->assertNotEmpty($row['action_hint']);
    }

    /** @param array<string, mixed> $overrides */
    private function bundle(array $overrides = []): array
    {
        $runtimeAllY = (bool) ($overrides['runtime_all_y'] ?? false);
        $humanReceiptPassed = (bool) ($overrides['human_receipt_passed'] ?? false);
        $realProviderSmokePassed = (bool) ($overrides['real_provider_smoke_passed'] ?? false);
        $completionAuditComplete = (bool) ($overrides['completion_audit_complete'] ?? false);
        $failedCriteria = (array) ($overrides['failed_criteria'] ?? [
            'runtime_gap_matrix_all_runtime_y',
            'human_signed_os_complete_receipt_present',
            'end_to_end_real_provider_smoke_green',
        ]);

        return (new AtlasSelfConstructionFinalEvidenceBundleService(app(AtlasSelfConstructionReadinessService::class)))->build([
            'runtime_gap_matrix' => [
                'schema_version' => 'atlas.self_construction.runtime_gap_matrix.v1',
                'status' => $runtimeAllY ? 'passed' : 'blocked',
                'all_runtime_y' => $runtimeAllY,
                'runtime_gap_matrix_hash' => str_repeat('a', 64),
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
        ]);
    }

    /** @param array<string, mixed> $bundle */
    private function rehashBundle(array &$bundle): void
    {
        $hashable = $bundle;
        unset($hashable['generated_at']);
        unset($hashable['assessed_at'], $hashable['audited_at']);
        unset($hashable['bundle_identity']['bundle_hash'], $hashable['bundle_identity']['bundle_id']);
        unset($hashable['machine_status']['final_bundle_hash']);
        unset($hashable['promotion_receipt_preimage']['receipt_id']);
        unset($hashable['runtime_promotion_receipt_template']['receipt_id']);
        unset($hashable['human_completion_receipt_template']['receipt_id']);

        $hash = hash('sha256', (string) json_encode($this->ksortRecursive($hashable), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        $bundle['bundle_identity']['bundle_hash'] = $hash;
        $bundle['bundle_identity']['bundle_id'] = 'final-evidence-bundle-'.substr($hash, 0, 16);
        $bundle['machine_status']['final_bundle_hash'] = $hash;
    }

    /** @param array<string, mixed> $value */
    private function ksortRecursive(array $value): array
    {
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->ksortRecursive($entry);
            }
        }
        if ($value !== [] && array_keys($value) !== range(0, count($value) - 1)) {
            ksort($value);
        }

        return $value;
    }
}
