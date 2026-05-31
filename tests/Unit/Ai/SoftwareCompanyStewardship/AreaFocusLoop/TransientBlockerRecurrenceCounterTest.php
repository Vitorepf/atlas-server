<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\TransientBlockerRecurrenceCounter;
use PHPUnit\Framework\TestCase;

final class TransientBlockerRecurrenceCounterTest extends TestCase
{
    private const TRANSIENT_BLOCKER = 'owner_runtime_provider_timeout';

    private TransientBlockerRecurrenceCounter $counter;

    protected function setUp(): void
    {
        $this->counter = new TransientBlockerRecurrenceCounter();
    }

    public function testCountsEightTrailingMatchingRecords(): void
    {
        $history = [];
        for ($i = 0; $i < 8; $i++) {
            $history[] = [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ];
        }

        $this->assertSame(
            8,
            $this->counter->consecutiveTransientRecurrences('F1', self::TRANSIENT_BLOCKER, $history),
        );
    }

    public function testStopsAtFirstMatchingRecordLackingBlocker(): void
    {
        $history = [];
        for ($i = 0; $i < 5; $i++) {
            $history[] = [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ];
        }
        $history[] = [
            'finding_key' => 'F1',
            'blockers' => ['owner_runtime_rate_limited'],
        ];
        $history[] = [
            'finding_key' => 'F1',
            'blockers' => [self::TRANSIENT_BLOCKER],
        ];
        $history[] = [
            'finding_key' => 'F1',
            'blockers' => [self::TRANSIENT_BLOCKER],
        ];

        $this->assertSame(
            2,
            $this->counter->consecutiveTransientRecurrences('F1', self::TRANSIENT_BLOCKER, $history),
        );
    }

    public function testSkipsOtherFindingKeysWithoutBreakingStreak(): void
    {
        $history = [
            [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
            [
                'finding_key' => 'F2',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
            [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
            [
                'finding_key' => 'F2',
                'blockers' => ['other_blocker'],
            ],
            [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
        ];

        $this->assertSame(
            3,
            $this->counter->consecutiveTransientRecurrences('F1', self::TRANSIENT_BLOCKER, $history),
        );
    }

    public function testEmptyFindingKeyReturnsZero(): void
    {
        $history = [
            [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
        ];

        $this->assertSame(
            0,
            $this->counter->consecutiveTransientRecurrences('', self::TRANSIENT_BLOCKER, $history),
        );
    }

    public function testBlockerNeverPresentForRequestedFindingKeyReturnsZero(): void
    {
        $history = [
            [
                'finding_key' => 'F2',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
            [
                'finding_key' => 'F2',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
        ];

        $this->assertSame(
            0,
            $this->counter->consecutiveTransientRecurrences('F1', self::TRANSIENT_BLOCKER, $history),
        );
    }

    public function testEmptyBlockerOrHistoryReturnsZero(): void
    {
        $history = [
            [
                'finding_key' => 'F1',
                'blockers' => [self::TRANSIENT_BLOCKER],
            ],
        ];

        $this->assertSame(0, $this->counter->consecutiveTransientRecurrences('F1', '', $history));
        $this->assertSame(0, $this->counter->consecutiveTransientRecurrences('F1', self::TRANSIENT_BLOCKER, []));
    }
}
