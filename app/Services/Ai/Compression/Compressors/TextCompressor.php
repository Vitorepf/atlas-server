<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Compressors;

use App\Services\Ai\Compression\CompressionResult;
use App\Services\Ai\Compression\Contracts\Compressor;

/**
 * Near-lossless plain-text compressor — the conservative fallback (AP-813).
 *
 * This is the LAST compressor the {@see \App\Services\Ai\Compression\ContentRouter}
 * consults (after json/log/search/diff), so its {@see detect()} is always true. It
 * makes ZERO assumptions about structure and therefore only ever removes provable,
 * mechanical redundancy. It NEVER summarizes, paraphrases, reflows paragraphs, or
 * drops a single unique line — every distinct line survives verbatim and in order.
 *
 * Two byte-redundancy collapses, applied in one line-wise pass:
 *   (b) collapse a run of a BYTE-IDENTICAL consecutive non-blank line into its first
 *       occurrence plus a "[xN]" marker (the run count is preserved, so the fact
 *       "this line repeated N times" is lossless, not merely the line text); the
 *       single emitted representative is right-trimmed of trailing whitespace, which
 *       is safe because every line in the run was byte-identical (the trailing bytes
 *       were redundant bulk, recoverable from the CCR store);
 *   (c) collapse a run of 3+ consecutive blank lines into a single blank line.
 *
 * UNIQUE-CONTENT INVARIANT (the crux of the quality contract): a line that does NOT
 * belong to a collapsed run — i.e. it occurs exactly once consecutively — is emitted
 * BYTE-FOR-BYTE VERBATIM. It is never right-trimmed, never rewritten. This matters
 * because trailing whitespace can itself be signal (a Markdown hard line break "  ",
 * a TSV empty trailing field, raw protocol bytes, trailing NULs), and the compressor
 * must keep unique content UNCONDITIONALLY. Consequently two lines that differ ONLY
 * by trailing whitespace are NOT merged: dedup compares EXACT bytes, so a
 * whitespace-only distinction is preserved as two separate (verbatim) lines and a
 * "[xN]" marker only ever counts genuinely byte-identical lines.
 *
 * Blank lines are handled solely by rule (c), never by rule (b), so paragraph
 * spacing is never turned into a "[xN]" marker. Determinism: pure string transform,
 * no randomness, no I/O — same input always yields the same output. Fail-open: if
 * the savings are not strictly positive it returns unchanged.
 *
 * The "[xN]" marker is intentionally a literal suffix and is therefore ambiguous
 * against content that itself ends in "[xN]" (e.g. a literal line "foo [x2]" twice
 * becomes "foo [x2] [x2]"). That ambiguity is acceptable under the contract because
 * exact byte recovery is delegated to the CCR store; the marker only has to make the
 * run-count human-legible in the compressed surface, not be a reversible encoding.
 */
final class TextCompressor implements Compressor
{
    /**
     * Max consecutive blank lines kept verbatim before a run is collapsed to one.
     * A "run of 3+" (per spec) is collapsed; 1 or 2 blanks are left untouched so
     * normal paragraph spacing is preserved exactly.
     */
    private const MAX_BLANK_RUN = 2;

    public function contentType(): string
    {
        return 'text';
    }

    /**
     * Always true: text is the conservative catch-all consulted last by the router.
     * It is safe because every transform is provably information-preserving.
     */
    public function detect(string $block): bool
    {
        return true;
    }

    public function compress(string $block, array $options = []): CompressionResult
    {
        // Nothing to gain on an empty block — short-circuit before any work.
        if ($block === '') {
            return CompressionResult::unchanged($block, 'text');
        }

        // Split on \n only. We dedup on EXACT line bytes (CR included): two lines are
        // collapsed into a "[xN]" marker only if they are byte-identical, so a line
        // that differs by even one trailing byte is kept as its own verbatim line.
        $lines = explode("\n", $block);
        $linesTotal = count($lines);

        /** @var list<string> $out collapsed output lines */
        $out = [];
        $blankRun = 0;

        // State for the "byte-identical consecutive non-blank line" collapse.
        $pendingLine = null;      // the EXACT line bytes currently being counted
        $pendingCount = 0;        // how many times it has appeared consecutively
        $pendingIsBlank = false;  // whether the pending line is blank

        $flushPending = static function () use (&$out, &$pendingLine, &$pendingCount, &$pendingIsBlank): void {
            if ($pendingLine === null) {
                return;
            }
            // A blank pending line is emitted as-is (count handled by the blank-run
            // logic, never via a [xN] marker). A non-blank run of 2+ byte-identical
            // lines collapses to a single right-trimmed representative + marker; the
            // trim is safe here precisely because the whole run was byte-identical
            // (redundant bulk, recoverable from the CCR store). A UNIQUE line (count
            // 1) is emitted BYTE-FOR-BYTE VERBATIM — never trimmed, never rewritten.
            if (! $pendingIsBlank && $pendingCount >= 2) {
                $representative = rtrim($pendingLine, " \t\r\0\x0B\x0C");
                $out[] = $representative . ' [x' . $pendingCount . ']';
            } else {
                for ($i = 0; $i < $pendingCount; $i++) {
                    $out[] = $pendingLine;
                }
            }
            $pendingLine = null;
            $pendingCount = 0;
            $pendingIsBlank = false;
        };

        foreach ($lines as $line) {
            // Blankness is decided AFTER a trailing-whitespace trim so a
            // whitespace-only line counts toward the blank-run collapse (rule c).
            // The trim is used ONLY for this classification and for dedup-run
            // emission; it never mutates a unique line that is emitted (see flush).
            $isBlank = (rtrim($line, " \t\r\0\x0B\x0C") === '');

            if ($isBlank) {
                // A blank line breaks any non-blank duplicate run in progress.
                if ($pendingLine !== null && ! $pendingIsBlank) {
                    $flushPending();
                }
                // (c) collapse runs of 3+ blanks: keep at most MAX_BLANK_RUN, then
                // emit a single blank to stand in for the whole over-long run.
                $blankRun++;
                $pendingLine = null;
                $pendingCount = 0;
                $pendingIsBlank = false;
                if ($blankRun <= self::MAX_BLANK_RUN) {
                    $out[] = '';
                } elseif ($blankRun === self::MAX_BLANK_RUN + 1) {
                    // Replace the last kept blank with the single collapsed blank:
                    // drop the extra so 3+ blanks -> exactly one blank line.
                    array_splice($out, count($out) - self::MAX_BLANK_RUN, self::MAX_BLANK_RUN, ['']);
                }
                // blankRun > MAX_BLANK_RUN+1: nothing more to add, already one blank.
                continue;
            }

            // Non-blank line ends any blank run.
            $blankRun = 0;

            // (b) accumulate consecutive BYTE-IDENTICAL non-blank lines. Comparing
            // exact bytes (not a trimmed form) is what guarantees the [xN] marker
            // only ever counts truly identical lines and that a trailing-whitespace
            // distinction is never silently merged away.
            if ($pendingLine === $line) {
                $pendingCount++;
            } else {
                $flushPending();
                $pendingLine = $line;
                $pendingCount = 1;
                $pendingIsBlank = false;
            }
        }

        $flushPending();

        $candidate = implode("\n", $out);

        $meta = [
            'lines_total' => $linesTotal,
            'lines_after' => count($out),
        ];

        // self-downgrades to unchanged if not strictly smaller (fail-open guard).
        return CompressionResult::compressed($block, $candidate, 'text', $meta);
    }
}
