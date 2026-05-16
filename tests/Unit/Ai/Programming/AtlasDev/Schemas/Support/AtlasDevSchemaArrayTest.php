<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Support;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\AtlasDevSchemaArray;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class AtlasDevSchemaArrayTest extends TestCase
{
    public function test_string_returns_string(): void
    {
        $this->assertSame('hello', AtlasDevSchemaArray::string(['name' => 'hello'], 'name'));
    }

    public function test_string_rejects_non_string(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasDevSchemaArray::string(['name' => 42], 'name');
    }

    public function test_string_rejects_missing_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasDevSchemaArray::string([], 'name');
    }

    public function test_nullable_string_accepts_null_or_missing(): void
    {
        $this->assertNull(AtlasDevSchemaArray::nullableString(['x' => null], 'x'));
        $this->assertNull(AtlasDevSchemaArray::nullableString([], 'x'));
        $this->assertSame('v', AtlasDevSchemaArray::nullableString(['x' => 'v'], 'x'));
    }

    public function test_int_rejects_string_representation(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasDevSchemaArray::int(['n' => '5'], 'n');
    }

    public function test_int_returns_integer(): void
    {
        $this->assertSame(7, AtlasDevSchemaArray::int(['n' => 7], 'n'));
    }

    public function test_nullable_int_accepts_null(): void
    {
        $this->assertNull(AtlasDevSchemaArray::nullableInt([], 'n'));
        $this->assertSame(0, AtlasDevSchemaArray::nullableInt(['n' => 0], 'n'));
    }

    public function test_bool_strict(): void
    {
        $this->assertTrue(AtlasDevSchemaArray::bool(['b' => true], 'b'));

        $this->expectException(InvalidArgumentException::class);
        AtlasDevSchemaArray::bool(['b' => 1], 'b');
    }

    public function test_string_list_returns_list(): void
    {
        $this->assertSame(['a', 'b'], AtlasDevSchemaArray::stringList(['xs' => ['a', 'b']], 'xs'));
    }

    public function test_string_list_rejects_non_list(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasDevSchemaArray::stringList(['xs' => ['key' => 'val']], 'xs');
    }

    public function test_string_list_rejects_non_string_item(): void
    {
        $this->expectException(InvalidArgumentException::class);
        AtlasDevSchemaArray::stringList(['xs' => ['a', 5]], 'xs');
    }

    public function test_empty_string_list_is_allowed(): void
    {
        $this->assertSame([], AtlasDevSchemaArray::stringList(['xs' => []], 'xs'));
    }
}
