<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Support;

use App\Services\Ai\SelfConstruction\Support\UsesUtcClock;
use Tests\TestCase;

/**
 * Locks the canonical UsesUtcClock trait contract.
 */
final class UsesUtcClockTest extends TestCase
{
    public function test_injected_clock_returned_as_string(): void
    {
        $harness = $this->harness(static fn (): string => '2026-06-26T18:51:00+00:00');

        $this->assertSame('2026-06-26T18:51:00+00:00', $harness->callNow());
    }

    public function test_without_clock_returns_valid_iso_8601_utc_timestamp(): void
    {
        $harness = $this->harness(null);

        $value = $harness->callNow();

        // ISO-8601 with timezone offset (e.g. 2026-06-26T18:51:00+00:00).
        $this->assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}[+\-]\d{2}:\d{2}$/',
            $value,
            "UsesUtcClock::now() without clock must return a gmdate(DATE_ATOM) ISO-8601 string"
        );
    }

    /**
     * Build an anonymous-class fixture that declares the `private $clock`
     * property the trait expects and exposes `now()` from the trait.
     */
    private function harness(?callable $clock): object
    {
        return new class($clock)
        {
            use UsesUtcClock;

            /** @var callable|null */
            private $clock;

            public function __construct(?callable $clock)
            {
                $this->clock = $clock;
            }

            // Expose the trait's private now() via a differently-named public
            // method. Reflection is not needed because PHP trait methods can
            // be re-declared in the using class only when visibility differs;
            // here we expose via a distinct name.
            public function callNow(): string
            {
                // Invoke the private trait method through closure binding so
                // the harness acts as a real consumer of the trait.
                return (function (): string {
                    return $this->now();
                })->call($this);
            }
        };
    }
}