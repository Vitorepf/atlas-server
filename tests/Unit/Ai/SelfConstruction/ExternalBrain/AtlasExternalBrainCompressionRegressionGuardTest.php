<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCompressionRegressionGuard;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCompressionRegressionGuardTest extends TestCase
{
    private function guard(): AtlasExternalBrainCompressionRegressionGuard
    {
        return new AtlasExternalBrainCompressionRegressionGuard;
    }

    private function snapshot(array $overrides = []): array
    {
        return array_merge([
            'test_count' => 100,
            'docs_sync' => true,
            'capability_coverage' => 0.9,
            'worker_yield' => 5.0,
        ], $overrides);
    }

    public function test_approve_preserved_floor_case(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(),
        ]);

        $this->assertSame('approve', $r['decision']);
        $this->assertSame([], $r['violated_floors']);
        $this->assertSame([], $r['required_repair_actions']);
    }

    public function test_approve_when_floors_improved(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(['test_count' => 120, 'capability_coverage' => 0.95, 'worker_yield' => 6.0]),
        ]);

        $this->assertSame('approve', $r['decision']);
    }

    public function test_hold_regression_case_test_count_dropped(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(['test_count' => 80]),
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('test_count', $r['violated_floors']);
        $this->assertContains('restore_removed_test_coverage', $r['required_repair_actions']);
    }

    public function test_hold_when_docs_sync_regresses(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(['docs_sync' => true]),
            'after' => $this->snapshot(['docs_sync' => false]),
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('docs_sync', $r['violated_floors']);
        $this->assertContains('resync_documentation_before_release', $r['required_repair_actions']);
    }

    public function test_docs_sync_going_false_to_true_is_never_a_violation(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(['docs_sync' => false]),
            'after' => $this->snapshot(['docs_sync' => true]),
        ]);

        $this->assertSame('approve', $r['decision']);
    }

    public function test_hold_when_capability_coverage_regresses(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(['capability_coverage' => 0.5]),
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('capability_coverage', $r['violated_floors']);
    }

    public function test_hold_when_worker_yield_regresses(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(['worker_yield' => 1.0]),
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('worker_yield', $r['violated_floors']);
    }

    public function test_multiple_regressions_all_named_with_repair_actions(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(['test_count' => 10, 'worker_yield' => 0.0]),
        ]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('test_count', $r['violated_floors']);
        $this->assertContains('worker_yield', $r['violated_floors']);
        $this->assertCount(2, $r['required_repair_actions']);
    }

    public function test_missing_before_snapshot_fails_closed(): void
    {
        $r = $this->guard()->evaluate(['after' => $this->snapshot()]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('before_snapshot', $r['violated_floors']);
    }

    public function test_missing_after_snapshot_fails_closed(): void
    {
        $r = $this->guard()->evaluate(['before' => $this->snapshot()]);

        $this->assertSame('hold', $r['decision']);
        $this->assertContains('after_snapshot', $r['violated_floors']);
    }

    public function test_schema_present(): void
    {
        $r = $this->guard()->evaluate([
            'before' => $this->snapshot(),
            'after' => $this->snapshot(),
        ]);

        $this->assertSame(AtlasExternalBrainCompressionRegressionGuard::SCHEMA, $r['schema']);
    }
}
