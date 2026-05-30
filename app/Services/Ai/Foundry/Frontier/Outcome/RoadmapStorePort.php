<?php

declare(strict_types=1);

namespace App\Services\Ai\Foundry\Frontier\Outcome;

/**
 * Foundry AP-E · roadmap.v1 living/versioned/append-only store port.
 *
 * latest() returns the newest roadmap line for an area (highest version) or
 * null when none exists. The materializer reads it, transitions a capability
 * implemented->proven (consolidate) or implemented->reverted (revert), bumps
 * version+1, and appends a NEW full line via the JSONL seam. The roadmap is
 * NEVER mutated in place and NEVER prose.
 */
interface RoadmapStorePort
{
    /**
     * @return array<string,mixed>|null roadmap.v1 line, or null when absent
     */
    public function latest(string $areaId): ?array;
}
