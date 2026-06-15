<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Verify;

use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\Parser;
use PhpParser\ParserFactory;

/**
 * Read-only signal analyzer for the non-autofix P2/P3 modes.
 *
 * These findings are intentionally FLAGS, not autonomous edits: duplicate code,
 * complexity, missing characterization tests and doc drift require judgment about
 * the right abstraction or ownership. The loop can rank and surface them; a human
 * or Forge-owned implementation path decides the repair.
 */
final class AtlasLoopSignalAnalyzer
{
    public const SCHEMA = 'atlas.loop.signal_analyzer.v1';

    private readonly Parser $parser;

    private readonly NodeFinder $finder;

    public function __construct()
    {
        $this->parser = (new ParserFactory)->createForHostVersion();
        $this->finder = new NodeFinder;
    }

    /**
     * @param  array{code_roots?:list<string>, docs_roots?:list<string>, max_files?:int, exclude_substrings?:list<string>, complexity_threshold?:int}  $options
     * @return array{schema_version:string,flags:list<array<string,mixed>>,summary:array<string,mixed>}
     */
    public function scan(string $repoRoot, array $options = []): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $codeRoots = $options['code_roots'] ?? ['app'];
        $docsRoots = $options['docs_roots'] ?? ['docs/engineering-knowledge-base'];
        $maxFiles = max(1, (int) ($options['max_files'] ?? 6000));
        $exclude = $options['exclude_substrings'] ?? ['/archive/', '/vendor/', '/node_modules/'];
        $complexityThreshold = max(4, (int) ($options['complexity_threshold'] ?? 12));

        $phpFiles = iterator_to_array($this->filesByExt($repoRoot, $codeRoots, 'php', $maxFiles, $exclude), false);
        $mdFiles = iterator_to_array($this->filesByExt($repoRoot, $docsRoots, 'md', $maxFiles, $exclude), false);
        $testCorpus = $this->testCorpus($repoRoot, $maxFiles);

        $flags = [
            ...$this->cloneFlags($repoRoot, $phpFiles),
            ...$this->complexityFlags($repoRoot, $phpFiles, $complexityThreshold),
            ...$this->coverageGapFlags($repoRoot, $phpFiles, $testCorpus),
            ...$this->docFlags($repoRoot, $mdFiles),
        ];

        $summary = [
            'php_files_scanned' => count($phpFiles),
            'docs_scanned' => count($mdFiles),
            'signal_flags' => count($flags),
            'code_clone_flags' => count(array_filter($flags, static fn (array $f): bool => ($f['mode'] ?? null) === 'code_clone')),
            'complexity_hotspot_flags' => count(array_filter($flags, static fn (array $f): bool => ($f['mode'] ?? null) === 'complexity_hotspot')),
            'coverage_gap_flags' => count(array_filter($flags, static fn (array $f): bool => ($f['mode'] ?? null) === 'coverage_gap')),
            'doc_drift_flags' => count(array_filter($flags, static fn (array $f): bool => ($f['mode'] ?? null) === 'doc_drift')),
            'doc_duplicate_flags' => count(array_filter($flags, static fn (array $f): bool => ($f['mode'] ?? null) === 'doc_duplicate')),
        ];

