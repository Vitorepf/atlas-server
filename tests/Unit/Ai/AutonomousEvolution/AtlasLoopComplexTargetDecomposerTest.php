<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopComplexTargetDecomposer;
use Tests\TestCase;

/**
 * Net-new MATERIAL supply via decomposition — the invariants that keep it from becoming a proxy faucet:
 *   1. MULTIPLIES supply: a file with N independently-complex methods yields N independent sub-refactors
 *      (the loop mints 1 today), worst-method-first.
 *   2. MATERIAL-by-construction: a sub-target is minted ONLY for a method at/above the loop's own material
 *      cyclomatic bar — a simple method is NEVER decomposed (no proxy manufactured).
 *   3. RED-required + method-scoped: every sub-objective demands its own failing-test-first proof and names
 *      the exact method to touch.
 *   4. FAIL-CLOSED: unparseable / nothing qualifying / empty input => no sub-targets.
 */
final class AtlasLoopComplexTargetDecomposerTest extends TestCase
{
    /** Emit a method whose cyclomatic complexity is ~1+$branches (one decision per `if`). */
    private function complexMethod(string $name, int $branches): string
    {
        $body = "        \$r = 0;\n";
        for ($i = 1; $i <= $branches; $i++) {
            $body .= "        if (\$x > {$i}) { \$r += {$i}; }\n";
        }
        $body .= "        return \$r;\n";

        return "    public function {$name}(int \$x): int\n    {\n{$body}    }\n";
    }

    private function sampleClass(): string
    {
        return "<?php\n\nclass Sample\n{\n"
            .$this->complexMethod('alpha', 18)   // cyclomatic ~19 — well above the 12 material bar
            .$this->complexMethod('beta', 14)    // cyclomatic ~15 — above the bar
            ."    public function simple(): int\n    {\n        return 1;\n    }\n"  // cyclomatic 1 — below
            ."}\n";
    }

    public function test_multiplies_supply_one_material_subtarget_per_complex_method_worst_first(): void
    {
        $out = (new AtlasLoopComplexTargetDecomposer)->decompose('app/Services/Sample.php', $this->sampleClass());

        // Exactly the two complex methods are decomposed — the simple method is never a sub-target.
        $methods = array_column($out, 'method');
        $this->assertSame(['Sample::alpha', 'Sample::beta'], $methods, 'worst-method-first, simple excluded');

        foreach ($out as $sub) {
            $this->assertTrue($sub['material'], 'every minted sub-target is material-by-construction');
            $this->assertTrue($sub['acceptance']['red_required'], 'each sub-refactor earns its own RED proof');
            $this->assertSame('decompose_subrefactor', $sub['shape']);
            $this->assertSame('app/Services/Sample.php', $sub['target_path']);
            $this->assertStringContainsString($sub['method'], $sub['objective'], 'objective names the exact method');
        }
        // alpha is strictly more complex than beta => ordered first, and both clear the bar.
        $this->assertGreaterThan($out[1]['cyclomatic'], $out[0]['cyclomatic']);
        $this->assertGreaterThanOrEqual(12, $out[1]['cyclomatic']);
    }

    public function test_cap_bounds_the_number_of_subtargets(): void
    {
        $out = (new AtlasLoopComplexTargetDecomposer)->decompose('app/Services/Sample.php', $this->sampleClass(), 1);
        $this->assertCount(1, $out, 'the cap bounds how many sub-targets a single file floods into a refill');
        $this->assertSame('Sample::alpha', $out[0]['method'], 'the cap keeps the WORST method, not an arbitrary one');
    }

    public function test_fail_closed_no_complex_method_mints_nothing(): void
    {
        $simpleOnly = "<?php\n\nclass Tiny\n{\n    public function a(): int { return 1; }\n"
            ."    public function b(int \$x): int { return \$x + 1; }\n}\n";
        $this->assertSame([], (new AtlasLoopComplexTargetDecomposer)->decompose('app/Tiny.php', $simpleOnly),
            'a file with no method at/above the material bar yields ZERO sub-targets (never proxy)');
    }

    public function test_fail_closed_on_empty_or_unparseable_input(): void
    {
        $d = new AtlasLoopComplexTargetDecomposer;
        $this->assertSame([], $d->decompose('', $this->sampleClass()), 'empty path => nothing');
        $this->assertSame([], $d->decompose('app/X.php', '   '), 'empty source => nothing');
        $this->assertSame([], $d->decompose('app/X.php', "<?php this is not ){ valid php"), 'unparseable => nothing');
    }
}
