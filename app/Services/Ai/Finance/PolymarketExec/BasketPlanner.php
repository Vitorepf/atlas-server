<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketShadow\PolymarketPinnedHttp;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed;
use Illuminate\Support\Carbon;

/**
 * Turns a verified long-side opportunity into a fully-priced {@see BasketPlan}.
 *
 * v1 handles ONLY long_sum_under / simple_buy_all_legs (buy every YES leg by
 * limit so the per-set ask sum is < $1; exactly one leg pays $1 at resolution).
 * Short/mint/merge are explicitly out of scope.
 *
 * Sizing: the stake is the smaller of the per-basket cap and the remaining
 * daily budget, divided by the per-set cost — then clamped to the executable
 * depth at our slippage-bounded limit prices. Legs are ordered ascending by
 * that depth so the thinnest (most failure-prone) leg is bought first.
 */
final class BasketPlanner
{
    private const GAMMA_BASE = 'https://gamma-api.polymarket.com';

    /** @var callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>} */
    private $bookSource;

    /** @var callable(string): ?array<string, mixed> */
    private $eventMetaSource;

    /**
     * @param  null|callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>}  $bookSource
     * @param  null|callable(string): ?array<string, mixed>  $eventMetaSource
     */
    public function __construct(
        private readonly PolyExecConfig $cfg,
        ?callable $bookSource = null,
        ?callable $eventMetaSource = null,
    ) {
        $feed = new PolymarketShadowFeed;
        $http = new PolymarketPinnedHttp;
        $this->bookSource = $bookSource ?? fn (string $token): ?array => $feed->bookLevels($token);
        $this->eventMetaSource = $eventMetaSource ?? function (string $slug) use ($http): ?array {
            $events = $http->getJson(self::GAMMA_BASE.'/events?slug='.urlencode($slug), 15);

            return is_array($events) ? ($events[0] ?? null) : null;
        };
    }

    /**
     * @param  list<array{token: string, question?: string}>  $legs
     */
    public function plan(
        string $eventSlug,
        string $kind,
        array $legs,
        int $persistenceSeconds,
        ?float $dailyRemainingUsd = null,
    ): ?BasketPlan {
        // v1 is long-side only — refuse anything else loudly rather than mis-handle.
        if ($kind !== 'long_sum_under' || count($legs) < 2) {
            return null;
        }

        $priced = [];
        $sumBestAsks = 0.0;
        foreach ($legs as $leg) {
            $token = (string) ($leg['token'] ?? '');
            if ($token === '') {
                return null;
            }
            $book = ($this->bookSource)($token);
            $asks = is_array($book) ? ($book['asks'] ?? []) : null;
            if (! is_array($asks) || $asks === []) {
                return null; // an unpriceable leg means the long basket cannot be completed
            }

            $bestAsk = (float) ($asks[0]['price'] ?? 0);
            if ($bestAsk <= 0.0 || $bestAsk >= 1.0) {
                return null;
            }
            $limit = $this->cfg->limitPriceFor($bestAsk);

            $depthAtLimit = 0.0;
            foreach ($asks as $level) {
                $price = (float) ($level['price'] ?? 0);
                if ($price > 0.0 && $price <= $limit) {
                    $depthAtLimit += (float) ($level['size'] ?? 0);
                }
            }
            if ($depthAtLimit <= 0.0) {
                return null;
            }

            $sumBestAsks += $bestAsk;
            $priced[] = [
                'token' => $token,
                'question' => (string) ($leg['question'] ?? ''),
                'target_price' => round($bestAsk, 6),
                'limit_price' => $limit,
                'depth_at_limit' => round($depthAtLimit, 4),
            ];
        }

        $sumBestAsks = round($sumBestAsks, 6);
        // No edge to capture if the legs already sum to >= $1.
        if ($sumBestAsks <= 0.0 || $sumBestAsks >= 1.0) {
            return null;
        }

        // Binding executable depth = the thinnest leg's depth at limit (one share
        // per leg per set, so the smallest leg caps how many full sets we can buy).
        $executableDepth = min(array_column($priced, 'depth_at_limit'));
        $costPerSet = $sumBestAsks;

        $capUsd = $dailyRemainingUsd === null
            ? $this->cfg->maxBasketUsd
            : min($this->cfg->maxBasketUsd, max(0.0, $dailyRemainingUsd));

        $maxSetsByCap = $costPerSet > 0.0 ? $capUsd / $costPerSet : 0.0;
        // Floor to 4dp so we never round UP past the cap or available depth.
        $targetSets = floor(min($maxSetsByCap, $executableDepth) * 10_000) / 10_000;
        if ($targetSets <= 0.0) {
            return null;
        }

        $feePerSet = $this->cfg->takerFeeRate * $sumBestAsks;
        $gasPerSet = $targetSets > 0.0 ? $this->cfg->estGasUsdPerBasket / $targetSets : INF;
        $grossEdgePerSet = 1.0 - $sumBestAsks;
        $netEdgePerSet = round($grossEdgePerSet - $feePerSet - $gasPerSet, 6);

        $targetCost = round($targetSets * $costPerSet, 4);
        $estFee = round($feePerSet * $targetSets, 4);
        $estGas = round($this->cfg->estGasUsdPerBasket, 4);
        $estProfit = round($grossEdgePerSet * $targetSets - $estFee - $estGas, 4);

        [$resolutionAt, $resolutionHours] = $this->resolution($eventSlug);

        // Thinnest leg first: ascending by executable depth at limit.
        usort($priced, fn (array $a, array $b) => $a['depth_at_limit'] <=> $b['depth_at_limit']);

        $planLegs = [];
        foreach ($priced as $i => $p) {
            $planLegs[] = [
                'token' => $p['token'],
                'question' => $p['question'],
                'position' => $i,
                'plan_depth' => $p['depth_at_limit'],
                'target_price' => $p['target_price'],
                'limit_price' => $p['limit_price'],
                'target_size' => $targetSets,
            ];
        }

        return new BasketPlan(
            eventSlug: $eventSlug,
            kind: 'long_sum_under',
            executionClass: 'simple_buy_all_legs',
            legs: $planLegs,
            targetSets: $targetSets,
            targetSum: $sumBestAsks,
            targetCostUsd: $targetCost,
            estProfitUsd: $estProfit,
            estEdgePerSet: $netEdgePerSet,
            estFeeUsd: $estFee,
            estGasUsd: $estGas,
            capUsd: round($capUsd, 4),
            slippageBps: $this->cfg->slippageBps,
            executableDepthShares: round($executableDepth, 4),
            persistenceSeconds: max(0, $persistenceSeconds),
            resolutionAt: $resolutionAt,
            resolutionHours: $resolutionHours,
            minLegPrice: round(min(array_column($priced, 'target_price')), 6),
            maxLegPrice: round(max(array_column($priced, 'target_price')), 6),
        );
    }

    /**
     * @return array{0: string|null, 1: float|null}
     */
    private function resolution(string $eventSlug): array
    {
        $event = ($this->eventMetaSource)($eventSlug);
        $end = is_array($event) ? ($event['endDate'] ?? $event['end_date'] ?? null) : null;
        if (! is_string($end) || $end === '') {
            return [null, null]; // unknown => resolution_horizon gate fails closed
        }

        try {
            $endAt = Carbon::parse($end);
        } catch (\Throwable) {
            return [null, null];
        }

        $hours = round((Carbon::now()->getTimestamp() - $endAt->getTimestamp()) / -3600, 2);

        return [$endAt->toIso8601String(), $hours];
    }
}
