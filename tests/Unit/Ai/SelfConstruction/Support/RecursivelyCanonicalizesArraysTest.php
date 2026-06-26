<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\RecursivelyCanonicalizesArrays;
use Tests\TestCase;

final class RecursivelyCanonicalizesArraysTest extends TestCase
{
    public function test_associative_map_is_ksorted(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            ['a' => 1, 'b' => 2, 'c' => 3],
            $host->call(['c' => 3, 'a' => 1, 'b' => 2])
        );
    }

    public function test_nested_maps_are_ksorted_at_every_level(): void
    {
        $host = $this->makeHost();

        $input = [
            'z' => ['y' => 1, 'x' => 2, 'a' => 3],
            'a' => ['c' => 4, 'b' => 5],
        ];

        $this->assertSame(
            [
                'a' => ['b' => 5, 'c' => 4],
                'z' => ['a' => 3, 'x' => 2, 'y' => 1],
            ],
            $host->call($input)
        );
    }

    public function test_list_preserves_order(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            [3, 1, 4, 1, 5, 9, 2, 6],
            $host->call([3, 1, 4, 1, 5, 9, 2, 6])
        );
    }

    public function test_nested_list_inside_map_is_kept_in_order(): void
    {
        $host = $this->makeHost();

        $input = [
            'z' => [3, 1, 4],
            'a' => ['nested' => [9, 8, 7]],
        ];

        $this->assertSame(
            [
                'a' => ['nested' => [9, 8, 7]],
                'z' => [3, 1, 4],
            ],
            $host->call($input)
        );
    }

    public function test_canonicalization_is_idempotent(): void
    {
        $host = $this->makeHost();

        $input = ['z' => ['y' => 1, 'x' => 2], 'a' => [3, 1, 2]];
        $once = $host->call($input);
        $twice = $host->call($once);

        $this->assertSame($once, $twice);
    }

    public function test_empty_map_and_empty_list_are_handled(): void
    {
        $host = $this->makeHost();

        $this->assertSame([], $host->call([]));
    }

    private function makeHost(): object
    {
        return new class
        {
            use RecursivelyCanonicalizesArrays;

            public function call(array $value): array
            {
                return $this->canonicalize($value);
            }
        };
    }
}
