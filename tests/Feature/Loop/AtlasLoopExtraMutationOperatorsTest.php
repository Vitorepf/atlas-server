<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use App\Services\Ai\AutonomousEvolution\AtlasLoopMutationOperators;
use Tests\TestCase;

/**
 * ACDE QA1 — three extra deterministic decision operators widen the mutation kill vocabulary. Default OFF keeps
 * the operator map byte-identical; ON adds exception_throw_noop / null_coalesce_null / early_return_delete, each
 * a pure source transform single-sourced for the gate + the characterization verifier.
 */
final class AtlasLoopExtraMutationOperatorsTest extends TestCase
{
    private const KEYS = ['exception_throw_noop', 'null_coalesce_null', 'early_return_delete'];

    public function test_off_is_byte_identical_no_extra_operators(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => false]);
        $map = AtlasLoopMutationOperators::map();

        foreach (self::KEYS as $k) {
            $this->assertArrayNotHasKey($k, $map, "OFF must not expose {$k}");
        }
        // applyOperator returns null for an unknown (OFF) operator.
        $this->assertNull(AtlasLoopMutationOperators::applyOperator('exception_throw_noop', "<?php\nthrow new \\RuntimeException('x');\n"));
    }

    public function test_on_adds_the_three_operators(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => true]);
        $map = AtlasLoopMutationOperators::map();

        foreach (self::KEYS as $k) {
            $this->assertArrayHasKey($k, $map);
        }
    }

    public function test_exception_throw_noop_removes_the_throw(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => true]);
        $r = AtlasLoopMutationOperators::applyOperator('exception_throw_noop', "<?php\nif (! \$x) { throw new \\RuntimeException('bad'); }\n");

        $this->assertIsString($r);
        $this->assertStringNotContainsString('throw', (string) $r, 'the throw is removed => a test expecting it dies');
    }

    public function test_null_coalesce_forces_the_fallback_to_null(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => true]);
        $r = AtlasLoopMutationOperators::applyOperator('null_coalesce_null', "<?php\n\$y = \$x ?? 'default';\n");

        $this->assertIsString($r);
        $this->assertStringContainsString('?? null', (string) $r);
        $this->assertStringNotContainsString('default', (string) $r, 'the non-null fallback is killed => a test covering it dies');
    }

    public function test_early_return_delete_removes_a_void_return(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => true]);
        $r = AtlasLoopMutationOperators::applyOperator('early_return_delete', "<?php\nif (\$x) { return; }\necho 1;\n");

        $this->assertIsString($r);
        $this->assertStringNotContainsString('return', (string) $r, 'the early exit is removed => a test covering it dies');
    }

    public function test_return_value_operators_are_untouched_by_the_void_return_op(): void
    {
        config(['atlas.loop.extra_mutation_operators_enabled' => true]);
        // `return $x;` is a VALUE return handled by other operators — the void-return op must not touch it.
        $r = AtlasLoopMutationOperators::applyOperator('early_return_delete', "<?php\nreturn \$x;\n");

        $this->assertNull($r, 'a value return is not a void early-return');
    }
}
