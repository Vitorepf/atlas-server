<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\TaskServing;

/**
 * REFACTOR DELTA PROVER — the organ that makes "green" insufficient for a
 * refactor/optimize task. A refactor that preserves behavior but improves
 * nothing measurable is a ZERO improvement (the canonical Loop doctrine);
 * before this prover the serving path accepted any green diff as success.
 *
 * Pure: takes before/after file-content maps (the caller resolves them from
 * git HEAD vs worktree, or from the packet's origination baseline), returns
 * metrics, deltas, anti-fake flags and a verdict. NO I/O, NO provider calls —
 * deterministic on content, so the verdict is replayable from the receipt.
 *
 * Metrics are text-level approximations (this is a prover, not a compiler):
 * ponytail: brace-blind function spans + keyword-count complexity; upgrade to
 * AST (nikic/php-parser is already in vendor) if heuristics ever misjudge a
 * real delivery.
 */
final class AtlasRefactorDeltaProver
{
    public const SCHEMA = 'atlas.task_serving.refactor_delta_proof.v1';

    /** Shingle width (in normalized lines) for duplicate-block detection. */
    private const DUP_SHINGLE_LINES = 6;

    /**
     * @param  array<string,string>  $before  path => content (missing path = file did not exist)
     * @param  array<string,string>  $after  path => content (missing path = file was deleted)
     * @return array{
     *   schema:string,
     *   before:array<string,int>, after:array<string,int>, delta:array<string,int>,
     *   flags:list<string>, improved:bool, reasons:list<string>,
     * }
     */
    public function prove(array $before, array $after): array
    {
        $beforeMetrics = $this->scopeMetrics($before);
        $afterMetrics = $this->scopeMetrics($after);

        $delta = [];
        foreach ($beforeMetrics as $key => $value) {
            $delta[$key] = ($afterMetrics[$key] ?? 0) - $value;
        }

        $flags = [];
        $reasons = [];

        if ($this->isPureMove($before, $after)) {
            $flags[] = 'move_only';
            $reasons[] = 'identical_code_lines_rearranged_no_reduction';
        }
        if ($this->isWrapperOnly($before, $after, $delta)) {
            $flags[] = 'wrapper_only';
            $reasons[] = 'added_functions_are_pure_delegations_with_no_size_reduction';
        }
        if ($singleImpl = $this->singleImplAbstractions($before, $after)) {
            $flags[] = 'single_impl_abstraction';
            $reasons[] = 'new_abstraction_with_at_most_one_implementation: '.implode(',', $singleImpl);
        }

        // A refactor improves when SOMETHING the operator cares about strictly
        // shrinks: total code, decision-point complexity, duplication, or the
        // longest function. Growth in all of them = nothing measurable improved.
        $improvementAxes = ['loc', 'decision_points', 'duplicate_blocks', 'max_fn_lines'];
        $shrunk = array_values(array_filter($improvementAxes, fn (string $k): bool => ($delta[$k] ?? 0) < 0));
        if ($shrunk === []) {
            $flags[] = 'no_measurable_improvement';
            $reasons[] = 'no_axis_shrunk: '.implode(',', array_map(
                fn (string $k): string => $k.'='.($delta[$k] ?? 0),
                $improvementAxes,
            ));
        }

        $improved = $shrunk !== []
            && ! in_array('move_only', $flags, true)
            && ! in_array('wrapper_only', $flags, true);
        if ($improved) {
            $reasons[] = 'shrunk_axes: '.implode(',', $shrunk);
        }

        return [
            'schema' => self::SCHEMA,
            'before' => $beforeMetrics,
            'after' => $afterMetrics,
            'delta' => $delta,
            'flags' => $flags,
            'improved' => $improved,
            'reasons' => $reasons,
        ];
    }

