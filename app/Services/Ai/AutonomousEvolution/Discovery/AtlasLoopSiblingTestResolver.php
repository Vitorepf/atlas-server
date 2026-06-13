<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use Throwable;

/**
 * The single, deterministic, provider-free source of truth for "does this production
 * file have a real human-authored convention sibling test, and where is it?".
 *
 * The substantive-grind lane (lift NON_TRIVIAL) is built ENTIRELY on this resolver:
 * discovery preference, the test-gap objective, and the merge-time canary must all
 * resolve the SAME sibling the same way — otherwise frozen-green and canary-green are
 * two different facts and the credit is gameable. So this mirrors EXACTLY the glob
 * `AtlasLoopAutoMergeService::canary()` uses to find the sibling at merge time:
 *   glob(repoRoot/tests/{Unit,Feature}/&#42;&#42;/{Class}Test.php, GLOB_BRACE)
 * plus the flat (non-recursive) case, so a target whose sibling the canary will run is
 * exactly the target this resolver reports has_sibling=true for.
 *
 * Read-only; never mutates. Fail-closed on has_sibling (a parse/IO error reports NO
 * sibling, so a target only enters the substantive lane when a sibling is provably there).
 */
final class AtlasLoopSiblingTestResolver
{
    /**
     * Per-root cached index: repoRoot => (basename => list<relpath>). Built ONCE by
     * scanning tests/ so discovery's many lookups are O(1), not a full-tree walk each.
     *
     * @var array<string,array<string,list<string>>>
     */
    private static array $indexCache = [];

    public function __construct(private readonly ?string $repoRoot = null) {}

    /**
     * @return array{has_sibling: bool, sibling_path: ?string, asserted_methods: list<string>}
     */
    public function resolve(string $productionRelPath): array
    {
        $none = ['has_sibling' => false, 'sibling_path' => null, 'asserted_methods' => []];

        try {
            $root = rtrim($this->repoRoot ?? base_path(), '/');
            $class = pathinfo($productionRelPath, PATHINFO_FILENAME);
            if ($class === '' || ! str_ends_with($productionRelPath, '.php')) {
                return $none;
            }

            // TRULY recursive: PHP glob's `**` is NOT recursive (it matches one path
            // segment), so the old `tests/{Unit,Feature}/**/X` only found tests 0-1 dirs
            // deep and MISSED the deep mirror layout (tests/Unit/Ai/.../{Class}Test.php).
            // We scan tests/Unit + tests/Feature recursively for the exact basename, so a
            // deep sibling is found. canary() is refactored onto this same resolver, so
            // "what the resolver finds" == "what the canary runs" by construction.
            $needle = $class.'Test.php';
            $index = $this->index($root);
            $hits = $index[$needle] ?? [];
            if ($hits === []) {
                return $none;
            }
            // Path-mirror tiebreak: on a basename collision (e.g. tests/Unit/Ai/XTest.php
            // vs the true tests/Unit/Ai/Programming/XTest.php), prefer the hit whose
            // directory shares the MOST segments with the production file's dir — the real
            // mirror — not the alphabetical-first wrong one. Deterministic, fixes the 3
            // measured collisions, and keeps canary + discovery resolving the same sibling.
            $siblingRel = $this->bestMirror($hits, $productionRelPath);

            return [
                'has_sibling' => true,
                'sibling_path' => $siblingRel,
                'asserted_methods' => $this->testMethods($root.'/'.$siblingRel),
            ];
        } catch (Throwable) {
            return $none;
        }
    }

    public function hasSibling(string $productionRelPath): bool
    {
        return $this->resolve($productionRelPath)['has_sibling'];
    }

