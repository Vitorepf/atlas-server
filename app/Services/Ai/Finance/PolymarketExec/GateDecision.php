<?php

declare(strict_types=1);

namespace App\Services\Ai\Finance\PolymarketExec;

/**
 * Result of running the structural gates. `allowed` is true only when EVERY
 * check passed; `checks` is the full audited list (each {name, ok, reason}) so
 * a block is always explainable in the receipt, never a silent no-op.
 */
final class GateDecision
{
    /**
     * @param  list<array{name: string, ok: bool, reason: string}>  $checks
     */
    public function __construct(
        public readonly bool $allowed,
        public readonly array $checks,
    ) {}

    public function blockingReasons(): string
    {
        $blocked = array_filter($this->checks, fn (array $c) => ! $c['ok']);

        return implode('; ', array_map(fn (array $c) => $c['name'].': '.$c['reason'], $blocked));
    }

    /** @return list<string> */
    public function failedNames(): array
    {
        return array_values(array_map(
            fn (array $c) => $c['name'],
            array_filter($this->checks, fn (array $c) => ! $c['ok']),
        ));
    }
}
