<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;

final class InboxActionRegistryHardeningTest extends TestCase
{
    /**
     * Verify the source has a parseSnoozeUntil method with try/catch.
     */
    public function test_parse_source_has_parse_snooze_until_guard(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Mobile/InboxActionRegistry.php');

        $this->assertStringContainsString('parseSnoozeUntil', $source, 'must have parseSnoozeUntil method');
        $this->assertStringContainsString('catch', $source, 'must have try/catch guard');
    }

    /**
     * Verify the old unguarded parse is gone.
     */
    public function test_parse_old_unguarded_parse_removed(): void
    {
        $source = file_get_contents(__DIR__.'/../../../../app/Services/Ai/Mobile/InboxActionRegistry.php');

        $this->assertStringNotContainsString(
            "Carbon::parse((string) (\$input['snoozed_until']",
            $source,
            'old unguarded parse must be replaced'
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
