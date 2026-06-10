<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketShadow\PolymarketPinnedHttp;
use App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed;
use Illuminate\Support\Carbon;

/**
 * Turns a verified short-side opportunity into a fully-priced {@see ShortBasketPlan}.
 *
 * short_sum_over / requires_minting_full_set: the sellable legs' best bids sum to
 * more than $1 per set, so minting a full set ($1) and selling those legs banks
 * the difference. We size the mint by the smaller of the per-basket cap and the
 * remaining daily budget (each set costs exactly $1 to mint), clamped to the
 * binding sellable bid depth at our protective sell floor. Sell legs are ordered
 * thinnest-bid-first; legs with no usable bid become a freeroll (held, not sold).
 *
 * conditionId / negRisk are best-effort enrichment for the live on-chain mint;
 * the sim path does not need them (it models the mint economically).
 */
final class ShortBasketPlanner
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
     * @param  list<array{token: string, question?: string}>  $legs  ALL outcome legs of the event
     */
    public function plan(
        string $eventSlug,
        array $legs,
        int $persistenceSeconds,
        ?float $dailyRemainingUsd = null,
    ): ?ShortBasketPlan {
        if (! $this->cfg->shortEnabled || count($legs) < 3) {
            return null; // a tradable negRisk set is >= 3 outcomes
        }

        $allTokens = [];
        $sellable = [];   // priced, will-sell
        $freeroll = [];   // held tokens
        $sumBids = 0.0;
        foreach ($legs as $leg) {
            $token = (string) ($leg['token'] ?? '');
            if ($token === '') {
                return null; // a missing token means we cannot mint the full set safely
            }
            $allTokens[] = $token;

            $book = ($this->bookSource)($token);
            $bids = is_array($book) ? ($book['bids'] ?? []) : null;
            $bestBid = (is_array($bids) && $bids !== []) ? (float) ($bids[0]['price'] ?? 0) : 0.0;

            if (! is_finite($bestBid) || $bestBid <= 0.0 || $bestBid >= 1.0) {
                $freeroll[] = $token; // no usable bid -> hold as freeroll
                continue;
            }

            $floor = $this->cfg->sellFloorFor($bestBid);
            $depthAtFloor = 0.0;
            foreach ($bids as $level) {
                $price = (float) ($level['price'] ?? 0);
                if ($price >= $floor && $price < 1.0) {
                    $depthAtFloor += (float) ($level['size'] ?? 0);
                }
            }
            if ($depthAtFloor <= 0.0) {
                $freeroll[] = $token;
                continue;
            }

            $sumBids += $bestBid;
            $sellable[] = [
                'token' => $token,
                'question' => (string) ($leg['question'] ?? ''),
                'target_price' => round($bestBid, 6),
                'limit_price' => $floor,
                'depth_at_floor' => round($depthAtFloor, 4),
            ];
        }

        // Need at least 2 sellable legs whose bids sum to > $1 for any short edge.
        $sumBids = round($sumBids, 6);
        if (count($sellable) < 2 || $sumBids <= 1.0) {
            return null;
        }

        // Binding sellable depth: the thinnest sellable leg caps how many full sets
        // we can sell (one share per leg per set).
        $executableDepth = min(array_column($sellable, 'depth_at_floor'));

        // Each set costs exactly $1 to mint, so the budget directly bounds sets.
        $capUsd = $dailyRemainingUsd === null
            ? $this->cfg->maxBasketUsd
            : min($this->cfg->maxBasketUsd, max(0.0, $dailyRemainingUsd));
        $maxSetsByCap = $capUsd; // $1 per set
        $targetSets = floor(min($maxSetsByCap, $executableDepth) * 10_000) / 10_000;
        if ($targetSets <= 0.0) {
            return null;
        }

        $mintCost = round($targetSets * 1.0, 4);
        $feePerSet = $this->cfg->takerFeeRate * $sumBids;        // taker fee on the sells
        $gasPerSet = $targetSets > 0.0 ? $this->cfg->estMintGasUsd / $targetSets : INF;
        $grossEdgePerSet = $sumBids - 1.0;
        $netEdgePerSet = round($grossEdgePerSet - $feePerSet - $gasPerSet, 6);

        $estFee = round($feePerSet * $targetSets, 4);
        $estGas = round($this->cfg->estMintGasUsd, 4);
        $estProfit = round($grossEdgePerSet * $targetSets - $estFee - $estGas, 4);

        [$resolutionAt, $resolutionHours, $conditionId, $negRisk] = $this->meta($eventSlug);

        // Thinnest bid first: ascending by sellable depth at floor.
        usort($sellable, fn (array $a, array $b) => $a['depth_at_floor'] <=> $b['depth_at_floor']);

        $planLegs = [];
        foreach ($sellable as $i => $s) {
            $planLegs[] = [
                'token' => $s['token'],
                'question' => $s['question'],
                'position' => $i,
                'plan_depth' => $s['depth_at_floor'],
                'target_price' => $s['target_price'],
                'limit_price' => $s['limit_price'],
                'target_size' => $targetSets,
            ];
        }

        return new ShortBasketPlan(
            eventSlug: $eventSlug,
            legs: $planLegs,
            allTokenIds: $allTokens,
            freerollTokens: $freeroll,
            conditionId: $conditionId,
            negRisk: $negRisk,
            targetSets: $targetSets,
            targetSumBids: $sumBids,
            mintCostUsd: $mintCost,
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
            minLegPrice: round(min(array_column($sellable, 'target_price')), 6),
            maxLegPrice: round(max(array_column($sellable, 'target_price')), 6),
        );
    }

    /**
     * @return array{0: string|null, 1: float|null, 2: string|null, 3: bool}
     */
    private function meta(string $eventSlug): array
    {
        $event = ($this->eventMetaSource)($eventSlug);
        if (! is_array($event)) {
            return [null, null, null, false];
        }

        $end = $event['endDate'] ?? $event['end_date'] ?? null;
        $resolutionAt = null;
        $resolutionHours = null;
        if (is_string($end) && $end !== '') {
            try {
                $endAt = Carbon::parse($end);
                $resolutionAt = $endAt->toIso8601String();
                $resolutionHours = round((Carbon::now()->getTimestamp() - $endAt->getTimestamp()) / -3600, 2);
            } catch (\Throwable) {
                // leave null => resolution gate fails closed
            }
        }

        // negRisk full-set identifier (best effort; live path validates, sim ignores).
        $negRisk = (bool) ($event['negRisk'] ?? $event['neg_risk'] ?? false);
        $conditionId = $event['negRiskMarketID'] ?? $event['neg_risk_market_id'] ?? null;
        if (! is_string($conditionId) || $conditionId === '') {
            $markets = is_array($event['markets'] ?? null) ? $event['markets'] : [];
            foreach ($markets as $m) {
                if (is_array($m) && ($m['negRisk'] ?? false)) {
                    $negRisk = true;
                }
            }
            $conditionId = null;
        }

        return [$resolutionAt, $resolutionHours, is_string($conditionId) ? $conditionId : null, $negRisk];
    }
}
