<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainScaffoldOverfitDetector;
use Tests\TestCase;

final class AtlasExternalBrainScaffoldOverfitDetectorTest extends TestCase
{
    private function svc(): AtlasExternalBrainScaffoldOverfitDetector
    {
        return new AtlasExternalBrainScaffoldOverfitDetector;
    }

    private function detect(array $metrics): array
    {
        return $this->svc()->detect(['scaffold_metrics' => $metrics]);
    }

    private function cleanVariant(string $id = 'v1', array $overrides = []): array
    {
        return array_merge([
            'variant_id'              => $id,
            'gate_pass_rate'          => 0.90,
            'commit_success_rate'     => 0.85,
            'value_proof_rate'        => 0.80,
            'diversity_score'         => 0.75,
            'compounding_impact'      => 0.70,
            'give_back_rate'          => 0.10,
            'poison_rate'             => 0.05,
            'benchmark_pass_rate'     => 0.90,
            'heldout_pass_rate'       => 0.88,
            'template_repetition_score' => 0.20,
            'gate_keyword_density'    => 0.10,
            'schema_change_only'      => false,
            'evidence_quality_delta'  => 0.10,
            'replay_accuracy_delta'   => 0.05,
            'escalation_quality_delta' => 0.05,
        ], $overrides);
    }

    // ── AC1: runnable gate (implicit) ─────────────────────────────────────────

    public function test_ac1_empty_metrics_returns_expected_keys(): void
    {
        $r = $this->detect([]);
        $this->assertArrayHasKey('overfit_detected',         $r);
        $this->assertArrayHasKey('suspect_scaffolds',        $r);
        $this->assertArrayHasKey('evidence_trend',           $r);
        $this->assertArrayHasKey('benchmark_to_heldout_gap', $r);
        $this->assertArrayHasKey('template_shape_repetition', $r);
        $this->assertArrayHasKey('recommended_action',       $r);
        $this->assertArrayHasKey('repair_hints',             $r);
    }