    /**
     * Pick the test hit whose directory best mirrors the production file's directory
     * (most shared path segments), so a basename collision resolves to the true mirror.
     * Deterministic: ties broken by the shortest path then alphabetical.
     *
     * @param  list<string>  $hits
     */
    private function bestMirror(array $hits, string $productionRelPath): string
    {
        $prodSegs = array_values(array_filter(
            explode('/', trim(str_replace('\\', '/', \dirname($productionRelPath)), '/')),
            static fn (string $s): bool => $s !== '' && $s !== 'app',
        ));
        $prodSet = array_flip($prodSegs);

        $best = null;
        $bestScore = -1;
        $bestLen = PHP_INT_MAX;
        foreach ($hits as $hit) {
            $segs = explode('/', trim(str_replace('\\', '/', $hit), '/'));
            $score = 0;
            foreach ($segs as $s) {
                if (isset($prodSet[$s])) {
                    $score++;
                }
            }
            $len = count($segs);
            if ($score > $bestScore || ($score === $bestScore && ($len < $bestLen || ($len === $bestLen && $hit < (string) $best)))) {
                $best = $hit;
                $bestScore = $score;
                $bestLen = $len;
            }
        }

        return (string) $best;
    }

    /**
     * Build (once, cached) the basename => list<relpath> index of every *Test.php under
     * tests/. NO symlink-follow (a stray symlink loop was OOMing a per-call walk), prunes
     * vendor/.git/node_modules, bounded by a hard node cap. Scans tests/ ONE time so the
     * many discovery lookups are O(1) map reads.
     *
     * @return array<string,list<string>>
     */
    private function index(string $root): array
    {
        if (isset(self::$indexCache[$root])) {
            return self::$indexCache[$root];
        }

        $index = [];
        $budget = 300000;
        foreach (['tests/Unit', 'tests/Feature'] as $rel) {
            $base = $root.'/'.$rel;
            if (! is_dir($base)) {
                continue;
            }
            try {
                $dirIt = new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS);
                $filter = new \RecursiveCallbackFilterIterator($dirIt, static function (\SplFileInfo $cur): bool {
                    if ($cur->isDir()) {
                        return ! in_array($cur->getFilename(), ['vendor', '.git', 'node_modules'], true);
                    }

                    return str_ends_with($cur->getFilename(), 'Test.php');
                });
                $it = new \RecursiveIteratorIterator($filter, \RecursiveIteratorIterator::LEAVES_ONLY);
                foreach ($it as $file) {
                    if (--$budget <= 0) {
                        break;
                    }
                    $name = $file->getFilename();
                    $index[$name][] = $this->relative($file->getPathname(), $root);
                }
            } catch (Throwable) {
                continue;
            }
        }

        return self::$indexCache[$root] = $index;
    }

    private function relative(string $abs, string $root): string
    {
        $abs = str_replace('\\', '/', $abs);
        $root = str_replace('\\', '/', $root).'/';

        return str_starts_with($abs, $root) ? substr($abs, strlen($root)) : $abs;
    }

    /**
     * Parse the test method names declared in the sibling (the existing coverage), so the
     * objective stage can frame the gap and the anti-gaming stage can require the NEW
     * assertion to be distinct from what already exists.
     *
     * @return list<string>
     */
    private function testMethods(string $absPath): array
    {
        if (! is_file($absPath)) {
            return [];
        }
        $src = @file_get_contents($absPath, false, null, 0, 200000);
        if (! is_string($src) || $src === '') {
            return [];
        }
        $methods = [];
        // PHPUnit conventions: `public function testFoo()` and `#[Test]`/`/** @test */ public function foo()`.
        if (preg_match_all('/function\s+(test[A-Za-z0-9_]*)\s*\(/', $src, $m) > 0) {
            foreach ($m[1] as $name) {
                $methods[$name] = true;
            }
        }
        if (preg_match_all('/(?:#\[Test\]|@test)\s*(?:public\s+|protected\s+)?function\s+([A-Za-z0-9_]+)\s*\(/', $src, $m2) > 0) {
            foreach ($m2[1] as $name) {
                $methods[$name] = true;
            }
        }

        return array_values(array_keys($methods));
    }
}
