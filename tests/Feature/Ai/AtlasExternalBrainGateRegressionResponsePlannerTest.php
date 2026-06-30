<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGateRegressionResponsePlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGateRegressionResponsePlannerTest extends TestCase
{
    private AtlasExternalBrainGateRegressionResponsePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainGateRegressionResponsePlanner;
    }

    private function plan(array $holes = [], array $extra = []): array
    {
        return $this->planner->plan(array_merge([
            'audit' => ['attacks_tried' => count($holes), 'holes' => $holes],
        ], $extra));
    }

    private function hole(string $attack, string $severity = 'critical', string $expected = 'should_fail'): array
    {
        return ['attack' => $attack, 'severity' => $severity, 'expected' => $expected, 'deficiencies' => []];
    }

    // ── AC2: any hole → repair_first + blocked + evidence_command ─────────────

    public function test_single_hole_returns_repair_first(): void
    {
        $r = $this->plan([$this->hole('scope_guard_attack')]);

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::VERDICT_REPAIR_FIRST, $r['verdict']);
    }

    public function test_single_hole_blocks_origination(): void
    {
        $r = $this->plan([$this->hole('scope_guard_attack')]);

        $this->assertTrue($r['blocked_origination']);
    }

    public function test_single_hole_includes_evidence_command(): void
    {
        $r = $this->plan([$this->hole('scope_guard_attack')]);

        $this->assertArrayHasKey('evidence_command', $r);
        $this->assertNotEmpty($r['evidence_command']);
        $this->assertStringContainsString('artisan', $r['evidence_command']);
    }

    public function test_zero_holes_returns_no_regression(): void
    {
        $r = $this->plan([]);

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::VERDICT_NO_REGRESSION, $r['verdict']);
        $this->assertFalse($r['blocked_origination']);
    }

    // ── AC3: multiple holes sorted by severity ────────────────────────────────

    public function test_critical_severity_sorted_before_high(): void
    {
        $r = $this->plan([
            $this->hole('medium_attack', 'medium'),
            $this->hole('critical_attack', 'critical'),
            $this->hole('high_attack', 'high'),
        ]);

        $this->assertSame('critical_attack', $r['repair_target']);
    }

    public function test_high_severity_sorted_before_medium(): void
    {
        $r = $this->plan([
            $this->hole('medium_attack', 'medium'),
            $this->hole('high_attack', 'high'),
        ]);

        $this->assertSame('high_attack', $r['repair_target']);
    }

    public function test_remaining_holes_excludes_top_hole(): void
    {
        $r = $this->plan([
            $this->hole('critical_attack', 'critical'),
            $this->hole('high_attack', 'high'),
        ]);

        $this->assertCount(1, $r['remaining_holes']);
        $this->assertSame('high_attack', $r['remaining_holes'][0]['attack']);
    }

    // ── AC4: poison classes → deterministic repair actions ────────────────────

    public function test_contradiction_maps_to_rewrite_criteria(): void
    {
        $d = $this->planner->diagnose([
            'allowed_files'  => ['app/Services/Foo.php'],
            'forbidden_files' => [],
            'packet_quality'  => ['deficiencies' => ['contradictory_acceptance']],
        ]);

        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::POISON_CONTRADICTION,
            $d['poison_class'],
        );
        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::REPAIR_REWRITE_CRITERIA,
            $d['repair_action'],
        );
    }

    public function test_forbidden_target_maps_to_quarantine(): void
    {
        $d = $this->planner->diagnose([
            'allowed_files'   => ['app/Services/Foo.php'],
            'forbidden_files' => ['app/Kernel.php'],
            'packet_quality'  => ['deficiencies' => []],
        ]);

        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::POISON_FORBIDDEN_TARGET,
            $d['poison_class'],
        );
        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::REPAIR_QUARANTINE,
            $d['repair_action'],
        );
    }

    public function test_test_only_spec_maps_to_give_back(): void
    {
        $d = $this->planner->diagnose([
            'allowed_files'  => ['tests/Feature/SomeTest.php'],
            'forbidden_files' => [],
            'packet_quality'  => ['deficiencies' => ['test_only_has_contract']],
        ]);

        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::POISON_TEST_ONLY_SPEC,
            $d['poison_class'],
        );
        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::REPAIR_GIVE_BACK,
            $d['repair_action'],
        );
    }

    public function test_stale_duplicate_maps_to_split_packet(): void
    {
        $d = $this->planner->diagnose([
            'allowed_files'  => ['app/Services/Foo.php'],
            'forbidden_files' => [],
            'packet_quality'  => ['deficiencies' => ['stale_duplicate']],
            'is_duplicate'    => true,
        ]);

        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::POISON_STALE_DUPLICATE,
            $d['poison_class'],
        );
        $this->assertSame(
            AtlasExternalBrainGateRegressionResponsePlanner::REPAIR_SPLIT_PACKET,
            $d['repair_action'],
        );
    }

    public function test_missing_allowed_files_fails_closed(): void
    {
        $d = $this->planner->diagnose([
            'allowed_files'  => [],
            'forbidden_files' => [],
            'packet_quality'  => ['deficiencies' => []],
        ]);

        $this->assertNull($d['poison_class']);
        $this->assertTrue($d['fail_closed']);
    }

    // ── deterministic ─────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $holes = [$this->hole('attack_x', 'critical')];

        $this->assertSame(json_encode($this->plan($holes)), json_encode($this->plan($holes)));
    }

    public function test_schema_is_set(): void
    {
        $r = $this->plan([]);

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::SCHEMA, $r['schema']);
    }
}
