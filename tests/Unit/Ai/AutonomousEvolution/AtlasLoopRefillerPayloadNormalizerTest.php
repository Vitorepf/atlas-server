<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AutonomousEvolution;

use App\Services\Ai\AutonomousEvolution\Discovery\Supply\AtlasLoopRefillerPayloadNormalizer;
use Tests\TestCase;

class AtlasLoopRefillerPayloadNormalizerTest extends TestCase
{
    public function test_first_string_returns_first_non_empty(): void
    {
        self::assertSame('hello', AtlasLoopRefillerPayloadNormalizer::firstString(['', '  ', 'hello', 'world']));
    }

    public function test_first_string_returns_empty_when_all_empty(): void
    {
        self::assertSame('', AtlasLoopRefillerPayloadNormalizer::firstString(['', '  ']));
    }

    public function test_first_string_returns_empty_for_empty_array(): void
    {
        self::assertSame('', AtlasLoopRefillerPayloadNormalizer::firstString([]));
    }

    public function test_first_string_trims_result(): void
    {
        self::assertSame('x', AtlasLoopRefillerPayloadNormalizer::firstString(['  x  ']));
    }

    public function test_first_string_skips_non_strings(): void
    {
        self::assertSame('found', AtlasLoopRefillerPayloadNormalizer::firstString([null, 42, false, 'found']));
    }

    public function test_array_list_filters_non_arrays(): void
    {
        $result = AtlasLoopRefillerPayloadNormalizer::arrayList([['a' => 1], 'not_array', ['b' => 2], 42]);

        self::assertSame([['a' => 1], ['b' => 2]], $result);
    }

    public function test_array_list_returns_empty_for_non_array(): void
    {
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::arrayList('string'));
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::arrayList(null));
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::arrayList(42));
    }

    public function test_array_list_reindexes_keys(): void
    {
        $result = AtlasLoopRefillerPayloadNormalizer::arrayList(['x' => ['inner'], 'y' => ['inner2']]);

        self::assertSame([['inner'], ['inner2']], $result);
    }

    public function test_string_list_maps_and_filters(): void
    {
        $result = AtlasLoopRefillerPayloadNormalizer::stringList(['  a  ', '', 'b', '  ', 42]);

        self::assertSame(['a', 'b', '42'], $result);
    }

    public function test_string_list_returns_empty_for_empty(): void
    {
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::stringList([]));
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::stringList(null));
    }

    public function test_string_list_filters_whitespace_only(): void
    {
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::stringList(['   ', '  ', "\t"]));
    }

    public function test_json_object_returns_array_directly(): void
    {
        $arr = ['key' => 'value'];
        self::assertSame($arr, AtlasLoopRefillerPayloadNormalizer::jsonObject($arr));
    }

    public function test_json_object_converts_stdclass(): void
    {
        $obj = new \stdClass();
        $obj->key = 'value';
        self::assertSame(['key' => 'value'], AtlasLoopRefillerPayloadNormalizer::jsonObject($obj));
    }

    public function test_json_object_parses_json_string(): void
    {
        self::assertSame(['key' => 'value'], AtlasLoopRefillerPayloadNormalizer::jsonObject('{"key":"value"}'));
    }

    public function test_json_object_returns_empty_for_empty_string(): void
    {
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::jsonObject(''));
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::jsonObject('   '));
    }

    public function test_json_object_returns_empty_for_invalid_json(): void
    {
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::jsonObject('{invalid'));
    }

    public function test_json_object_returns_empty_for_non_json_scalar(): void
    {
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::jsonObject(42));
        self::assertSame([], AtlasLoopRefillerPayloadNormalizer::jsonObject(true));
    }

    public function test_all_methods_are_deterministic(): void
    {
        self::assertSame(AtlasLoopRefillerPayloadNormalizer::firstString(['', 'x']), AtlasLoopRefillerPayloadNormalizer::firstString(['', 'x']));
        self::assertSame(AtlasLoopRefillerPayloadNormalizer::stringList(['a', 'b']), AtlasLoopRefillerPayloadNormalizer::stringList(['a', 'b']));
    }
}
