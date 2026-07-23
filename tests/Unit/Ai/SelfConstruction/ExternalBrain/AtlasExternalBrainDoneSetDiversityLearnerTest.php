<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDoneSetDiversityLearner;
use Tests\TestCase;

final class AtlasExternalBrainDoneSetDiversityLearnerTest extends TestCase
{
    private function svc(): AtlasExternalBrainDoneSetDiversityLearner
    {
        return new AtlasExternalBrainDoneSetDiversityLearner;
    }

    private function task(string $family, string $outcome = 'served'): array
    {
        return ['task_family' => $family, 'outcome' => $outcome];
    }

    // ── empty done-set ────────────────────────────────────────────────────────

    public function test_empty_done_set_gives_full_diversity(): void
    {
        $r = $this->svc()->learn([]);

        $this->assertEqualsWithDelta(1.0, $r['diversity_score'], 0.001);
        $this->assertSame([], $r['concentrated_families']);
        $this->assertSame([], $r['high_negative_signal_families']);
        $this->assertNotEmpty($r['missing_family_recommendations']);
    }

    // ── concentration detection ───────────────────────────────────────────────

    public function test_single_family_gives_zero_diversity(): void
    {
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('gate-impl'),
            $this->task('gate-impl'),
        ]);

        $this->assertEqualsWithDelta(0.0, $r['diversity_score'], 0.001);
        $this->assertContains('gate-impl', $r['concentrated_families']);
    }

    public function test_concentrated_family_detected_above_threshold(): void
    {
        // gate-impl = 3/4 = 75% > 50%
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('gate-impl'),
            $this->task('gate-impl'),
            $this->task('discovery'),
        ]);

        $this->assertContains('gate-impl', $r['concentrated_families']);
        $this->assertNotContains('discovery', $r['concentrated_families']);
    }

    public function test_equal_distribution_gives_high_diversity(): void
    {
        // 4 families, 1 each → max concentration = 0.25 → diversity = 0.75
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('discovery'),
            $this->task('research'),
            $this->task('bug-hunt'),
        ]);

        $this->assertGreaterThan(0.5, $r['diversity_score']);
        $this->assertSame([], $r['concentrated_families']);
    }

    // ── negative signals ──────────────────────────────────────────────────────

    public function test_high_refused_rate_is_negative_signal(): void
    {
        // gate-wiring: 1 served, 2 refused → refused_rate = 2/3 > 0.5
        $r = $this->svc()->learn([
            $this->task('gate-wiring', 'served'),
            $this->task('gate-wiring', 'refused'),
            $this->task('gate-wiring', 'refused'),
            $this->task('discovery', 'served'),
        ]);

        $this->assertContains('gate-wiring', $r['high_negative_signal_families']);
        $this->assertNotContains('discovery', $r['high_negative_signal_families']);
    }

    public function test_give_back_counts_as_negative_signal(): void
    {
        // bug-hunt: 0 served, 2 give_back → 100% negative
        $r = $this->svc()->learn([
            $this->task('bug-hunt', 'give_back'),
            $this->task('bug-hunt', 'give_back'),
            $this->task('discovery', 'served'),
        ]);

        $this->assertContains('bug-hunt', $r['high_negative_signal_families']);
    }

    public function test_negative_signal_families_excluded_from_recommendations(): void
    {
        // gate-wiring has high refused rate → should not be recommended
        $r = $this->svc()->learn([
            $this->task('gate-wiring', 'refused'),
            $this->task('gate-wiring', 'refused'),
            $this->task('discovery', 'served'),
        ]);

        $this->assertNotContains('gate-wiring', $r['missing_family_recommendations']);
    }

    // ── missing recommendations ───────────────────────────────────────────────

    public function test_missing_families_recommended(): void
    {
        // Only gate-impl in history → all others should be recommended (no negatives)
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('gate-impl'),
            $this->task('gate-impl'),
        ]);

        $this->assertContains('discovery', $r['missing_family_recommendations']);
        $this->assertContains('research', $r['missing_family_recommendations']);
        $this->assertNotContains('gate-impl', $r['missing_family_recommendations']);
    }

    public function test_successful_high_yield_family_not_recommended_when_present(): void
    {
        // gate-impl present with good served rate AND >10% share → not missing
        $tasks = [];
        for ($i = 0; $i < 5; $i++) {
            $tasks[] = $this->task('gate-impl');
        }
        $tasks[] = $this->task('discovery');
        $tasks[] = $this->task('research');
        $tasks[] = $this->task('bug-hunt');
        $tasks[] = $this->task('origination');
        $tasks[] = $this->task('telemetry-wiring');
        $r = $this->svc()->learn($tasks);

        // gate-impl is 5/10 = 50% → meets threshold; not underrepresented
        $this->assertNotContains('gate-impl', $r['missing_family_recommendations']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->learn([]);

        $this->assertSame(AtlasExternalBrainDoneSetDiversityLearner::SCHEMA, $r['schema_version']);
    }

    // ── AC1: template_signature concentration ────────────────────────────────

    public function test_template_concentration_always_present(): void
    {
        $r = $this->svc()->learn([]);
        $this->assertArrayHasKey('template_concentration', $r);
        $this->assertArrayHasKey('template_farm_detected', $r);
        $this->assertArrayHasKey('top_template_signature', $r);
    }

    public function test_template_concentration_zero_when_no_signatures(): void
    {
        $r = $this->svc()->learn([['task_family' => 'bug-hunt', 'outcome' => 'success']]);
        $this->assertSame(0.0, $r['template_concentration']);
        $this->assertFalse($r['template_farm_detected']);
        $this->assertNull($r['top_template_signature']);
    }

    public function test_template_farm_detected_when_one_sig_dominates(): void
    {
        $tasks = array_fill(0, 6, ['task_family' => 'bug-hunt', 'outcome' => 'success', 'template_signature' => 'tpl-A']);
        $tasks[] = ['task_family' => 'research', 'outcome' => 'success', 'template_signature' => 'tpl-B'];
        $tasks[] = ['task_family' => 'discovery', 'outcome' => 'success', 'template_signature' => 'tpl-B'];

        $r = $this->svc()->learn($tasks);

        $this->assertTrue($r['template_farm_detected']);
        $this->assertSame('tpl-A', $r['top_template_signature']);
        $this->assertGreaterThan(0.5, $r['template_concentration']);
    }

    // ── AC1: capability_family coverage ──────────────────────────────────────

    public function test_capability_family_coverage_zero_when_none_provided(): void
    {
        $r = $this->svc()->learn([['task_family' => 'bug-hunt', 'outcome' => 'success']]);
        $this->assertSame(0.0, $r['capability_family_coverage']);
    }

    public function test_capability_family_coverage_increases_with_distinct_families(): void
    {
        $tasks = [
            ['task_family' => 'bug-hunt',  'outcome' => 'success', 'capability_family' => 'self_improvement'],
            ['task_family' => 'research',  'outcome' => 'success', 'capability_family' => 'evidence'],
            ['task_family' => 'discovery', 'outcome' => 'success', 'capability_family' => 'origination'],
        ];
        $r = $this->svc()->learn($tasks);
        $this->assertGreaterThan(0.0, $r['capability_family_coverage']);
    }

    // ── AC1: repeated_objective_shape ────────────────────────────────────────

    public function test_dominant_objective_shape_null_when_no_shapes(): void
    {
        $r = $this->svc()->learn([['task_family' => 'bug-hunt', 'outcome' => 'success']]);
        $this->assertNull($r['dominant_objective_shape']);
    }

    public function test_dominant_objective_shape_detected_when_one_dominates(): void
    {
        $tasks = array_fill(0, 6, ['task_family' => 'bug-hunt', 'outcome' => 'success', 'objective_shape' => 'add-method']);
        $tasks[] = ['task_family' => 'research', 'outcome' => 'success', 'objective_shape' => 'refactor'];

        $r = $this->svc()->learn($tasks);
        $this->assertSame('add-method', $r['dominant_objective_shape']);
    }

    // ── AC2: high-leverage families first when template farm detected ─────────

    public function test_high_leverage_families_sorted_first_when_template_farm(): void
    {
        $tasks = array_fill(0, 6, [
            'task_family'        => 'telemetry-wiring',
            'outcome'            => 'success',
            'template_signature' => 'tpl-X',
        ]);
        $tasks[] = ['task_family' => 'research',  'outcome' => 'success', 'template_signature' => 'other'];
        $tasks[] = ['task_family' => 'discovery', 'outcome' => 'success', 'template_signature' => 'other'];

        $r = $this->svc()->learn($tasks);

        $this->assertTrue($r['template_farm_detected']);
        $missing = $r['missing_family_recommendations'];
        // First missing entries must include the high-leverage families that are missing.
        $highLeverageMissing = array_intersect($missing, ['bug-hunt', 'gate-impl', 'research']);
        if ($highLeverageMissing !== []) {
            $firstFew = array_slice($missing, 0, count($highLeverageMissing));
            foreach ($highLeverageMissing as $fam) {
                $this->assertContains($fam, $firstFew);
            }
        }
    }

    // ── AC1/AC2: recommendation driven by naming vs capability impact ────────

    public function test_concentrated_naming_with_diverse_capability_impact_recommends_continue(): void
    {
        $tasks = [
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-a']),
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-b']),
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-c']),
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-d']),
        ];

        $r = $this->svc()->learn($tasks);

        $this->assertLessThan(0.5, $r['diversity_score']);
        $this->assertGreaterThanOrEqual(0.5, $r['capability_impact_score']);
        $this->assertSame('continue_or_compound', $r['recommendation']);
    }

    public function test_concentrated_naming_with_concentrated_capability_impact_recommends_shift(): void
    {
        $tasks = [
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-a']),
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-a']),
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-a']),
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-a']),
        ];

        $r = $this->svc()->learn($tasks);

        $this->assertLessThan(0.5, $r['diversity_score']);
        $this->assertLessThan(0.5, $r['capability_impact_score']);
        $this->assertSame('shift_pattern', $r['recommendation']);
    }

    public function test_diverse_naming_recommends_continue_regardless_of_capability_impact(): void
    {
        $tasks = [
            array_merge($this->task('gate-impl'), ['capability_family' => 'cap-a']),
            array_merge($this->task('research'), ['capability_family' => 'cap-a']),
            array_merge($this->task('discovery'), ['capability_family' => 'cap-a']),
        ];

        $r = $this->svc()->learn($tasks);

        $this->assertGreaterThanOrEqual(0.5, $r['diversity_score']);
        $this->assertSame('continue_or_compound', $r['recommendation']);
    }

    // ── AC2: risk reduced, autonomy gained, downstream unlocks per family ────

    public function test_structural_impact_by_family_aggregates_risk_autonomy_and_unlocks(): void
    {
        $r = $this->svc()->learn([
            array_merge($this->task('gate-impl'), ['risk_reduced' => true]),
            array_merge($this->task('gate-impl'), ['autonomy_gained' => true, 'downstream_unlocks' => 2]),
            $this->task('discovery'),
        ]);

        $this->assertSame(1, $r['structural_impact_by_family']['gate-impl']['risk_reduced_count']);
        $this->assertSame(1, $r['structural_impact_by_family']['gate-impl']['autonomy_gained_count']);
        $this->assertSame(2, $r['structural_impact_by_family']['gate-impl']['downstream_unlocks_sum']);
        $this->assertContains('gate-impl', $r['high_impact_families']);
        $this->assertNotContains('discovery', $r['high_impact_families']);
    }

    public function test_family_with_only_repeated_commits_is_not_high_impact(): void
    {
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('gate-impl'),
            $this->task('gate-impl'),
        ]);

        $this->assertSame([], $r['high_impact_families']);
    }

    // ── AC4: discounting duplicated wrappers, cosmetic CLIs, behavior-neutral tasks ──

    public function test_duplicate_wrapper_tasks_are_discounted_from_diversity_score(): void
    {
        $r = $this->svc()->learn([
            $this->task('discovery'),
            array_merge($this->task('gate-impl'), ['is_duplicate_wrapper' => true]),
            array_merge($this->task('gate-impl'), ['is_duplicate_wrapper' => true]),
        ]);

        // Only the single non-discounted 'discovery' task counts toward diversity — one
        // family out of one surviving task is fully concentrated (score 0.0), not diverse.
        $this->assertEqualsWithDelta(0.0, $r['diversity_score'], 0.001);
        $this->assertSame(2, $r['discounted_task_count']);
        $this->assertSame(3, $r['total_delivered']);
    }

    public function test_cosmetic_cli_and_behavior_neutral_tasks_are_also_discounted(): void
    {
        $r = $this->svc()->learn([
            array_merge($this->task('gate-impl'), ['is_cosmetic_cli' => true]),
            array_merge($this->task('gate-impl'), ['is_behavior_neutral' => true]),
        ]);

        $this->assertSame(2, $r['discounted_task_count']);
        $this->assertSame(0, $r['total_delivered'] - $r['discounted_task_count']);
        $this->assertEqualsWithDelta(1.0, $r['diversity_score'], 0.001);
    }

    public function test_discounted_task_count_zero_and_total_delivered_matches_when_nothing_discounted(): void
    {
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('discovery'),
        ]);

        $this->assertSame(0, $r['discounted_task_count']);
        $this->assertSame(2, $r['total_delivered']);
    }

    // ── AC3: recommended next originator focus ────────────────────────────────

    public function test_recommended_focus_prefers_under_served_high_leverage_family(): void
    {
        // Only gate-impl delivered; bug-hunt, research, gate-impl itself are high-leverage —
        // gate-impl is present so it must not be recommended; bug-hunt should win as it is
        // first among KNOWN_FAMILIES that is also high-leverage and missing.
        $r = $this->svc()->learn([
            $this->task('gate-impl'),
            $this->task('gate-impl'),
            $this->task('gate-impl'),
        ]);

        $this->assertSame('bug-hunt', $r['recommended_next_originator_focus']);
    }

    public function test_recommended_focus_is_null_when_nothing_missing(): void
    {
        $tasks = [];
        foreach (['bug-hunt', 'discovery', 'gate-certification', 'gate-impl', 'gate-wiring', 'origination', 'research', 'telemetry-wiring', 'trend-measurement'] as $family) {
            $tasks[] = $this->task($family);
        }

        $r = $this->svc()->learn($tasks);

        $this->assertNull($r['recommended_next_originator_focus']);
    }

    public function test_recommended_focus_present_on_empty_done_set(): void
    {
        $r = $this->svc()->learn([]);

        $this->assertSame('bug-hunt', $r['recommended_next_originator_focus']);
    }
}