    public function test_ac1_clean_variant_is_not_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant()]);
        $this->assertFalse($r['overfit_detected']);
        $this->assertEmpty($r['suspect_scaffolds']);
        $this->assertSame('none', $r['recommended_action']);
    }

    // ── AC2: high gate + degraded quality signals → suspect ───────────────────

    public function test_ac2_high_gate_with_low_value_proof_is_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant('v1', [
            'gate_pass_rate'  => 0.95,
            'value_proof_rate' => 0.50,  // below threshold
        ])]);

        $this->assertTrue($r['overfit_detected']);
        $suspect = $r['suspect_scaffolds'][0];
        $this->assertContains('value_proof_rate', $suspect['declining_metrics']);
        $this->assertContains('high_gate_low_real_quality', $suspect['reasons']);
    }

    public function test_ac2_high_gate_with_low_diversity_is_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant('v1', [
            'gate_pass_rate'  => 0.90,
            'diversity_score' => 0.30,   // below threshold
        ])]);

        $this->assertTrue($r['overfit_detected']);
        $this->assertContains('diversity_score', $r['suspect_scaffolds'][0]['declining_metrics']);
    }

    public function test_ac2_high_gate_with_low_compounding_is_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant('v1', [
            'gate_pass_rate'     => 0.85,
            'compounding_impact' => 0.20,
        ])]);

        $this->assertTrue($r['overfit_detected']);
        $this->assertContains('compounding_impact', $r['suspect_scaffolds'][0]['declining_metrics']);
    }

    public function test_ac2_high_gate_with_high_give_back_is_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant('v1', [
            'gate_pass_rate' => 0.90,
            'give_back_rate' => 0.40,  // above threshold
        ])]);

        $this->assertTrue($r['overfit_detected']);
        $this->assertContains('give_back_rate', $r['suspect_scaffolds'][0]['declining_metrics']);
    }

    public function test_ac2_high_gate_with_high_poison_is_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant('v1', [
            'gate_pass_rate' => 0.85,
            'poison_rate'    => 0.20,   // above threshold
        ])]);

        $this->assertTrue($r['overfit_detected']);
        $this->assertContains('poison_rate', $r['suspect_scaffolds'][0]['declining_metrics']);
    }

    public function test_ac2_clean_gate_without_quality_degradation_is_not_suspect(): void
    {
        $r = $this->detect([$this->cleanVariant('v1', ['gate_pass_rate' => 0.70])]);
        $this->assertFalse($r['overfit_detected']);
    }

    // ── AC3: benchmark-to-heldout and template repetition reported separately ─

    public function test_ac3_heldout_gap_is_reported_per_variant(): void
    {
        $r = $this->detect([
            $this->cleanVariant('v1', ['benchmark_pass_rate' => 0.90, 'heldout_pass_rate' => 0.55]),
        ]);

        $this->assertArrayHasKey('v1', $r['benchmark_to_heldout_gap']);
        $this->assertGreaterThan(
            AtlasExternalBrainScaffoldOverfitDetector::HELDOUT_GAP_THRESHOLD,
            $r['benchmark_to_heldout_gap']['v1'],
        );
        $this->assertTrue($r['heldout_gap']);
    }

    public function test_ac3_template_repetition_flagged_separately(): void
    {
        $r = $this->detect([
            $this->cleanVariant('v1', ['template_repetition_score' => 0.80]),
        ]);

        $this->assertTrue($r['template_shape_repetition'],
            'template_shape_repetition must be reported separately');
        $this->assertContains('template_shape_repetition', $r['suspect_scaffolds'][0]['reasons']);
    }

    public function test_ac3_clean_variant_shows_zero_heldout_gap_per_variant(): void
    {
        $r = $this->detect([$this->cleanVariant('clean', ['benchmark_pass_rate' => 0.88, 'heldout_pass_rate' => 0.87])]);
        $this->assertFalse($r['heldout_gap']);
        $this->assertFalse($r['template_shape_repetition']);
        $gap = $r['benchmark_to_heldout_gap']['clean'];
        $this->assertLessThanOrEqual(AtlasExternalBrainScaffoldOverfitDetector::HELDOUT_GAP_THRESHOLD, $gap);
    }

    // ── AC4: action priority: retire_template > reset_scaffold > expand_heldout_set > monitor > none ──

    public function test_ac4_template_farm_triggers_retire_template_over_reset(): void
    {
        // Two suspects, one of which has template farm — retire_template must win.
        $r = $this->detect([
            $this->cleanVariant('v1', ['template_repetition_score' => 0.80]),
            $this->cleanVariant('v2', ['gate_pass_rate' => 0.90, 'value_proof_rate' => 0.40]),
        ]);

        $this->assertSame('retire_template', $r['recommended_action'],
            'template farm must outrank reset_scaffold');
    }

    public function test_ac4_two_non_template_suspects_triggers_reset_scaffold(): void
    {
        $r = $this->detect([
            $this->cleanVariant('v1', ['gate_pass_rate' => 0.90, 'value_proof_rate' => 0.40]),
            $this->cleanVariant('v2', ['gate_pass_rate' => 0.85, 'diversity_score'  => 0.20]),
        ]);

        $this->assertSame('reset_scaffold', $r['recommended_action']);
    }

    public function test_ac4_heldout_only_failure_triggers_expand_heldout_set(): void
    {
        // One suspect due to heldout gap only (no template, no multiple suspects).
        $r = $this->detect([
            $this->cleanVariant('v1', [
                'benchmark_pass_rate' => 0.95,
                'heldout_pass_rate'   => 0.60,
                // quality signals fine — only heldout gap fires
                'gate_pass_rate'      => 0.70,  // below high gate threshold
            ]),
        ]);

        $this->assertSame('expand_heldout_set', $r['recommended_action']);
    }

    public function test_ac4_single_borderline_suspect_triggers_monitor(): void
    {
        $r = $this->detect([
            $this->cleanVariant('v1', ['gate_pass_rate' => 0.90, 'value_proof_rate' => 0.50]),
        ]);

        $this->assertSame('monitor', $r['recommended_action']);
    }

    public function test_ac4_no_suspects_returns_none(): void
    {
        $r = $this->detect([$this->cleanVariant()]);
        $this->assertSame('none', $r['recommended_action']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_deterministic_output(): void
    {
        $metrics = [
            $this->cleanVariant('a', ['gate_pass_rate' => 0.90, 'value_proof_rate' => 0.50]),
            $this->cleanVariant('b', ['template_repetition_score' => 0.80]),
        ];

        $this->assertSame(
            json_encode($this->detect($metrics), JSON_UNESCAPED_SLASHES),
            json_encode($this->detect($metrics), JSON_UNESCAPED_SLASHES),
        );
    }
}
