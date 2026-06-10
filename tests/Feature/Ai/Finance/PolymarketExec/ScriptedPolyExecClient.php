<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\FillResult;
use App\Services\Ai\Finance\PolymarketExec\PolyExecClient;

/**
 * Deterministic, programmable {@see PolyExecClient} for state-machine tests.
 *
 * Buys/sells are scripted per token (FIFO). Without a script a buy fills fully
 * at the limit and a sell liquidates fully at a default price, so the common
 * happy path stays terse. Every call is recorded so tests can assert ORDER
 * (thinnest-first) and idempotency (no double buys).
 */
final class ScriptedPolyExecClient implements PolyExecClient
{
    /** @var array<string, list<FillResult>> */
    private array $buyScript = [];

    /** @var array<string, list<FillResult>> */
    private array $sellScript = [];

    /** @var array<string, float> */
    private array $position = [];

    /** @var list<array{token: string, price: float, size: float}> */
    public array $buys = [];

    /** @var list<array{token: string, size: float}> */
    public array $sells = [];

    /** @var null|callable(string): void */
    private $afterBuy;

    public function __construct(
        private readonly string $mode = 'sim',
        private readonly float $defaultSellPrice = 0.40,
    ) {}

    public function scriptBuy(string $token, FillResult $result): self
    {
        $this->buyScript[$token][] = $result;

        return $this;
    }

    public function scriptSell(string $token, FillResult $result): self
    {
        $this->sellScript[$token][] = $result;

        return $this;
    }

    /** Run side effects (e.g. touch the kill-switch) right after a buy returns. */
    public function afterEachBuy(callable $fn): self
    {
        $this->afterBuy = $fn;

        return $this;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function buyLimit(string $token, float $limitPrice, float $size): FillResult
    {
        $this->buys[] = ['token' => $token, 'price' => $limitPrice, 'size' => $size];

        $result = ! empty($this->buyScript[$token]) ? array_shift($this->buyScript[$token]) : null;
        $result ??= new FillResult(true, $size, $limitPrice, round($size * $limitPrice, 6), 'fake-buy-'.$token);

        if ($result->filledSize > 0.0) {
            $this->position[$token] = ($this->position[$token] ?? 0.0) + $result->filledSize;
        }
        if ($this->afterBuy !== null) {
            ($this->afterBuy)($token);
        }

        return $result;
    }

    public function sellMarket(string $token, float $size): FillResult
    {
        $this->sells[] = ['token' => $token, 'size' => $size];

        $result = ! empty($this->sellScript[$token]) ? array_shift($this->sellScript[$token]) : null;
        $result ??= new FillResult(true, $size, $this->defaultSellPrice, round($size * $this->defaultSellPrice, 6), 'fake-sell-'.$token);

        if ($result->filledSize > 0.0) {
            $this->position[$token] = max(0.0, ($this->position[$token] ?? 0.0) - $result->filledSize);
        }

        return $result;
    }

    public function positionSize(string $token): ?float
    {
        return $this->position[$token] ?? 0.0;
    }
}
