<?php

namespace Tests\Unit\Ai\Rivals2;

use App\Services\Ai\Rivals2\Core\DifficultyCalibrator;
use Tests\TestCase;

class DifficultyCalibratorTest extends TestCase
{
    public function test_bands_follow_operator_ruler(): void
    {
        $c = new DifficultyCalibrator;
        $this->assertSame('too_easy', $c->bandFor(0.80));
        $this->assertSame('too_easy', $c->bandFor(0.36));
        $this->assertSame('borderline', $c->bandFor(0.33));
        $this->assertSame('elite_valid', $c->bandFor(0.30));
        $this->assertSame('elite_valid', $c->bandFor(0.25));
        $this->assertSame('elite_valid', $c->bandFor(0.20));
        $this->assertSame('hard', $c->bandFor(0.10));
        // <5% é frontier/hardcore — VÁLIDA, não inválida
        $this->assertSame('frontier', $c->bandFor(0.02));
        $this->assertSame('frontier', $c->bandFor(0.0));
    }

    public function test_suite_band_comes_from_strongest_bare_arm_and_flags_too_easy(): void
    {
        $rows = [
            ['task_type' => 'senior_bug_investigation', 'arm_id' => 'model_a@bare', 'success_rate' => 0.25],
            ['task_type' => 'senior_bug_investigation', 'arm_id' => 'model_b@bare', 'success_rate' => 0.80],
            // harness e runtimes Atlas nunca calibram a suite
            ['task_type' => 'senior_bug_investigation', 'arm_id' => 'harness_golden@bare', 'success_rate' => 1.0],
            ['task_type' => 'senior_bug_investigation', 'arm_id' => 'model_a@atlas_dev', 'success_rate' => 0.9],
        ];
        $result = (new DifficultyCalibrator)->calibrate($rows);

        $this->assertSame('too_easy', $result['suite_band']);
        $this->assertSame('model_b@bare', $result['baseline']['arm_id']);
        $this->assertSame('elite_valid', $result['row_bands']['senior_bug_investigation|model_a@bare']);
        $this->assertCount(1, $result['flags']);
        $this->assertStringContainsString('model_b@bare', $result['flags'][0]);
        $this->assertArrayNotHasKey('senior_bug_investigation|harness_golden@bare', $result['row_bands']);
    }

    public function test_no_bare_model_arm_means_uncalibrated_never_fabricated(): void
    {
        $rows = [['task_type' => 'x', 'arm_id' => 'harness_golden@bare', 'success_rate' => 1.0]];
        $result = (new DifficultyCalibrator)->calibrate($rows);

        $this->assertNull($result['suite_band']);
        $this->assertNull($result['baseline']);
    }
}
