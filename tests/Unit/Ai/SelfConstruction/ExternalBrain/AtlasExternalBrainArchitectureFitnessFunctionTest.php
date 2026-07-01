<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainArchitectureFitnessFunction;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainArchitectureFitnessFunctionTest extends TestCase
{
    private function fitness(): AtlasExternalBrainArchitectureFitnessFunction
    {
        return new AtlasExternalBrainArchitectureFitnessFunction;
    }

    private function metrics(array $overrides = []): array
    {
        return array_merge([
            'entropy_score' => 0.5,
            'coupling_score' => 0.5,
            'proof_coverage' => 0.8,
            'capability_score' => 1.0,
            'line_count' => 200,
        ], $overrides);
    }

    // ── AC: fitness_improved_case ──────────────────────────────────────────

    public function test_fitness_improved_case_when_all_dimensions_improve_together(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['entropy_score' => 0.3, 'proof_coverage' => 0.9, 'line_count' => 150]),
        ]);

        $this->assertTrue($r['fitness_improved']);
        $this->assertSame([], $r['regressions']);
    }

    public function test_fitness_improved_when_only_line_count_shrinks_and_nothing_regresses(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['line_count' => 150]),
        ]);

        $this->assertTrue($r['fitness_improved']);
    }

    // ── AC: line_only_regression_case — line reduction alone must not pass ──

    public function test_line_only_regression_case_fails_when_capability_regresses(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['line_count' => 50, 'capability_score' => 0.6]),
        ]);

        $this->assertFalse($r['fitness_improved']);
        $this->assertContains('capability_regressed', $r['regressions']);
    }

    public function test_line_only_regression_case_fails_when_proof_coverage_regresses(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['line_count' => 50, 'proof_coverage' => 0.4]),
        ]);

        $this->assertFalse($r['fitness_improved']);
        $this->assertContains('proof_coverage_regressed', $r['regressions']);
    }

    public function test_entropy_regression_fails_the_gate(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['entropy_score' => 0.9]),
        ]);

        $this->assertFalse($r['fitness_improved']);
        $this->assertContains('entropy_regressed', $r['regressions']);
    }

    public function test_coupling_regression_fails_the_gate(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['coupling_score' => 0.9]),
        ]);

        $this->assertFalse($r['fitness_improved']);
        $this->assertContains('coupling_regressed', $r['regressions']);
    }

    public function test_no_change_is_not_improved(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(),
        ]);

        $this->assertFalse($r['fitness_improved']);
        $this->assertSame([], $r['regressions']);
    }

    // ── Deltas ────────────────────────────────────────────────────────────

    public function test_deltas_are_computed_for_all_five_metrics(): void
    {
        $r = $this->fitness()->compute([
            'before' => $this->metrics(),
            'after' => $this->metrics(['line_count' => 150]),
        ]);

        foreach (['entropy', 'coupling', 'proof_coverage', 'capability', 'line_count'] as $key) {
            $this->assertArrayHasKey($key, $r['deltas']);
        }
        $this->assertSame(-50.0, $r['deltas']['line_count']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_compute_is_deterministic(): void
    {
        $facts = ['before' => $this->metrics(), 'after' => $this->metrics(['line_count' => 150])];
        $a = $this->fitness()->compute($facts);
        $b = $this->fitness()->compute($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->fitness()->compute([]);
        $this->assertSame(AtlasExternalBrainArchitectureFitnessFunction::SCHEMA, $r['schema']);
    }
}
