<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery\Cortex\Memory;

/**
 * Canonical immutable EPISODE record for the Cortex memory ledger. ONE record per Cortex cycle. The shape
 * is the FACT contract — any deviation (extra/missing field, non-list inventory, missing sentinel) is
 * rejected at the ledger boundary, not silently coerced.
 */
final class AtlasCortexMemoryEpisodeRecord
{
    /**
     * @param  list<array{item_id:string, kind:string, fingerprint:string}>  $inventoryItems
     * @param  list<array{gap_id:string, kind:string}>                      $blindSpots
     * @param  list<array{intent_id:string, canonical_form:string}>          $intentInterpretations
     */
    public function __construct(
        public readonly string $cycleId,
        public readonly int $capturedAt,
        public readonly string $scopeRoot,
        public readonly string $snapshotDigest,
        public readonly array $inventoryItems,
        public readonly array $blindSpots,
        public readonly array $intentInterpretations,
        public readonly bool $sourceFactsOnly = true,
    ) {
    }

    /**
     * @return array<string,mixed>  canonical, recursively ksort-ed shape — caller is responsible for encoding.
     */
    public function toCanonicalArray(): array
    {
        $arr = [
            'blind_spots' => array_map(self::canonicalize(...), $this->blindSpots),
            'captured_at' => $this->capturedAt,
            'cycle_id' => $this->cycleId,
            'intent_interpretations' => array_map(self::canonicalize(...), $this->intentInterpretations),
            'inventory_items' => array_map(self::canonicalize(...), $this->inventoryItems),
            'scope_root' => $this->scopeRoot,
            'snapshot_digest' => $this->snapshotDigest,
            'source_facts_only' => $this->sourceFactsOnly,
        ];

        return $arr;
    }

    /**
     * @param  array<string,mixed>  $row
     * @return array<string,mixed>
     */
    private static function canonicalize(array $row): array
    {
        ksort($row);

        return $row;
    }
}
