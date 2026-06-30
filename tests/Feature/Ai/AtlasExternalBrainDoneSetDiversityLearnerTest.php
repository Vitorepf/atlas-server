<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainDoneSetDiversityLearner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainDoneSetDiversityLearnerTest extends TestCase
{
    private function learner(): AtlasExternalBrainDoneSetDiversityLearner
    {
        return new AtlasExternalBrainDoneSetDiversityLearner;
    }

    private function task(string $family, string $capFamily = '', string $outcome = 'served'): array
    {
        return ['task_family' => $family, 'capability_family' => $capFamily, 'outcome' => $outcome];
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->learner()->learn([]);

        foreach (['schema_version', 'diversity_score', 'capability_impact_score',
                  'recommendation', 'concentrated_families',
                  'high_negative_signal_families', 'missing_family_recommendations'] as $k) {
            $this->assertArrayHasKey($k, $r);
        }
    }

    // ── AC1: homogeneous done_set → low diversity_score + shift_pattern ───────

    public function test_homogeneous_task_family_produces_low_diversity_score(): void
    {
        $done = array_fill(0, 5, $this->task('gate-impl', 'gate'));

        $r = $this->learner()->learn($done);

        $this->assertLessThan(0.5, $r['diversity_score']);
    }

    public function test_homogeneous_done_set_recommends_shift_pattern(): void
    {
        // All same task_family AND same capability_family → both concentrated.
        $done = array_fill(0, 5, $this->task('gate-impl', 'gate'));

        $r = $this->learner()->learn($done);

        $this->assertSame('shift_pattern', $r['recommendation']);
    }

    public function test_homogeneous_no_capability_family_recommends_shift_pattern(): void
    {
        // All same task_family, no capability_family supplied → impact unknown → still shift.
        $done = array_fill(0, 5, $this->task('bug-hunt'));

        $r = $this->learner()->learn($done);

        $this->assertSame('shift_pattern', $r['recommendation']);
    }

    // ── AC2: diverse structural wins → continue_or_compound ──────────────────

    public function test_diverse_task_families_produce_high_diversity_score(): void
    {
        $done = [
            $this->task('bug-hunt',          'quality'),
            $this->task('gate-impl',         'gate'),
            $this->task('research',          'knowledge'),
            $this->task('telemetry-wiring',  'observability'),
            $this->task('origination',       'pipeline'),
        ];

        $r = $this->learner()->learn($done);

        $this->assertGreaterThanOrEqual(0.5, $r['diversity_score']);
    }

    public function test_diverse_structural_wins_recommend_continue_or_compound(): void
    {
        $done = [
            $this->task('bug-hunt',         'quality'),
            $this->task('gate-impl',        'gate'),
            $this->task('research',         'knowledge'),
            $this->task('telemetry-wiring', 'observability'),
            $this->task('origination',      'pipeline'),
        ];

        $r = $this->learner()->learn($done);

        $this->assertSame('continue_or_compound', $r['recommendation']);
    }

    public function test_diverse_capability_impact_produces_high_capability_impact_score(): void
    {
        $done = [
            $this->task('gate-impl', 'gate'),
            $this->task('gate-impl', 'quality'),
            $this->task('gate-impl', 'knowledge'),
            $this->task('gate-impl', 'pipeline'),
            $this->task('gate-impl', 'observability'),
        ];

        $r = $this->learner()->learn($done);

        $this->assertGreaterThanOrEqual(0.5, $r['capability_impact_score']);
    }

    // ── AC3: distinguishes repeated naming from repeated capability impact ────

    public function test_concentrated_naming_with_diverse_capability_impact_is_not_shift_pattern(): void
    {
        // Same task_family ("gate-impl") repeated, but different capability_family each time.
        // Naming is concentrated; impact is NOT → should continue_or_compound, not shift.
        $done = [
            $this->task('gate-impl', 'gate-quality'),
            $this->task('gate-impl', 'gate-observability'),
            $this->task('gate-impl', 'gate-pipeline'),
            $this->task('gate-impl', 'gate-knowledge'),
            $this->task('gate-impl', 'gate-resilience'),
        ];

        $r = $this->learner()->learn($done);

        $this->assertLessThan(0.5, $r['diversity_score'], 'naming should be concentrated');
        $this->assertGreaterThanOrEqual(0.5, $r['capability_impact_score'], 'impact should be diverse');
        $this->assertSame('continue_or_compound', $r['recommendation']);
    }

    public function test_naming_and_impact_both_concentrated_gives_shift_pattern(): void
    {
        // Same family AND same capability_family → both concentrated → shift.
        $done = array_fill(0, 6, $this->task('gate-impl', 'gate'));

        $r = $this->learner()->learn($done);

        $this->assertLessThan(0.5, $r['capability_impact_score']);
        $this->assertSame('shift_pattern', $r['recommendation']);
    }

    // ── Empty done_set edge case ──────────────────────────────────────────────

    public function test_empty_done_set_returns_defaults(): void
    {
        $r = $this->learner()->learn([]);

        $this->assertEqualsWithDelta(1.0, $r['diversity_score'], 0.001);
        $this->assertSame('continue_or_compound', $r['recommendation']);
    }

    // ── AC4: deterministic ────────────────────────────────────────────────────

    public function test_learn_is_deterministic(): void
    {
        $done = [
            $this->task('bug-hunt',    'quality'),
            $this->task('gate-impl',   'gate'),
            $this->task('gate-impl',   'gate'),
            $this->task('origination', 'pipeline'),
        ];

        $a = $this->learner()->learn($done);
        $b = $this->learner()->learn($done);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
