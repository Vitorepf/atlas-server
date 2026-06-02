<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AtlasGuardedNumeric;
use Tests\TestCase;

require_once __DIR__ . '/../../../..' . '/app/Services/Ai/Support/AtlasGuardedNumeric.php';

final class AtlasGuardedNumericTest extends TestCase
{
    public function test_finite_float_accepts_finite_numeric_values_and_rejects_non_finite(): void
    {
        $this->assertSame(12.5, AtlasGuardedNumeric::finiteFloat('12.5'));
        $this->assertSame(10.0, AtlasGuardedNumeric::finiteFloat(10));
        $this->assertSame(null, AtlasGuardedNumeric::finiteFloat(INF));
        $this->assertSame(null, AtlasGuardedNumeric::finiteFloat(-INF));
        $this->assertSame(null, AtlasGuardedNumeric::finiteFloat(NAN));
        $this->assertSame(null, AtlasGuardedNumeric::finiteFloat('not numeric'));
        $this->assertSame(null, AtlasGuardedNumeric::finiteFloat([]));
    }

    public function test_saturating_int_clamps_within_bounds_and_rejects_invalid_numeric_inputs(): void
    {
        $this->assertSame(3, AtlasGuardedNumeric::saturatingInt(3, 1, 10));
        $this->assertSame(1, AtlasGuardedNumeric::saturatingInt(-4, 1, 10));
        $this->assertSame(10, AtlasGuardedNumeric::saturatingInt(42, 1, 10));
        $this->assertSame(1, AtlasGuardedNumeric::saturatingInt(INF, 1, 10));
        $this->assertSame(1, AtlasGuardedNumeric::saturatingInt(NAN, 1, 10));
        $this->assertSame(1, AtlasGuardedNumeric::saturatingInt('not numeric', 1, 10));
        $this->assertSame(7, AtlasGuardedNumeric::saturatingInt('7', 1, 10));
        $this->assertSame(4, AtlasGuardedNumeric::saturatingInt(4.9, 1, 10));
    }

    public function test_safe_string_returns_string_or_empty_for_nonscalar_non_stringable_values(): void
    {
        $this->assertSame('value', AtlasGuardedNumeric::safeString('value'));
        $this->assertSame('10', AtlasGuardedNumeric::safeString(10));
        $this->assertSame('1', AtlasGuardedNumeric::safeString(true));
        $this->assertSame('1.5', AtlasGuardedNumeric::safeString(1.5));
        $this->assertSame('object', AtlasGuardedNumeric::safeString(new class () {
            public function __toString(): string
            {
                return 'object';
            }
        }));
        $this->assertSame('', AtlasGuardedNumeric::safeString([]));
        $this->assertSame('', AtlasGuardedNumeric::safeString(new class {}));
    }

    public function test_sorted_string_list_keeps_only_strings_and_sorts_with_sort_string(): void
    {
        $input = [3, '10', '2', null, 'apple', 'Banana', ['a'], 'banana', 5.5];
        $expected = ['10', '2', 'Banana', 'apple', 'banana'];

        $this->assertSame($expected, AtlasGuardedNumeric::sortedStringList($input));
    }
}