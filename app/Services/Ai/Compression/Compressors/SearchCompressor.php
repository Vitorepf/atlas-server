<?php

declare(strict_types=1);

namespace App\Services\Ai\Compression\Compressors;

use App\Services\Ai\Compression\CompressionResult;
use App\Services\Ai\Compression\Contracts\Compressor;
use App\Services\Ai\Compression\Support\AdaptiveSizer;

/**
 * Compressor for grep / ripgrep style search output (AP-813).
 *
 * Recognises the `path:line:content` shape that `grep -rn` / `rg` emit. Large
 * searches are dominated by the SAME match repeated across many lines of the same
 * file (e.g. one symbol hit on hundreds of nearly-identical call sites). That bulk
 * is provably redundant, but a search result is ALSO where the rare, load-bearing
 * hit hides — so the algorithm is information-preserving by construction:
 *
 *   1. Every distinct FILE stays represented (its first hit is always kept), so the
 *      reader never loses "this symbol also lives in foo.php".
 *   2. Within a file, the first + last match are always kept (the anchors a human
 *      scans for), plus every match that is an anomaly (error/warning/TODO/etc.) and
 *      every match that is STRUCTURALLY UNIQUE (not a near-duplicate of a kept
 *      sibling, by SimHash). Those mandatory keeps are NEVER subject to the cap.
 *   3. Only the remaining, provably-redundant near-duplicate middle is sampled, by
 *      the {@see AdaptiveSizer::knee} on a content-importance curve, bounded by the
 *      per-file and overall budget.
 *   4. The dropped near-duplicates of a file are replaced by a single
 *      `[... N more matches in <file> ...]` note. The full original is always
 *      recoverable from the CCR store the pipeline writes before this output lands.
 *
 * Original file order and within-file order are preserved. Pure, deterministic,
 * stdlib-only — no I/O, no config, no container.
 */
final class SearchCompressor implements Compressor
{
    /** path:line: prefix — the grep/ripgrep contract (path may itself contain colons on Windows, hence the lazy `.+?`). */
    private const MATCH_SHAPE = '/^.+?:\d+:/';

    /** Lines whose CONTENT carries signal that must survive unconditionally. */
    private const ANOMALY_SHAPE = '/\b(error|errors|err|exception|throwable|panic|fatal|fail|failed|failure|warn|warning|critical|severe|deprecated|todo|fixme|hack|xxx|bug|traceback|stacktrace|segfault|undefined|null pointer|nullpointer|timeout|denied|forbidden|unauthorized|leak|overflow|race condition|deadlock)\b/i';

    /** SimHash hamming distance at/under which two match contents are "the same hit" (provably redundant). */
    private const NEAR_DUP_HAMMING = 3;

    public function contentType(): string
    {
        return 'search';
    }

    /**
     * Fast + conservative: at least 3 lines and a strict majority shaped like
     * `path:line:content`. Cheap line scan, no grouping, errs toward false.
     */
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

        $matches = 0;
        foreach ($lines as $line) {
            if ($line !== '' && preg_match(self::MATCH_SHAPE, $line) === 1) {
                $matches++;
            }
        }

