<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class OperatorPatternDetectorHardeningTest extends TestCase
{
    /**
     * Verify the source has a safeParse method with try/catch.
     */
    public function test_parse_source_has_safe_parse_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/OperatorIntelligence/OperatorPatternDetector.php');

        $this->assertStringContainsString('safeParse', $source, 'must have safeParse method');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify all Carbon::parse calls on created_at go through safeParse.
     */
    public function test_parse_no_unguarded_created_at_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/OperatorIntelligence/OperatorPatternDetector.php');

        // No direct Carbon::parse($s->created_at) calls should remain
        $this->assertStringNotContainsString(
            'Carbon::parse($s->created_at)',
            $source,
            'no unguarded Carbon::parse on created_at'
        );
    }

    /**
     * Verify safeParse catches and returns a default.
     */
    public function test_parse_safe_parse_returns_default_on_invalid(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/OperatorIntelligence/OperatorPatternDetector.php');

        $this->assertMatchesRegularExpression(
            '/function\s+safeParse[^{]*\{[^}]*try\s*\{[^}]*Carbon::parse\([^}]*\}[^}]*catch[^}]*return/s',
            $source,
            'safeParse must catch and return a default on invalid date'
        );
    }

    /**
     * Demonstrate the bug: Carbon::parse throws on invalid date.
     */
    public function test_parse_invalid_date_throws_without_guard(): void
    {
        $this->expectException(\Throwable::class);
        \Illuminate\Support\Carbon::parse('not-a-date');
    }
}
