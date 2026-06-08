<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Compressors;

use App\Services\Ai\Compression\CompressionResult;
use App\Services\Ai\Compression\Contracts\Compressor;

/**
 * Log / test-runner output compressor (AP-813).
 *
 * Logs are the canonical homogeneous-bulk content type: thousands of near-identical
 * heartbeat / progress / request lines surrounding a handful of lines that actually
 * carry signal (errors, failures, exceptions, warnings). This compressor is
 * information-preserving BY CONSTRUCTION:
 *
 *   - Every line matching {@see self::SIGNAL} (error|fail|exception|traceback|fatal|
 *     panic|warn, case-insensitive) is kept UNCONDITIONALLY and verbatim.
 *   - The first {@code keep_head} and last {@code keep_tail} lines are kept
 *     unconditionally (the boundary context a reader always wants).
 *   - Only RUNS of consecutive NON-signal lines that are PROVABLY redundant of the
 *     run's representative are collapsed — to the FIRST occurrence verbatim plus a
 *     single "[... N similar lines omitted ...]" breadcrumb.
 *
 * The redundancy proof is deliberately EXACT, not a hash. A line is collapsed into
 * the representative only when, after masking nothing but volatile timestamp/clock
 * fields, the two lines are BYTE-IDENTICAL (see {@see self::isRedundantOf()}). This
 * means the ONLY difference permitted between a dropped line and the kept line is the
 * timestamp — pure positional clock noise. Any difference in a value, id, IP,
 * permission word, list order, repetition count, or any other token BLOCKS the
 * collapse and the line is kept verbatim. That makes the "unique content survives"
 * contract hold for ALL content, not just keyword-matched lines.
 *
 * Why not SimHash: a 64-bit bag-of-tokens SimHash is BOTH lossy directions. It
 * saturates (a unique trailing id contributes ~zero net bits when the line is
 * dominated by a repeated token -> hamming 0 -> a unique line silently dropped) and
 * it is order-insensitive (same token multiset in a different order hashes
 * identically -> a genuinely different event treated as a duplicate). Exact masked
 * equality has neither failure mode and is just as cheap, pure and deterministic.
 *
 * It NEVER summarizes, paraphrases or rewrites a line, and it never reorders.
 * Pure / deterministic: string in, {@see CompressionResult} out, no I/O. Dropped
 * bulk is recoverable in full from the CCR store the pipeline writes before this
 * output replaces it.
 */
final class LogCompressor implements Compressor
{
    /** Lines that carry signal — kept unconditionally, never collapsed. */
    private const SIGNAL = '/error|fail(?:ed|ure|s)?|exception|traceback|fatal|panic|warn/i';

    /** A timestamp-shaped prefix: ISO-8601 date, or a bare HH:MM:SS clock. */
    private const TIMESTAMP = '/^\s*(?:\[|\()?\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}|^\s*(?:\[|\()?\d{2}:\d{2}:\d{2}/';

    /**
     * A log-level token in a LEADING (log-prefix) position: start of line, optionally
     * after a "[...]" tag or a "(pid)" bracket. Mid-line level-shaped words (e.g. a
     * `self::ERROR` constant in source code) deliberately do NOT match — detect() must
     * err toward FALSE so the collapse logic never runs on non-log text.
     */
    private const LEVEL_LEAD = '/^\s*(?:\[[^\]]*\]\s*)?(?:\(\d+\)\s*)?(?:ERROR|WARN|WARNING|INFO|DEBUG|TRACE|FATAL)\b/';

    /** Test-runner / status markers, anchored at the start of the line. */
    private const RUNNER_LEAD = '/^\s*(?:PASS(?:ED)?|FAIL(?:ED)?|OK|✓|✗)\b/u';

    /**
     * Volatile fields masked OUT before the exact redundancy comparison. ONLY
     * timestamp/clock fields are masked — never counters, ids or any other token —
     * so the sole difference a collapse can hide is positional clock noise.
     *
     * @var list<string>
     */
    private const VOLATILE = [
        // ISO-8601: 2026-06-08 10:00:05[.123][Z|+00:00] (space or 'T' separator).
        '/\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}:\d{2}(?:[.,]\d+)?(?:Z|[+-]\d{2}:?\d{2})?/',
        // Bare wall clock: 10:00:05[.123].
        '/\b\d{2}:\d{2}:\d{2}(?:[.,]\d+)?\b/',
    ];

    /** Fraction of lines that must look log-shaped for detect() to fire. */
    private const DETECT_FRACTION = 0.4;

    public function contentType(): string
    {
        return 'log';
    }

