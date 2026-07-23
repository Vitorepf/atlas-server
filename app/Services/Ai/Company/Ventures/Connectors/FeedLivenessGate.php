<?php

namespace App\Services\Ai\Company\Ventures\Connectors;

use Illuminate\Support\Carbon;

/**
 * K6 (core) — feed-liveness gate.
 *
 * Distinguishes apparent-zero-because-nothing-happened from feed-is-down. When
 * a connector feed is stale or has never reported, consumers (allocator,
 * learning loop) must FREEZE rather than read the silence as a real zero —
 * otherwise dormancy and outage are indistinguishable. Pure / deterministic.
 */
class FeedLivenessGate
{
    public const LIVE = 'live';

    public const STALE = 'stale';

    public const NEVER = 'never';

    public function status(?Carbon $lastEventAt, int $maxStalenessSeconds, ?Carbon $now = null): string
    {
        if ($lastEventAt === null) {
            return self::NEVER; // no event ever — NOT a real zero
        }
        $now ??= Carbon::now();

        return $lastEventAt->diffInSeconds($now) <= $maxStalenessSeconds ? self::LIVE : self::STALE;
    }

    /**
     * Consumers freeze unless the feed is provably live. Fail-closed: never
     * treat stale/never as a confirmed zero.
     */
    public function shouldFreezeConsumers(?Carbon $lastEventAt, int $maxStalenessSeconds, ?Carbon $now = null): bool
    {
        return $this->status($lastEventAt, $maxStalenessSeconds, $now) !== self::LIVE;
    }
}
