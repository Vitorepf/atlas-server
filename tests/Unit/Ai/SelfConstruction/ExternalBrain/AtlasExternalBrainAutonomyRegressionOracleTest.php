<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyRegressionOracle;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAutonomyRegressionOracleTest extends TestCase
{
    private AtlasExternalBrainAutonomyRegressionOracle $oracle;

    protected function setUp(): void
    {
        $this->oracle = new AtlasExternalBrainAutonomyRegressionOracle;
    }

    private function base(array $beforeOverrides = [], array $afterOverrides = [], array $seams = []): array
    {
        $before = array_merge([
            'human_in_steady_state'          => 0,
            'operator_in_steady_state'       => 0,
            'provider_in_steady_state'       => 0,
            'manual_only_decisions'          => 0,
            'unverified_runtime_assumptions' => 0,
            'atlas_native_paths_proven'      => 2,
        ], $beforeOverrides);

        $after = array_merge($before, $afterOverrides);

        return ['before' => $before, 'after' => $after, 'bootstrap_seams' => $seams];
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->oracle->assess($this->base());

        foreach (['schema', 'autonomy_delta', 'regression_flags', 'severity', 'required_repair_task_family'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SCHEMA, $result['schema']);
    }

    // ── AC1: no regression → severity none ───────────────────────────────────

    public function test_no_change_produces_severity_none_and_no_flags(): void
    {
        $result = $this->oracle->assess($this->base());

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_NONE, $result['severity']);
        $this->assertSame([], $result['regression_flags']);
        $this->assertNull($result['required_repair_task_family']);
        $this->assertSame(0.0, $result['autonomy_delta']);
    }

    // ── AC1: human regression → critical ──────────────────────────────────────

    public function test_human_in_steady_state_increase_is_critical(): void
    {
        $result = $this->oracle->assess($this->base([], ['human_in_steady_state' => 1]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_CRITICAL, $result['severity']);
        $this->assertSame('autonomy_blocking_human_dependency_removal', $result['required_repair_task_family']);
        $this->assertNotEmpty($result['regression_flags']);
        $this->assertStringContainsString('human_in_steady_state', $result['regression_flags'][0]);
    }

    // ── AC1: operator regression → high ───────────────────────────────────────

    public function test_operator_in_steady_state_increase_is_high(): void
    {
        $result = $this->oracle->assess($this->base([], ['operator_in_steady_state' => 1]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_HIGH, $result['severity']);
        $this->assertSame('autonomy_blocking_provider_operator_removal', $result['required_repair_task_family']);
    }

    // ── AC1: provider regression → high ───────────────────────────────────────

    public function test_provider_in_steady_state_increase_is_high(): void
    {
        $result = $this->oracle->assess($this->base([], ['provider_in_steady_state' => 2]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_HIGH, $result['severity']);
    }

    // ── AC2: bootstrap seam with proven native path is NOT a regression ───────

    public function test_provider_increase_offset_by_proven_bootstrap_seam_is_not_flagged(): void
    {
        $seam = [
            'dependency_type'        => 'external_provider',
            'is_bootstrap_only'      => true,
            'atlas_native_path_proven' => true,
        ];

        $result = $this->oracle->assess($this->base(
            ['provider_in_steady_state' => 0],
            ['provider_in_steady_state' => 1],  // one new provider dep
            [$seam],                             // one proven bootstrap seam offsets it
        ));

        // Effective provider delta = 1 - 1 = 0 → no regression
        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_NONE, $result['severity']);
        $this->assertSame([], $result['regression_flags']);
    }

    public function test_unproven_bootstrap_seam_does_not_offset_provider_regression(): void
    {
        $seam = [
            'dependency_type'          => 'external_provider',
            'is_bootstrap_only'        => true,
            'atlas_native_path_proven' => false,  // NOT proven
        ];

        $result = $this->oracle->assess($this->base(
            ['provider_in_steady_state' => 0],
            ['provider_in_steady_state' => 1],
            [$seam],
        ));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_HIGH, $result['severity']);
    }

    // ── AC1: manual_only_decisions → high ────────────────────────────────────

    public function test_manual_only_decisions_increase_is_high(): void
    {
        $result = $this->oracle->assess($this->base([], ['manual_only_decisions' => 1]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_HIGH, $result['severity']);
    }

    // ── AC1: unverified runtime assumptions → medium ──────────────────────────

    public function test_unverified_runtime_assumptions_increase_is_medium(): void
    {
        $result = $this->oracle->assess($this->base([], ['unverified_runtime_assumptions' => 2]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_MEDIUM, $result['severity']);
        $this->assertSame('autonomy_risk_assumption_verification', $result['required_repair_task_family']);
    }

    // ── severity escalation ───────────────────────────────────────────────────

    public function test_critical_beats_high_in_severity(): void
    {
        $result = $this->oracle->assess($this->base([], [
            'human_in_steady_state'    => 1,  // critical
            'operator_in_steady_state' => 1,  // high
        ]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_CRITICAL, $result['severity']);
    }

    // ── autonomy_delta ────────────────────────────────────────────────────────

    public function test_autonomy_delta_negative_on_regression(): void
    {
        $result = $this->oracle->assess($this->base([], ['human_in_steady_state' => 2]));

        $this->assertLessThan(0.0, $result['autonomy_delta']);
    }

    public function test_autonomy_delta_positive_when_native_paths_increase(): void
    {
        $result = $this->oracle->assess($this->base([], ['atlas_native_paths_proven' => 4]));

        $this->assertGreaterThan(0.0, $result['autonomy_delta']);
    }

    public function test_autonomy_delta_zero_when_no_change(): void
    {
        $result = $this->oracle->assess($this->base());

        $this->assertSame(0.0, $result['autonomy_delta']);
    }

    // ── decrease in bad metrics is not a regression ───────────────────────────

    public function test_decrease_in_provider_dep_does_not_flag(): void
    {
        $result = $this->oracle->assess($this->base(
            ['provider_in_steady_state' => 3],
            ['provider_in_steady_state' => 1],  // improved
        ));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_NONE, $result['severity']);
        $this->assertSame([], $result['regression_flags']);
    }

    // ── AC2: improvement_flags present in output ──────────────────────────────

    public function test_improvement_flags_key_always_present(): void
    {
        $result = $this->oracle->assess($this->base());

        $this->assertArrayHasKey('improvement_flags', $result);
        $this->assertIsArray($result['improvement_flags']);
    }

    public function test_improvement_flags_empty_when_no_positive_change(): void
    {
        $result = $this->oracle->assess($this->base());

        $this->assertSame([], $result['improvement_flags']);
    }

    public function test_improvement_flags_populated_when_native_paths_increase(): void
    {
        $result = $this->oracle->assess($this->base([], ['atlas_native_paths_proven' => 5]));

        $this->assertNotEmpty($result['improvement_flags']);
        $this->assertStringContainsString('atlas_native_paths_proven', $result['improvement_flags'][0]);
        $this->assertStringContainsString('3', $result['improvement_flags'][0]); // delta = 5-2=3
    }

    public function test_improvement_flags_empty_when_native_paths_decrease(): void
    {
        $result = $this->oracle->assess($this->base(
            ['atlas_native_paths_proven' => 5],
            ['atlas_native_paths_proven' => 2], // decreased → not an improvement
        ));

        $this->assertSame([], $result['improvement_flags']);
    }

    // ── AC4: improvement alongside regression ────────────────────────────────

    public function test_improvement_flag_and_regression_flag_can_coexist(): void
    {
        $result = $this->oracle->assess($this->base([], [
            'human_in_steady_state'  => 1,        // critical regression
            'atlas_native_paths_proven' => 5,     // improvement (delta=3)
        ]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_CRITICAL, $result['severity']);
        $this->assertNotEmpty($result['regression_flags']);
        $this->assertNotEmpty($result['improvement_flags']);
    }

    // ── AC4: full severity matrix ─────────────────────────────────────────────

    public function test_critical_requires_human_in_steady_state_flag(): void
    {
        $result = $this->oracle->assess($this->base([], ['human_in_steady_state' => 2]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_CRITICAL, $result['severity']);
        $this->assertStringContainsString('human_in_steady_state_increased_by_2', $result['regression_flags'][0]);
    }

    public function test_high_via_manual_only_decisions(): void
    {
        $result = $this->oracle->assess($this->base([], ['manual_only_decisions' => 3]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_HIGH, $result['severity']);
        $this->assertStringContainsString('manual_only_decisions_increased_by_3', $result['regression_flags'][0]);
    }

    public function test_medium_via_unverified_runtime_assumptions(): void
    {
        $result = $this->oracle->assess($this->base([], ['unverified_runtime_assumptions' => 1]));

        $this->assertSame(AtlasExternalBrainAutonomyRegressionOracle::SEVERITY_MEDIUM, $result['severity']);
        $this->assertStringContainsString('unverified_runtime_assumptions_increased_by_1', $result['regression_flags'][0]);
    }
}
