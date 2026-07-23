<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\Readiness;

use Carbon\CarbonImmutable;

/**
 * Immutable per-request projection context.
 *
 * ARCH BLUEPRINT SelfConstructionReadiness §2.1 (resolves A1-SC-0006/0023/0031):
 * every `Schema::hasTable` probe happens at most once per context (at build time,
 * in ReadinessContextProvider — never here), aggregate query snapshots are taken
 * once, and chain projections are memoized. This class performs NO I/O: asking
 * about a table that was never probed fails closed (false), it never re-probes.
 */
final class ReadinessProjectionContext
{
    /** @var array<string, mixed> */
    private array $chainMemo = [];

    /**
     * @param  array<string, bool>  $tableExists  probed once by ReadinessContextProvider
     * @param  array<string, mixed>  $snapshots  aggregate query snapshots taken at build time
     */
    public function __construct(
        private readonly array $tableExists,
        private readonly CarbonImmutable $now,
        private readonly array $snapshots = [],
    ) {}

    /** Fail-closed: an unprobed table reports absent, it is never re-probed. */
    public function hasTable(string $table): bool
    {
        return $this->tableExists[$table] ?? false;
    }

    public function wasProbed(string $table): bool
    {
        return array_key_exists($table, $this->tableExists);
    }

    /** @return list<string> */
    public function probedTables(): array
    {
        return array_keys($this->tableExists);
    }

    public function now(): CarbonImmutable
    {
        return $this->now;
    }

    public function snapshot(string $key): mixed
    {
        return $this->snapshots[$key] ?? null;
    }

    public function hasSnapshot(string $key): bool
    {
        return array_key_exists($key, $this->snapshots);
    }

    /**
     * Memoize a chain projection for the lifetime of this context
     * (kills the 108/194 predecessor recomputations per call — A1-SC-0014).
     *
     * @param  callable(): mixed  $compute
     */
    public function rememberChain(string $key, callable $compute): mixed
    {
        if (! array_key_exists($key, $this->chainMemo)) {
            $this->chainMemo[$key] = $compute();
        }

        return $this->chainMemo[$key];
    }
}