    /**
     * CROSS-FILE duplication clusters — the origination-side read of the same
     * shingle metric prove() judges deliveries by: which normalized 6-line
     * blocks appear in MORE THAN ONE file, and which files share them. The
     * scout derives evidence-backed refactor design specs from this.
     *
     * @param  array<string,string>  $files  path => content
     * @return list<array{shingle_hash:string, files:list<string>, occurrences:int, excerpt:string}>
     */
    public function duplicateClusters(array $files): array
    {
        $byShingle = [];
        foreach ($files as $path => $content) {
            $lines = $this->normalizedLines($content);
            for ($i = 0; $i + self::DUP_SHINGLE_LINES <= count($lines); $i++) {
                $block = implode("\n", array_slice($lines, $i, self::DUP_SHINGLE_LINES));
                $key = hash('xxh3', $block);
                $byShingle[$key] ??= ['files' => [], 'occurrences' => 0, 'excerpt' => $block];
                $byShingle[$key]['files'][$path] = true;
                $byShingle[$key]['occurrences']++;
            }
        }

        $clusters = [];
        foreach ($byShingle as $key => $row) {
            if (count($row['files']) < 2) {
                continue;
            }
            $clusters[] = [
                'shingle_hash' => (string) $key,
                'files' => array_keys($row['files']),
                'occurrences' => (int) $row['occurrences'],
                'excerpt' => mb_substr($row['excerpt'], 0, 400),
            ];
        }
        usort($clusters, static fn (array $a, array $b): int => [count($b['files']), $b['occurrences']] <=> [count($a['files']), $a['occurrences']]);

        return $clusters;
    }

    /** @param array<string,string> $files */
    private function scopeMetrics(array $files): array
    {
        $loc = 0;
        $functions = 0;
        $decisionPoints = 0;
        $maxFnLines = 0;
        $shingles = [];

        foreach ($files as $content) {
            $lines = $this->normalizedLines($content);
            $loc += count($lines);
            $decisionPoints += $this->decisionPoints($lines);

            $spans = $this->functionSpans($lines);
            $functions += count($spans);
            foreach ($spans as $span) {
                $maxFnLines = max($maxFnLines, $span);
            }

            for ($i = 0; $i + self::DUP_SHINGLE_LINES <= count($lines); $i++) {
                $key = hash('xxh3', implode("\n", array_slice($lines, $i, self::DUP_SHINGLE_LINES)));
                $shingles[$key] = ($shingles[$key] ?? 0) + 1;
            }
        }

        return [
            'files' => count($files),
            'loc' => $loc,
            'functions' => $functions,
            'decision_points' => $decisionPoints,
            'max_fn_lines' => $maxFnLines,
            'duplicate_blocks' => count(array_filter($shingles, fn (int $n): bool => $n > 1)),
        ];
    }

    /** @return list<string> trimmed, non-empty, non-comment code lines */
    private function normalizedLines(string $content): array
    {
        $out = [];
        foreach (explode("\n", $content) as $line) {
            $line = trim($line);
            if ($line === '' || $line === '{' || $line === '}') {
                continue;
            }
            if (str_starts_with($line, '//') || str_starts_with($line, '#')
                || str_starts_with($line, '*') || str_starts_with($line, '/*')) {
                continue;
            }
            // Per-file boilerplate is not code: moving a function to a new file
            // must not read as +2 lines (a second <?php / declare / namespace).
            if ($line === '<?php' || str_starts_with($line, 'declare(strict_types')
                || str_starts_with($line, 'namespace ') || str_starts_with($line, 'use ')) {
                continue;
            }
            $out[] = $line;
        }

        return $out;
    }

    /** @param list<string> $lines */
    private function decisionPoints(array $lines): int
    {
        $count = 0;
        foreach ($lines as $line) {
            $count += preg_match_all('/\b(if|elseif|for|foreach|while|case|catch|match)\b/', $line);
            $count += preg_match_all('/&&|\|\|/', $line);
        }

        return $count;
    }

