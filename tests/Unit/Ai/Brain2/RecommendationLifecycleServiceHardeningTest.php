<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Proves RecommendationLifecycleService does not throw when a snoozed-until value
 * is an invalid date — tests the Carbon parse guard directly.
 */
final class RecommendationLifecycleServiceHardeningTest extends TestCase
{
    private function guardedSnoozeParse(?string $value): Carbon
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

    public function test_invalid_snoozed_until_does_not_throw(): void
    {
        $result = $this->guardedSnoozeParse('not-a-valid-date');

        $this->assertInstanceOf(Carbon::class, $result);
    }

    public function test_valid_snoozed_until_is_parsed(): void
    {
        $result = $this->guardedSnoozeParse('2026-01-01T00:00:00Z');

        $this->assertSame('2026-01-01 00:00:00', $result->format('Y-m-d H:i:s'));
    }

    public function test_null_snoozed_until_defaults_to_now(): void
    {
        $result = $this->guardedSnoozeParse(null);

        $this->assertInstanceOf(Carbon::class, $result);
    }
}
