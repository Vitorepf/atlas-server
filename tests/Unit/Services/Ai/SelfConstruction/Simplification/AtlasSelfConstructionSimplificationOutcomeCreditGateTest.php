<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai\SelfConstruction\Simplification;

use App\Services\Ai\SelfConstruction\Simplification\AtlasSelfConstructionSimplificationOutcomeCreditGate;
use Tests\TestCase;

final class AtlasSelfConstructionSimplificationOutcomeCreditGateTest extends TestCase
{
    private function gate(): AtlasSelfConstructionSimplificationOutcomeCreditGate
    {
        return new AtlasSelfConstructionSimplificationOutcomeCreditGate;
    }

    // ── AC: deletion without capability loss credits ──

    public function test_deletion_with_capability_preserved_credits(): void
    {
        $result = $this->gate()->evaluate([
            'files_deleted' => 3,
            'lines_removed' => 150,
            'capability_preserved' => true,
        ]);

        $this->assertSame('credited', $result['verdict']);
        $this->assertGreaterThan(0, $result['credit']);
    }

    // ── AC: cosmetic reshuffle receives zero credit ──

    public function test_cosmetic_reshuffle_zero_credit(): void
    {
        $result = $this->gate()->evaluate([
            'cosmetic_reshuffle' => true,
            'files_deleted' => 0,
            'capability_preserved' => true,
        ]);

        $this->assertSame('zero_credit', $result['verdict']);
        $this->assertSame(0, $result['credit']);
        $this->assertContains('cosmetic_reshuffle_no_capability_preservation', $result['reasons']);
    }

    // ── cyclomatic reduction credits ──

    public function test_cyclomatic_reduction_credits(): void
    {
        $result = $this->gate()->evaluate([
            'cyclomatic_reduction' => 5,
            'capability_preserved' => true,
        ]);

        $this->assertSame('credited', $result['verdict']);
        $this->assertSame(5, $result['credit']);
    }

    // ── no meaningful reduction → zero credit ──

    public function test_no_meaningful_reduction_zero_credit(): void
    {
        $result = $this->gate()->evaluate([]);

        $this->assertSame('zero_credit', $result['verdict']);
        $this->assertSame(0, $result['credit']);
    }

    // ── deletion without capability preservation → zero credit ──

    public function test_deletion_without_capability_preservation_zero_credit(): void
    {
        $result = $this->gate()->evaluate([
            'files_deleted' => 2,
            'capability_preserved' => false,
        ]);

        $this->assertSame('zero_credit', $result['verdict']);
    }

    // ── output structure ──

    public function test_output_has_required_keys(): void
    {
        $result = $this->gate()->evaluate([]);

        $this->assertSame(AtlasSelfConstructionSimplificationOutcomeCreditGate::SCHEMA, $result['schema_version']);
        $this->assertArrayHasKey('verdict', $result);
        $this->assertArrayHasKey('credit', $result);
    }

    public function test_result_is_deterministic(): void
    {
        $outcome = ['files_deleted' => 2, 'capability_preserved' => true];

        $a = $this->gate()->evaluate($outcome);
        $b = $this->gate()->evaluate($outcome);

        $this->assertSame(json_encode($a), json_encode($b));
    }
}
