<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\OpenBrainContextInjection;

use App\Services\Ai\OpenBrain\Support\ContextExpansionRenderSupport;
use App\Services\Ai\OpenBrainContextInjection\TextNormalizeSupport;
use App\Services\Ai\OpenBrainContextPack\Support as ContextPackSupport;
use PHPUnit\Framework\TestCase;

/**
 * Open Brain scalar SSOT pure peel — no I/O, no DB, no Laravel app boot.
 * BC: ContextExpansion scalarString/stringList and Pack stringOpt must match SSOT.
 */
final class TextNormalizeSupportTest extends TestCase
{
    public function test_string_value_and_scalar_string_are_aliases(): void
    {
        self::assertSame('ok', TextNormalizeSupport::stringValue('  ok  '));
        self::assertSame('ok', TextNormalizeSupport::scalarString('  ok  '));
        self::assertSame('fallback', TextNormalizeSupport::stringValue('   ', 'fallback'));
        self::assertSame('fallback', TextNormalizeSupport::scalarString(null, 'fallback'));
        self::assertSame('fallback', TextNormalizeSupport::stringValue(['x'], 'fallback'));
        self::assertSame('42', TextNormalizeSupport::stringValue(42));
    }

    public function test_nullable_string_trims_empty_to_null(): void
    {
        self::assertSame('x', TextNormalizeSupport::nullableString('  x  '));
        self::assertNull(TextNormalizeSupport::nullableString(''));
        self::assertNull(TextNormalizeSupport::nullableString('   '));
        self::assertNull(TextNormalizeSupport::nullableString(null));
        self::assertNull(TextNormalizeSupport::nullableString(['a']));
        self::assertSame('7', TextNormalizeSupport::nullableString(7));
    }

    public function test_string_opt_reads_opts_via_nullable_string(): void
    {
        self::assertSame('ws', TextNormalizeSupport::stringOpt(['workspace' => '  ws  '], 'workspace'));
        self::assertNull(TextNormalizeSupport::stringOpt(['workspace' => '  '], 'workspace'));
        self::assertNull(TextNormalizeSupport::stringOpt([], 'workspace'));
        self::assertNull(TextNormalizeSupport::stringOpt(['workspace' => ['nested']], 'workspace'));
    }

    public function test_string_list_unique_trim_and_limits(): void
    {
        self::assertSame(['a', 'b'], TextNormalizeSupport::stringList([' a ', 'b', 'a', '', null]));
        self::assertSame(['solo'], TextNormalizeSupport::stringList(' solo '));
        self::assertSame([], TextNormalizeSupport::stringList(new \stdClass));

        $many = array_map(static fn (int $i): string => 'v'.$i, range(1, 30));
        self::assertCount(24, TextNormalizeSupport::stringList($many));
        self::assertCount(12, TextNormalizeSupport::stringList($many, 12));
        self::assertCount(30, TextNormalizeSupport::stringList($many, null));
        self::assertCount(30, TextNormalizeSupport::stringList($many, 0));
    }

    public function test_sorted_strings_orders_unique_trimmed(): void
    {
        self::assertSame(
            ['a', 'b', 'c'],
            TextNormalizeSupport::sortedStrings([' c ', 'a', 'b', 'a', '  ']),
        );
    }

    public function test_context_expansion_wrappers_match_ssot(): void
    {
        self::assertSame(
            TextNormalizeSupport::scalarString('  hi  ', 'd'),
            ContextExpansionRenderSupport::scalarString('  hi  ', 'd'),
        );
        self::assertSame(
            TextNormalizeSupport::scalarString(null, 'd'),
            ContextExpansionRenderSupport::scalarString(null, 'd'),
        );

        $raw = array_map(static fn (int $i): string => 'x'.$i, range(1, 20));
        self::assertSame(
            TextNormalizeSupport::stringList($raw, 12),
            ContextExpansionRenderSupport::stringList($raw),
        );
    }

    public function test_context_pack_string_opt_matches_ssot(): void
    {
        $pack = new ContextPackSupport;
        $opts = ['task' => '  peel  ', 'empty' => '  ', 'missing_scalar' => ['nope']];

        self::assertSame(
            TextNormalizeSupport::stringOpt($opts, 'task'),
            $pack->stringOpt($opts, 'task'),
        );
        self::assertSame(
            TextNormalizeSupport::stringOpt($opts, 'empty'),
            $pack->stringOpt($opts, 'empty'),
        );
        self::assertSame(
            TextNormalizeSupport::stringOpt($opts, 'missing_scalar'),
            $pack->stringOpt($opts, 'missing_scalar'),
        );
        self::assertNull($pack->stringOpt($opts, 'absent'));
    }
}
