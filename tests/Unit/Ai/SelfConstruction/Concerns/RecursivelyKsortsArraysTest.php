<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Concerns;

use App\Services\Ai\SelfConstruction\Concerns\RecursivelyKsortsArrays;
use Tests\TestCase;

/**
 * Locks the canonical recursive-ksort contract de-duplicated by the trait.
 */
final class RecursivelyKsortsArraysTest extends TestCase
{
    public function test_list_order_is_preserved(): void
    {
        $harness = $this->harness();

        $result = $harness->run(['cherry', 'apple', 'date']);

        $this->assertSame(['cherry', 'apple', 'date'], $result);
    }

    public function test_nested_associative_maps_are_sorted(): void
    {
        $harness = $this->harness();

        $result = $harness->run([
            'zoo' => ['zebra' => 1, 'ant' => 2],
            'apple' => ['banana' => 3, 'avocado' => 4],
        ]);

        $this->assertSame([
            'apple' => ['avocado' => 4, 'banana' => 3],
            'zoo' => ['ant' => 2, 'zebra' => 1],
        ], $result);
    }

    public function test_empty_array_is_a_noop(): void
    {
        $harness = $this->harness();

        $this->assertSame([], $harness->run([]));
    }

    public function test_idempotence(): void
    {
        $harness = $this->harness();

        $input = [
            'c' => [3, 1, 2],
            'a' => ['z' => 1, 'a' => 2],
            'b' => [['y' => 1, 'x' => 2], ['b' => 1, 'a' => 2]],
        ];

        $firstPass = $harness->run($input);
        $secondPass = $harness->run($firstPass);

        $this->assertSame($firstPass, $secondPass);
    }

    private function harness(): object
    {
        return new class
        {
            use RecursivelyKsortsArrays;

            /** @param  array<mixed, mixed>  $value @return array<mixed, mixed> */
            public function run(array $value): array
            {
                return $this->recursivelyKsort($value);
            }
        };
    }
}
