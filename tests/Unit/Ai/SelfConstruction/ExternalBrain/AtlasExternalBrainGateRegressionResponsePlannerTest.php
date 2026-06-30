<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGateRegressionResponsePlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGateRegressionResponsePlannerTest extends TestCase
{
    private AtlasExternalBrainGateRegressionResponsePlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainGateRegressionResponsePlanner;
    }

    private function hole(array $overrides = []): array
    {
        return array_merge([
            'attack'       => 'test_only_packet_slips_through',
            'expected'     => 'gate_must_reject_test_only_allowed_files',
            'deficiencies' => ['test_only_has_contract'],
        ], $overrides);
    }

    private function auditWithHoles(array $holes, int $attacksTried = 5): array
    {
        return ['audit' => ['attacks_tried' => $attacksTried, 'holes' => $holes]];
    }

    private function auditClean(int $attacksTried = 5): array
    {
        return ['audit' => ['attacks_tried' => $attacksTried, 'holes' => []]];
    }

    // ── Schema ────────────────────────────────────────────────────────────────

    public function test_result_has_schema(): void
    {
        $result = $this->planner->plan($this->auditClean());
        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::SCHEMA, $result['schema']);
    }

    // ── AC2: zero holes → no_regression ──────────────────────────────────────

    public function test_zero_holes_returns_no_regression_verdict(): void
    {
        $result = $this->planner->plan($this->auditClean());

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::VERDICT_NO_REGRESSION, $result['verdict']);
        $this->assertSame(0, $result['regression_count']);
        $this->assertFalse($result['blocked_origination']);
    }

    public function test_no_regression_does_not_contain_repair_fields(): void
    {
        $result = $this->planner->plan($this->auditClean());

        $this->assertArrayNotHasKey('repair_target', $result);
        $this->assertArrayNotHasKey('expected_deficiency', $result);
        $this->assertArrayNotHasKey('evidence_command', $result);
    }

    // ── AC1: critical holes → repair_first ───────────────────────────────────

    public function test_critical_hole_returns_repair_first_verdict(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([$this->hole()]));

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::VERDICT_REPAIR_FIRST, $result['verdict']);
    }

    public function test_repair_first_has_required_fields(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([$this->hole()]));

        foreach (['repair_target', 'expected_deficiency', 'evidence_command', 'regression_count', 'blocked_origination'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing: {$k}");
        }
    }

    public function test_repair_first_blocks_origination(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([$this->hole()]));

        $this->assertTrue($result['blocked_origination']);
    }

    public function test_repair_target_comes_from_first_hole(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'first_attack']),
            $this->hole(['attack' => 'second_attack']),
        ]));

        $this->assertSame('first_attack', $result['repair_target']);
    }

    public function test_expected_deficiency_comes_from_first_hole(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['expected' => 'gate_must_catch_poison']),
        ]));

        $this->assertSame('gate_must_catch_poison', $result['expected_deficiency']);
    }

    public function test_evidence_command_is_present_and_non_empty(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([$this->hole()]));

        $this->assertIsString($result['evidence_command']);
        $this->assertNotEmpty($result['evidence_command']);
    }

    public function test_regression_count_matches_number_of_holes(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'h1']),
            $this->hole(['attack' => 'h2']),
            $this->hole(['attack' => 'h3']),
        ]));

        $this->assertSame(3, $result['regression_count']);
    }

    // ── No volume-padding fallback ────────────────────────────────────────────

    public function test_repair_first_does_not_contain_volume_padding_keys(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([$this->hole()]));

        // These would indicate volume-padding (suggesting unrelated tasks instead of repair)
        $this->assertArrayNotHasKey('suggested_volume_tasks', $result);
        $this->assertArrayNotHasKey('fallback_origination', $result);
        $this->assertArrayNotHasKey('volume_pad', $result);
    }

    // ── severity_override ────────────────────────────────────────────────────

    public function test_default_severity_is_critical(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([$this->hole()]));

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::SEVERITY_CRITICAL, $result['highest_severity']);
    }

    public function test_severity_override_is_respected(): void
    {
        $input = array_merge($this->auditWithHoles([$this->hole()]), ['severity_override' => 'high']);
        $result = $this->planner->plan($input);

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::SEVERITY_HIGH, $result['highest_severity']);
    }

    public function test_invalid_severity_override_falls_back_to_critical(): void
    {
        $input = array_merge($this->auditWithHoles([$this->hole()]), ['severity_override' => 'banana']);
        $result = $this->planner->plan($input);

        $this->assertSame(AtlasExternalBrainGateRegressionResponsePlanner::SEVERITY_CRITICAL, $result['highest_severity']);
    }

    // ── AC3: severity-based hole sorting ─────────────────────────────────────

    public function test_critical_hole_selected_over_high_regardless_of_input_order(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'high_attack',     'severity' => 'high']),
            $this->hole(['attack' => 'critical_attack', 'severity' => 'critical']),
        ]));

        $this->assertSame('critical_attack', $result['repair_target']);
    }

    public function test_high_hole_selected_over_medium(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'medium_attack', 'severity' => 'medium']),
            $this->hole(['attack' => 'high_attack',   'severity' => 'high']),
        ]));

        $this->assertSame('high_attack', $result['repair_target']);
    }

    public function test_same_severity_holes_preserve_input_order(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'first',  'severity' => 'high']),
            $this->hole(['attack' => 'second', 'severity' => 'high']),
        ]));

        $this->assertSame('first', $result['repair_target']);
    }

    public function test_sorted_holes_present_in_output(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'low',  'severity' => 'medium']),
            $this->hole(['attack' => 'high', 'severity' => 'critical']),
        ]));

        $this->assertArrayHasKey('sorted_holes', $result);
        $this->assertSame('high', $result['sorted_holes'][0]['attack']);
        $this->assertSame('low',  $result['sorted_holes'][1]['attack']);
    }

    public function test_remaining_holes_reflect_sorted_order(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'medium_one', 'severity' => 'medium']),
            $this->hole(['attack' => 'critical_one', 'severity' => 'critical']),
        ]));

        // After sort: critical_one first, medium_one is the remaining.
        $this->assertCount(1, $result['remaining_holes']);
        $this->assertSame('medium_one', $result['remaining_holes'][0]['attack']);
    }

    // ── AC4: mixed-severity test coverage ────────────────────────────────────

    public function test_three_holes_critical_high_medium_sorted_correctly(): void
    {
        $result = $this->planner->plan($this->auditWithHoles([
            $this->hole(['attack' => 'med',  'severity' => 'medium']),
            $this->hole(['attack' => 'crit', 'severity' => 'critical']),
            $this->hole(['attack' => 'hi',   'severity' => 'high']),
        ]));

        $sorted = $result['sorted_holes'];
        $this->assertSame('crit', $sorted[0]['attack']);
        $this->assertSame('hi',   $sorted[1]['attack']);
        $this->assertSame('med',  $sorted[2]['attack']);
        $this->assertSame(3, $result['regression_count']);
        $this->assertTrue($result['blocked_origination']);
    }
}
