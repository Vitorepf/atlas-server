<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldOverfitDetector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainScaffoldOverfitDetectorTest extends TestCase
{
    private AtlasExternalBrainScaffoldOverfitDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AtlasExternalBrainScaffoldOverfitDetector;
    }

    private function metric(string $id, array $overrides = []): array
    {
        return array_merge([
            'variant_id'               => $id,
            'gate_pass_rate'           => 0.70,
            'commit_success_rate'      => 0.80,
            'value_proof_rate'         => 0.80,
            'diversity_score'          => 0.70,
            'compounding_impact'       => 0.70,
            'give_back_rate'           => 0.10,
            'poison_rate'              => 0.03,
            'benchmark_pass_rate'      => 0.80,
            'heldout_pass_rate'        => 0.80,
            'template_repetition_score' => 0.10,
        ], $overrides);
    }

    private function detect(array ...$metrics): array
    {
        return $this->detector->detect(['scaffold_metrics' => $metrics]);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->detector->detect([]);

        foreach (['schema_version', 'overfit_detected', 'suspect_scaffolds', 'evidence_trend',
                  'heldout_gap', 'template_shape_repetition', 'benchmark_to_heldout_gap', 'recommended_action'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainScaffoldOverfitDetector::SCHEMA, $result['schema_version']);
    }

    // ── AC2: give_back_rate as suspect signal ─────────────────────────────────

    public function test_high_give_back_with_high_gate_is_suspect(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_pass_rate'  => 0.90,
            'give_back_rate'  => 0.30, // > 0.25
        ]));

        $this->assertTrue($result['overfit_detected']);
        $this->assertContains('v1', array_column($result['suspect_scaffolds'], 'variant_id'));
    }

    // ── AC2: poison_rate as suspect signal ────────────────────────────────────

    public function test_high_poison_rate_with_high_gate_is_suspect(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_pass_rate' => 0.85,
            'poison_rate'    => 0.15, // > 0.10
        ]));

        $this->assertTrue($result['overfit_detected']);
        $declining = $result['evidence_trend']['v1']['declining_metrics'];
        $this->assertContains('poison_rate', $declining);
    }

    // ── AC2: high gate with low commit success ────────────────────────────────

    public function test_high_gate_with_low_commit_success_is_suspect(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_pass_rate'      => 0.90,
            'commit_success_rate' => 0.55,
        ]));

        $this->assertTrue($result['overfit_detected']);
    }

    // ── AC2: clean scaffold ───────────────────────────────────────────────────

    public function test_healthy_scaffold_is_clean(): void
    {
        $result = $this->detect($this->metric('v1', ['gate_pass_rate' => 0.90]));

        $this->assertFalse($result['overfit_detected']);
        $this->assertSame('none', $result['recommended_action']);
    }

    // ── AC3: template_shape_repetition signal ────────────────────────────────

    public function test_high_template_repetition_flags_signal(): void
    {
        $result = $this->detect($this->metric('v1', [
            'template_repetition_score' => 0.80, // > 0.70
        ]));

        $this->assertTrue($result['template_shape_repetition']);
        $this->assertTrue($result['overfit_detected']);
    }

    public function test_low_template_repetition_not_flagged(): void
    {
        $result = $this->detect($this->metric('v1'));

        $this->assertFalse($result['template_shape_repetition']);
    }

    // ── AC3: benchmark_to_heldout_gap separate reporting ─────────────────────

    public function test_benchmark_to_heldout_gap_reported_per_variant(): void
    {
        $result = $this->detect($this->metric('v1', [
            'benchmark_pass_rate' => 0.90,
            'heldout_pass_rate'   => 0.60, // gap = 0.30
        ]));

        $this->assertArrayHasKey('v1', $result['benchmark_to_heldout_gap']);
        $this->assertEqualsWithDelta(0.30, $result['benchmark_to_heldout_gap']['v1'], 0.001);
    }

    // ── AC4: retire_template when template farm detected ─────────────────────

    public function test_template_farm_recommends_retire_template(): void
    {
        $result = $this->detect($this->metric('v1', [
            'template_repetition_score' => 0.85,
        ]));

        $this->assertSame('retire_template', $result['recommended_action']);
    }

    // ── AC4: retire_template beats reset_scaffold ────────────────────────────

    public function test_template_farm_beats_multiple_suspects(): void
    {
        $result = $this->detect(
            $this->metric('v1', ['gate_pass_rate' => 0.90, 'commit_success_rate' => 0.50, 'template_repetition_score' => 0.80]),
            $this->metric('v2', ['gate_pass_rate' => 0.85, 'commit_success_rate' => 0.50]),
        );

        $this->assertSame('retire_template', $result['recommended_action']);
    }

    // ── AC4: reset_scaffold for ≥2 severe suspects ───────────────────────────

    public function test_two_suspects_recommends_reset_scaffold(): void
    {
        $result = $this->detect(
            $this->metric('v1', ['gate_pass_rate' => 0.90, 'commit_success_rate' => 0.50, 'value_proof_rate' => 0.50]),
            $this->metric('v2', ['gate_pass_rate' => 0.85, 'commit_success_rate' => 0.55, 'diversity_score' => 0.30]),
        );

        $this->assertSame('reset_scaffold', $result['recommended_action']);
    }

    // ── AC4: expand_heldout_set for heldout-only failures ────────────────────

    public function test_heldout_only_failure_recommends_expand_heldout_set(): void
    {
        $result = $this->detect($this->metric('v1', [
            'benchmark_pass_rate' => 0.92,
            'heldout_pass_rate'   => 0.55, // gap > 0.25
        ]));

        $this->assertSame('expand_heldout_set', $result['recommended_action']);
    }

    // ── AC4: monitor for 1 borderline issue ──────────────────────────────────

    public function test_single_suspect_recommends_monitor(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_pass_rate'      => 0.90,
            'commit_success_rate' => 0.55,
        ]));

        $this->assertSame('monitor', $result['recommended_action']);
    }

    // ── Evidence trend ────────────────────────────────────────────────────────

    public function test_two_declining_metrics_gives_declining_trend(): void
    {
        $result = $this->detect($this->metric('v1', [
            'commit_success_rate' => 0.50,
            'value_proof_rate'    => 0.50,
        ]));

        $this->assertSame('declining', $result['evidence_trend']['v1']['trend']);
    }

    public function test_one_declining_metric_gives_borderline_trend(): void
    {
        $result = $this->detect($this->metric('v1', [
            'commit_success_rate' => 0.60,
        ]));

        $this->assertSame('borderline', $result['evidence_trend']['v1']['trend']);
    }

    // ── Empty input ───────────────────────────────────────────────────────────

    public function test_empty_input_returns_clean(): void
    {
        $result = $this->detector->detect([]);

        $this->assertFalse($result['overfit_detected']);
        $this->assertFalse($result['template_shape_repetition']);
        $this->assertSame([], $result['benchmark_to_heldout_gap']);
        $this->assertSame('none', $result['recommended_action']);
    }

    // ── AC2: gate-keyword stuffing detection ─────────────────────────────────

    public function test_gate_keyword_stuffing_is_flagged_as_suspect(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_keyword_density' => 0.75, // > 0.60
        ]));

        $this->assertTrue($result['overfit_detected']);
        $reasons = $result['suspect_scaffolds'][0]['reasons'];
        $this->assertContains('gate_keyword_stuffing', $reasons);
    }

    public function test_low_gate_keyword_density_does_not_flag(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_keyword_density' => 0.40,
        ]));

        $this->assertFalse($result['overfit_detected']);
    }

    // ── AC2: schema-only scaffold detection ──────────────────────────────────

    public function test_schema_only_with_no_quality_improvement_is_suspect(): void
    {
        $result = $this->detect($this->metric('v1', [
            'schema_change_only'       => true,
            'evidence_quality_delta'   => 0.0,
            'replay_accuracy_delta'    => 0.0,
            'escalation_quality_delta' => 0.0,
        ]));

        $this->assertTrue($result['overfit_detected']);
        $reasons = $result['suspect_scaffolds'][0]['reasons'];
        $this->assertContains('schema_only_scaffold', $reasons);
    }

    // ── AC3: schema change WITH quality improvement is permitted ─────────────

    public function test_schema_change_with_positive_evidence_delta_is_clean(): void
    {
        $result = $this->detect($this->metric('v1', [
            'schema_change_only'     => true,
            'evidence_quality_delta' => 0.15, // positive → not schema-only
        ]));

        $this->assertFalse($result['overfit_detected']);
    }

    public function test_schema_change_with_positive_replay_accuracy_is_clean(): void
    {
        $result = $this->detect($this->metric('v1', [
            'schema_change_only'    => true,
            'replay_accuracy_delta' => 0.10,
        ]));

        $this->assertFalse($result['overfit_detected']);
    }

    // ── AC4: risk_score, blocking_reason, repair_hints ───────────────────────

    public function test_output_has_risk_score_blocking_reason_repair_hints(): void
    {
        $result = $this->detector->detect([]);

        $this->assertArrayHasKey('risk_score', $result);
        $this->assertArrayHasKey('blocking_reason', $result);
        $this->assertArrayHasKey('repair_hints', $result);
        $this->assertIsFloat($result['risk_score']);
        $this->assertIsString($result['blocking_reason']);
        $this->assertIsArray($result['repair_hints']);
    }

    public function test_clean_scaffold_has_zero_risk_score_and_empty_blocking_reason(): void
    {
        $result = $this->detect($this->metric('v1'));

        $this->assertSame(0.0, $result['risk_score']);
        $this->assertSame('', $result['blocking_reason']);
    }

    public function test_suspect_scaffold_has_nonzero_risk_score(): void
    {
        $result = $this->detect($this->metric('v1', [
            'gate_pass_rate'      => 0.90,
            'commit_success_rate' => 0.50,
        ]));

        $this->assertGreaterThan(0.0, $result['risk_score']);
    }

    public function test_repair_hints_always_has_at_least_one_element(): void
    {
        $clean   = $this->detect($this->metric('v1'));
        $suspect = $this->detect($this->metric('v2', ['gate_pass_rate' => 0.90, 'commit_success_rate' => 0.50]));

        $this->assertNotEmpty($clean['repair_hints']);
        $this->assertNotEmpty($suspect['repair_hints']);
    }

    public function test_gate_stuffing_repair_hint_is_present(): void
    {
        $result = $this->detect($this->metric('v1', ['gate_keyword_density' => 0.80]));

        $this->assertContains(
            'remove_gate_keyword_saturation_and_add_concrete_capability_proof',
            $result['repair_hints'],
        );
    }

    public function test_blocking_reason_matches_recommended_action(): void
    {
        $template = $this->detect($this->metric('v1', ['template_repetition_score' => 0.90]));
        $this->assertStringContainsString('template_farm', $template['blocking_reason']);

        $heldout = $this->detect($this->metric('v2', ['benchmark_pass_rate' => 0.95, 'heldout_pass_rate' => 0.50]));
        $this->assertStringContainsString('heldout_gap', $heldout['blocking_reason']);
    }
}
