<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution\Brain;

use Tests\TestCase;

/**
 * S0 — the self-improvement PORTFOLIO as DATA + the keystone flags. Proves the brain has the 7 paths it must
 * rotate (each pointing at a REAL executor organ), and that the keystone wiring flags are fail-closed (OFF by
 * default ⇒ later slices are byte-identical until armed).
 */
final class AtlasBrainPortfolioConfigTest extends TestCase
{
    public function test_seven_self_improvement_paths_are_present_and_well_formed(): void
    {
        $paths = config('atlas.brain.paths');

        self::assertIsArray($paths);
        self::assertCount(7, $paths, 'the brain rotates exactly the 7 self-improvement paths');

        $ids = [];
        foreach ($paths as $p) {
            foreach (['id', 'intent', 'objective_kind', 'lens', 'executor_organ'] as $key) {
                self::assertArrayHasKey($key, $p, "path missing key {$key}");
                self::assertNotSame('', trim((string) $p[$key]), "path key {$key} is empty");
            }
            self::assertSame('self_improvement', $p['intent']);
            self::assertTrue(class_exists($p['executor_organ']), "executor_organ does not resolve to a real class: {$p['executor_organ']}");
            $ids[] = $p['id'];
        }

        self::assertSame(
            ['frontier-harvest', 'metrics-optimization', 'pattern-design', 'simulation-twin', 'comprehension-deepening', 'adversarial-critique', 'compounding'],
            $ids,
            'all 7 canonical paths present',
        );
        self::assertCount(7, array_unique($ids), 'no duplicate path ids');
    }

    public function test_keystone_flags_default_off_failclosed(): void
    {
        // Read straight from the config file defaults (no env set) — must be fail-closed OFF.
        self::assertFalse((bool) config('atlas.brain.reflection_enabled'), 'reflection wiring OFF by default');
        self::assertFalse((bool) config('atlas.brain.causal_selector_enabled'), 'causal selector OFF by default');
        self::assertNotSame('', (string) config('atlas.brain.reflection_root'), 'reflection_root path is configured');
    }
}
