<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSelfImprovementGroundingBridge;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * ACDE C1 — the self-improvement grounding bridge gives the dead AtlasLoopSelfImprovementObjectiveBuilder its
 * caller. Proves: an armed harness target grounds into an AST-anchored spec naming the worst method; the double
 * meta-flag gate (default OFF) and petreous harness guard still govern admissibility; a missing sibling fails
 * open to null. Temp-root, no DB => hang-free + deterministic.
 */
final class AtlasLoopSelfImprovementGroundingBridgeTest extends TestCase
{
    private string $root = '';

    private const HARNESS_DIR = 'app/Services/Ai/AutonomousEvolution';

    /** A harness file whose worst method (complexZone) carries the highest cyclomatic score. */
    private const HARNESS_SRC = "<?php\n\nnamespace App\\Services\\Ai\\AutonomousEvolution;\n\nclass FooHarness\n{\n    public function simple(): int\n    {\n        return 1;\n    }\n\n    public function complexZone(int \$x): int\n    {\n        if (\$x > 1) { return 1; }\n        if (\$x > 2) { return 2; }\n        if (\$x > 3) { return 3; }\n        if (\$x > 4) { return 4; }\n        foreach (range(1, \$x) as \$i) { if (\$i % 2 === 0) { \$x++; } }\n        return \$x;\n    }\n}\n";

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir().'/atlas-c1-'.bin2hex(random_bytes(5));
        @mkdir($this->root.'/'.self::HARNESS_DIR, 0o755, true);
        @mkdir($this->root.'/tests/Unit', 0o755, true);
        // Both meta flags ON so the builder admits a harness target; tests flip OFF where needed.
        config([
            'atlas.loop.framework_refactor_min_cyclomatic' => 3,
            'atlas.loop.meta_harness_targets' => true,
            'atlas.loop.meta_harness_self_improve.enabled' => true,
            'atlas.loop.self_improve_single_file_refactor_enabled' => true,
        ]);
    }

    protected function tearDown(): void
    {
        if ($this->root !== '' && is_dir($this->root)) {
            (new Process(['rm', '-rf', $this->root]))->run();
        }
        parent::tearDown();
    }

    private function seedHarness(string $basename, string $src = self::HARNESS_SRC): string
    {
        $rel = self::HARNESS_DIR.'/'.$basename;
        file_put_contents($this->root.'/'.$rel, $src);

        return $rel;
    }

    private function seedSibling(string $basename): void
    {
        $name = pathinfo($basename, PATHINFO_FILENAME);
        file_put_contents($this->root."/tests/Unit/{$name}Test.php", "<?php\n\nclass {$name}Test\n{\n    public function test_it(): void {}\n}\n");
    }

    private function seedProductionCaller(string $basename): void
    {
        $name = pathinfo($basename, PATHINFO_FILENAME);
        file_put_contents($this->root.'/'.self::HARNESS_DIR."/{$name}Caller.php", "<?php\n\nnamespace App\\Services\\Ai\\AutonomousEvolution;\n\nfinal class {$name}Caller\n{\n    public function target(): string\n    {\n        return \\\\App\\\\Services\\\\Ai\\\\AutonomousEvolution\\\\{$name}::class;\n    }\n}\n");
    }

    public function test_grounds_an_armed_harness_target_naming_the_worst_method(): void
    {
        $rel = $this->seedHarness('FooHarness.php');
        $this->seedSibling('FooHarness.php');

        $r = (new AtlasLoopSelfImprovementGroundingBridge)->ground($rel, $this->root);

        $this->assertIsArray($r, 'an armed, harness, with-sibling target must ground');
        $this->assertTrue($r['admitted']);
        $this->assertGreaterThan(0.0, (float) $r['quality_bar'], 'the ≥9 bar is stamped');
        $this->assertStringContainsString('complexZone', json_encode($r), 'the grounded spec names the measured worst method');
    }

    public function test_prefers_single_file_self_improvement_when_the_harness_target_is_wired(): void
    {
        $rel = $this->seedHarness('FooHarness.php');
        $this->seedSibling('FooHarness.php');
        $this->seedProductionCaller('FooHarness.php');

        $r = (new AtlasLoopSelfImprovementGroundingBridge)->ground($rel, $this->root);

        $this->assertIsArray($r, 'a wired harness target should ground into a certifiable self-edit');
        $this->assertTrue($r['admitted']);
        $this->assertSame('refactor_reduce_complexity', $r['payload']['objective_kind']);
        $this->assertSame('single_file_refactor', $r['payload']['self_improvement_mode']);
        $this->assertTrue($r['payload']['is_self_improvement']);
        $this->assertArrayNotHasKey('_target_id', $r['payload'], 'synthetic self-improvement tasks must not fake a DB target UUID');
        $this->assertSame([$rel], $r['payload']['allowed_files']);
        $this->assertStringContainsString('SELF-IMPROVEMENT:', $r['objective']);
        $this->assertStringContainsString('complexZone', $r['objective']);
    }

    public function test_returns_null_when_meta_flags_off(): void
    {
        config(['atlas.loop.meta_harness_targets' => false, 'atlas.loop.meta_harness_self_improve.enabled' => false]);
        $rel = $this->seedHarness('FooHarness.php');
        $this->seedSibling('FooHarness.php');

        $this->assertNull((new AtlasLoopSelfImprovementGroundingBridge)->ground($rel, $this->root));
    }

    public function test_returns_null_for_a_forbidden_petreous_target(): void
    {
        // A file whose name matches the petreous never-target set must never ground, even fully armed.
        $rel = $this->seedHarness('AtlasLoopAutoMergeService.php');
        $this->seedSibling('AtlasLoopAutoMergeService.php');

        $this->assertNull((new AtlasLoopSelfImprovementGroundingBridge)->ground($rel, $this->root));
    }

    public function test_returns_null_when_no_sibling_test_exists(): void
    {
        $rel = $this->seedHarness('FooHarness.php');
        // no sibling seeded

        $this->assertNull((new AtlasLoopSelfImprovementGroundingBridge)->ground($rel, $this->root));
    }

    public function test_returns_null_for_a_non_php_or_missing_target(): void
    {
        $this->assertNull((new AtlasLoopSelfImprovementGroundingBridge)->ground('app/Services/Ai/AutonomousEvolution/Nope.php', $this->root));
        $this->assertNull((new AtlasLoopSelfImprovementGroundingBridge)->ground('readme.md', $this->root));
    }
}
