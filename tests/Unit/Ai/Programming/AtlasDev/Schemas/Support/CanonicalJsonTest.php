<?php

namespace Tests\Unit\Ai\Programming\AtlasDev\Schemas\Support;

use App\Services\Ai\Programming\AtlasDev\Schemas\Support\CanonicalJson;
use PHPUnit\Framework\TestCase;

final class CanonicalJsonTest extends TestCase
{
    public function test_keys_are_sorted_alphabetically_at_top_level(): void
    {
        $result = CanonicalJson::canonicalize([
            'zebra' => 1,
            'apple' => 2,
            'mango' => 3,
        ]);

        $this->assertSame(['apple', 'mango', 'zebra'], array_keys($result));
    }

    public function test_canonicalization_is_recursive_on_nested_maps(): void
    {
        $result = CanonicalJson::canonicalize([
            'outer_b' => [
                'inner_z' => 1,
                'inner_a' => 2,
            ],
            'outer_a' => 3,
        ]);

        $this->assertSame(['outer_a', 'outer_b'], array_keys($result));
        $this->assertSame(['inner_a', 'inner_z'], array_keys($result['outer_b']));
    }

    public function test_lists_preserve_order(): void
    {
        $result = CanonicalJson::canonicalize([
            'items' => ['c', 'a', 'b'],
        ]);

        $this->assertSame(['c', 'a', 'b'], $result['items']);
    }

    public function test_lists_of_maps_are_canonicalized_recursively(): void
    {
        $result = CanonicalJson::canonicalize([
            'items' => [
                ['z' => 1, 'a' => 2],
                ['m' => 3, 'b' => 4],
            ],
        ]);

        $this->assertSame(['a', 'z'], array_keys($result['items'][0]));
        $this->assertSame(['b', 'm'], array_keys($result['items'][1]));
    }

    public function test_encode_uses_unescaped_slashes_and_unicode(): void
    {
        $json = CanonicalJson::encode([
            'path' => 'a/b/c',
            'name' => 'café',
        ]);

        $this->assertStringContainsString('a/b/c', $json);
        $this->assertStringNotContainsString('a\/b\/c', $json);
        $this->assertStringContainsString('café', $json);
    }

    public function test_encode_is_deterministic_regardless_of_input_order(): void
    {
        $first = CanonicalJson::encode(['b' => 2, 'a' => 1, 'c' => 3]);
        $second = CanonicalJson::encode(['c' => 3, 'a' => 1, 'b' => 2]);

        $this->assertSame($first, $second);
    }

    public function test_canonicalize_without_strips_top_level_key(): void
    {
        $payload = ['a' => 1, 'hash' => 'xx', 'b' => 2];
        $stripped = CanonicalJson::canonicalizeWithout($payload, 'hash');

        $this->assertSame(['a', 'b'], array_keys($stripped));
    }

    public function test_empty_list_stays_empty_list(): void
    {
        $result = CanonicalJson::canonicalize(['items' => []]);

        $this->assertSame([], $result['items']);
    }
}
