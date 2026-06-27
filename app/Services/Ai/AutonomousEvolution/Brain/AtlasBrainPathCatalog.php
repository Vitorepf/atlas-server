<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Brain;

/**
 * Centralized read over `config('atlas.brain.paths')` — the 7-path portfolio data. Multiple organs were
 * inlining the same `foreach ($entry['id'] === $pathId)` lookup; this class is the single source. Pure,
 * fail-safe: a missing path or malformed entry returns null rather than throwing.
 *
 * No mutation. No I/O. Pétreo: the réu never edits the registry that maps path-id → executor, else it'd
 * remap a path to whatever executor it wanted (same priorizador trap as the router and the leverage brief).
 */
final class AtlasBrainPathCatalog
{
    /**
     * Every configured path, raw. Filters out non-array entries defensively.
     *
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        $out = [];
        foreach ((array) config('atlas.brain.paths', []) as $entry) {
            if (is_array($entry)) {
                $out[] = $entry;
            }
        }

        return $out;
    }

    /**
     * The full entry for a given path id, or null when not found.
     *
     * @return array<string,mixed>|null
     */
    public function find(string $pathId): ?array
    {
        foreach ($this->all() as $entry) {
            if (($entry['id'] ?? null) === $pathId) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * Executor organ FQCN for the given path id, or null when the path is unknown / empty.
     */
    public function executorOrganFor(string $pathId): ?string
    {
        $entry = $this->find($pathId);
        if ($entry === null) {
            return null;
        }
        $organ = trim((string) ($entry['executor_organ'] ?? ''));

        return $organ === '' ? null : $organ;
    }

    /**
     * Human-readable lens (purpose description) for the path, or null when unknown.
     */
    public function lensFor(string $pathId): ?string
    {
        $entry = $this->find($pathId);
        if ($entry === null) {
            return null;
        }
        $lens = trim((string) ($entry['lens'] ?? ''));

        return $lens === '' ? null : $lens;
    }
}
