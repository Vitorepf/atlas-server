<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex;

use JsonSerializable;

/**
 * The result of a cortex intent staleness scan: which stored intent facts have drifted from the current tree
 * (and why), how many are still fresh, and the threshold the scan used. A pure read artifact — producing it
 * never mutates the snapshot.
 */
final class StalenessReport implements JsonSerializable
{
    /**
     * @param  list<array{path:string, fqcn:string, current_commit_count:int, last_extract_commit_count:int, reasons:list<string>}>  $staleItems
     */
    public function __construct(
        public readonly array $staleItems,
        public readonly int $freshItemsTotal,
        public readonly int $thresholdUsed,
    ) {
    }

    /**
     * @return array{stale_items:list<array<string,mixed>>, fresh_items_total:int, threshold_used:int}
     */
    public function toArray(): array
    {
        return [
            'stale_items' => $this->staleItems,
            'fresh_items_total' => $this->freshItemsTotal,
            'threshold_used' => $this->thresholdUsed,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function jsonSerialize(): array
    {
        return $this->toArray();
    }
}
