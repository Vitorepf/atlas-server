<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceListNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasWorkspaceIntelligenceListNormalizerTest extends TestCase
{
    public function test_strings_from_array_cast_preserves_raw_workspace_refs(): void
    {
        $normalizer = new AtlasWorkspaceIntelligenceListNormalizer();

        $this->assertSame(
            [' app/Foo.php ', '', '0'],
            $normalizer->stringsFromArrayCast([' app/Foo.php ', 42, '', null, '0']),
        );

        $this->assertSame(['single.php'], $normalizer->stringsFromArrayCast('single.php'));
    }

    public function test_unique_mapped_strings_trims_filters_and_deduplicates(): void
    {
        $normalizer = new AtlasWorkspaceIntelligenceListNormalizer();

        $this->assertSame(
            ['area:abc', 'stack:def'],
            $normalizer->uniqueMappedStrings(
                [' area:abc ', 'ignored', 'area:abc', 'stack:def'],
                static fn (mixed $value): string => str_starts_with(trim((string) $value), 'ignored') ? '' : (string) $value,
            ),
        );
    }

    public function test_unique_string_values_keeps_only_non_empty_strings(): void
    {
        $normalizer = new AtlasWorkspaceIntelligenceListNormalizer();

        $this->assertSame(
            ['0', 'alpha', 'beta'],
            $normalizer->uniqueStringValues([' 0 ', 0, ' alpha ', ['ignored'], '', 'alpha', ' beta ']),
        );
    }

    public function test_unique_strings_accepts_scalar_values_for_workspace_metadata(): void
    {
        $normalizer = new AtlasWorkspaceIntelligenceListNormalizer();

        $this->assertSame(
            ['0', 'alpha', '42'],
            $normalizer->uniqueStrings([' 0 ', 0, ' alpha ', ['ignored'], '', 42, 'alpha']),
        );
    }

    public function test_unique_single_line_strings_rejects_multiline_values(): void
    {
        $normalizer = new AtlasWorkspaceIntelligenceListNormalizer();

        $this->assertSame(
            ['alpha', 'beta'],
            $normalizer->uniqueSingleLineStrings([' alpha ', "bad\nline", 'beta', 'alpha']),
        );
    }
}
