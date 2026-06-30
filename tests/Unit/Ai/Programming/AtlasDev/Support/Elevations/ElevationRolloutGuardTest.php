<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support\Elevations;

use PHPUnit\Framework\TestCase;
use Tests\Unit\Ai\Programming\AtlasDev\Probe\E1HardGateTest;

/**
 * VAL-M2-028: Rollout guard — each elevation's trip condition must be verified
 * on a fixture before its config default flips to hard.
 *
 * The architecture rollout rule (architecture.md §3 M2): "each elevation flips
 * to hard only after its trip condition is verified to fire correctly on a
 * fixture." This test enforces that discipline programmatically: for every
 * elevation whose config source ships a `hard` default, the guard asserts that
 * BOTH a trip-fires fixture test AND a does-not-false-fail fixture test exist
 * (are registered in {@see self::VERIFIED_FIXTURES} and resolvable via
 * reflection). An elevation promoted to hard without the fixture proof fails
 * this guard.
 *
 * The guard is forward-looking: while no elevation has a `hard` default, the
 * test passes trivially. When a future feature (m2-e1-hard, m2-e2-hard, etc.)
 * flips a config default to `hard`, that feature MUST also register its
 * verified fixture pair here, or this guard fails — preventing an unverified
 * promotion.
 *
 * "Both tests are green" is enforced transitively: the fixture test methods
 * exist (verified here by reflection) and are executed by the test suite (a
 * failing fixture would break the suite run, not this guard).
 */
final class ElevationRolloutGuardTest extends TestCase
{
    private const ALL_ELEVATIONS = ['e1', 'e2', 'e3', 'e4', 'e5', 'e6'];

    /**
     * Registry of verified fixture test pairs for hard-by-default elevations.
     *
     * Each entry maps an elevation to its verified trip-fires and
     * does-not-false-fail fixture test methods. The guard uses reflection to
     * confirm the methods exist on the named test classes.
     *
     * Format:
     *   'eN' => [
     *       'trip'  => [TestClass::class, 'test_method_name'],
     *       'clear' => [TestClass::class, 'test_method_name'],
     *   ]
     *
     * Populate this registry when promoting an elevation's config default to
     * `hard`. An entry MUST be added in the same feature that flips the
     * default; the guard fails otherwise.
     */
    private const VERIFIED_FIXTURES = [
        // No elevations are hard-by-default yet. Entries are added by the
        // feature that promotes each elevation to hard (e.g. m2-e1-hard adds
        // the e1 entry, m2-e2-hard adds e2, etc.).
        //
        // Example (when m2-e1-hard lands):
        //   'e1' => [
        //       'trip'  => [E1HardGateTest::class, 'test_e1_hard_trip_produces_failed_on_intent_missing_diff'],
        //       'clear' => [E1HardGateTest::class, 'test_e1_hard_does_not_false_fail_on_genuine_intent_diff'],
        //   ],

        // m2-e1-hard: E1 (intent-falsification / coverage probe) promoted
        // advisory -> hard. The trip condition (an intent-missing diff on a
        // green gate forces STATUS_FAILED -> `failed`, flag retained) and the
        // does-not-false-fail condition (a genuine-intent diff does not trip)
        // are proven on a fixture pair in E1HardGateTest, which drives a real
        // PipelineRunExecutor with e1.mode=hard. Registered BEFORE the config
        // default flipped to hard, per the VAL-M2-028 rollout discipline.
        'e1' => [
            'trip' => [E1HardGateTest::class, 'test_e1_hard_trip_produces_failed_on_intent_missing_diff'],
            'clear' => [E1HardGateTest::class, 'test_e1_hard_does_not_false_fail_on_genuine_intent_diff'],
        ],
    ];

