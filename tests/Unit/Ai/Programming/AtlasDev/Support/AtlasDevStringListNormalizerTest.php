<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming\AtlasDev\Support;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use InvalidArgumentException;
use Tests\TestCase;

final class AtlasDevStringListNormalizerTest extends TestCase
{
    public function test_unique_strings_preserves_raw_first_occurrence(): void
    {
        $this->assertSame(
            [' alpha ', 'beta', 'alpha'],
            AtlasDevStringListNormalizer::uniqueStrings([' alpha ', 'beta', 'alpha', 'beta'])
        );
    }

    public function test_strings_preserves_provider_arg_semantics(): void
    {
        $this->assertSame(
            ['--flag', '', ' value '],
            AtlasDevStringListNormalizer::strings(['--flag', 123, '', null, ' value '])
        );

        $this->assertSame([], AtlasDevStringListNormalizer::strings('--flag'));
    }

    public function test_non_empty_strings_preserves_order_without_deduping_provider_tools(): void
    {
        $this->assertSame(
            ['Read', 'Read', 'Write'],
            AtlasDevStringListNormalizer::nonEmptyStrings(['Read', '', 'Read', 'Write'])
        );
    }

    public function test_require_non_blank_strings_preserves_raw_values_and_throws_with_field_path(): void
    {
        $this->assertSame(
            [' alpha ', 'beta'],
            AtlasDevStringListNormalizer::requireNonBlankStrings([' alpha ', 'beta'], 'payload.refs')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload.refs[1] must be a non-empty string.');

        AtlasDevStringListNormalizer::requireNonBlankStrings(['ok', '   '], 'payload.refs');
    }

    public function test_require_non_empty_strings_preserves_legacy_space_only_semantics(): void
    {
        $this->assertSame(
            [' alpha ', '   '],
            AtlasDevStringListNormalizer::requireNonEmptyStrings([' alpha ', '   '], 'payload.files')
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('payload.files[1] must be a non-empty string.');

        AtlasDevStringListNormalizer::requireNonEmptyStrings(['ok', ''], 'payload.files');
    }

    public function test_trimmed_strings_preserves_order_and_duplicates(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'alpha'],
            AtlasDevStringListNormalizer::trimmedStrings([' alpha ', '', null, 'beta', 42, 'alpha'])
        );
    }

    public function test_unique_trimmed_strings_preserves_first_occurrence(): void
    {
        $this->assertSame(
            ['alpha', 'beta'],
            AtlasDevStringListNormalizer::uniqueTrimmedStrings([' alpha ', 'beta', 'alpha', ''])
        );
    }

    public function test_unique_recursive_trimmed_strings_flattens_nested_values(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            AtlasDevStringListNormalizer::uniqueRecursiveTrimmedStrings(['alpha', [' beta ', ['gamma', 'alpha']], null])
        );
    }

    public function test_unique_sorted_strings_matches_repair_signal_semantics(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'zeta'],
            AtlasDevStringListNormalizer::uniqueSortedStrings(['zeta', 'alpha'], ['beta', 'alpha'])
        );
    }

    public function test_unique_trimmed_scalar_values_matches_runtime_payload_semantics(): void
    {
        $this->assertSame(
            ['alpha', '42', 'beta'],
            AtlasDevStringListNormalizer::uniqueTrimmedScalarValues([' alpha ', 42, false, ['ignored'], 'beta', 'alpha'])
        );
    }

    public function test_unique_merged_strings_preserves_first_occurrence(): void
    {
        $this->assertSame(
            ['alpha', 'beta', 'gamma'],
            AtlasDevStringListNormalizer::uniqueMergedStrings(['alpha', 'beta'], ['alpha', 'gamma'])
        );
    }
}
