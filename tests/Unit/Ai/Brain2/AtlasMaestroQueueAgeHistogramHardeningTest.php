<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Brain2;

use PHPUnit\Framework\TestCase;
use App\Services\Ai\SelfConstruction\Maestro\Health\AtlasMaestroQueueAgeHistogram;

final class AtlasMaestroQueueAgeHistogramHardeningTest extends TestCase
{
    /**
     * A malformed claimable-since timestamp must not throw — the histogram
     * should fall through to the next key or the clock fallback.
     */
    public function test_malformed_claimable_since_does_not_throw(): void
    {
        $histogram = new AtlasMaestroQueueAgeHistogram(
            queue: new class
            {
                public function list(array $filters = []): array
                {
                    return [
                        [
                            'task_packet_id' => 'pkt-1',
                            'claimable_since' => 'not-a-real-date!!!',
                        ],
                    ];
                }
            },
            clock: fn () => new \DateTimeImmutable('2026-07-03T12:00:00+00:00'),
        );

        // Must not throw — the malformed date is skipped and the clock fallback is used.
        $result = $histogram->histogram();

        $this->assertArrayHasKey('total_claimable', $result);
        $this->assertIsInt($result['total_claimable']);
    }

    /**
     * A malformed timestamp falls through to the next valid key.
     */
    public function test_malformed_date_falls_through_to_next_valid_key(): void
    {
        $histogram = new AtlasMaestroQueueAgeHistogram(
            queue: new class
            {
                public function list(array $filters = []): array
                {
                    return [
                        [
                            'task_packet_id' => 'pkt-1',
                            'claimable_since' => 'bad-date',
                            'created_at' => '2026-07-03T10:00:00+00:00',
                        ],
                    ];
                }
            },
            clock: fn () => new \DateTimeImmutable('2026-07-03T12:00:00+00:00'),
        );

        $result = $histogram->histogram();

        // The packet should be aged from created_at (10:00) to now (12:00) = 7200s.
        $this->assertSame(1, $result['total_claimable']);
        $this->assertSame(7200, $result['oldest_seconds']);
    }

    /**
     * When all date keys are malformed, the clock fallback is used.
     */
    public function test_all_malformed_dates_use_clock_fallback(): void
    {
        $histogram = new AtlasMaestroQueueAgeHistogram(
            queue: new class
            {
                public function list(array $filters = []): array
                {
                    return [
                        [
                            'task_packet_id' => 'pkt-1',
                            'claimable_since' => 'bad',
                            'created_at' => 'also-bad',
                            'enqueued_at' => 'still-bad',
                            'updated_at' => 'not-a-date',
                        ],
                    ];
                }
            },
            clock: fn () => new \DateTimeImmutable('2026-07-03T12:00:00+00:00'),
        );

        // Must not throw — falls through to clock fallback, age = 0.
        $result = $histogram->histogram();

        $this->assertSame(1, $result['total_claimable']);
        $this->assertSame(0, $result['oldest_seconds']);
    }
}
