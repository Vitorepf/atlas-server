<?php

declare(strict_types=1);

namespace App\Observers;

use App\Models\AtlasMemoryEntry;
use App\Services\Ai\Memory\AtlasMemoryRecallCache;

/**
 * MAXB-09 — bumps the recall-cache corpus version on any write to
 * `atlas_memory_entries`. Corpus is small; global invalidation is cheap.
 */
final class AtlasMemoryRecallCacheObserver
{
    public function __construct(private readonly AtlasMemoryRecallCache $cache) {}

    public function saved(AtlasMemoryEntry $entry): void
    {
        $this->bump();
    }

    public function deleted(AtlasMemoryEntry $entry): void
    {
        $this->bump();
    }

    private function bump(): void
    {
        try {
            $this->cache->bumpCorpus();
        } catch (\Throwable) {
            // fail-open: bump is optimization, never a correctness gate.
        }
    }
}
