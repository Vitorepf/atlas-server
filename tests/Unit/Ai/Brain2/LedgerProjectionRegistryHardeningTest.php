<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class LedgerProjectionRegistryHardeningTest extends TestCase
{
    /**
     * Verify the source wraps CarbonImmutable::parse in try/catch.
     */
    public function test_parse_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php');

        $this->assertStringContainsString('CarbonImmutable::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify parseTimestamp returns null on invalid date instead of throwing.
     */
    public function test_parse_invalid_date_returns_null(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Kernel/Evidence/LedgerProjectionRegistry.php');

        $this->assertMatchesRegularExpression(
            '/try\s*\{[^}]*CarbonImmutable::parse[^}]*\}[^}]*catch[^}]*return\s+null/s',
            $source,
            'parseTimestamp must catch and return null on invalid date'
        );
    }

    /**
     * Demonstrate the bug: CarbonImmutable::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws_without_guard(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
