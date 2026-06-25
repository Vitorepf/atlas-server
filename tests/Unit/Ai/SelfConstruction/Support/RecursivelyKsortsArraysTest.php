<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\RecursivelyKsortsArrays;
use Tests\TestCase;

final class RecursivelyKsortsArraysTest extends TestCase
{
    public function test_empty_array_is_returned_unchanged(): void
    {
        $host = $this->host();
        $this->assertSame([], $host->run([]));
    }

    public function test_list_preserves_order(): void
    {
        $host = $this->host();
        $this->assertSame(['c', 'a', 'b'], $host->run(['c', 'a', 'b']));
    }

    public function test_associative_top_level_is_ksorted(): void
    {
        $host = $this->host();
        $this->assertSame(
            ['a' => 1, 'b' => 2, 'c' => 3],
            $host->run(['c' => 3, 'a' => 1, 'b' => 2]),
        );
    }

    public function test_recurses_into_nested_associative_arrays(): void
    {
        $host = $this->host();
        $out = $host->run([
            'z' => ['y' => 2, 'x' => 1],
            'a' => ['b' => 3, 'a' => 4],
        ]);
        $this->assertSame(['a', 'z'], array_keys($out));
        $this->assertSame(['a' => 4, 'b' => 3], $out['a']);
        $this->assertSame(['x' => 1, 'y' => 2], $out['z']);
    }

    public function test_list_of_assoc_arrays_preserves_outer_order_and_sorts_inner(): void
    {
        $host = $this->host();
        $out = $host->run([
            ['b' => 1, 'a' => 2],
            ['d' => 3, 'c' => 4],
        ]);
        $this->assertSame([
            ['a' => 2, 'b' => 1],
            ['c' => 4, 'd' => 3],
        ], $out);
    }

    private function host(): object
    {
        return new class()
        {
            use RecursivelyKsortsArrays;

            public function run(array $value): array
            {
                return $this->recursivelyKsort($value);
            }
        };
    }
}
