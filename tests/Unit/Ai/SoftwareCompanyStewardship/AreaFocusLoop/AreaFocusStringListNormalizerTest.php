<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use Tests\TestCase;

final class AreaFocusStringListNormalizerTest extends TestCase
{
    public function test_preserve_strings_keeps_only_string_values_without_trimming_or_deduping(): void
    {
        $this->assertSame(
            ['alpha', '  spaced  ', 'alpha', '0', '   '],
            AreaFocusStringListNormalizer::preserveStrings([
                'alpha',
                '  spaced  ',
                '',
                'alpha',
                123,
                false,
                null,
                ['nested'],
                '0',
                '   ',
            ]),
        );
    }

    public function test_preserve_strings_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::preserveStrings('alpha'));
        $this->assertSame([], AreaFocusStringListNormalizer::preserveStrings(null));
    }

    public function test_coerced_string_values_preserves_legacy_array_cast_contract(): void
    {
        $this->assertSame(
            ['alpha', '', '  ', '0'],
            AreaFocusStringListNormalizer::coercedStringValues([
                'alpha',
                '',
                123,
                false,
                null,
                ['nested'],
                '  ',
                '0',
            ]),
        );

        $this->assertSame(['scalar'], AreaFocusStringListNormalizer::coercedStringValues('scalar'));
        $this->assertSame([], AreaFocusStringListNormalizer::coercedStringValues(123));
        $this->assertSame([], AreaFocusStringListNormalizer::coercedStringValues(null));
    }

    public function test_unique_string_values_preserves_raw_strings_and_dedupes(): void
    {
        $this->assertSame(
            ['alpha', '', '0', '  '],
            AreaFocusStringListNormalizer::uniqueStringValues([
                'alpha',
                '',
                'alpha',
                '0',
                0,
                false,
                null,
                ['nested'],
                '  ',
                '0',
            ]),
        );
    }

    public function test_unique_merged_string_values_merges_lists_and_preserves_first_seen_order(): void
    {
        $this->assertSame(
            ['alpha', '', 'beta', '0', '  '],
            AreaFocusStringListNormalizer::uniqueMergedStringValues(
                ['alpha', '', 'beta', 'alpha'],
                ['beta', 123, '0', false, ['nested']],
                '  ',
                ['0', 'gamma' => 'alpha'],
            ),
        );
    }

    public function test_truthy_values_preserves_legacy_php_truthiness(): void
    {
        $this->assertSame(
            ['alpha', '  ', 1, true],
            AreaFocusStringListNormalizer::truthyValues(['alpha', '', '0', '  ', 0, false, 1, true, null]),
        );

        $this->assertSame(['scalar'], AreaFocusStringListNormalizer::truthyValues('scalar'));
        $this->assertSame([], AreaFocusStringListNormalizer::truthyValues('0'));
        $this->assertSame([], AreaFocusStringListNormalizer::truthyValues(null));
    }

    public function test_truthy_stringified_scalar_values_preserves_legacy_php_truthiness(): void
    {
        $this->assertSame(
            ['alpha', '  ', '1'],
            AreaFocusStringListNormalizer::truthyStringifiedScalarValues(['alpha', '', '0', '  ', 0, false, 1, null, ['nested']]),
        );
    }

    public function test_unique_stringified_values_preserves_order_dedupes_and_keeps_empty_values(): void
    {
        $this->assertSame(
            ['alpha', '', '0', '1', '4.2'],
            AreaFocusStringListNormalizer::uniqueStringifiedValues([
                'alpha',
                '',
                'alpha',
                '0',
                0,
                false,
                true,
                null,
                4.2,
                ['nested'],
            ]),
        );

        $this->assertSame(['scalar'], AreaFocusStringListNormalizer::uniqueStringifiedValues('scalar'));
        $this->assertSame([], AreaFocusStringListNormalizer::uniqueStringifiedValues(null));
    }

    public function test_unique_truthy_stringified_values_dedupes_after_php_truthiness_filtering(): void
    {
        $this->assertSame(
            ['alpha', '1', '4.2', '  '],
            AreaFocusStringListNormalizer::uniqueTruthyStringifiedValues([
                'alpha',
                '',
                'alpha',
                '0',
                0,
                false,
                true,
                null,
                4.2,
                '  ',
                ['nested'],
            ]),
        );
    }

    public function test_preserve_non_blank_strings_filters_blank_values_without_trimming(): void
    {
        $this->assertSame(
            ['alpha', '  spaced  ', 'alpha', '0'],
            AreaFocusStringListNormalizer::preserveNonBlankStrings([
                'alpha',
                '  spaced  ',
                '',
                'alpha',
                123,
                false,
                null,
                ['nested'],
                '0',
                '   ',
            ]),
        );
    }

    public function test_preserve_non_blank_strings_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::preserveNonBlankStrings('alpha'));
        $this->assertSame([], AreaFocusStringListNormalizer::preserveNonBlankStrings(null));
    }

    public function test_trimmed_strings_trims_filters_and_preserves_duplicates(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'alpha', '0'],
            AreaFocusStringListNormalizer::trimmedStrings([
                ' alpha ',
                '',
                '  ',
                'beta',
                'alpha',
                123,
                null,
                '0',
            ]),
        );
    }

    public function test_trimmed_strings_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedStrings(' alpha '));
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedStrings(null));
    }

    public function test_trimmed_strings_or_scalar_string_accepts_one_scalar_string(): void
    {
        $this->assertSame(
            ['alpha'],
            AreaFocusStringListNormalizer::trimmedStringsOrScalarString(' alpha ')
        );
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedStringsOrScalarString(''));
        $this->assertSame(
            ['alpha', 'beta'],
            AreaFocusStringListNormalizer::trimmedStringsOrScalarString([' alpha ', 42, 'beta'])
        );
    }

    public function test_trimmed_lines_splits_trims_and_drops_blank_lines(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            AreaFocusStringListNormalizer::trimmedLines(" alpha \n\n beta \n  \ngamma"),
        );
    }

    public function test_trimmed_unique_strings_trims_filters_and_dedupes(): void
    {
        $this->assertSame(
            ['alpha', 'beta', '0'],
            AreaFocusStringListNormalizer::trimmedUniqueStrings([
                ' alpha ',
                'alpha',
                '',
                ' beta ',
                'beta',
                123,
                null,
                '0',
                ' 0 ',
            ]),
        );
    }

    public function test_trimmed_unique_strings_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedUniqueStrings(' alpha '));
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedUniqueStrings(null));
    }

    public function test_stringified_non_empty_values_casts_scalars_and_drops_empty_results(): void
    {
        $this->assertSame(
            ['alpha', '0', '1', '4.2', '  spaced  '],
            AreaFocusStringListNormalizer::stringifiedNonEmptyValues([
                'alpha',
                '',
                0,
                false,
                true,
                null,
                4.2,
                '  spaced  ',
            ]),
        );
    }

    public function test_stringified_non_empty_values_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::stringifiedNonEmptyValues('alpha'));
        $this->assertSame([], AreaFocusStringListNormalizer::stringifiedNonEmptyValues(null));
    }

    public function test_trimmed_scalar_values_accepts_scalars_and_drops_empty_results(): void
    {
        $this->assertSame(
            ['alpha', '0', '1', '4.2'],
            AreaFocusStringListNormalizer::trimmedScalarValues([
                ' alpha ',
                '',
                '  ',
                0,
                false,
                true,
                null,
                4.2,
                ['nested'],
            ]),
        );
    }

    public function test_trimmed_scalar_values_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedScalarValues('alpha'));
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedScalarValues(null));
    }

    public function test_normalized_id_accepts_strings_ints_and_floats_only(): void
    {
        $this->assertSame('alpha', AreaFocusStringListNormalizer::normalizedId(' alpha '));
        $this->assertSame('42', AreaFocusStringListNormalizer::normalizedId(42));
        $this->assertSame('4.2', AreaFocusStringListNormalizer::normalizedId(4.2));
        $this->assertSame('', AreaFocusStringListNormalizer::normalizedId(false));
        $this->assertSame('', AreaFocusStringListNormalizer::normalizedId(['alpha']));
    }

    public function test_non_empty_field_values_extracts_field_values_from_rows(): void
    {
        $this->assertSame(
            ['evt_1', '0', '42'],
            AreaFocusStringListNormalizer::nonEmptyFieldValues([
                ['event_id' => 'evt_1'],
                ['event_id' => ''],
                ['event_id' => '0'],
                ['event_id' => 42],
                ['other' => 'evt_other'],
            ], 'event_id'),
        );
    }

    public function test_trimmed_unique_string_or_number_values_accepts_numeric_ids_without_sorting(): void
    {
        $this->assertSame(
            ['10', '9', '100', '1', '01', '1.0'],
            AreaFocusStringListNormalizer::trimmedUniqueStringOrNumberValues([
                '10',
                ' 9 ',
                100,
                '1',
                '01',
                '1.0',
                '9',
                '',
                false,
                null,
                ['nested'],
            ]),
        );
    }

    public function test_trimmed_unique_string_or_number_values_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedUniqueStringOrNumberValues('alpha'));
        $this->assertSame([], AreaFocusStringListNormalizer::trimmedUniqueStringOrNumberValues(null));
    }

    public function test_coerced_trimmed_unique_string_or_number_values_accepts_scalar_or_list_ids(): void
    {
        $this->assertSame(
            ['alpha'],
            AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues(' alpha ')
        );
        $this->assertSame(
            ['10', '9', '0', '4.2'],
            AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues([
                '10',
                ' 9 ',
                10,
                0,
                false,
                4.2,
                ['nested'],
            ])
        );
    }

    public function test_normalized_unique_sorted_ids_dedupes_and_sorts_as_strings(): void
    {
        $this->assertSame(
            ['01', '1', '1.0', '10', '100', '9'],
            AreaFocusStringListNormalizer::normalizedUniqueSortedIds([
                '10',
                '9',
                '100',
                '1',
                '01',
                '1.0',
                ' 9 ',
                '',
                null,
                ['nested'],
            ]),
        );
    }

    public function test_normalized_unique_sorted_ids_returns_empty_list_for_non_array_payloads(): void
    {
        $this->assertSame([], AreaFocusStringListNormalizer::normalizedUniqueSortedIds('alpha'));
        $this->assertSame([], AreaFocusStringListNormalizer::normalizedUniqueSortedIds(null));
    }
}
