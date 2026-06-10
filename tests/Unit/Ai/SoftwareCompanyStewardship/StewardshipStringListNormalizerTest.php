<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship;

use App\Services\Ai\SoftwareCompanyStewardship\StewardshipStringListNormalizer;
use Tests\TestCase;

final class StewardshipStringListNormalizerTest extends TestCase
{
    public function test_deduplicates_strings_while_preserving_first_seen_order(): void
    {
        $this->assertSame(
            ['AP-731', 'AP-735'],
            StewardshipStringListNormalizer::uniqueStrings(['AP-731', 'AP-735', 'AP-731']),
        );
    }

    public function test_deduplicates_merged_string_lists(): void
    {
        $this->assertSame(
            ['repo_ready', 'budget_ready', 'risk_ready'],
            StewardshipStringListNormalizer::uniqueMergedStrings(
                ['repo_ready', 'budget_ready'],
                ['repo_ready', 'risk_ready'],
            ),
        );
    }

    public function test_keeps_non_empty_strings_without_coercing_values(): void
    {
        $this->assertSame(
            ['0', 'ready'],
            StewardshipStringListNormalizer::uniqueNonEmptyStrings(['0', '', 0, 'ready', 'ready']),
        );
    }

    public function test_preserves_legacy_truthy_stringification_semantics(): void
    {
        $this->assertSame(
            ['owner'],
            StewardshipStringListNormalizer::uniqueTruthyStringifiedValues(['0', 0, '', false, 'owner', 'owner']),
        );
    }

    public function test_trims_and_deduplicates_strings(): void
    {
        $this->assertSame(
            ['owner', '0'],
            StewardshipStringListNormalizer::trimmedUniqueStrings([' owner ', '', 'owner', '  ', '0', 0]),
        );
    }

    public function test_trims_strings_without_deduping(): void
    {
        $this->assertSame(
            ['owner', 'owner', '0'],
            StewardshipStringListNormalizer::trimmedStrings([' owner ', '', 'owner', '  ', '0', 0]),
        );
    }

    public function test_maps_non_empty_strings(): void
    {
        $this->assertSame(
            ['php artisan test', 'php artisan test'],
            StewardshipStringListNormalizer::mappedNonEmptyStrings(
                [['command_display' => 'php artisan test'], ['command_display' => ''], ['command_display' => 'php artisan test']],
                static fn (mixed $row): string => is_array($row) ? (string) ($row['command_display'] ?? '') : '',
            ),
        );
    }

    public function test_deduplicates_mapped_truthy_strings(): void
    {
        $normalized = StewardshipStringListNormalizer::uniqueMappedTruthyStringValues(
            ['Atlas Dev', 'Atlas Dev', '0', 'Forge!'],
            static fn (mixed $value): string => preg_replace('/[^a-z0-9_]+/', '_', strtolower(trim((string) $value))) ?: '',
        );

        $this->assertSame(['atlas_dev', 'forge_'], $normalized);
    }
}
