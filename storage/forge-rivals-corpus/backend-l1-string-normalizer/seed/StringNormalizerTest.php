<?php

declare(strict_types=1);

namespace Tests\Unit\Support;

use App\Support\StringNormalizer;
use PHPUnit\Framework\TestCase;

final class StringNormalizerTest extends TestCase
{
    /** @return iterable<string, array{0:string,1:string}> */
    public static function rows(): iterable
    {
        yield 'trims edges' => ["  hello  ", 'hello'];
        yield 'collapses internal whitespace' => ["a   b\tc\nd", 'a b c d'];
        yield 'casefolds ascii' => ['HELLO World', 'hello world'];
        yield 'mixes trim+collapse+casefold' => ["  HELLO   World  ", 'hello world'];
        yield 'preserves accent bytes' => ['Olá Mundo', 'olá mundo'];
        yield 'preserves cjk bytes' => ['Hello 世界', 'hello 世界'];
        yield 'idempotent on already canonical' => ['hello world', 'hello world'];
        yield 'empty stays empty' => ['', ''];
    }

    /** @dataProvider rows */
    public function test_canonical_tabulated(string $input, string $expected): void
    {
        $this->assertSame($expected, StringNormalizer::canonical($input));
    }

    public function test_canonical_is_idempotent(): void
    {
        foreach (['  HELLO   World  ', 'já-aqui', 'X', ''] as $input) {
            $once = StringNormalizer::canonical($input);
            $twice = StringNormalizer::canonical($once);
            $this->assertSame($once, $twice, "not idempotent for {$input}");
        }
    }

    public function test_canonical_is_pure(): void
    {
        $globals = $GLOBALS;
        StringNormalizer::canonical('  hello  ');
        $this->assertSame($globals, $GLOBALS, 'canonical() must not mutate globals');
    }
}
