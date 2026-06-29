<?php

declare(strict_types=1);

namespace Tests\Feature\Loop;

use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * Proves the numeric-safety guard ops are live at the operator surface and emit deterministic, never-NaN/INF
 * results: divide-by-zero falls back, an out-of-range value is clamped, and a list is safely averaged. A
 * missing --op is a usage error; an unknown op is rejected.
 */
final class AtlasLoopNumericSafeCommandTest extends TestCase
{
    public function test_requires_op(): void
    {
        $exit = Artisan::call('atlas:loop:numeric-safe', ['--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('usage_error', $decoded['status']);
    }

    public function test_unknown_op_is_rejected(): void
    {
        $exit = Artisan::call('atlas:loop:numeric-safe', ['--op' => 'explode', '--json' => true]);
        $decoded = json_decode(trim(Artisan::output()), true);

        $this->assertNotSame(0, $exit);
        $this->assertSame('unknown_op', $decoded['status']);
    }

    public function test_safe_divide_by_zero_falls_back(): void
    {
        $decoded = $this->op('safeDivide', ['numerator' => 10, 'denominator' => 0, 'when_zero' => -1]);

        $this->assertSame('atlas.loop.numeric_safe.v1', $decoded['schema']);
        $this->assertEquals(-1, $decoded['result']);
    }

    public function test_clamp_bounds_value(): void
    {
        $this->assertEquals(10, $this->op('clamp', ['value' => 15, 'min' => 0, 'max' => 10])['result']);
        $this->assertEquals(0, $this->op('clamp', ['value' => -3, 'min' => 0, 'max' => 10])['result']);
    }

    public function test_safe_mean_of_list(): void
    {
        $this->assertEquals(4, $this->op('safeMean', ['values' => [2, 4, 6]])['result']);
        $this->assertEquals(0, $this->op('safeMean', ['values' => []])['result']); // empty ⇒ when_empty default
    }

    /**
     * @param  array<string,mixed>  $args
     * @return array<string,mixed>
     */
    private function op(string $op, array $args): array
    {
        $exit = Artisan::call('atlas:loop:numeric-safe', [
            '--op' => $op,
            '--args' => json_encode($args),
            '--json' => true,
        ]);
        $this->assertSame(0, $exit);

        return json_decode(trim(Artisan::output()), true);
    }
}
