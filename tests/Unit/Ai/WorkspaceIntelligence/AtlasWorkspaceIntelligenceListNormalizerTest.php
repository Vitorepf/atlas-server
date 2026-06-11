<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\WorkspaceIntelligence;

use App\Services\Ai\WorkspaceIntelligence\AtlasWorkspaceIntelligenceListNormalizer;
use PHPUnit\Framework\TestCase;

final class AtlasWorkspaceIntelligenceListNormalizerTest extends TestCase
{
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
}
