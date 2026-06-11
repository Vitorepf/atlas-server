<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Support;

use App\Services\Ai\Support\AiStringListNormalizer;
use Tests\TestCase;

final class AiStringListNormalizerTest extends TestCase
{
    public function test_unique_strings_preserves_raw_values_for_cli_args(): void
    {
        $this->assertSame(
            ['--flag', ' value ', 'value'],
            AiStringListNormalizer::uniqueStrings(['--flag', ' value ', '--flag', 'value'])
        );
    }

    public function test_strings_preserves_raw_string_entries_without_filtering_blank_values(): void
    {
        $this->assertSame(
            [' alpha ', '', ' ', '0'],
            AiStringListNormalizer::strings([' alpha ', '', null, ' ', 42, '0'])
        );
    }

    public function test_strings_from_array_cast_preserves_legacy_scalar_cast_semantics(): void
    {
        $this->assertSame(['alpha'], AiStringListNormalizer::stringsFromArrayCast('alpha'));
        $this->assertSame([' alpha ', '', '0'], AiStringListNormalizer::stringsFromArrayCast([' alpha ', '', 42, '0']));
        $this->assertSame([], AiStringListNormalizer::stringsFromArrayCast(null));
    }

    public function test_unique_trimmed_strings_filters_non_strings_and_empty_values(): void
    {
        $this->assertSame(
            ['alpha', 'beta'],
            AiStringListNormalizer::uniqueTrimmedStrings([' alpha ', '', null, 'beta', 'alpha'])
        );
    }

    public function test_cast_items_to_strings_preserves_legacy_array_map_cast_semantics(): void
    {
        $this->assertSame(
            [' alpha ', '', '42', '1', ''],
            AiStringListNormalizer::castItemsToStrings([' alpha ', '', 42, true, false])
        );

        $this->assertSame([], AiStringListNormalizer::castItemsToStrings('alpha'));
    }

    public function test_trimmed_strings_from_array_cast_accepts_scalar_string_but_filters_non_strings(): void
    {
        $this->assertSame(['alpha'], AiStringListNormalizer::trimmedStringsFromArrayCast(' alpha '));
        $this->assertSame(['alpha', '0'], AiStringListNormalizer::trimmedStringsFromArrayCast([' alpha ', 42, ' ', '0']));
        $this->assertSame([], AiStringListNormalizer::trimmedStringsFromArrayCast(42));
    }

    public function test_trimmed_cast_items_to_strings_filters_blank_values_after_casting(): void
    {
        $this->assertSame(
            ['alpha', '42', '1'],
            AiStringListNormalizer::trimmedCastItemsToStrings([' alpha ', '', ' ', 42, true, false])
        );

        $this->assertSame([], AiStringListNormalizer::trimmedCastItemsToStrings('alpha'));
    }

    public function test_trimmed_cast_values_accepts_scalar_values_through_array_cast(): void
    {
        $this->assertSame(['alpha'], AiStringListNormalizer::trimmedCastValues(' alpha '));
        $this->assertSame(['42'], AiStringListNormalizer::trimmedCastValues(42));
        $this->assertSame(['alpha', '42', '1'], AiStringListNormalizer::trimmedCastValues([' alpha ', 42, true, false, ' ']));
    }

    public function test_unique_recursive_trimmed_strings_flattens_nested_arrays(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            AiStringListNormalizer::uniqueRecursiveTrimmedStrings([' alpha ', ['beta', ['alpha', ' gamma ']], '', 42])
        );
    }

    public function test_unique_merged_and_sorted_strings_preserve_contracts(): void
    {
        $this->assertSame(
            ['b', 'a', 'c'],
            AiStringListNormalizer::uniqueMergedStrings(['b', 'a'], ['b', 'c'])
        );

        $this->assertSame(
            ['a', 'b', 'c'],
            AiStringListNormalizer::uniqueSortedStrings(['b', 'a'], ['b', 'c'])
        );
    }

    public function test_non_empty_strings_preserve_raw_strings_without_trim(): void
    {
        $this->assertSame(
            [' alpha ', ' ', '0', 'beta'],
            AiStringListNormalizer::uniqueNonEmptyStrings([' alpha ', '', ' ', null, '0', 'beta', ' alpha '])
        );
    }

    public function test_non_blank_strings_filter_whitespace_but_preserve_raw_values(): void
    {
        $this->assertSame(
            [' alpha ', '0', 'beta'],
            AiStringListNormalizer::nonBlankStrings([' alpha ', '', ' ', null, '0', 'beta'])
        );
    }

    public function test_unique_trimmed_scalar_values_accepts_numbers_and_drops_blank_values(): void
    {
        $this->assertSame(
            ['alpha', '42', '4.2'],
            AiStringListNormalizer::uniqueTrimmedScalarValues([' alpha ', 42, false, ' ', ['nested'], 4.2, 'alpha'])
        );
    }

    public function test_unique_mapped_strings_trims_filters_and_deduplicates(): void
    {
        $this->assertSame(
            ['area:abc', 'stack:def'],
            AiStringListNormalizer::uniqueMappedStrings(
                [' area:abc ', 'ignored', 'area:abc', 'stack:def'],
                static fn (mixed $value): string => str_starts_with(trim((string) $value), 'ignored') ? '' : (string) $value,
            )
        );
    }

    public function test_unique_mapped_scalar_strings_ignores_non_scalars_and_can_lowercase(): void
    {
        $this->assertSame(
            ['alpha', '42', 'beta'],
            AiStringListNormalizer::uniqueMappedScalarStrings(
                [' Alpha ', ['ignored'], 42, 'BETA', 'alpha'],
                static fn (mixed $value): mixed => $value,
                lowercase: true,
            )
        );
    }

    public function test_unique_single_line_strings_drops_multiline_values(): void
    {
        $this->assertSame(
            ['alpha', '42', 'beta'],
            AiStringListNormalizer::uniqueSingleLineStrings([' alpha ', "bad\nline", 42, 'beta', 'alpha'])
        );
    }

    public function test_csv_or_array_normalizes_comma_separated_values(): void
    {
        $this->assertSame(
            ['one', 'two', 'three'],
            AiStringListNormalizer::csvOrArray(' one, two,one,three ')
        );
    }
}