        // Strict majority of all lines look like grep hits.
        return $matches * 2 > $count;
    }

    /**
     * @param  array<string,mixed>  $options  keep_head, keep_tail, max_keep, min_block_chars
     */
    public function compress(string $block, array $options = []): CompressionResult
    {
        $minBlockChars = (int) ($options['min_block_chars'] ?? 0);
        if ($block === '' || strlen($block) < $minBlockChars) {
            return CompressionResult::unchanged($block, 'search');
        }

        $overallCap = max(1, (int) ($options['max_keep'] ?? 40));

        // Tokenise into ordered records, preserving every non-match line verbatim
        // and in place (it might be a `--` separator or a banner — pure signal).
        $records = $this->tokenize($block);

        /** @var list<array{path:string,content:string,raw:string,idx:int}> $matches */
        $matches = [];
        foreach ($records as $rec) {
            if ($rec['type'] === 'match') {
                $matches[] = [
                    'path' => $rec['path'],
                    'content' => $rec['content'],
                    'raw' => $rec['raw'],
                    'idx' => $rec['idx'],
                ];
            }
        }

        $itemsTotal = count($matches);
        if ($itemsTotal === 0) {
            return CompressionResult::unchanged($block, 'search');
        }

        // Group matches by file, preserving first-seen file order and within-file order.
        /** @var array<string, list<int>> $byFile  path => list of indices into $matches */
        $byFile = [];
        /** @var list<string> $fileOrder */
        $fileOrder = [];
        foreach ($matches as $i => $m) {
            if (! isset($byFile[$m['path']])) {
                $byFile[$m['path']] = [];
                $fileOrder[] = $m['path'];
            }
            $byFile[$m['path']][] = $i;
        }

        // Decide, per file, which match indices to keep. Mandatory keeps (first,
        // last, anomalies, structurally-unique) are never capped; the redundant
        // middle is sampled by the knee within the per-file + overall budget.
        $perFileCap = $this->perFileCap($overallCap, count($fileOrder));

        /** @var array<int,bool> $keep  matches index => kept */
        $keep = [];
        /** @var array<string,int> $droppedPerFile */
        $droppedPerFile = [];

        foreach ($fileOrder as $path) {
            [$keptIdx, $dropped] = $this->selectForFile($matches, $byFile[$path], $perFileCap);
            foreach ($keptIdx as $i) {
                $keep[$i] = true;
            }
            if ($dropped > 0) {
                $droppedPerFile[$path] = $dropped;
            }
        }

        $itemsKept = count($keep);

        // Nothing to drop → leave the block untouched (fail-open, no marker noise).
        if ($itemsKept >= $itemsTotal) {
            return CompressionResult::unchanged($block, 'search');
        }

        // Rebuild output: walk the original records in order, emit kept matches and
        // every non-match line verbatim, and emit ONE note per file at the position
        // of that file's first dropped match.
        $output = $this->render($records, $matches, $keep, $byFile, $droppedPerFile);

        return CompressionResult::compressed($block, $output, 'search', [
            'items_total' => $itemsTotal,
            'items_kept' => $itemsKept,
            'files' => count($fileOrder),
        ]);
    }

    /**
     * Tokenise the block into ordered records. Match lines carry their parsed path
     * and content; everything else is preserved verbatim as a 'line' record.
     *
     * @return list<array{type:string,raw:string,path?:string,content?:string,idx?:int}>
     */
    private function tokenize(string $block): array
    {
        $lines = $this->splitLines($block);
        $records = [];
        $matchIdx = 0;

        foreach ($lines as $line) {
            $parsed = $this->parseMatch($line);
            if ($parsed === null) {
                $records[] = ['type' => 'line', 'raw' => $line];

                continue;
            }

            $records[] = [
                'type' => 'match',
                'raw' => $line,
                'path' => $parsed['path'],
                'content' => $parsed['content'],
                'idx' => $matchIdx,
            ];
            $matchIdx++;
        }

        return $records;
    }

    /**
     * Parse a `path:line:content` line. Returns null if it is not that shape.
     *
     * @return array{path:string,content:string}|null
     */
    private function parseMatch(string $line): ?array
    {
        // path (lazy, may contain colons) : digits : rest. Anchored, single pass.
        if (preg_match('/^(.+?):(\d+):(.*)$/s', $line, $m) !== 1) {
            return null;
        }

        return ['path' => $m[1], 'content' => $m[3]];
    }

    /**
     * Choose which match indices of one file to keep.
     *
     * @param  list<array{path:string,content:string,raw:string,idx:int}>  $matches
     * @param  list<int>  $group  indices (into $matches) for this file, in order
     * @return array{0: list<int>, 1: int}  [kept indices (sorted), dropped count]
     */
    private function selectForFile(array $matches, array $group, int $perFileCap): array
    {
        $n = count($group);
        if ($n <= 2) {
            // First+last cover everything; nothing is droppable.
            return [$group, 0];
        }

        // Mandatory keeps: first, last, anomalies, and structurally-unique items.
        /** @var array<int,bool> $mandatory  position-in-group => kept */
        $mandatory = [0 => true, $n - 1 => true];

        // Precompute SimHashes once (deterministic).
        $hashes = [];
        foreach ($group as $pos => $i) {
            $hashes[$pos] = AdaptiveSizer::simhash($matches[$i]['content']);
        }

        foreach ($group as $pos => $i) {
            if (isset($mandatory[$pos])) {
                continue;
            }
            $content = $matches[$i]['content'];
            if ($this->isAnomaly($content)) {
                $mandatory[$pos] = true;

                continue;
            }
            // Structurally unique = not a near-duplicate of ANY other match in the
            // file. Only provably-redundant near-duplicate bulk may be dropped.
            if ($this->isStructurallyUnique($pos, $group, $hashes)) {
                $mandatory[$pos] = true;
            }
        }

        // Candidate (droppable) middle = redundant items not already mandatory.
        /** @var list<int> $candidates  positions in group */
        $candidates = [];
        foreach ($group as $pos => $i) {
            if (! isset($mandatory[$pos])) {
                $candidates[] = $pos;
            }
        }

        $mandatoryCount = count($mandatory);

        // Budget left for the redundant middle after honoring mandatory keeps.
        $budget = max(0, $perFileCap - $mandatoryCount);

        // Knee on the descending importance curve of the redundant candidates: keep
        // the highest-signal ones up to the budget, bounded by the knee.
        $extraKeep = 0;
        if ($budget > 0 && $candidates !== []) {
            $scores = [];
            foreach ($candidates as $pos) {
                $scores[$pos] = $this->score($matches[$group[$pos]]['content']);
            }
            // Sort candidate positions by score desc (stable: tie-break by position
            // asc for determinism), then take a knee count, clamped to the budget.
            $ordered = $candidates;
            usort($ordered, function (int $a, int $b) use ($scores): int {
                $cmp = $scores[$b] <=> $scores[$a];

                return $cmp !== 0 ? $cmp : ($a <=> $b);
            });

            $descending = [];
            foreach ($ordered as $pos) {
                $descending[] = $scores[$pos];
            }
            $knee = AdaptiveSizer::knee($descending, 1, $budget);
            $extraKeep = min($knee, $budget, count($ordered));

            for ($k = 0; $k < $extraKeep; $k++) {
                $mandatory[$ordered[$k]] = true;
            }
        }

        // Materialize kept indices in original order.
        $kept = [];
        foreach ($group as $pos => $i) {
            if (isset($mandatory[$pos])) {
                $kept[] = $i;
            }
        }

        $dropped = $n - count($kept);

        return [$kept, $dropped];
    }

    /**
     * A match is structurally unique when no OTHER match in the same file is a
     * near-duplicate of it (SimHash hamming <= threshold). Unique items are signal,
     * never redundant bulk, so they are always kept.
     *
     * @param  list<int>  $group
     * @param  array<int,string>  $hashes  position-in-group => simhash
     */
    private function isStructurallyUnique(int $pos, array $group, array $hashes): bool
    {
        $h = $hashes[$pos];
        foreach ($group as $otherPos => $i) {
            if ($otherPos === $pos) {
                continue;
            }
            if (AdaptiveSizer::hamming($h, $hashes[$otherPos]) <= self::NEAR_DUP_HAMMING) {
                return false; // has a sibling near-duplicate → part of redundant bulk
            }
        }

        return true;
    }

    private function isAnomaly(string $content): bool
    {
        return preg_match(self::ANOMALY_SHAPE, $content) === 1;
    }

    /**
     * Importance score for a redundant-candidate match: longer / more-distinct
     * content carries more information. Cheap and deterministic.
     */
    private function score(string $content): float
    {
        $trimmed = trim($content);
        if ($trimmed === '') {
            return 0.0;
        }
        $len = strlen($trimmed);
        // Distinct-token richness rewards varied content over copy-paste lines.
        $tokens = preg_split('/\s+/', $trimmed) ?: [];
        $distinct = count(array_unique($tokens));

        return (float) $len + (float) $distinct;
    }

    /**
     * Rebuild the compressed string. Kept matches and all non-match lines are
     * emitted verbatim in original order; each file with dropped matches gets ONE
     * note inserted at the position of its first dropped match.
     *
     * @param  list<array{type:string,raw:string,path?:string,content?:string,idx?:int}>  $records
     * @param  list<array{path:string,content:string,raw:string,idx:int}>  $matches
     * @param  array<int,bool>  $keep  matches index => kept
     * @param  array<string, list<int>>  $byFile
     * @param  array<string,int>  $droppedPerFile
     */
    private function render(array $records, array $matches, array $keep, array $byFile, array $droppedPerFile): string
    {
        // First dropped match index per file (so the note lands in original order).
        /** @var array<string,int> $firstDroppedIdx  path => matches index */
        $firstDroppedIdx = [];
        foreach ($byFile as $path => $indices) {
            if (($droppedPerFile[$path] ?? 0) <= 0) {
                continue;
            }
            foreach ($indices as $i) {
                if (! isset($keep[$i])) {
                    $firstDroppedIdx[$path] = $i;
                    break;
                }
            }
        }
        $noteByMatchIdx = array_flip($firstDroppedIdx); // matches index => path

        $out = [];
        foreach ($records as $rec) {
            if ($rec['type'] !== 'match') {
                $out[] = $rec['raw'];

                continue;
            }

            $i = $rec['idx'];

            // Emit the per-file note at the first dropped match position.
            if (isset($noteByMatchIdx[$i])) {
                $path = $noteByMatchIdx[$i];
                $out[] = '[... '.$droppedPerFile[$path].' more matches in '.$path.' ...]';
            }

            if (isset($keep[$i])) {
                $out[] = $matches[$i]['raw'];
            }
            // else: this redundant match is folded into its file's note.
        }

        return implode("\n", $out);
    }

    /**
     * Per-file keep cap derived from the overall budget, spread across files so a
     * search hitting many files keeps a few per file rather than exhausting the
     * whole budget on the first. Always leaves room for the anchors (>= 2).
     */
    private function perFileCap(int $overallCap, int $files): int
    {
        if ($files <= 0) {
            return $overallCap;
        }
        $share = (int) ceil($overallCap / $files);

        return max(2, min($overallCap, $share + 1));
    }

    /**
     * Split on newlines, preserving content. Trailing carriage returns are kept on
     * the line (verbatim) so output is byte-faithful to the input lines.
     *
     * @return list<string>
     */
    private function splitLines(string $block): array
    {
        return explode("\n", $block);
    }
}
