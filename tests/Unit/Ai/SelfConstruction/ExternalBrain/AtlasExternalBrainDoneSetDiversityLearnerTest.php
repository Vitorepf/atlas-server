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
}
