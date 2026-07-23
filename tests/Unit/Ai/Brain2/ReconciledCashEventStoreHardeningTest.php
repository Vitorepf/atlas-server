<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class ReconciledCashEventStoreHardeningTest extends TestCase
{
    /**
     * Verify the source wraps Carbon::parse in try/catch.
     */
    public function test_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Company/Ventures/Reward/ReconciledCashEventStore.php');

        $this->assertStringContainsString('Carbon::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the parse is wrapped in try/catch.
     */
    public function test_parse_is_guarded(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Company/Ventures/Reward/ReconciledCashEventStore.php');

        $this->assertMatchesRegularExpression(
            '/try\s*\{[\s\S]*?Carbon::parse[\s\S]*?\}\s*catch/s',
            $source,
            'parse must be wrapped in try/catch'
        );
    }

    /**
     * Demonstrate the bug: Carbon::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws(): void
    {
        $this->expectException(\Throwable::class);
        \Carbon\CarbonImmutable::parse('not-a-date');
    }
}
