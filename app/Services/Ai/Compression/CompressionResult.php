<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression;

/**
 * Immutable result of compressing ONE content block (AP-813).
 *
 * `changed` is true only when the compressor actually produced a strictly smaller,
 * information-preserving form. On `unchanged()` the original is returned verbatim so
 * the pipeline is byte-identical to no-compression for that block (fail-open).
 */
final class CompressionResult
{
    /**
     * @param  array<string,mixed>  $meta  e.g. items_total, items_kept, anomalies_kept
     */
    public function __construct(
        public readonly string $output,
        public readonly bool $changed,
        public readonly int $originalChars,
        public readonly int $compressedChars,
        public readonly string $contentType,
        public readonly array $meta = [],
    ) {}

    public static function unchanged(string $block, string $contentType = 'text'): self
    {
        $len = strlen($block);

        return new self($block, false, $len, $len, $contentType, []);
    }

    /**
     * Build a CHANGED result but DOWNGRADE to unchanged automatically if the
     * candidate is not strictly smaller than the original — so a compressor can
     * never accidentally make a block bigger (the tokenizer-rejection guard).
     *
     * @param  array<string,mixed>  $meta
     */
    public static function compressed(string $original, string $candidate, string $contentType, array $meta = []): self
    {
        $originalChars = strlen($original);
        $candidateChars = strlen($candidate);

        if ($candidateChars >= $originalChars) {
            return self::unchanged($original, $contentType);
        }

        return new self($candidate, true, $originalChars, $candidateChars, $contentType, $meta);
    }

    /** Compressed/original char ratio (1.0 = no change, 0.2 = 80% smaller). */
    public function ratio(): float
    {
        return $this->originalChars > 0 ? $this->compressedChars / $this->originalChars : 1.0;
    }

    /** Chars saved (>= 0). */
    public function saved(): int
    {
        return max(0, $this->originalChars - $this->compressedChars);
    }
}