    /**
     * VAL-M2-028: every hard-by-default elevation has a verified fixture pair.
     *
     * Reads the config source to determine which elevations ship a `hard`
     * default. For each, asserts the elevation is registered in
     * VERIFIED_FIXTURES and both fixture methods exist via reflection. An
     * unregistered hard elevation (promoted without fixture proof) fails.
     */
    public function test_val_m2_028_hard_by_default_elevations_have_verified_fixture_pairs(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));
        $hardElevations = [];

        foreach (self::ALL_ELEVATIONS as $elevation) {
            $envVar = 'ATLAS_DEV_ELEVATION_'.strtoupper($elevation).'_MODE';
            // Extract the default value from env('..._MODE', '<default>')
            $pattern = "/env\(\s*['\"]".preg_quote($envVar, '/')."['\"]\s*,\s*['\"]([a-z]+)['\"]\s*\)/";
            if (preg_match($pattern, $configSource, $matches)) {
                if ($matches[1] === 'hard') {
                    $hardElevations[] = $elevation;
                }
            }
        }

        // If no elevations are hard-by-default, the guard passes trivially.
        // This is the current state: all elevations are advisory or off.
        if ($hardElevations === []) {
            $this->addToAssertionCount(1);

            return;
        }

        // For each hard-by-default elevation, the fixture pair must exist.
        foreach ($hardElevations as $elevation) {
            $this->assertArrayHasKey(
                $elevation,
                self::VERIFIED_FIXTURES,
                "[$elevation] config default is 'hard' but no verified fixture pair is registered "
                .'in ElevationRolloutGuardTest::VERIFIED_FIXTURES. The rollout rule (VAL-M2-028) '
                .'requires a trip-fires + does-not-false-fail fixture pair to be registered BEFORE '
                .'the config default flips to hard. Add the entry in the feature that promotes '
                .'this elevation to hard.'
            );

            $fixture = self::VERIFIED_FIXTURES[$elevation];

            // Verify the trip-fires fixture test method exists.
            [$tripClass, $tripMethod] = $fixture['trip'];
            $this->assertTrue(
                method_exists($tripClass, $tripMethod),
                "[$elevation] trip-fires fixture test method {$tripClass}::{$tripMethod} does not exist. "
                .'The rollout guard requires a proven trip-fires fixture.'
            );

            // Verify the does-not-false-fail fixture test method exists.
            [$clearClass, $clearMethod] = $fixture['clear'];
            $this->assertTrue(
                method_exists($clearClass, $clearMethod),
                "[$elevation] does-not-false-fail fixture test method {$clearClass}::{$clearMethod} does not exist. "
                .'The rollout guard requires a proven does-not-false-fail fixture.'
            );
        }
    }

    /**
     * VAL-M2-028 (complement): no hard-by-default elevation is missing EITHER
     * fixture. This splits the trip and clear checks into separate assertions
     * so a failure pinpoints exactly which half is missing.
     */
    public function test_val_m2_028_hard_elevations_have_both_trip_and_clear_fixtures(): void
    {
        $configSource = (string) file_get_contents($this->repoPath('config/atlas_dev.php'));
        $anyHard = false;

        foreach (self::ALL_ELEVATIONS as $elevation) {
            $envVar = 'ATLAS_DEV_ELEVATION_'.strtoupper($elevation).'_MODE';
            $pattern = "/env\(\s*['\"]".preg_quote($envVar, '/')."['\"]\s*,\s*['\"]([a-z]+)['\"]\s*\)/";

            if (! preg_match($pattern, $configSource, $matches)) {
                continue;
            }

            if ($matches[1] !== 'hard') {
                continue;
            }

            // This elevation is hard-by-default: require both fixtures.
            $anyHard = true;
            $this->assertArrayHasKey(
                $elevation,
                self::VERIFIED_FIXTURES,
                "[$elevation] hard-by-default but no verified fixture pair registered"
            );

            $fixture = self::VERIFIED_FIXTURES[$elevation];
            $this->assertArrayHasKey('trip', $fixture, "[$elevation] fixture pair missing 'trip' entry");
            $this->assertArrayHasKey('clear', $fixture, "[$elevation] fixture pair missing 'clear' entry");
        }

        if (! $anyHard) {
            // No hard-by-default elevations: the guard is not triggered.
            // Assert the config source was readable (proves the test ran the
            // parsing logic, not a no-op).
            $this->assertNotEmpty($configSource, 'config source must be readable');
        }
    }

    private function repoPath(string $relative): string
    {
        // This test lives 7 directories deep from the repo root:
        // tests/Unit/Ai/Programming/AtlasDev/Support/Elevations/
        return dirname(__DIR__, 7).'/'.$relative;
    }
}
