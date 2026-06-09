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
}
