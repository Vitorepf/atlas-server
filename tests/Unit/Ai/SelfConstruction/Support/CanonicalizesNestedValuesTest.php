<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\CanonicalizesNestedValues;
use Tests\TestCase;

final class CanonicalizesNestedValuesTest extends TestCase
{
    public function test_scalars_pass_through_intact(): void
    {
        $host = $this->makeHost();

        $this->assertSame('hello', $host->call('hello'));
        $this->assertSame(42, $host->call(42));
        $this->assertSame(3.14, $host->call(3.14));
        $this->assertTrue($host->call(true));
        $this->assertNull($host->call(null));
    }

    public function test_list_preserves_order(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            [3, 1, 4, 1, 5, 9, 2, 6],
            $host->call([3, 1, 4, 1, 5, 9, 2, 6])
        );
    }

    public function test_list_recurses_into_scalars(): void
    {
        $host = $this->makeHost();

        $this->assertSame(
            [['a', 'b'], ['c', 'd']],
            $host->call([['a', 'b'], ['c', 'd']])
        );
    }

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

    public function test_mixed_list_and_map_recurses_correctly(): void
    {
        $host = $this->makeHost();

        $input = [
            'z_list' => [3, 1, 2],
            'a_map' => ['y' => 1, 'x' => 2],
            'literal' => 'untouched',
        ];

        $this->assertSame(
            [
                'a_map' => ['x' => 2, 'y' => 1],
                'literal' => 'untouched',
                'z_list' => [3, 1, 2],
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
        $this->assertSame([], $host->call([], 'map'));
    }

    private function makeHost(): object
    {
        return new class
        {
            use CanonicalizesNestedValues;

            public function call(mixed $value): mixed
            {
                return $this->canonicalize($value);
            }
        };
    }
}
