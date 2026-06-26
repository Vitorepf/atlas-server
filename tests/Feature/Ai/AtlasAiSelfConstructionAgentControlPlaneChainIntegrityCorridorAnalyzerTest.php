<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneChainIntegrityCorridorAnalyzer;
use Tests\TestCase;

final class AtlasAiSelfConstructionAgentControlPlaneChainIntegrityCorridorAnalyzerTest extends TestCase
{
    public function test_all_corridor_slices_ok_returns_true_when_all_present_and_ok(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $names = ['a', 'b', 'c'];
        $status = [
            'a' => ['all_artifacts_ok' => true],
            'b' => ['all_artifacts_ok' => true],
            'c' => ['all_artifacts_ok' => true],
        ];

        $this->assertTrue($analyzer->allCorridorSlicesOk($names, $status));
    }

    public function test_all_corridor_slices_ok_returns_false_when_any_not_ok(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $names = ['a', 'b'];
        $status = [
            'a' => ['all_artifacts_ok' => true],
            'b' => ['all_artifacts_ok' => false],
        ];

        $this->assertFalse($analyzer->allCorridorSlicesOk($names, $status));
    }

    public function test_provider_runtime_preflight_matrix_returns_byte_identical_envelope(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $matrix = $analyzer->providerRuntimePreflightMatrix([]);

        $this->assertSame('provider_runtime_preflight_matrix', $matrix['matrix_name']);
        $this->assertSame('v1', $matrix['matrix_version']);
        $this->assertGreaterThan(0, $matrix['row_count']);
        $this->assertGreaterThan(0, $matrix['check_count']);
        // No slice reports → every row's `slice_ok` is false → every check is false → all_true is false.
        $this->assertFalse($matrix['all_true']);
        $this->assertSame(0, $matrix['check_ok_count']);
    }

    public function test_provider_runtime_preflight_matrix_flips_rows_when_slice_is_not_ok(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $sliceKey = 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate';
        $reports = [
            ['slice_key' => $sliceKey, 'ok' => false],
        ];

        $matrix = $analyzer->providerRuntimePreflightMatrix($reports);
        $row = $matrix['rows']['post_start_provider_start_driver_gate'];

        $this->assertTrue($row['present_in_deep_chain']);
        $this->assertFalse($row['slice_ok']);
        $this->assertFalse($row['row_ok']);
        $this->assertFalse($matrix['all_true']);
        // Every check must be false when the underlying slice is not OK.
        foreach ($row['checks'] as $value) {
            $this->assertFalse($value);
        }
    }

    public function test_provider_runtime_preflight_matrix_passes_when_slice_ok(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $sliceKey = 'automatic_dispatch_scheduler_one_shot_tick_codex_real_invoker_post_start_provider_start_driver_gate';
        $reports = [
            ['slice_key' => $sliceKey, 'ok' => true],
        ];

        $matrix = $analyzer->providerRuntimePreflightMatrix($reports);
        $row = $matrix['rows']['post_start_provider_start_driver_gate'];

        $this->assertTrue($row['present_in_deep_chain']);
        $this->assertTrue($row['slice_ok']);
        $this->assertTrue($row['row_ok']);
        // The row's checks pass when its slice is OK, so all_true depends on
        // the OTHER rows (still no reports → still false at matrix level).
        $this->assertFalse($matrix['all_true']);
    }

    public function test_post_start_evidence_corridor_delegates_to_static_projector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $out = $analyzer->postStartEvidenceCorridor([], 'next_required');

        $this->assertIsArray($out);
        $this->assertArrayHasKey('corridor_slice_keys', $out);
        $this->assertIsArray($out['corridor_slice_keys']);
    }

    public function test_provider_to_runtime_corridor_delegates_to_static_projector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $out = $analyzer->providerToRuntimeCorridor([], 'next_required');

        $this->assertIsArray($out);
        $this->assertArrayHasKey('corridor_slice_keys', $out);
        $this->assertIsArray($out['corridor_slice_keys']);
    }

    public function test_implementation_to_operator_handoff_corridor_delegates_to_static_projector(): void
    {
        $analyzer = new AgentControlPlaneChainIntegrityCorridorAnalyzer;

        $out = $analyzer->implementationToOperatorHandoffCorridor([], 'next_required');

        $this->assertIsArray($out);
        $this->assertArrayHasKey('corridor_slice_keys', $out);
        $this->assertIsArray($out['corridor_slice_keys']);
    }
}
