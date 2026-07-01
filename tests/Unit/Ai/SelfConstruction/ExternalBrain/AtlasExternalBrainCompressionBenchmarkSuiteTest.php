<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionBenchmarkSuite;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionBenchmarkSuiteTest extends TestCase
{
    private function suite(): AtlasExternalBrainCompressionBenchmarkSuite
    {
        return new AtlasExternalBrainCompressionBenchmarkSuite;
    }

    private function passingFacts(array $overrides = []): array
    {
        return array_merge([
            'line_reduction_score' => 0.8,
            'behavior_preservation_score' => 0.95,
            'test_strength_score' => 0.9,
            'rollback_readiness_score' => 0.7,
            'worker_yield_preservation_score' => 0.75,
        ], $overrides);
    }

    public function test_passing_benchmark_case(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts());

        $this->assertSame('approve', $r['decision']);
        $this->assertSame([], $r['failed_floors']);
        foreach (['line_reduction', 'behavior_preservation', 'test_strength', 'rollback_readiness', 'worker_yield_preservation'] as $floor) {
            $this->assertArrayHasKey($floor, $r['floor_scores']);
        }
    }

    public function test_failed_floor_hold_case_behavior_preservation_below_threshold(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts(['behavior_preservation_score' => 0.3]));

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('behavior_preservation', $r['failed_floors']);
    }

    public function test_strong_line_reduction_does_not_offset_a_failed_floor(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts([
            'line_reduction_score' => 1.0,
            'test_strength_score' => 0.1,
        ]));

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('test_strength', $r['failed_floors']);
    }

    public function test_missing_scores_default_to_zero_and_fail_every_floor(): void
    {
        $r = $this->suite()->evaluate([]);

        $this->assertSame('hold', $r['decision']);
        $this->assertCount(5, $r['failed_floors']);
        foreach ($r['floor_scores'] as $score) {
            $this->assertSame(0.0, $score);
        }
    }

    public function test_multiple_failed_floors_all_named(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts([
            'rollback_readiness_score' => 0.1,
            'worker_yield_preservation_score' => 0.2,
        ]));

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('rollback_readiness', $r['failed_floors']);
        $this->assertContains('worker_yield_preservation', $r['failed_floors']);
        $this->assertCount(2, $r['failed_floors']);
    }

    public function test_custom_threshold_is_honored(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts(['threshold' => 0.99]));

        $this->assertSame('hold', $r['decision']);
        $this->assertSame(0.99, $r['threshold']);
    }

    public function test_scores_are_clamped_between_zero_and_one(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts(['line_reduction_score' => 5.0, 'test_strength_score' => -3.0]));

        $this->assertSame(1.0, $r['floor_scores']['line_reduction']);
        $this->assertSame(0.0, $r['floor_scores']['test_strength']);
    }

    public function test_schema_present(): void
    {
        $r = $this->suite()->evaluate($this->passingFacts());

        $this->assertSame(AtlasExternalBrainCompressionBenchmarkSuite::SCHEMA, $r['schema']);
    }
}
