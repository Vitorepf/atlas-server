<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Models\AtlasLoopConfidenceSample;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * ITEM10 — the confidence-calibration flywheel command is REPORT-ONLY and HONEST: it returns null
 * (insufficient evidence) until enough well-calibrated samples accrue, and it never arms the gate.
 */
final class AtlasLoopConfidenceCalibrateCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! Schema::hasTable('atlas_loop_confidence_samples')) {
            (require base_path('database/migrations/2026_06_15_000100_create_atlas_loop_confidence_samples_table.php'))->up();
        }

        // Mirror the integrator-wired config block so the test does not depend on config/atlas.php.
        config([
            'atlas.loop.confidence_calibration.enabled' => true,
            'atlas.loop.confidence_calibration.target_precision' => 0.93,
            'atlas.loop.confidence_calibration.min_samples' => 20,
        ]);
    }

    private function seedSample(float $predicted, bool $correct): void
    {
        AtlasLoopConfidenceSample::create([
            'proposal_id' => (string) Str::uuid(),
            'predicted' => $predicted,
            'correct' => $correct,
        ]);
    }

    public function test_insufficient_evidence_cannot_arm(): void
    {
        // 10 samples (< the 20 min) — no threshold can clear the bar with enough samples.
        for ($i = 0; $i < 10; $i++) {
            $this->seedSample(0.95, true);
        }

        $this->artisan('atlas:loop:confidence-calibrate --json')
            ->assertExitCode(0);

        // Re-run capturing the JSON to assert the honest null recommendation.
        $exit = $this->artisan('atlas:loop:confidence-calibrate');
        $exit->expectsOutputToContain('Insufficient evidence to arm')->assertExitCode(0);
    }

    public function test_well_calibrated_evidence_recommends_a_threshold(): void
    {
        // 30 high-confidence (0.95) samples, 29 correct (~0.967 precision >= 0.93) => arm at 0.95.
        for ($i = 0; $i < 30; $i++) {
            $this->seedSample(0.95, $i !== 0);
        }
        // plus 30 low-confidence (0.4) mostly-wrong samples (correctly NOT armed at the low band).
        for ($i = 0; $i < 30; $i++) {
            $this->seedSample(0.4, $i < 5);
        }

        $this->artisan('atlas:loop:confidence-calibrate')
            ->expectsOutputToContain('Recommended arm threshold')
            ->assertExitCode(0);
    }

    public function test_command_never_arms_the_confidence_gate(): void
    {
        config(['atlas.loop.confidence_gate_enabled' => false]);
        for ($i = 0; $i < 30; $i++) {
            $this->seedSample(0.95, $i !== 0);
        }

        $this->artisan('atlas:loop:confidence-calibrate --json')->assertExitCode(0);

        // The gate stays operator-armed: the command computes the number but never flips the flag.
        $this->assertFalse((bool) config('atlas.loop.confidence_gate_enabled'));
    }
}
