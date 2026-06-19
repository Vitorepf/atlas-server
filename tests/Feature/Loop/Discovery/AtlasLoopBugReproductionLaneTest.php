<?php

declare(strict_types=1);

namespace Tests\Feature\Loop\Discovery;

use App\Services\Ai\AutonomousEvolution\Discovery\AtlasLoopBugReproductionLane;
use Tests\TestCase;

/**
 * §11.4 — bug-fix reproduction lane. Each test pins EXACT values (objective text shape,
 * acceptance command set, red_required, shape) so the suite fails if the lane were broken,
 * and proves the fail-closed contract: a signature with no anchor yields NO fabricated repro.
 */
final class AtlasLoopBugReproductionLaneTest extends TestCase
{
    private AtlasLoopBugReproductionLane $lane;

    protected function setUp(): void
    {
        parent::setUp();
        $this->lane = new AtlasLoopBugReproductionLane();
    }

    public function test_target_and_test_path_yields_red_required_bug_fix_objective(): void
    {
        $out = $this->lane->toReproductionObjective([
            'target_path' => 'app/Services/Foo/Calculator.php',
            'test_path' => 'tests/Feature/Foo/CalculatorTest.php',
            'failing_assertion' => 'test_divides_without_off_by_one',
            'message' => 'Expected 5 but got 4',
        ]);

        $this->assertNotNull($out);
        $this->assertSame('bug_fix', $out['shape']);
        $this->assertSame('app/Services/Foo/Calculator.php', $out['target_path']);

        // The RED bar is the failing test command — derived from test_path, exact set.
        $this->assertSame(
            ['./vendor/bin/phpunit tests/Feature/Foo/CalculatorTest.php'],
            $out['acceptance']['commands'],
        );
        $this->assertTrue($out['acceptance']['red_required']);

        // Objective is a concrete reproduce-then-fix instruction naming the target + failing case.
        $this->assertStringContainsString('Reproduce', $out['objective']);
        $this->assertStringContainsString('app/Services/Foo/Calculator.php', $out['objective']);
        $this->assertStringContainsString('test_divides_without_off_by_one', $out['objective']);
        $this->assertStringContainsString('RED', $out['objective']);
        $this->assertStringContainsString('GREEN', $out['objective']);
        $this->assertStringContainsString('Expected 5 but got 4', $out['objective']);
    }

    public function test_no_target_path_yields_null_no_fabricated_repro(): void
    {
        // Fail-closed: a signature with no anchor (only a log) must NOT produce a repro.
        $this->assertNull($this->lane->toReproductionObjective([
            'message' => 'Something blew up',
            'stack' => "#0 /app/x.php(10)\n#1 /app/y.php(20)",
        ]));

        // Empty / whitespace-only target_path is treated as absent (fail-closed).
        $this->assertNull($this->lane->toReproductionObjective([
            'target_path' => '   ',
            'test_path' => 'tests/Feature/Foo/CalculatorTest.php',
        ]));
    }

    public function test_target_without_any_runnable_handle_yields_null(): void
    {
        // A target but no test_path and no explicit command ⇒ no runnable RED handle ⇒ the
        // model-bound repro-synthesis is out of scope ⇒ null (never fake a command).
        $this->assertNull($this->lane->toReproductionObjective([
            'target_path' => 'app/Services/Foo/Calculator.php',
            'message' => 'Off-by-one somewhere',
        ]));
    }

    public function test_explicit_command_wins_over_derived_and_path_is_normalized(): void
    {
        $out = $this->lane->toReproductionObjective([
            'target_path' => '/app/Services/Foo/Calculator.php',
            'test_path' => 'tests/Feature/Foo/CalculatorTest.php',
            'command' => './vendor/bin/phpunit --filter test_off_by_one tests/Feature/Foo/CalculatorTest.php',
        ]);

        $this->assertNotNull($out);
        // Leading slash on target_path normalized to repo-relative.
        $this->assertSame('app/Services/Foo/Calculator.php', $out['target_path']);
        // Explicit command beats the test_path-derived default, exact set.
        $this->assertSame(
            ['./vendor/bin/phpunit --filter test_off_by_one tests/Feature/Foo/CalculatorTest.php'],
            $out['acceptance']['commands'],
        );
        $this->assertTrue($out['acceptance']['red_required']);
    }

    public function test_objective_is_deterministic(): void
    {
        $sig = [
            'target_path' => 'app/Services/Foo/Calculator.php',
            'test_path' => 'tests/Feature/Foo/CalculatorTest.php',
            'failing_assertion' => 'test_x',
        ];
        $this->assertSame(
            $this->lane->toReproductionObjective($sig),
            $this->lane->toReproductionObjective($sig),
            'pure: identical signature ⇒ identical objective (no clock/DB/provider)',
        );
    }
}
