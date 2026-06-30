<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainAutonomyDependencyInverter;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainAutonomyDependencyInverterTest extends TestCase
{
    private AtlasExternalBrainAutonomyDependencyInverter $inverter;

    protected function setUp(): void
    {
        $this->inverter = new AtlasExternalBrainAutonomyDependencyInverter;
    }

    private function dep(array $overrides = []): array
    {
        return array_merge([
            'stage'                    => 'originator',
            'dependency_type'          => AtlasExternalBrainAutonomyDependencyInverter::DEP_CLAUDE_CODEX,
            'owner'                    => 'codex-cli',
            'description'              => 'originator calls codex to generate tasks',
            'is_bootstrap_only'        => false,
            'atlas_native_path_proven' => false,
        ], $overrides);
    }

    // ── Schema / keys ────────────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->inverter->invert(['dependencies' => [$this->dep()]]);

        foreach (['schema', 'inversions', 'skipped_bootstrap_only', 'inversion_count'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SCHEMA, $result['schema']);
    }

    public function test_empty_dependencies_returns_zero_inversions(): void
    {
        $result = $this->inverter->invert(['dependencies' => []]);

        $this->assertSame([], $result['inversions']);
        $this->assertSame(0, $result['inversion_count']);
        $this->assertSame(0, $result['skipped_bootstrap_only']);
    }

    // ── AC1: emits required fields per inversion ─────────────────────────────

    public function test_inversion_has_required_per_inversion_fields(): void
    {
        $result = $this->inverter->invert(['dependencies' => [$this->dep()]]);

        $inv = $result['inversions'][0];
        foreach ([
            'stage', 'dependency_type', 'owner', 'severity',
            'atlas_native_replacement', 'proposed_task_family', 'evidence_floor',
            'classification', 'removal_order', 'autonomy_gain_score',
        ] as $key) {
            $this->assertArrayHasKey($key, $inv, "Missing field: {$key}");
        }
    }

    // ── Severity mapping ──────────────────────────────────────────────────────

    public function test_human_dependency_gets_critical_severity(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN, 'stage' => 'verification']),
        ]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_CRITICAL, $result['inversions'][0]['severity']);
    }

    public function test_operator_dependency_gets_high_severity(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPERATOR]),
        ]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_HIGH, $result['inversions'][0]['severity']);
    }

    public function test_claude_codex_dependency_gets_medium_severity(): void
    {
        $result = $this->inverter->invert(['dependencies' => [$this->dep()]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_MEDIUM, $result['inversions'][0]['severity']);
    }

    public function test_external_provider_dependency_gets_medium_severity(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_EXTERNAL_PROVIDER]),
        ]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_MEDIUM, $result['inversions'][0]['severity']);
    }

    public function test_optional_accelerator_gets_low_severity(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPTIONAL_ACCELERATOR]),
        ]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_LOW, $result['inversions'][0]['severity']);
    }

    // ── removal_order ─────────────────────────────────────────────────────────

    public function test_human_dep_has_removal_order_1(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN]),
        ]]);
        $this->assertSame(1, $result['inversions'][0]['removal_order']);
    }

    public function test_operator_dep_has_removal_order_2(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPERATOR]),
        ]]);
        $this->assertSame(2, $result['inversions'][0]['removal_order']);
    }

    public function test_claude_codex_dep_has_removal_order_3(): void
    {
        $result = $this->inverter->invert(['dependencies' => [$this->dep()]]);
        $this->assertSame(3, $result['inversions'][0]['removal_order']);
    }

    public function test_optional_accelerator_has_removal_order_4(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPTIONAL_ACCELERATOR]),
        ]]);
        $this->assertSame(4, $result['inversions'][0]['removal_order']);
    }

    // ── autonomy_gain_score ───────────────────────────────────────────────────

    public function test_human_has_highest_autonomy_gain(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN]),
        ]]);
        $this->assertSame(1.0, $result['inversions'][0]['autonomy_gain_score']);
    }

    public function test_operator_autonomy_gain_is_0_8(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPERATOR]),
        ]]);
        $this->assertSame(0.8, $result['inversions'][0]['autonomy_gain_score']);
    }

    public function test_claude_codex_autonomy_gain_is_0_5(): void
    {
        $result = $this->inverter->invert(['dependencies' => [$this->dep()]]);
        $this->assertSame(0.5, $result['inversions'][0]['autonomy_gain_score']);
    }

    public function test_optional_accelerator_autonomy_gain_is_0_2(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPTIONAL_ACCELERATOR]),
        ]]);
        $this->assertSame(0.2, $result['inversions'][0]['autonomy_gain_score']);
    }

    // ── classification field ──────────────────────────────────────────────────

    public function test_human_dep_classified_as_steady_state_blocker(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN]),
        ]]);
        $this->assertSame('steady_state_blocker', $result['inversions'][0]['classification']);
    }

    public function test_optional_accelerator_classified_correctly(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPTIONAL_ACCELERATOR]),
        ]]);
        $this->assertSame('optional_accelerator', $result['inversions'][0]['classification']);
    }

    // ── atlas_native dep skipped ──────────────────────────────────────────────

    public function test_atlas_native_dependency_is_skipped(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_ATLAS_NATIVE]),
        ]]);

        $this->assertSame(0, $result['inversion_count']);
        $this->assertSame(0, $result['skipped_bootstrap_only']);
    }

    public function test_atlas_native_not_counted_in_skipped_bootstrap(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_ATLAS_NATIVE]),
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN]),
        ]]);

        $this->assertSame(1, $result['inversion_count']);
        $this->assertSame(0, $result['skipped_bootstrap_only']);
    }

    // ── replacement and task family embed stage name ──────────────────────────

    public function test_replacement_and_task_family_embed_stage_name(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['stage' => 'maestro', 'dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_CLAUDE_CODEX]),
        ]]);

        $inv = $result['inversions'][0];
        $this->assertStringContainsString('maestro', $inv['atlas_native_replacement']);
        $this->assertStringContainsString('maestro', $inv['proposed_task_family']);
    }

    // ── all six stages produce inversions ─────────────────────────────────────

    public function test_all_six_stages_produce_inversions(): void
    {
        $stages = ['originator', 'task_fabric', 'maestro', 'worker', 'verification', 'knowledge_sync'];
        $deps   = array_map(fn ($s) => $this->dep(['stage' => $s]), $stages);

        $result = $this->inverter->invert(['dependencies' => $deps]);

        $this->assertSame(6, $result['inversion_count']);
        $foundStages = array_column($result['inversions'], 'stage');
        foreach ($stages as $s) {
            $this->assertContains($s, $foundStages);
        }
    }

    // ── bootstrap_only_superseded: proven seam skipped ────────────────────────

    public function test_bootstrap_only_with_native_proven_is_skipped(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep([
                'is_bootstrap_only'        => true,
                'atlas_native_path_proven' => true,
            ]),
        ]]);

        $this->assertSame([], $result['inversions']);
        $this->assertSame(1, $result['skipped_bootstrap_only']);
        $this->assertSame(0, $result['inversion_count']);
    }

    public function test_bootstrap_only_without_native_proven_is_flagged(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep([
                'is_bootstrap_only'        => true,
                'atlas_native_path_proven' => false,
            ]),
        ]]);

        $this->assertSame(1, $result['inversion_count']);
        $this->assertSame(0, $result['skipped_bootstrap_only']);
    }

    // ── deterministic severity ordering ──────────────────────────────────────

    public function test_inversions_sorted_critical_before_medium(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_CLAUDE_CODEX, 'stage' => 'maestro']),
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN,        'stage' => 'verification']),
        ]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_CRITICAL, $result['inversions'][0]['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_MEDIUM,   $result['inversions'][1]['severity']);
    }

    public function test_optional_accelerator_sorted_last(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPTIONAL_ACCELERATOR, 'stage' => 'a']),
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN,                'stage' => 'b']),
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_OPERATOR,             'stage' => 'c']),
        ]]);

        $severities = array_column($result['inversions'], 'severity');
        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_CRITICAL, $severities[0]);
        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_HIGH,     $severities[1]);
        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_LOW,      $severities[2]);
    }

    // ── inversion_count matches inversions list ───────────────────────────────

    public function test_inversion_count_matches_inversions_list(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['stage' => 'originator']),
            $this->dep(['stage' => 'maestro', 'is_bootstrap_only' => true, 'atlas_native_path_proven' => true]),
            $this->dep(['stage' => 'worker']),
        ]]);

        $this->assertSame(count($result['inversions']), $result['inversion_count']);
        $this->assertSame(1, $result['skipped_bootstrap_only']);
    }

    // ── unknown dep type skipped ──────────────────────────────────────────────

    public function test_unknown_dependency_type_is_skipped(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => 'some_unknown_future_type']),
        ]]);

        $this->assertSame(0, $result['inversion_count']);
        $this->assertSame(0, $result['skipped_bootstrap_only']);
    }
}
