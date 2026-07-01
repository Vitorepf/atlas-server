<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationBlastRadiusSimulator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationBlastRadiusSimulatorTest extends TestCase
{
    private function simulator(): AtlasExternalBrainSimplificationBlastRadiusSimulator
    {
        return new AtlasExternalBrainSimplificationBlastRadiusSimulator;
    }

    // ── AC: blast_radius_score and affected_surfaces from all five categories ──

    public function test_score_and_affected_surfaces_computed_from_all_five_categories(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => [
            'consumers' => ['A'],
            'commands' => ['cmd:a'],
            'docs' => ['doc.md'],
            'tests' => ['FooTest'],
            'runtime_entrypoints' => ['route:/foo'],
        ]]);

        $this->assertSame(['consumers', 'commands', 'docs', 'tests', 'runtime_entrypoints'], $r['affected_surfaces']);
        $this->assertSame(2 + 3 + 1 + 1 + 5, $r['blast_radius_score']);
    }

    // ── AC: low_radius_case ──────────────────────────────────────────────────

    public function test_low_radius_case_has_low_risk_and_no_prework(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => ['docs' => ['doc.md']]]);

        $this->assertSame('low', $r['risk_level']);
        $this->assertSame([], $r['required_prework']);
        $this->assertFalse($r['has_unknown_surfaces']);
        $this->assertSame(1, $r['blast_radius_score']);
    }

    public function test_empty_surfaces_yields_zero_score_and_low_risk(): void
    {
        $r = $this->simulator()->simulate([]);

        $this->assertSame(0, $r['blast_radius_score']);
        $this->assertSame([], $r['affected_surfaces']);
        $this->assertSame('low', $r['risk_level']);
    }

    // ── AC: unknown_surface_risk_case ────────────────────────────────────────

    public function test_unknown_surface_risk_case_always_forces_high_risk(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => [
            'docs' => ['doc.md'],
            'unknown_surfaces' => ['mystery_caller'],
        ]]);

        $this->assertSame('high', $r['risk_level']);
        $this->assertTrue($r['has_unknown_surfaces']);
        $this->assertContains('unknown_surfaces', $r['affected_surfaces']);
    }

    public function test_unknown_surface_requires_exact_prework(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => ['unknown_surfaces' => ['weird_caller']]]);

        $this->assertContains('classify_unknown_surface:weird_caller', $r['required_prework']);
    }

    public function test_multiple_unknown_surfaces_each_get_prework(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => ['unknown_surfaces' => ['a', 'b']]]);

        $this->assertCount(2, $r['required_prework']);
    }

    // ── risk tiers from score alone (no unknowns) ────────────────────────────

    public function test_medium_risk_tier_from_score_alone(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => ['commands' => ['a', 'b']]]);

        $this->assertSame(6, $r['blast_radius_score']);
        $this->assertSame('medium', $r['risk_level']);
    }

    public function test_high_risk_tier_from_score_alone_without_unknowns(): void
    {
        $r = $this->simulator()->simulate(['surfaces' => ['runtime_entrypoints' => ['a', 'b', 'c']]]);

        $this->assertSame(15, $r['blast_radius_score']);
        $this->assertSame('high', $r['risk_level']);
        $this->assertFalse($r['has_unknown_surfaces']);
    }

    // ── Determinism ────────────────────────────────────────────────────────

    public function test_simulate_is_deterministic(): void
    {
        $facts = ['surfaces' => ['consumers' => ['A'], 'unknown_surfaces' => ['x']]];
        $a = $this->simulator()->simulate($facts);
        $b = $this->simulator()->simulate($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->simulator()->simulate([]);
        $this->assertSame(AtlasExternalBrainSimplificationBlastRadiusSimulator::SCHEMA, $r['schema']);
    }
}
