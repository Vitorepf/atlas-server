<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Compressors;

use App\Services\Ai\Compression\CompressionResult;
use App\Services\Ai\Compression\Contracts\Compressor;

/**
 * Unified-diff compressor (AP-813).
 *
 * Information-preserving BY CONSTRUCTION: it NEVER drops a single line of signal.
 * Kept UNCONDITIONALLY are all file headers (`diff --git`, `index`, `+++`, `---`,
 * mode/rename/binary lines, the `\ No newline` marker), every hunk header (`@@ …`),
 * and every CHANGED line (a `+`/`-` line that is not the `+++`/`---` file marker).
 * The ONLY thing it ever touches is runs of UNCHANGED CONTEXT (lines beginning with
 * a single space): it keeps up to `context_keep` (~2) context lines adjacent to each
 * change/header on either side of a run, and collapses the provably-redundant middle
 * into a `[... N unchanged lines ...]` note. The removed context is still recoverable
 * verbatim from the CCR store (the pipeline keeps the original), so this is a pure
 * "drop redundant bulk, keep all signal" transform — no summary, no paraphrase.
 *
 * Deliberate non-cap: the spec permits capping when there are very many files/hunks,
 * but any such cap would drop changed lines, which the hard quality contract forbids.
 * So this compressor NEVER caps files/hunks — context trimming alone is the win, and
 * it is unconditionally lossless on signal.
 *
 * meta: items_total = context lines seen, items_kept = context lines kept verbatim.
 */
final class DiffCompressor implements Compressor
{
    /** Default number of context lines retained on each side of a change. */
    private const DEFAULT_CONTEXT_KEEP = 2;

    public function contentType(): string
    {
        return 'diff';
    }

    /** Line-anchored `diff --git ` header (the git porcelain prefix). */
    private const RE_GIT_HEADER = '/^diff --git /m';

    /**
     * Line-anchored unified hunk RANGE header: `@@ -l[,s] +l[,s] @@`. The numeric
     * ranges are mandatory, which is what distinguishes a real hunk from a prose
     * `@@ heading @@` or a `| --- |` markdown lookalike. Trailing section text after
     * the closing `@@` (e.g. `@@ class Worker`) is allowed by NOT anchoring the end.
     */
    private const RE_HUNK_RANGE = '/^@@ -\d+(?:,\d+)? \+\d+(?:,\d+)? @@/m';

    /** Line-anchored old-file header `--- ` (note the mandatory trailing space). */
    private const RE_MINUS_HEADER = '/^--- /m';

    /** Line-anchored new-file header `+++ ` (note the mandatory trailing space). */
    private const RE_PLUS_HEADER = '/^\+\+\+ /m';

    public function detect(string $block): bool
    {
        if ($block === '') {
            return false;
        }

        // STRONG signal: a git porcelain header is unambiguous diff structure.
        if (preg_match(self::RE_GIT_HEADER, $block) === 1) {
            return true;
        }

        // Otherwise require REAL unified-diff structure, not substrings-anywhere:
        // an anchored numeric hunk RANGE header AND both line-start file headers.
        //
        // Why so strict (the contract demands detect() err toward FALSE — "a wrong
        // match could mangle unrelated text"): the prior version matched any block
        // containing the substrings '@@ ' and ('--- ' OR '+++ ') ANYWHERE. Ordinary
        // markdown/config/prose trips that — a markdown table row `| --- |` carries
        // the '--- ' substring, a YAML/email '---' separator or release-notes line a
        // dash run, and '@@' shows up in prose/emails. Such a false positive is then
        // fed to compress(), which treats every space-indented line as redundant
        // 'context' and silently collapses the middle of a long UNIQUE indented run —
        // dropping unique content, violating the hard "keep unique content
        // UNCONDITIONALLY" contract. Requiring the anchored `@@ -d,d +d,d @@` range
        // header plus both `^--- `/`^+++ ` headers rejects every such lookalike while
        // still accepting every genuine unified diff (with or without `diff --git`,
        // with or without explicit hunk line counts).
        return preg_match(self::RE_HUNK_RANGE, $block) === 1
            && preg_match(self::RE_MINUS_HEADER, $block) === 1
            && preg_match(self::RE_PLUS_HEADER, $block) === 1;
    }

    public function compress(string $block, array $options = []): CompressionResult
    {
        if (! $this->detect($block)) {
            return CompressionResult::unchanged($block, 'diff');
        }

        $contextKeep = $this->contextKeep($options);

        // Preserve the exact newline style so the round-trip stays byte-honest.
        $lines = explode("\n", $block);

        $out = [];
        $contextRun = [];   // buffer of consecutive pure-context lines
        $contextTotal = 0;  // items_total
        $contextKept = 0;   // items_kept

        foreach ($lines as $line) {
            if ($this->isContext($line)) {
                $contextRun[] = $line;
                $contextTotal++;

                continue;
            }

            // Hit an anchor (header / hunk / changed line): flush any buffered run,
            // letting it keep context on BOTH the preceding and following side.
            $contextKept += $this->flushContextRun($contextRun, $out, $contextKeep);
            $contextRun = [];

            $out[] = $line; // anchors are kept unconditionally
        }

        // Trailing context run (no following anchor) — still keep its leading edge.
        $contextKept += $this->flushContextRun($contextRun, $out, $contextKeep);

        $candidate = implode("\n", $out);

        // compressed() self-downgrades to unchanged() if not strictly smaller.
        return CompressionResult::compressed($block, $candidate, 'diff', [
            'items_total' => $contextTotal,
            'items_kept' => $contextKept,
            'context_keep' => $contextKeep,
        ]);
    }

    /**
     * Emit a buffered context run, keeping up to $keep lines on each end and
     * collapsing the redundant middle into a single note. Returns the count of
     * context lines kept verbatim (for meta.items_kept). Pure, deterministic.
     *
     * @param  list<string>  $run
     * @param  list<string>  $out  (mutated)
     */
    private function flushContextRun(array $run, array &$out, int $keep): int
    {
        $len = count($run);
        if ($len === 0) {
            return 0;
        }

        // Short enough that collapsing can't help (or would erase adjacency): keep all.
        if ($len <= 2 * $keep) {
            foreach ($run as $line) {
                $out[] = $line;
            }

            return $len;
        }

        $omitted = $len - 2 * $keep;
        for ($i = 0; $i < $keep; $i++) {
            $out[] = $run[$i];
        }
        $out[] = '[... '.$omitted.' unchanged lines ...]';
        for ($i = $len - $keep; $i < $len; $i++) {
            $out[] = $run[$i];
        }

        return 2 * $keep;
    }

    /**
     * A collapsible UNCHANGED context line: starts with exactly one space. A `+ `/`- `
     * changed line, a `+++`/`---` header, a `@@` hunk, metadata (`diff --git`, `index`,
     * `\ No newline …`) and truly-empty lines are NOT context and are kept verbatim.
     */
    private function isContext(string $line): bool
    {
        return isset($line[0]) && $line[0] === ' ';
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function contextKeep(array $options): int
    {
        $raw = $options['context_keep'] ?? $options['keep_tail'] ?? self::DEFAULT_CONTEXT_KEEP;
        $keep = is_numeric($raw) ? (int) $raw : self::DEFAULT_CONTEXT_KEEP;

        // Bound to a sane, quality-safe window: at least 1 line of context, never huge.
        return max(1, min($keep, 8));
    }
}