        return [
            'schema_version' => self::SCHEMA,
            'flags' => $flags,
            'summary' => $summary,
        ];
    }

    /**
     * @param  list<string>  $phpFiles
     * @return list<array<string,mixed>>
     */
    private function cloneFlags(string $repoRoot, array $phpFiles): array
    {
        $groups = [];
        foreach ($phpFiles as $file) {
            $code = (string) @file_get_contents($file);
            $stmts = $this->parse($code);
            if ($stmts === null) {
                continue;
            }
            /** @var list<Node\FunctionLike> $units */
            $units = $this->finder->find($stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_);
            foreach ($units as $unit) {
                $body = $this->nodeSource($code, $unit);
                if (substr_count($body, "\n") < 4 || strlen($body) < 120) {
                    continue;
                }
                $hash = hash('sha256', $this->normalizePhpForClone($body));
                $groups[$hash][] = [
                    'path' => $this->relative($repoRoot, $file),
                    'symbol' => $this->symbolName($unit),
                    'lines' => substr_count($body, "\n") + 1,
                ];
            }
        }

        $flags = [];
        foreach ($groups as $hash => $members) {
            if (count($members) < 2) {
                continue;
            }
            $flags[] = [
                'id' => 'clone:'.substr($hash, 0, 12),
                'mode' => 'code_clone',
                'disposition' => 'flag',
                'path' => (string) $members[0]['path'],
                'count' => count($members),
                'clone_hash' => $hash,
                'members' => $members,
                'route' => 'human_or_forge_refactor',
                'detail' => 'structural clone across '.count($members).' symbols',
            ];
        }

        return $flags;
    }

    /**
     * @param  list<string>  $phpFiles
     * @return list<array<string,mixed>>
     */
    private function complexityFlags(string $repoRoot, array $phpFiles, int $threshold): array
    {
        $flags = [];
        foreach ($phpFiles as $file) {
            $code = (string) @file_get_contents($file);
            $stmts = $this->parse($code);
            if ($stmts === null) {
                continue;
            }
            /** @var list<Node\FunctionLike> $units */
            $units = $this->finder->find($stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_);
            foreach ($units as $unit) {
                $score = $this->cyclomaticScore($unit);
                if ($score < $threshold) {
                    continue;
                }
                $rel = $this->relative($repoRoot, $file);
                $symbol = $this->symbolName($unit);
                $flags[] = [
                    'id' => 'complexity:'.hash('crc32b', $rel.'#'.$symbol),
                    'mode' => 'complexity_hotspot',
                    'disposition' => 'flag',
                    'path' => $rel,
                    'symbol' => $symbol,
                    'score' => $score,
                    'count' => $score,
                    'route' => 'human_or_forge_refactor',
                    'detail' => $symbol.' cyclomatic_score='.$score,
                ];
            }
        }

        return $flags;
    }

    /**
     * @param  list<string>  $phpFiles
     * @return list<array<string,mixed>>
     */
    private function coverageGapFlags(string $repoRoot, array $phpFiles, string $testCorpus): array
    {
        $flags = [];
        foreach ($phpFiles as $file) {
            $code = (string) @file_get_contents($file);
            $stmts = $this->parse($code);
            if ($stmts === null) {
                continue;
            }
            /** @var list<Node\Stmt\ClassLike> $classes */
            $classes = $this->finder->findInstanceOf($stmts, Node\Stmt\ClassLike::class);
            foreach ($classes as $class) {
                if (! property_exists($class, 'name') || $class->name === null) {
                    continue;
                }
                $className = $class->name->toString();
                foreach ($class->getMethods() as $method) {
                    $methodName = $method->name->toString();
                    if (! $method->isPublic() || str_starts_with($methodName, '__')) {
                        continue;
                    }
                    if (str_contains($testCorpus, $className) && str_contains($testCorpus, $methodName)) {
                        continue;
                    }
                    $rel = $this->relative($repoRoot, $file);
                    $flags[] = [
                        'id' => 'coverage:'.hash('crc32b', $rel.'#'.$className.'::'.$methodName),
                        'mode' => 'coverage_gap',
                        'disposition' => 'flag',
                        'path' => $rel,
                        'symbol' => $className.'::'.$methodName,
                        'count' => 1,
                        'route' => 'generate_characterization_test_or_human_review',
                        'detail' => 'public method not mentioned by current test corpus',
                    ];
                }
            }
        }

        return array_slice($flags, 0, 200);
    }

    /**
     * @param  list<string>  $mdFiles
     * @return list<array<string,mixed>>
     */
    private function docFlags(string $repoRoot, array $mdFiles): array
    {
        $flags = [];
        $fingerprints = [];
        foreach ($mdFiles as $file) {
            $content = (string) @file_get_contents($file);
            $rel = $this->relative($repoRoot, $file);
            $fingerprint = $this->docFingerprint($content);
            if ($fingerprint !== '') {
                $fingerprints[$fingerprint][] = $rel;
            }
            foreach ($this->codeRefs($content) as $codeRef) {
                $abs = $repoRoot.'/'.$codeRef;
                if (is_file($abs) && filemtime($abs) !== false && filemtime($file) !== false && filemtime($abs) > filemtime($file) + 86400) {
                    $flags[] = [
                        'id' => 'docdrift:'.hash('crc32b', $rel.'#'.$codeRef),
                        'mode' => 'doc_drift',
                        'disposition' => 'flag',
                        'path' => $rel,
                        'code_ref' => $codeRef,
                        'count' => 1,
                        'route' => 'review_doc_against_code_change',
                        'detail' => 'doc references code changed after the doc',
                    ];
                }
            }
        }
        foreach ($fingerprints as $hash => $paths) {
            if (count($paths) < 2) {
                continue;
            }
            $flags[] = [
                'id' => 'docdup:'.substr($hash, 0, 12),
                'mode' => 'doc_duplicate',
                'disposition' => 'flag',
                'path' => $paths[0],
                'paths' => $paths,
                'count' => count($paths),
                'route' => 'human_doc_authority_consolidation',
                'detail' => 'duplicate or near-template doc body across '.count($paths).' docs',
            ];
        }

        return $flags;
    }

    /**
     * @return list<Node>|null
     */
    private function parse(string $code): ?array
    {
        try {
            return $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }
    }

    private function nodeSource(string $code, Node $node): string
    {
        $start = $node->getStartFilePos();
        $end = $node->getEndFilePos();

        return ($start >= 0 && $end >= $start) ? substr($code, $start, $end - $start + 1) : '';
    }

    private function normalizePhpForClone(string $code): string
    {
        $tokens = token_get_all("<?php\n".$code);
        $out = [];
        foreach ($tokens as $token) {
            if (is_string($token)) {
                $out[] = $token;
                continue;
            }
            [$id, $text] = $token;
            if (in_array($id, [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                continue;
            }
            if (in_array($id, [T_VARIABLE], true)) {
                $out[] = '$v';
                continue;
            }
            if (in_array($id, [T_CONSTANT_ENCAPSED_STRING, T_LNUMBER, T_DNUMBER], true)) {
                $out[] = 'lit';
                continue;
            }
            if ($id === T_STRING) {
                $out[] = 'id';
                continue;
            }
            $out[] = $text;
        }

        return implode('', $out);
    }

    /**
     * Deterministic, AST-based cyclomatic measure for a SINGLE file's source, exposed as
     * the one honest signal that BOTH the discovery ranker and the frozen judge consume
     * (so frozen-green and rank-boost are the same fact, never two divergent numbers).
     *
     * The aggregation is the load-bearing choice (open risk in the plan): the PRIMARY
     * metric is `max_per_method` — the worst single method's cyclomatic — so simplifying
     * or extracting from the worst method registers a real drop even when an
     * extract-method keeps the file's TOTAL flat. The judge additionally requires the
     * file `total` does not increase, so "extract a giant method into two ugly ones" does
     * not game the max while ballooning the file. Fail-open by construction: an
     * unparseable file returns measured=false and zeroed numbers, so a downstream gate
     * reads the documented null/0 default and never crashes.
     *
     * @return array{measured:bool, max_per_method:int, total:int, methods:int, worst_method:?string}
     */
    public function fileComplexity(string $source): array
    {
        $none = ['measured' => false, 'max_per_method' => 0, 'total' => 0, 'methods' => 0, 'worst_method' => null];
        $stmts = $this->parse($source);
        if ($stmts === null) {
            return $none;
        }

        /** @var list<Node\FunctionLike> $units */
        $units = $this->finder->find($stmts, static fn (Node $node): bool => $node instanceof Node\Stmt\ClassMethod || $node instanceof Node\Stmt\Function_);

        $max = 0;
        $total = 0;
        $methods = 0;
        $worstMethod = null;
        foreach ($units as $unit) {
            $score = $this->cyclomaticScore($unit);
            // Track the NAME of the worst method (not just its score) so the refactor objective can
            // tell the provider EXACTLY which method's decision-count to drive down. Provider diffs
            // that refactor broadly but miss the worst method never register an AST max drop and fail
            // certification (observed live: complexity_not_reduced on big multi-scenario diffs).
            if ($score > $max) {
                $max = $score;
                $worstMethod = isset($unit->name) ? (string) $unit->name : null;
            }
            $total += $score;
            $methods++;
        }

        return ['measured' => true, 'max_per_method' => $max, 'total' => $total, 'methods' => $methods, 'worst_method' => $worstMethod];
    }

    /**
     * Sum of `fileComplexity` over a set of absolute file paths (the judge's allowed_globs
     * census). Aggregates per-method max as the MAX across files and total as the SUM, so a
     * multi-file allowed scope still has a single comparable pair. Missing/unreadable files
     * are skipped (fail-open). Returns measured=true iff at least one file parsed.
     *
     * @param  list<string>  $absPaths
     * @return array{measured:bool, max_per_method:int, total:int, files:int, methods:int, per_file_max:array<string,int>}
     */
    public function aggregateComplexity(array $absPaths): array
    {
        $max = 0;
        $total = 0;
        $files = 0;
        $methods = 0;
        $perFileMax = [];
        foreach ($absPaths as $abs) {
            if (! is_file($abs)) {
                continue;
            }
            $source = @file_get_contents($abs);
            if (! is_string($source) || $source === '') {
                continue;
            }
            $one = $this->fileComplexity($source);
            if (! $one['measured']) {
                continue;
            }
            $max = max($max, $one['max_per_method']);
            // PER-FILE worst-method, so a multi-file cluster verdict can be per-file (not collapsed to
            // a single cluster-global max — which falsely rejected a real hub simplification whenever
            // the cluster's global-worst method lived in an UNTOUCHED sibling). Keyed by abs path; the
            // baseline/candidate pair measures the SAME path set, so the keys line up.
            $perFileMax[$abs] = $one['max_per_method'];
            $total += $one['total'];
            // Sum method count across the set so callers can derive DECISION POINTS (total − methods),
            // which is extract-method-neutral: each cyclomatic score is 1+decisions, so extraction adds
            // +1 per new method to `total` — a raw-total gate would falsely reject legitimate extraction
            // (the only way to lower max-per-method). Decisions isolate the real branch count.
            $methods += $one['methods'];
            $files++;
        }

        return ['measured' => $files > 0, 'max_per_method' => $max, 'total' => $total, 'files' => $files, 'methods' => $methods, 'per_file_max' => $perFileMax];
    }

    /**
     * The single source of the "did it genuinely get simpler" verdict, shared by the certifier and the
     * frozen judge so the two cannot drift. PER-FILE semantics (perFileGate, default ON):
     *   - NO changed file's worst method got worse (anti-laundering: blocks pushing complexity into a sibling),
     *   - AT LEAST ONE changed file's worst method dropped (a real simplification happened somewhere),
     *   - AND the decisions/total aggregate did not rise (the existing non-increasing lock).
     * For a single file this is byte-identical to `candidate.max < baseline.max` (per-file max == global
     * max). perFileGate OFF (or no per-file data) restores the legacy cluster-global-max rule.
     *
     * @param  array<string,mixed>  $baseline
     * @param  array<string,mixed>  $candidate
     */
    public static function complexityReduced(array $baseline, array $candidate, bool $decisionsGate, bool $perFileGate): bool
    {
        $candidateAgg = $decisionsGate ? ((int) $candidate['total'] - (int) ($candidate['methods'] ?? 0)) : (int) $candidate['total'];
        $baselineAgg = $decisionsGate ? ((int) $baseline['total'] - (int) ($baseline['methods'] ?? 0)) : (int) $baseline['total'];
        $aggOk = $candidateAgg <= $baselineAgg;

        $baseFiles = is_array($baseline['per_file_max'] ?? null) ? $baseline['per_file_max'] : [];
        $candFiles = is_array($candidate['per_file_max'] ?? null) ? $candidate['per_file_max'] : [];
        if (! $perFileGate || $baseFiles === [] || $candFiles === []) {
            // Legacy / no per-file data: the cluster-global-max rule (still safe, just coarser).
            return (int) $candidate['max_per_method'] < (int) $baseline['max_per_method'] && $aggOk;
        }

        $newFileFailClosed = (bool) config('atlas.loop.complexity_new_file_fail_closed', true);
        $noFileRegressed = true;
        $atLeastOneDropped = false;
        foreach ($candFiles as $path => $candMax) {
            if (! array_key_exists($path, $baseFiles)) {
                // A net-new candidate file has NO baseline worst-method to compare against, so its
                // complexity contribution is UNPROVABLE. The old `?? $candMax` treated it as no-change,
                // which let a god method relocated INTACT into a new file certify on a cosmetic drop
                // elsewhere (zero net simplification — reproduced). Fail closed: a refactor that
                // introduces a new file must prove its win another way (a future structural lane with a
                // per-method-identity census), never via the unknown-baseline default. Every EXISTING
                // path (single-file allowed_files=[target]; multi-file-with-siblings) leaves the baseline
                // census complete, so candFiles ⊆ baseFiles and this branch never fires for them.
                if ($newFileFailClosed) {
                    return false;
                }
                $baseMax = (int) $candMax; // legacy treat-as-no-change only when the lock is disabled
            } else {
                $baseMax = (int) $baseFiles[$path];
            }
            if ((int) $candMax > $baseMax) {
                $noFileRegressed = false;
            }
            if ((int) $candMax < $baseMax) {
                $atLeastOneDropped = true;
            }
        }

        return $noFileRegressed && $atLeastOneDropped && $aggOk;
    }

    private function cyclomaticScore(Node\FunctionLike $unit): int
    {
        $score = 1;
        $this->finder->find($unit->getStmts() ?? [], static function (Node $node) use (&$score): bool {
            if ($node instanceof Node\Stmt\If_
                || $node instanceof Node\Stmt\ElseIf_
                || $node instanceof Node\Stmt\For_
                || $node instanceof Node\Stmt\Foreach_
                || $node instanceof Node\Stmt\While_
                || $node instanceof Node\Stmt\Do_
                || $node instanceof Node\Stmt\Case_
                || $node instanceof Node\Stmt\Catch_
                || $node instanceof Node\Expr\Ternary
                || $node instanceof Node\Expr\BinaryOp\BooleanAnd
                || $node instanceof Node\Expr\BinaryOp\BooleanOr
            ) {
                $score++;
            }

            return false;
        });

        return $score;
    }

    private function symbolName(Node\FunctionLike $unit): string
    {
        if ($unit instanceof Node\Stmt\ClassMethod || $unit instanceof Node\Stmt\Function_) {
            return $unit->name->toString();
        }

        return 'anonymous';
    }

    private function testCorpus(string $repoRoot, int $maxFiles): string
    {
        $chunks = [];
        foreach ($this->filesByExt($repoRoot, ['tests'], 'php', $maxFiles, ['/vendor/', '/node_modules/']) as $file) {
            $chunks[] = (string) @file_get_contents($file);
        }

        return implode("\n", $chunks);
    }

    private function docFingerprint(string $content): string
    {
        $content = preg_replace('/^---\R.*?\R---\R/s', '', $content) ?? $content;
        $content = preg_replace('/```.*?```/s', '', $content) ?? $content;
        $content = strtolower($content);
        $content = preg_replace('/[^a-z0-9]+/', ' ', $content) ?? $content;
        $words = array_values(array_filter(explode(' ', trim($content))));
        if (count($words) < 40) {
            return '';
        }

        return hash('sha256', implode(' ', array_slice($words, 0, 120)));
    }

    /**
     * @return list<string>
     */
    private function codeRefs(string $content): array
    {
        preg_match_all('/`((?:app|routes|config|database|tests)\/[^`]+?\.(?:php|md))`/', $content, $matches);

        return array_values(array_unique(array_map('strval', $matches[1])));
    }

    /**
     * @param  list<string>  $roots
     * @param  list<string>  $exclude
     * @return iterable<string>
     */
    private function filesByExt(string $repoRoot, array $roots, string $ext, int $maxFiles, array $exclude): iterable
    {
        $count = 0;
        foreach ($roots as $root) {
            $base = $repoRoot.'/'.trim((string) $root, '/');
            if (! is_dir($base)) {
                continue;
            }
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($count >= $maxFiles) {
                    return;
                }
                if (! ($file instanceof \SplFileInfo) || ! $file->isFile() || $file->getExtension() !== $ext) {
                    continue;
                }
                $path = $file->getPathname();
                foreach ($exclude as $needle) {
                    if ($needle !== '' && str_contains($path, $needle)) {
                        continue 2;
                    }
                }
                $count++;
                yield $path;
            }
        }
    }

    private function relative(string $repoRoot, string $abs): string
    {
        return ltrim(str_replace($repoRoot, '', $abs), '/');
    }
}
