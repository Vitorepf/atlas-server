<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

use RuntimeException;

/**
 * Small append-only JSONL owner for fact-confidence validation outcomes.
 * Disabled mode is intentionally a true no-op: no directory creation, file
 * probe or serialization happens when the feature flag is off.
 */
final class AtlasLoopFactConfidenceBoundsReceiptLedger
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_LEGACY = 'legacy';

    public function __construct(
        private readonly string $path,
        private readonly bool $enabled,
    ) {}

    /** @param array<string,mixed> $receipt */
    public function append(array $receipt): bool
    {
        if (! $this->enabled) {
            return true;
        }

        $directory = dirname($this->path);
        if (! is_dir($directory) && ! mkdir($directory, 0775, true) && ! is_dir($directory)) {
            throw new RuntimeException('fact_confidence_receipt_directory_unavailable');
        }

        $handle = fopen($this->path, 'ab');
        if ($handle === false) {
            throw new RuntimeException('fact_confidence_receipt_open_failed');
        }

        try {
            if (! flock($handle, LOCK_EX)) {
                throw new RuntimeException('fact_confidence_receipt_lock_failed');
            }
            $encoded = json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR).PHP_EOL;
            if (fwrite($handle, $encoded) !== strlen($encoded)) {
                throw new RuntimeException('fact_confidence_receipt_write_failed');
            }
            fflush($handle);
            flock($handle, LOCK_UN);
        } finally {
            fclose($handle);
        }

        return true;
    }
}
