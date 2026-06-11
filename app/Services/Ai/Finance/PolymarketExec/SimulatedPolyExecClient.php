<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketShadow\PolymarketShadowFeed;

/**
 * Default executor backend: walks the REAL live CLOB book to simulate fills,
 * without keys, signatures or money. This is what lets us prove the entire
 * state machine — thinnest-leg-first, abort, unwind, idempotency — against
 * reality before a single dollar is signed.
 *
 * The book source is injectable so unit tests can feed synthetic books; the
 * default reads live depth via {@see PolymarketShadowFeed::bookLevels()}.
 *
 * Fill model (a marketable limit buy): consume ask levels in ascending price
 * while price <= limit, up to the requested size. avgPrice is the VWAP of the
 * consumed levels — exactly the math the depth-aware scanner already trusts.
 */
final class SimulatedPolyExecClient implements PolyExecClient
{
    /** @var array<string, float> in-memory simulated holdings (shares) per token */
    private array $position = [];

    /** @var callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>} */
    private $bookSource;

    /**
     * @param  null|callable(string): ?array{asks: list<array{price: float, size: float}>, bids: list<array{price: float, size: float}>}  $bookSource
     */
    public function __construct(
        ?callable $bookSource = null,
        ?PolymarketShadowFeed $feed = null,
        private readonly string $mode = 'sim',
    ) {
        $feed ??= new PolymarketShadowFeed;
        $this->bookSource = $bookSource ?? fn (string $token): ?array => $feed->bookLevels($token);
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function buyLimit(string $token, float $limitPrice, float $size): FillResult
    {
        $book = ($this->bookSource)($token);
        $asks = is_array($book) ? ($book['asks'] ?? []) : null;
        if (! is_array($asks) || $asks === []) {
            return FillResult::nothing('no_asks');
        }

        $remaining = $size;
        $filled = 0.0;
        $cost = 0.0;
        foreach ($asks as $level) {
            $price = (float) ($level['price'] ?? 0);
            $avail = (float) ($level['size'] ?? 0);
            if ($price <= 0.0 || $price > $limitPrice || $avail <= 0.0) {
                continue; // beyond the limit: a real limit order would not fill here
            }
            $take = min($remaining, $avail);
            $filled += $take;
            $cost += $take * $price;
            $remaining -= $take;
            if ($remaining <= 1e-9) {
                break;
            }
        }

        if ($filled <= 0.0) {
            return FillResult::nothing('no_fill_at_limit');
        }

        $this->position[$token] = ($this->position[$token] ?? 0.0) + $filled;

        return new FillResult(
            ok: true,
            filledSize: round($filled, 6),
            avgPrice: round($cost / $filled, 6),
            cashUsd: round($cost, 6),
            orderId: 'sim-buy-'.substr(hash('sha256', $token.$limitPrice.$size.$filled), 0, 16),
        );
    }

    /**
     * Credit minted full-set shares into the simulated position, so a paired
     * {@see \App\Services\Ai\Finance\PolymarketExec\OnChain\SimulatedPolyOnChainClient}
     * mint makes the legs sellable here and reconciliation stays exact.
     *
     * @param  list<string>  $tokens
     */
    public function creditMinted(array $tokens, float $sets): void
    {
        foreach ($tokens as $token) {
            $this->position[$token] = ($this->position[$token] ?? 0.0) + max(0.0, $sets);
        }
    }

    /** Burn held full-set shares back to collateral (a merge), mirroring creditMinted. */
    public function debitMerged(array $tokens, float $sets): void
    {
        foreach ($tokens as $token) {
            $this->position[$token] = max(0.0, ($this->position[$token] ?? 0.0) - max(0.0, $sets));
        }
    }

    public function sellLimit(string $token, float $limitPrice, float $size): FillResult
    {
        $book = ($this->bookSource)($token);
        $bids = is_array($book) ? ($book['bids'] ?? []) : null;
        if (! is_array($bids) || $bids === []) {
            return FillResult::nothing('no_bids');
        }

        // Bound by held shares (minted), if known — never sell more than we hold.
        $remaining = min($size, $this->position[$token] ?? $size);
        $filled = 0.0;
        $proceeds = 0.0;
        foreach ($bids as $level) {
            $price = (float) ($level['price'] ?? 0);
            $avail = (float) ($level['size'] ?? 0);
            if ($price < $limitPrice || $avail <= 0.0) {
                continue; // below the protective floor: a limit sell would not fill here
            }
            $take = min($remaining, $avail);
            $filled += $take;
            $proceeds += $take * $price;
            $remaining -= $take;
            if ($remaining <= 1e-9) {
                break;
            }
        }

        if ($filled <= 0.0) {
            return FillResult::nothing('no_bid_at_limit');
        }

        $this->position[$token] = max(0.0, ($this->position[$token] ?? 0.0) - $filled);

        return new FillResult(
            ok: true,
            filledSize: round($filled, 6),
            avgPrice: round($proceeds / $filled, 6),
            cashUsd: round($proceeds, 6),
            orderId: 'sim-selllimit-'.substr(hash('sha256', $token.$limitPrice.$size.$filled), 0, 16),
        );
    }

    public function sellMarket(string $token, float $size): FillResult
    {
        $book = ($this->bookSource)($token);
        $bids = is_array($book) ? ($book['bids'] ?? []) : null;
        if (! is_array($bids) || $bids === []) {
            return FillResult::nothing('no_bids');
        }

        $remaining = min($size, $this->position[$token] ?? $size);
        $filled = 0.0;
        $proceeds = 0.0;
        foreach ($bids as $level) {
            $price = (float) ($level['price'] ?? 0);
            $avail = (float) ($level['size'] ?? 0);
            if ($price <= 0.0 || $avail <= 0.0) {
                continue;
            }
            $take = min($remaining, $avail);
            $filled += $take;
            $proceeds += $take * $price;
            $remaining -= $take;
            if ($remaining <= 1e-9) {
                break;
            }
        }

        if ($filled <= 0.0) {
            return FillResult::nothing('no_bid_liquidity');
        }

        $this->position[$token] = max(0.0, ($this->position[$token] ?? 0.0) - $filled);

        return new FillResult(
            ok: true,
            filledSize: round($filled, 6),
            avgPrice: round($proceeds / $filled, 6),
            cashUsd: round($proceeds, 6),
            orderId: 'sim-sell-'.substr(hash('sha256', $token.$size.$filled), 0, 16),
        );
    }

    public function positionSize(string $token): ?float
    {
        return $this->position[$token] ?? 0.0;
    }
}
