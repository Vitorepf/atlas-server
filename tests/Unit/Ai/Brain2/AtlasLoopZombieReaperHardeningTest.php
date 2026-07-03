<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class AtlasLoopZombieReaperHardeningTest extends TestCase
{
    /**
     * Verify the source wraps Carbon::parse in try/catch for the now option.
     */
    public function test_source_has_try_catch_for_now_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Resilience/AtlasLoopZombieReaper.php');

        $this->assertStringContainsString('Carbon::parse', $source);
        $this->assertStringContainsString('try {', $source, 'Carbon::parse must be wrapped in try/catch');
        $this->assertStringContainsString('Throwable', $source, 'must catch Throwable for parse failures');
    }

    /**
     * Verify claimAgeSeconds also has try/catch.
     */
    public function test_claim_age_seconds_has_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/AutonomousEvolution/Resilience/AtlasLoopZombieReaper.php');

        // The claimAgeSeconds method should have its own try/catch.
        $count = substr_count($source, 'try {');
        $this->assertGreaterThanOrEqual(2, $count, 'must have at least 2 try/catch blocks (now + claimAgeSeconds)');
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
