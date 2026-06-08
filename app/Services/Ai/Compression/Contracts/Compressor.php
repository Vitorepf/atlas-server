<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Contracts;

use App\Services\Ai\Compression\CompressionResult;

/**
 * A single-content-type compressor (AP-813).
 *
 * Quality contract (the crux — "qualidade ao extremo"): every implementation MUST
 * be information-preserving BY CONSTRUCTION. It keeps all errors, anomalies,
 * outliers and otherwise-unique content UNCONDITIONALLY, and only ever samples /
 * collapses provably-redundant, homogeneous bulk. It NEVER summarizes, rewrites or
 * paraphrases content (that would be lossy in an unbounded way). The dropped bulk
 * is always recoverable because the pipeline stores the original in the CCR store
 * (Evidence Ledger backed) before the compressor's output replaces it.
 *
 * Compressors are PURE: block string in, {@see CompressionResult} out. They do NOT
 * touch the CCR store, the ledger, config, or any I/O — the
 * {@see \App\Services\Ai\Compression\CompressionPipeline} owns all of that. This
 * keeps each compressor trivially unit-testable and side-effect free.
 */
interface Compressor
{
    /**
     * Stable content-type id this compressor handles.
     * One of: 'json' | 'log' | 'search' | 'diff' | 'text'.
     */
    public function contentType(): string;

    /**
     * Cheap, conservative detection: does this block look like THIS content type?
     * Must be fast (no heavy parsing) and must err on the side of FALSE — a missed
     * block is just left uncompressed; a wrong match could mangle unrelated text.
     */
    public function detect(string $block): bool;

    /**
     * Compress the block. Information-preserving by construction. If it cannot help,
     * or the result is not strictly smaller, it MUST return
     * {@see CompressionResult::unchanged()} so the pipeline passes the original
     * through (fail-open).
     *
     * @param  array<string,mixed>  $options  e.g. keep_head, keep_tail, max_keep, min_block_chars
     */
    public function compress(string $block, array $options = []): CompressionResult;
}
