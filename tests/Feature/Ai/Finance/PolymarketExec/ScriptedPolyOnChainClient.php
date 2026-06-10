<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Finance\PolymarketExec;

use App\Services\Ai\Finance\PolymarketExec\OnChain\PolyOnChainClient;
use App\Services\Ai\Finance\PolymarketExec\OnChain\TxResult;

/**
 * Deterministic, programmable {@see PolyOnChainClient} for short state-machine
 * tests. By default a split/merge succeeds (no real tx, credits the paired exec
 * client so the legs become sellable / the held set burns). A scripted failure
 * lets tests exercise the "mint failed → no position" path.
 */
final class ScriptedPolyOnChainClient implements PolyOnChainClient
{
    private ?TxResult $splitResult = null;

    private ?TxResult $mergeResult = null;

    /** @var list<array{op: string, sets: float}> */
    public array $calls = [];

    /**
     * @param  null|callable(list<string>, float): void  $onMint
     * @param  null|callable(list<string>, float): void  $onMerge
     */
    public function __construct(
        private readonly string $mode = 'sim',
        private readonly float $mintGasUsd = 0.05,
        private readonly float $mergeGasUsd = 0.05,
        private readonly mixed $onMint = null,
        private readonly mixed $onMerge = null,
    ) {}

    public function failSplitWith(string $error): self
    {
        $this->splitResult = TxResult::nothing($error);

        return $this;
    }

    public function failMergeWith(string $error): self
    {
        $this->mergeResult = TxResult::nothing($error);

        return $this;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function splitFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        $this->calls[] = ['op' => 'split', 'sets' => $sets];
        if ($this->splitResult !== null) {
            return $this->splitResult;
        }
        if (is_callable($this->onMint)) {
            ($this->onMint)($tokenIds, $sets);
        }

        return new TxResult(true, false, $sets, round($sets, 6), $this->mintGasUsd, 'scripted-split');
    }

    public function mergeFullSet(string $conditionId, array $tokenIds, float $sets, bool $negRisk): TxResult
    {
        $this->calls[] = ['op' => 'merge', 'sets' => $sets];
        if ($this->mergeResult !== null) {
            return $this->mergeResult;
        }
        if (is_callable($this->onMerge)) {
            ($this->onMerge)($tokenIds, $sets);
        }

        return new TxResult(true, false, $sets, round($sets, 6), $this->mergeGasUsd, 'scripted-merge');
    }
}
