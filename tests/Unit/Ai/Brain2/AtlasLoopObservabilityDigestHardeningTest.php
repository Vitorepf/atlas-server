<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasLoopObservabilityDigestHardeningTest extends TestCase
{
    /**
     * Verify the source wraps Carbon::parse in try/catch.
     */
    public function test_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/AtlasLoopObservabilityDigest.php');

        $this->assertStringContainsString('Carbon::parse', $source);
        $this->assertStringContainsString('try {', $source, 'Carbon::parse must be wrapped in try/catch');
        $this->assertStringContainsString('Throwable', $source, 'must catch Throwable for parse failures');
    }

    /**
     * Demonstrate the bug: Carbon::parse on invalid string throws.
     */
    public function test_invalid_parse_throws_without_guard(): void
    {
        $this->expectException(\Exception::class);
        \Carbon\Carbon::parse('not-a-date');
    }

    /**
     * Verify the parse is no longer in the same expression as the if-guard.
     * The old pattern was: && Carbon::parse(...) in the if condition.
     */
    public function test_parse_not_in_if_condition(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/AtlasLoopObservabilityDigest.php');

        // The old pattern had Carbon::parse in the same if() as claim_owner check.
        // After the fix, Carbon::parse is inside a try block, not in the if condition.
        $this->assertStringNotContainsString('lease_expires_at !== null && Carbon::parse', $source, 'parse must not be in the if condition');
    }
}
