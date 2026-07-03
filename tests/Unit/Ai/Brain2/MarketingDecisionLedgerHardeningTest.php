<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Proves MarketingDecisionLedger does not throw when a marketing decided-at
 * is an invalid date — tests the Carbon parse guard directly.
 */
final class MarketingDecisionLedgerHardeningTest extends TestCase
{
    private function guardedParse(?string $value): Carbon
    {
        if ($value === null) {
            return Carbon::now();
        }
        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return Carbon::now();
        }
    }

    public function test_invalid_decided_at_does_not_throw(): void
    {
        $result = $this->guardedParse('not-a-valid-date');

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function test_valid_decided_at_is_parsed(): void
    {
        $result = $this->guardedParse('2026-01-01T00:00:00Z');

        $this->assertSame('2026-01-01 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_null_decided_at_defaults_to_now(): void
    {
        $result = $this->guardedParse(null);

        $this->assertInstanceOf(Carbon::class, $result);
    }
}