    /**
     * Approximate function sizes: lines between successive `function x(` markers.
     *
     * @param  list<string>  $lines
     * @return list<int>
     */
    private function functionSpans(array $lines): array
    {
        $starts = [];
        foreach ($lines as $i => $line) {
            if (preg_match('/\bfunction\s+\w+\s*\(/', $line) === 1) {
                $starts[] = $i;
            }
        }
        $spans = [];
        $total = count($lines);
        foreach ($starts as $n => $start) {
            $end = $starts[$n + 1] ?? $total;
            $spans[] = $end - $start;
        }

        return $spans;
    }

    /**
     * Pure move: the multiset of normalized code lines is unchanged — code was
     * rearranged between/within files with zero reduction. Rearrangement can be
     * a legitimate STEP of a bigger refactor, which is why this is a flag the
     * enforce policy reads, not a hard verdict by itself.
     *
     * @param  array<string,string>  $before
     * @param  array<string,string>  $after
     */
    private function isPureMove(array $before, array $after): bool
    {
        if ($before === [] || $after === []) {
            return false;
        }

        return $this->lineMultisetHash($before) === $this->lineMultisetHash($after)
            && $before !== $after;
    }

    /** @param array<string,string> $files */
    private function lineMultisetHash(array $files): string
    {
        $all = [];
        foreach ($files as $content) {
            foreach ($this->normalizedLines($content) as $line) {
                $all[] = $line;
            }
        }
        sort($all);

        return hash('xxh3', implode("\n", $all));
    }

    /**
     * Wrapper-only: every function ADDED by the change is a pure delegation
     * (single `return <call>;` body) and the scope did not shrink — abstraction
     * theatre that adds a hop without removing anything.
     *
     * @param  array<string,string>  $before
     * @param  array<string,string>  $after
     * @param  array<string,int>  $delta
     */
    private function isWrapperOnly(array $before, array $after, array $delta): bool
    {
        $addedFunctions = array_diff($this->functionNames($after), $this->functionNames($before));
        if ($addedFunctions === [] || ($delta['loc'] ?? 0) < 0) {
            return false;
        }

        foreach ($addedFunctions as $name) {
            if (! $this->functionIsPureDelegation($after, $name)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string,string>  $files
     * @return list<string>
     */
    private function functionNames(array $files): array
    {
        $names = [];
        foreach ($files as $content) {
            if (preg_match_all('/\bfunction\s+(\w+)\s*\(/', $content, $m) > 0) {
                foreach ($m[1] as $name) {
                    $names[] = $name;
                }
            }
        }

        return array_values(array_unique($names));
    }

    /** @param array<string,string> $files */
    private function functionIsPureDelegation(array $files, string $name): bool
    {
        foreach ($files as $content) {
            if (preg_match(
                '/function\s+'.preg_quote($name, '/').'\s*\([^)]*\)[^{]*\{\s*return\s+[^;{}]+;\s*\}/s',
                $content,
            ) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * New interfaces/abstract classes introduced by the change that have at
     * most ONE implementation/extension inside the scope.
     *
     * @param  array<string,string>  $before
     * @param  array<string,string>  $after
     * @return list<string>
     */
    private function singleImplAbstractions(array $before, array $after): array
    {
        $abstractions = static function (array $files): array {
            $found = [];
            foreach ($files as $content) {
                if (preg_match_all('/\b(?:interface|abstract\s+class)\s+(\w+)/', $content, $m) > 0) {
                    foreach ($m[1] as $name) {
                        $found[] = $name;
                    }
                }
            }

            return array_values(array_unique($found));
        };

        $offenders = [];
        foreach (array_diff($abstractions($after), $abstractions($before)) as $name) {
            $impls = 0;
            foreach ($after as $content) {
                $impls += preg_match_all('/\b(?:implements|extends)\s+[\w\\\\,\s]*\b'.preg_quote($name, '/').'\b/', $content);
            }
            if ($impls <= 1) {
                $offenders[] = $name;
            }
        }

        return $offenders;
    }
}
