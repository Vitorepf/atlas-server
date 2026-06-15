<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\AtlasLoopSemanticImplementationCertifier;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * REGRESSION (HIGH false-accept): a refactor contract's ONLY behaviour-preservation check is the
 * frozen sibling exiting 0. A sibling that exits 0 VACUOUSLY (all tests skipped in the hermetic
 * worktree, or zero assertions executed) leaves behaviour UNCHECKED, so a behaviour-breaking but
 * complexity-reducing refactor would certify. The certifier now requires the phpunit anchor to have
 * executed >=1 real assertion — airtight (separates vacuous-green from partial-skip) and fail-OPEN
 * (a partial-skip sibling still asserts >=1 and is never false-rejected; non-phpunit / unparseable
 * anchors are not gated).
 */
final class AtlasLoopBehaviorAnchorAssertionTest extends TestCase
{
    private function certifier(): AtlasLoopSemanticImplementationCertifier
    {
        // Pure helpers (no instance state) — skip the constructor so this stays a true Unit test.
        return (new ReflectionClass(AtlasLoopSemanticImplementationCertifier::class))->newInstanceWithoutConstructor();
    }

    private function parseCount(string $stdout): ?int
    {
        $m = new ReflectionMethod(AtlasLoopSemanticImplementationCertifier::class, 'phpunitAssertionCount');

        return $m->invoke($this->certifier(), $stdout);
    }

    private function asserted(array $commandResults): bool
    {
        $gate = ['report' => ['target_acceptance' => ['details' => ['command_results' => $commandResults]]]];
        $m = new ReflectionMethod(AtlasLoopSemanticImplementationCertifier::class, 'behaviorAnchorAsserted');

        return $m->invoke($this->certifier(), $gate);
    }

    public function test_phpunit_assertion_count_parses_both_summary_forms(): void
    {
        $this->assertSame(50, $this->parseCount('PHPUnit 12.5.23 ...\n\nOK (5 tests, 50 assertions)'));
        $this->assertSame(1, $this->parseCount('OK (1 test, 1 assertion)'));
        $this->assertSame(0, $this->parseCount("OK, but there were issues!\nTests: 5, Assertions: 0, Skipped: 5."));
        $this->assertNull($this->parseCount('not a phpunit summary at all'));
    }

    public function test_a_real_asserting_phpunit_anchor_passes(): void
    {
        $this->assertTrue($this->asserted([
            ['command' => "./vendor/bin/phpunit 'tests/Unit/FooTest.php'", 'stdout' => 'OK (5 tests, 50 assertions)'],
        ]));
    }

    public function test_a_vacuous_all_skipped_zero_assertion_anchor_is_refused(): void
    {
        $this->assertFalse($this->asserted([
            ['command' => "./vendor/bin/phpunit 'tests/Unit/FooTest.php'", 'stdout' => "OK, but there were issues!\nTests: 5, Assertions: 0, Skipped: 5."],
        ]));
    }

    public function test_partial_skip_with_at_least_one_assertion_is_not_false_rejected(): void
    {
        // The refute's load-bearing concern: a sibling that skips an env-gated method but still asserts
        // in others must NOT be penalised (phpunit reports the executed assertions).
        $this->assertTrue($this->asserted([
            ['command' => "./vendor/bin/phpunit 'tests/Unit/FooTest.php'", 'stdout' => "OK, but there were issues!\nTests: 3, Assertions: 7, Skipped: 1."],
        ]));
    }

    public function test_non_phpunit_and_unparseable_anchors_are_not_gated(): void
    {
        // A gate-style acceptance (php -r) is not a behaviour anchor subject to this check.
        $this->assertTrue($this->asserted([
            ['command' => 'php -r "exit(is_file(\'x\')?0:1);"', 'stdout' => ''],
        ]));
        // A phpunit anchor whose output cannot be parsed falls OPEN (never a false-reject).
        $this->assertTrue($this->asserted([
            ['command' => './vendor/bin/phpunit x', 'stdout' => 'weird unparseable output'],
        ]));
        // No run data at all => not determinable => not gated.
        $this->assertTrue($this->asserted([]));
    }

    public function test_one_asserting_anchor_among_several_passes(): void
    {
        $this->assertTrue($this->asserted([
            ['command' => './vendor/bin/phpunit a', 'stdout' => 'Tests: 1, Assertions: 0, Skipped: 1.'],
            ['command' => './vendor/bin/phpunit b', 'stdout' => 'OK (2 tests, 9 assertions)'],
        ]));
    }
}
