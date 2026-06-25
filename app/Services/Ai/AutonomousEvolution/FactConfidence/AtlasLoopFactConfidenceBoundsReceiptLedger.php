<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\FactConfidence;

/**
 * Append-only JSONL audit trail for every FACT seen by the confidence-bounds validator.
 * Records (ts, fact_key, value, sample_size, source_count, caller_path, enforce_flag, outcome)
 * so the operator can distinguish single-sample claims from real-backed FACTs.
 * Anti-Goodhart: never produces a scalar score; only enumerable FACT receipts.
 */
final class AtlasLoopFactConfidenceBoundsReceiptLedger
{
    public const OUTCOME_ACCEPTED = 'accepted';

    public const OUTCOME_REJECTED = 'rejected';

    public const OUTCOME_LEGACY = 'legacy';

    public function __construct(
        private readonly string $ledgerPath,
        private readonly bool $enabled = false,
    ) {}

    /**
     * @param  array<string,mixed>  $receipt  fully-formed payload (caller assembles fields)
     */
    public function append(array $receipt): bool
    {
        if (! $this->enabled) {
            return false;
        }
        $dir = \dirname($this->ledgerPath);
        if (! is_dir($dir) && ! @mkdir($dir, 0o755, true) && ! is_dir($dir)) {
            return false;
        }
        $fh = @fopen($this->ledgerPath, 'cb+');
        if (! is_resource($fh)) {
            return false;
        }
        try {
            if (! @flock($fh, LOCK_EX)) {
                return false;
            }
            ksort($receipt, SORT_STRING);
            $line = (string) json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            fseek($fh, 0, SEEK_END);
            fwrite($fh, $line."\n");
            @fflush($fh);

            return true;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    public function ledgerPath(): string
    {
        return $this->ledgerPath;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
