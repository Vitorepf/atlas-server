<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainHintEntropyRestorationPlanner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainHintEntropyRestorationPlannerTest extends TestCase
{
    private AtlasExternalBrainHintEntropyRestorationPlanner $planner;

    protected function setUp(): void
    {
        $this->planner = new AtlasExternalBrainHintEntropyRestorationPlanner;
    }

    private function base(array $overrides = []): array
    {
        return array_merge([
            'hint_entropy'             => 0.80,
            'recent_batch_families'    => ['frontier-harvest', 'compounding', 'pattern-design'],
            'starved_paths'            => ['adversarial-critique', 'simulation-twin'],
            'dominant_vein'            => 'frontier-harvest',
            'dominant_vein_compounding' => true,
            'batch_size'               => 10,
        ], $overrides);
    }

    // ── Schema / AC4 required keys ────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->planner->plan($this->base());

        foreach (['schema', 'target_entropy_floor', 'required_hint_families', 'banned_repeated_families', 'allowed_exceptions'] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainHintEntropyRestorationPlanner::SCHEMA, $result['schema']);
    }

    // ── AC3: dominant compounding vein preserved as exception ─────────────────

    public function test_compounding_dominant_vein_is_in_allowed_exceptions(): void
    {
        $result = $this->planner->plan($this->base());

        $this->assertContains('frontier-harvest', $result['allowed_exceptions']);
    }

    public function test_non_compounding_dominant_vein_is_not_in_exceptions(): void
    {
        $result = $this->planner->plan($this->base(['dominant_vein_compounding' => false]));

        $this->assertNotContains('frontier-harvest', $result['allowed_exceptions']);
    }

    // ── AC2: stronger constraints at low entropy ───────────────────────────────

    public function test_critical_entropy_produces_high_target_floor(): void
    {
        $result = $this->planner->plan($this->base(['hint_entropy' => 0.10]));

        $this->assertGreaterThanOrEqual(0.60, $result['target_entropy_floor']);
    }

    public function test_moderate_entropy_produces_medium_target_floor(): void
    {
        $result = $this->planner->plan($this->base(['hint_entropy' => 0.45]));

        $this->assertGreaterThanOrEqual(0.40, $result['target_entropy_floor']);
        $this->assertLessThan(0.65, $result['target_entropy_floor']);
    }

    public function test_high_entropy_produces_minimum_floor(): void
    {
        $result = $this->planner->plan($this->base(['hint_entropy' => 0.90]));

        $this->assertSame(0.30, $result['target_entropy_floor']);
    }

    // ── AC2: concentrated family is banned at low entropy ────────────────────

    public function test_family_appearing_over_50_percent_of_recent_batches_is_banned_at_low_entropy(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'          => 0.10,
            'recent_batch_families' => ['compounding', 'compounding', 'compounding', 'frontier-harvest'],
        ]));

        $this->assertContains('compounding', $result['banned_repeated_families']);
    }

    public function test_no_ban_at_high_entropy(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'          => 0.85,
            'recent_batch_families' => ['compounding', 'compounding', 'compounding'],
        ]));

        $this->assertSame([], $result['banned_repeated_families']);
    }

    // ── AC2: starved paths appear in required families ────────────────────────

    public function test_starved_paths_appear_in_required_hint_families(): void
    {
        $result = $this->planner->plan($this->base([
            'starved_paths' => ['adversarial-critique', 'simulation-twin'],
        ]));

        $this->assertContains('adversarial-critique', $result['required_hint_families']);
        $this->assertContains('simulation-twin', $result['required_hint_families']);
    }

    public function test_banned_family_excluded_from_required_even_if_starved(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'          => 0.10,
            'recent_batch_families' => ['adversarial-critique', 'adversarial-critique', 'adversarial-critique'],
            'starved_paths'         => ['adversarial-critique'],  // starved but also banned
        ]));

        $this->assertNotContains('adversarial-critique', $result['required_hint_families']);
    }

    // ── AC3: at least one orthogonal probe required when critical ─────────────

    public function test_critical_entropy_with_no_starved_paths_adds_orthogonal_probe_signal(): void
    {
        $result = $this->planner->plan($this->base([
            'hint_entropy'   => 0.05,
            'starved_paths'  => [],
            'dominant_vein'  => 'frontier-harvest',
        ]));

        // Must have at least one entry signalling orthogonal probe need
        $this->assertNotEmpty($result['required_hint_families']);
    }

    // ── Dominant vein not duplicated in required when it's in exceptions ──────

    public function test_dominant_compounding_vein_not_in_required_hint_families(): void
    {
        $result = $this->planner->plan($this->base([
            'starved_paths'            => ['frontier-harvest', 'simulation-twin'],
            'dominant_vein'            => 'frontier-harvest',
            'dominant_vein_compounding' => true,
        ]));

        $this->assertNotContains('frontier-harvest', $result['required_hint_families']);
        $this->assertContains('simulation-twin', $result['required_hint_families']);
    }

    public function test_compounding_exception_at_non_critical_entropy_still_requires_orthogonal_probe(): void
    {
        // High entropy (not critical) but a compounding exception is granted, and no starved
        // path differs from the dominant vein — an orthogonal probe must still be required.
        $result = $this->planner->plan($this->base([
            'hint_entropy'              => 0.90,
            'starved_paths'             => [],
            'dominant_vein'             => 'frontier-harvest',
            'dominant_vein_compounding' => true,
        ]));

        $this->assertContains('orthogonal_probe_required', $result['required_hint_families']);
    }

    // ── Empty inputs ──────────────────────────────────────────────────────────

    public function test_empty_recent_batches_produces_no_banned_families(): void
    {
        $result = $this->planner->plan($this->base([
            'recent_batch_families' => [],
            'hint_entropy'          => 0.05,
        ]));

        $this->assertSame([], $result['banned_repeated_families']);
    }

    public function test_target_entropy_floor_is_always_between_zero_and_one(): void
    {
        foreach ([0.0, 0.1, 0.3, 0.6, 1.0] as $entropy) {
            $result = $this->planner->plan($this->base(['hint_entropy' => $entropy]));
            $this->assertGreaterThanOrEqual(0.0, $result['target_entropy_floor']);
            $this->assertLessThanOrEqual(1.0, $result['target_entropy_floor']);
        }
    }

    // ── measureAndRecommend ─────────────────────────────────────────────────────

    private function hint(array $overrides = []): array
    {
        return array_merge([
            'capability_area' => 'queue_governance',
            'task_family' => 'lease_repair',
            'evidence_source' => 'github_issues',
            'expected_impact' => 'medium',
            'normalized_template_signature' => 'sig_lease_repair',
        ], $overrides);
    }

    public function test_measure_output_has_required_keys(): void
    {
        $r = $this->planner->measureAndRecommend(['recent_hints' => [$this->hint()]]);

        foreach (['diversity_by_axis', 'overall_diversity_score', 'template_collapse_detected', 'recommendation', 'reason'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
        $this->assertSame(AtlasExternalBrainHintEntropyRestorationPlanner::SCHEMA, $r['schema']);
    }

    public function test_high_diversity_across_all_axes_recommends_maintain(): void
    {
        $r = $this->planner->measureAndRecommend(['recent_hints' => [
            $this->hint(['capability_area' => 'a1', 'task_family' => 'f1', 'evidence_source' => 'e1', 'expected_impact' => 'high', 'normalized_template_signature' => 's1']),
            $this->hint(['capability_area' => 'a2', 'task_family' => 'f2', 'evidence_source' => 'e2', 'expected_impact' => 'low', 'normalized_template_signature' => 's2']),
            $this->hint(['capability_area' => 'a3', 'task_family' => 'f3', 'evidence_source' => 'e3', 'expected_impact' => 'medium', 'normalized_template_signature' => 's3']),
            $this->hint(['capability_area' => 'a4', 'task_family' => 'f4', 'evidence_source' => 'e4', 'expected_impact' => 'critical', 'normalized_template_signature' => 's4']),
        ]]);

        $this->assertSame('maintain_current_breadth', $r['recommendation']);
        $this->assertFalse($r['template_collapse_detected']);
    }

    public function test_collapsed_evidence_source_recommends_change_search_method(): void
    {
        $hints = [];
        for ($i = 0; $i < 5; $i++) {
            $hints[] = $this->hint(['capability_area' => "a{$i}", 'task_family' => "f{$i}", 'evidence_source' => 'same_source', 'normalized_template_signature' => "sig{$i}"]);
        }

        $r = $this->planner->measureAndRecommend(['recent_hints' => $hints]);

        $this->assertSame('change_search_method', $r['recommendation']);
        $this->assertContains('orthogonal_probe:evidence_source', $r['required_hint_families']);
    }

    public function test_collapsed_capability_area_recommends_rotate_area(): void
    {
        $hints = [];
        for ($i = 0; $i < 5; $i++) {
            $hints[] = $this->hint(['capability_area' => 'same_area', 'task_family' => "f{$i}", 'evidence_source' => "e{$i}", 'normalized_template_signature' => "sig{$i}"]);
        }

        $r = $this->planner->measureAndRecommend(['recent_hints' => $hints]);

        $this->assertSame('rotate_area', $r['recommendation']);
    }

    public function test_collapsed_expected_impact_recommends_consolidate(): void
    {
        $hints = [];
        for ($i = 0; $i < 5; $i++) {
            $hints[] = $this->hint(['capability_area' => "a{$i}", 'task_family' => "f{$i}", 'evidence_source' => "e{$i}", 'expected_impact' => 'medium', 'normalized_template_signature' => "sig{$i}"]);
        }

        $r = $this->planner->measureAndRecommend(['recent_hints' => $hints]);

        $this->assertSame('consolidate', $r['recommendation']);
    }

    public function test_renamed_template_variants_detected_as_collapse_not_real_diversity(): void
    {
        // Different task_family labels (looks diverse) but identical underlying
        // template signature (a renamed template-farm variant) — must NOT be
        // accepted as restored diversity.
        $hints = [];
        for ($i = 0; $i < 6; $i++) {
            $hints[] = $this->hint(['capability_area' => "a{$i}", 'task_family' => "renamed_family_{$i}", 'evidence_source' => "e{$i}", 'normalized_template_signature' => 'identical_template_signature']);
        }

        $r = $this->planner->measureAndRecommend(['recent_hints' => $hints]);

        $this->assertTrue($r['template_collapse_detected']);
        $this->assertSame('inspect_negative_results', $r['recommendation']);
    }

    public function test_template_collapse_outranks_other_axis_collapses(): void
    {
        $hints = [];
        for ($i = 0; $i < 6; $i++) {
            $hints[] = $this->hint(['capability_area' => 'same_area', 'task_family' => "renamed_family_{$i}", 'evidence_source' => 'same_source', 'normalized_template_signature' => 'identical_template_signature']);
        }

        $r = $this->planner->measureAndRecommend(['recent_hints' => $hints]);

        $this->assertSame('inspect_negative_results', $r['recommendation']);
    }

    public function test_empty_hints_returns_zero_diversity_and_maintain(): void
    {
        $r = $this->planner->measureAndRecommend(['recent_hints' => []]);

        $this->assertSame(0.0, $r['overall_diversity_score']);
        $this->assertFalse($r['template_collapse_detected']);
    }

    // ── AC: plan() axis diversity ──────────────────────────────────────────────

    public function test_plan_computes_low_axis_diversity_for_all_four_axes(): void
    {
        $recentHints = array_fill(0, 5, [
            'capability_area' => 'loop',
            'task_family' => 'bug_fix',
            'evidence_source' => 'grep',
            'expected_impact' => 'medium',
        ]);

        $r = $this->planner->plan(['recent_hints' => $recentHints]);

        foreach (['capability_area', 'task_family', 'evidence_source', 'expected_impact'] as $axis) {
            $this->assertTrue($r['low_axis_diversity'][$axis], "expected {$axis} to be flagged low-diversity");
            $this->assertContains($axis, $r['axis_diversity_gaps']);
        }
    }

    public function test_plan_preserves_compounding_dominant_vein_only_when_orthogonal_probe_required(): void
    {
        $r = $this->planner->plan([
            'hint_entropy' => 0.90,
            'dominant_vein' => 'compounding_wiring',
            'dominant_vein_compounding' => true,
            'starved_paths' => ['orthogonal_family'],
        ]);

        $this->assertContains('compounding_wiring', $r['allowed_exceptions']);
        $this->assertNotEmpty($r['required_hint_families']);
        $this->assertNotContains('compounding_wiring', $r['required_hint_families']);
    }

    public function test_plan_output_includes_target_entropy_floor_required_families_banned_families_and_axis_gaps(): void
    {
        $r = $this->planner->plan([]);

        foreach (['target_entropy_floor', 'required_hint_families', 'banned_repeated_families', 'axis_diversity_gaps'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key: {$key}");
        }
    }
}
