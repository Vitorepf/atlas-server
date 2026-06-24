<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\UnifiedReceipts;

/**
 * The manifest header line every export starts with — the offline auditor reads this first and verifies the
 * exported window against the chain head before accepting any payload nodes downstream.
 *
 * Co-located with the exporter so a single file owns the export contract. Pure FACT shape; no scores, no
 * derived counts beyond {@see $totalNodes} which mirrors the export's payload-line count.
 */
final class AtlasLoopUnifiedReceiptExportManifest
{
    public const MAGIC = 'atlas_unified_receipts_export';

    public const VERSION = 1;

    /**
     * @param  array{mode:string, from:?int, to:?int, since_hash:?string}  $range  the requested export window
     */
    public function __construct(
        public readonly string $headHash,
        public readonly int $totalNodes,
        public readonly int $exportedAtUs,
        public readonly array $range,
    ) {
    }

    /**
     * @return array<string,mixed>  the canonical first-line shape: magic key, version, head_hash, total_nodes,
     *                              exported_at_us, range
     */
    public function toArray(): array
    {
        return [
            self::MAGIC => self::VERSION,
            'exported_at_us' => $this->exportedAtUs,
            'head_hash' => $this->headHash,
            'range' => $this->range,
            'total_nodes' => $this->totalNodes,
        ];
    }
}
