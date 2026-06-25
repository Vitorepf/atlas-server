<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Introspection\AtlasLoopSelfCapabilityCoverageReporter;
use Tests\TestCase;

class AtlasLoopSelfCapabilityCoverageReporterTest extends TestCase
{
    public function test_registry_contains_canonical_phase_names(): void
    {
        foreach (['orient', 'comprehend', 'decide_leverage', 'architect', 'decompose', 'implement', 'certify', 'close_to_main', 'learn'] as $cap) {
            self::assertArrayHasKey($cap, AtlasLoopSelfCapabilityCoverageReporter::REGISTRY, "missing canonical capability: {$cap}");
        }
    }

    public function test_scan_returns_per_capability_facts_with_required_shape(): void
    {
        $verdict = (new AtlasLoopSelfCapabilityCoverageReporter)->scan();

        self::assertArrayHasKey('capabilities', $verdict);
        foreach ($verdict['capabilities'] as $capability => $row) {
            self::assertContains($row['state'], [
                AtlasLoopSelfCapabilityCoverageReporter::STATE_MISSING,
                AtlasLoopSelfCapabilityCoverageReporter::STATE_BUILT_ONLY,
                AtlasLoopSelfCapabilityCoverageReporter::STATE_BUILT,
                AtlasLoopSelfCapabilityCoverageReporter::STATE_ARMED,
            ], "unknown state for {$capability}");
            self::assertArrayHasKey('class_fqcn', $row);
            self::assertArrayHasKey('wiring_evidence', $row);
            self::assertArrayHasKey('scanned_at', $row);
        }
    }

    public function test_no_scalar_coverage_or_score_field_in_output(): void
    {
        $verdict = (new AtlasLoopSelfCapabilityCoverageReporter)->scan();

        $flatKeys = [];
        $walk = function ($v) use (&$walk, &$flatKeys): void {
            if (! is_array($v)) {
                return;
            }
            foreach ($v as $k => $sub) {
                if (is_string($k)) {
                    $flatKeys[] = $k;
                }
                $walk($sub);
            }
        };
        $walk($verdict);

        foreach ($flatKeys as $key) {
            self::assertDoesNotMatchRegularExpression('/(percent|score|grade|rating|coverage_score)/i', $key);
        }
    }

    public function test_scan_detects_at_least_one_built_only_capability_on_real_tree(): void
    {
        $verdict = (new AtlasLoopSelfCapabilityCoverageReporter)->scan();

        self::assertTrue($verdict['has_built_only'], 'real tree should expose at least one BUILT_ONLY capability');
    }

    public function test_scan_is_deterministic_across_two_calls(): void
    {
        $reporter = new AtlasLoopSelfCapabilityCoverageReporter();
        // Freeze the clock by ignoring scanned_at differences.
        $a = $reporter->scan();
        $b = $reporter->scan();
        foreach ($a['capabilities'] as $cap => $row) {
            self::assertSame($row['state'], $b['capabilities'][$cap]['state']);
            self::assertSame($row['class_fqcn'], $b['capabilities'][$cap]['class_fqcn']);
            self::assertSame($row['wiring_evidence'], $b['capabilities'][$cap]['wiring_evidence']);
        }
    }
}
