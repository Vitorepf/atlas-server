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
        foreach (['stage', 'dependency_type', 'owner', 'severity', 'atlas_native_replacement', 'proposed_task_family', 'evidence_floor'] as $key) {
            $this->assertArrayHasKey($key, $inv, "Missing field: {$key}");
        }
    }

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

    // ── AC1: replacement and task family contain stage name ──────────────────

    public function test_replacement_and_task_family_embed_stage_name(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['stage' => 'maestro', 'dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_CLAUDE_CODEX]),
        ]]);

        $inv = $result['inversions'][0];
        $this->assertStringContainsString('maestro', $inv['atlas_native_replacement']);
        $this->assertStringContainsString('maestro', $inv['proposed_task_family']);
    }

    // ── AC1: all six stages produce an inversion when steady-state ───────────

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

    // ── AC2: bootstrap-only + proven → skipped ───────────────────────────────

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
        // bootstrap-only but native NOT yet proven → still a steady-state concern
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep([
                'is_bootstrap_only'        => true,
                'atlas_native_path_proven' => false,
            ]),
        ]]);

        $this->assertSame(1, $result['inversion_count']);
        $this->assertSame(0, $result['skipped_bootstrap_only']);
    }

    // ── Sorting: critical first ───────────────────────────────────────────────

    public function test_inversions_sorted_critical_before_medium(): void
    {
        $result = $this->inverter->invert(['dependencies' => [
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_CLAUDE_CODEX, 'stage' => 'maestro']),
            $this->dep(['dependency_type' => AtlasExternalBrainAutonomyDependencyInverter::DEP_HUMAN,        'stage' => 'verification']),
        ]]);

        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_CRITICAL, $result['inversions'][0]['severity']);
        $this->assertSame(AtlasExternalBrainAutonomyDependencyInverter::SEV_MEDIUM,   $result['inversions'][1]['severity']);
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
}
