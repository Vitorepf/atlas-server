<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\KsortsArraysByReference;
use Tests\TestCase;

class KsortsArraysByReferenceTest extends TestCase
{
    private function newConsumer(): object
    {
        return new class
        {
            use KsortsArraysByReference {
                ksortRecursiveByReference as public publicKsortRecursive;
            }
        };
    }

    public function test_orders_associative(): void
    {
        $c = $this->newConsumer();
        $arr = ['b' => 2, 'a' => 1];
        $c->publicKsortRecursive($arr);
        self::assertSame(['a' => 1, 'b' => 2], $arr);
    }

    public function test_preserves_lists(): void
    {
        $c = $this->newConsumer();
        $arr = ['c', 'a', 'b'];
        $c->publicKsortRecursive($arr);
        self::assertSame(['c', 'a', 'b'], $arr);
    }

    public function test_handles_nested(): void
    {
        $c = $this->newConsumer();
        $arr = ['b' => ['y' => 1, 'x' => 2], 'a' => 0];
        $c->publicKsortRecursive($arr);
        self::assertSame(['a' => 0, 'b' => ['x' => 2, 'y' => 1]], $arr);
    }

    public function test_handles_empty(): void
    {
        $c = $this->newConsumer();
        $arr = [];
        $c->publicKsortRecursive($arr);
        self::assertSame([], $arr);
    }

    public function test_modifies_array_in_place(): void
    {
        $c = $this->newConsumer();
        $arr = ['z' => 1, 'a' => 2];
        $ref = &$arr;
        $c->publicKsortRecursive($arr);
        // The variable $arr must reflect the new order even after the call.
        self::assertSame(['a', 'z'], array_keys($arr));
        unset($ref);
    }
}