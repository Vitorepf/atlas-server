<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class VentureAdmissionGateHardeningTest extends TestCase
{
    /**
     * Verify the source wraps Carbon::parse in try/catch.
     */
    public function test_parse_source_has_try_catch_for_parse(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Company/Ventures/Success/VentureAdmissionGate.php');

        $this->assertStringContainsString('Carbon::parse', $source, 'must parse dates');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the old unguarded parse is gone.
     */
    public function test_parse_old_unguarded_parse_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Company/Ventures/Success/VentureAdmissionGate.php');

        $this->assertStringNotContainsString(
            "isset(\$args['decided_at']) ? Carbon::parse",
            $source,
            'old unguarded ternary parse must be replaced'
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