    public function detect(string $block): bool
    {
        if ($block === '') {
            return false;
        }

        $lines = $this->splitLines($block);
        $count = count($lines);
        if ($count < 3) {
            return false;
        }

        $shaped = 0;
        foreach ($lines as $line) {
            if ($this->looksLikeLogLine($line)) {
                $shaped++;
            }
        }

        return $shaped >= (int) ceil($count * self::DETECT_FRACTION);
    }

    public function compress(string $block, array $options = []): CompressionResult
    {
        $lines = $this->splitLines($block);
        $total = count($lines);

        // Too few lines to safely collapse anything — pass through.
        if ($total < 3) {
            return CompressionResult::unchanged($block, 'log');
        }

        $keepHead = max(0, (int) ($options['keep_head'] ?? 8));
        $keepTail = max(0, (int) ($options['keep_tail'] ?? 4));

        // Which indices are protected boundary lines (head/tail), kept unconditionally.
        $headEnd = min($keepHead, $total);            // indices [0, headEnd)
        $tailStart = max($headEnd, $total - $keepTail); // indices [tailStart, total)

        $out = [];
        $kept = 0;
        $omittedTotal = 0;

        $i = 0;
        while ($i < $total) {
            $line = $lines[$i];
            $protectedBoundary = $i < $headEnd || $i >= $tailStart;

            // Signal and boundary lines are emitted verbatim and break any run.
            if ($protectedBoundary || $this->isSignal($line)) {
                $out[] = $line;
                $kept++;
                $i++;

                continue;
            }

            // Start of a collapsible run: this non-signal, non-boundary line is the
            // representative (always kept verbatim). Absorb following lines that are
            // PROVABLY redundant of it (identical apart from timestamp noise) AND
            // themselves collapsible.
            $anchorMasked = $this->maskVolatile($line);
            $out[] = $line;
            $kept++;
            $i++;

            $omittedInRun = 0;
            while ($i < $total) {
                $next = $lines[$i];
                $nextBoundary = $i < $headEnd || $i >= $tailStart;
                if ($nextBoundary || $this->isSignal($next)) {
                    break; // a protected/signal line must be emitted on its own
                }
                if (! $this->isRedundantOf($anchorMasked, $next)) {
                    break; // not provably redundant — keep it verbatim
                }
                $omittedInRun++;
                $i++;
            }

            if ($omittedInRun > 0) {
                $out[] = '[... '.$omittedInRun.' similar lines omitted ...]';
                $omittedTotal += $omittedInRun;
            }
        }

        // Nothing was redundant -> nothing collapsed -> fail open (byte-identical).
        if ($omittedTotal === 0) {
            return CompressionResult::unchanged($block, 'log');
        }

        $candidate = implode("\n", $out);

        return CompressionResult::compressed($block, $candidate, 'log', [
            'items_total' => $total,
            'items_kept' => $kept,
            'lines_omitted' => $omittedTotal,
        ]);
    }

    /** @return list<string> */
    private function splitLines(string $block): array
    {
        // Normalize CRLF/CR so line detection and rejoin are stable; preserve content.
        $normalized = str_replace(["\r\n", "\r"], "\n", $block);

        return explode("\n", $normalized);
    }

    private function looksLikeLogLine(string $line): bool
    {
        if (trim($line) === '') {
            return false;
        }

        return (bool) preg_match(self::TIMESTAMP, $line)
            || (bool) preg_match(self::LEVEL_LEAD, $line)
            || (bool) preg_match(self::RUNNER_LEAD, $line);
    }

    private function isSignal(string $line): bool
    {
        return (bool) preg_match(self::SIGNAL, $line);
    }

    /**
     * Is {@code $next} a PROVABLE near-duplicate of the representative whose masked
     * form is {@code $anchorMasked}? True only when the two lines are byte-identical
     * after masking volatile timestamp/clock fields — i.e. the ONLY thing that
     * differs is positional clock noise. Order-sensitive, repetition-sensitive, and
     * preserves every value/id/token; this is what makes dropping the line lossless.
     */
    private function isRedundantOf(string $anchorMasked, string $next): bool
    {
        return $anchorMasked === $this->maskVolatile($next);
    }

    /** Replace volatile timestamp/clock fields with stable placeholders; collapse runs of whitespace. */
    private function maskVolatile(string $line): string
    {
        $masked = $line;
        foreach (self::VOLATILE as $i => $pattern) {
            $masked = (string) preg_replace($pattern, '<TS'.$i.'>', $masked);
        }

        // Normalize whitespace so pure spacing/indent differences do not block a
        // collapse (and so they are never the sole "difference" a drop hides).
        return trim((string) preg_replace('/\s+/', ' ', $masked));
    }
}
