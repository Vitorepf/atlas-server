<?php

declare(strict_types=1);

namespace Tests\Unit\Engineering;

use App\Services\Engineering\EngineeringStringListNormalizer;
use Tests\TestCase;

final class EngineeringStringListNormalizerTest extends TestCase
{
    public function test_unique_non_empty_strings_preserves_stringified_semantics(): void
    {
        $this->assertSame(
            ['0', 'ready'],
            EngineeringStringListNormalizer::uniqueNonEmptyStrings(['0', '', 0, 'ready', 'ready']),
        );
    }

    public function test_unique_string_casts_can_preserve_empty_and_spacing(): void
    {
        $values = [' a ', '', '0', 5, '5'];

        $this->assertSame(
            [' a ', '', '0', '5'],
            EngineeringStringListNormalizer::uniqueStringCasts($values, filterEmpty: false),
        );
        $this->assertSame(
            [' a ', '0', '5'],
            EngineeringStringListNormalizer::uniqueStringCasts($values, filterEmpty: true),
        );
    }

    public function test_unique_truthy_string_casts_preserves_legacy_array_filter_semantics(): void
    {
        $this->assertSame(
            [' a ', '5'],
            EngineeringStringListNormalizer::uniqueTruthyStringCasts([' a ', '', '0', 0, false, 5, '5']),
        );
    }

    public function test_unique_truthy_string_values_preserves_legacy_filtering(): void
    {
        $this->assertSame(
            ['ready'],
            EngineeringStringListNormalizer::uniqueTruthyStringValues([' 0 ', '', 0, false, 'ready', 'ready']),
        );
    }

    public function test_unique_non_empty_scalar_strings_ignores_non_scalars(): void
    {
        $this->assertSame(
            ['0', 'ready', '5'],
            EngineeringStringListNormalizer::uniqueNonEmptyScalarStrings([' 0 ', '', ['nested'], false, 'ready', 'ready', 5]),
        );
    }

    public function test_unique_non_empty_scalar_strings_accepts_single_scalar(): void
    {
        $this->assertSame(
            ['capability'],
            EngineeringStringListNormalizer::uniqueNonEmptyScalarStrings(' capability '),
        );
    }

    public function test_non_empty_scalar_strings_preserves_duplicates_and_accepts_single_scalar(): void
    {
        $this->assertSame(
            ['0', 'ready', 'ready', '5'],
            EngineeringStringListNormalizer::nonEmptyScalarStrings([' 0 ', '', ['nested'], false, 'ready', 'ready', 5]),
        );
        $this->assertSame(['capability'], EngineeringStringListNormalizer::nonEmptyScalarStrings(' capability '));
    }

    public function test_unique_non_empty_string_values_ignores_non_strings(): void
    {
        $this->assertSame(
            ['atlas-kernel', 'local', '0'],
            EngineeringStringListNormalizer::uniqueNonEmptyStringValues([' Atlas-Kernel ', 123, false, 'local', 'atlas-kernel', ' 0 '], lowercase: true),
        );
    }

    public function test_unique_bullet_list_strings_splits_text_and_trims_bullets(): void
    {
        $this->assertSame(
            ['api', 'app', '0'],
            EngineeringStringListNormalizer::uniqueBulletListStrings("- api\n- app\napi\n- 0"),
        );
    }

    public function test_unique_bullet_list_strings_accepts_arrays(): void
    {
        $this->assertSame(
            ['api', 'app'],
            EngineeringStringListNormalizer::uniqueBulletListStrings(['- api', ['nested'], ' app ', 'api']),
        );
    }

    public function test_unique_comma_separated_strings_preserves_case_by_default(): void
    {
        $this->assertSame(
            ['API', 'web', '0'],
            EngineeringStringListNormalizer::uniqueCommaSeparatedStrings(' API,web,,API, 0 '),
        );
    }

    public function test_unique_comma_separated_strings_can_lowercase_values(): void
    {
        $this->assertSame(
            ['api', 'web'],
            EngineeringStringListNormalizer::uniqueCommaSeparatedStrings([' API ', 'web', 'api', ['nested']], lowercase: true),
        );
    }
}
