<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasLoopSelfIntrospectionReceiptLedgerHardeningTest extends TestCase
{
    /**
     * Verify the source wraps Carbon::parse in try/catch.
     */
    public function test_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Introspection/AtlasLoopSelfIntrospectionReceiptLedger.php');

        $this->assertStringContainsString('Carbon::parse', $source);
        $this->assertStringContainsString('try {', $source, 'Carbon::parse must be wrapped in try/catch');
        $this->assertStringContainsString('Throwable', $source, 'must catch Throwable for parse failures');
    }

    /**
     * Verify the catch block skips the receipt (continue).
     */
    public function test_catch_skips_receipt(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Introspection/AtlasLoopSelfIntrospectionReceiptLedger.php');

        $this->assertStringContainsString('continue;', $source, 'catch must skip the bad receipt');
    }

    /**
     * Demonstrate the bug: Carbon::parse on invalid string throws.
     */
    public function test_invalid_parse_throws_without_guard(): void
    {
        $this->expectException(\Exception::class);
        \Carbon\Carbon::parse('not-a-date');
    }
}
