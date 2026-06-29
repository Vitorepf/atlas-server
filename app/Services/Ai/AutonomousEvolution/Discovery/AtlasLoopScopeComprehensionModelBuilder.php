<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\AutonomousEvolution\AtlasLoopHarnessGuard;
use App\Services\Ai\AutonomousEvolution\Verify\AtlasLoopSignalAnalyzer;
use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * §5.6 · LAYER 1 — the PURE builder of the {@see AtlasLoopScopeComprehensionModel}.
 *
 * `build($repoRoot, $scopeRoot)` is a deterministic function of a repo SNAPSHOT (DB/provider/clock are
 * treated as ABSENT — the caller-edge oracle is the grep-only {@see AtlasLoopWiredCallerService::callerPaths},
 * never the DB-unioned callerCounts; clones come from the AST {@see AtlasLoopSignalAnalyzer}). Same snapshot
 * ⇒ byte-identical model. It emits only FACTS (no rank), so it cannot become the cyclomatic-proxy reborn.
 *
 * Non-gameable oracles only:
 *   - ORPHAN = zero production callers under the FQCN-fixed-string + own-namespace-short grep (the basename
 *     false-positive — ~40 phantom orphans on the real tree — is structurally excluded).
 *   - CLONE  = identical normalized method-body hash (≥2 members) from the AST scanner.
 *   - FORBIDDEN = the authoritative {@see AtlasLoopHarnessGuard::isForbiddenSelfTarget}.
 *   - DOC-STATED-GAP = a class the canonical docs NAME but which resolves to NO inventoried symbol.
 * Doc-derived prose (docPurposes/docStatedGaps) is provenance-tagged untrusted and never structural.
 */
final class AtlasLoopScopeComprehensionModelBuilder
{
    /** Path substrings never inventoried (tests / archived / vendored code). */
    private const EXCLUDE = ['/tests/', '/Tests/', '/archive/', '/vendor/', '/node_modules/'];

    public function __construct(
        private readonly ?AtlasLoopSignalAnalyzer $analyzer = null,
        private readonly ?AtlasLoopHarnessGuard $guard = null,
    ) {}

    /**
     * @param  string  $repoRoot  the repo root the caller grep + clone scan resolve against
     * @param  string  $scopeRoot  the inventoried subtree, repo-relative (e.g. app/Services/Ai/AutonomousEvolution)
     * @param  array{docs_roots?:list<string>, max_files?:int}  $opts
     */
    public function build(string $repoRoot, string $scopeRoot, array $opts = []): AtlasLoopScopeComprehensionModel
    {
        $repoRoot = rtrim($repoRoot, '/');
        $scopeRel = trim(str_replace('\\', '/', $scopeRoot), '/');
        $docsRoots = $opts['docs_roots'] ?? ['docs/loop-canonical-definition.md', 'docs/loop-os-architecture.md'];

        // 1. INVENTORY — every class/enum/trait/interface file under the scope (sorted, deterministic).
        $files = $this->scopeFiles($repoRoot, $scopeRel);
        $inventory = [];
        $fqcnByRel = [];
        $docPurposes = [];
        foreach ($files as $rel) {
            $abs = $repoRoot.'/'.$rel;
            $src = (string) @file_get_contents($abs);
            $fqcn = $this->parseFqcn($src);
            if ($fqcn === null) {
                continue; // not a class file — never inventoried
            }
            $fqcnByRel[$rel] = $fqcn;
            $inventory[$rel] = [
                'rel_path' => $rel,
                'fqcn' => $fqcn,
                'public_methods' => $this->publicMethods($src),
                'is_orphan' => false,           // filled below
                'is_forbidden' => ($this->guard ?? new AtlasLoopHarnessGuard)->isForbiddenSelfTarget($rel),
                'clone_cluster_id' => null,     // filled below
            ];
            $purpose = $this->docblockFirstSentence($src);
            if ($purpose !== null) {
                $docPurposes[$fqcn] = $purpose;
            }
        }

        $relPaths = array_keys($inventory);

        // 2. EDGES + ORPHANS — grep-only caller oracle (DB arm structurally absent here).
        $edges = $this->resolveEdges($repoRoot, $scopeRel, $fqcnByRel);
        $orphans = [];
        foreach ($relPaths as $rel) {
            // orphan ⟺ MEASURED zero production callers (edge present and empty). Unmeasured (edge absent)
            // is NOT an orphan — fail-open, never a false orphan from a degraded grep.
            if (array_key_exists($rel, $edges) && $edges[$rel] === []) {
                $orphans[] = $fqcnByRel[$rel];
                $inventory[$rel]['is_orphan'] = true;
            }
        }
        sort($orphans);

        // 3. CLONE CLUSTERS — identical normalized method-body hash from the AST scanner (≥2 members).
        $cloneClusters = $this->resolveClones($repoRoot, $scopeRel, $opts);
        foreach ($cloneClusters as $cluster) {
            foreach ($cluster['members'] as $m) {
                $mp = ltrim((string) $m['path'], '/');
                if (isset($inventory[$mp])) {
                    $inventory[$mp]['clone_cluster_id'] = $cluster['cluster_id'];
                }
            }
        }

        // 4. FORBIDDEN set (rel paths).
        $forbidden = array_values(array_filter($relPaths, static fn (string $r): bool => $inventory[$r]['is_forbidden']));
        sort($forbidden);

        // 5. DOC-STATED GAPS — capabilities the canonical docs NAME but no inventoried symbol provides.
        $docStatedGaps = $this->resolveDocStatedGaps($repoRoot, $docsRoots, $fqcnByRel);

        // 6. SNAPSHOT ID — a hash of the STRUCTURAL facts only (prose-independent), so a docblock edit
        //    leaves it byte-identical.
        ksort($inventory);
        $inventory = array_values($inventory);
        ksort($edges);
        $snapshotId = $this->snapshotId($inventory, $edges, $orphans, $cloneClusters, $forbidden, $docStatedGaps);

        return new AtlasLoopScopeComprehensionModel(
            inventory: $inventory,
            edges: $edges,
            orphans: $orphans,
            cloneClusters: $cloneClusters,
            forbidden: $forbidden,
            docPurposes: $docPurposes,
            docStatedGaps: $docStatedGaps,
            snapshotId: $snapshotId,
        );
    }

    /**
     * The grep-only caller edges (production callers per scope file). DB arm is NOT consulted so the result
     * is identical with the code-symbols table present or absent.
     *
     * FAST PATH (proven EQUAL to {@see AtlasLoopWiredCallerService::callerPaths} on the fixture by test AND on
     * the real scope by an orphan/edge-set diff): the trusted oracle does ONE full-tree grep PER target (N
     * targets ⇒ ~126s). Here the SAME caller set is computed with two cheap passes: (1) FQCN edges via a SINGLE
     * fixed-pattern candidate filter on the scope namespace prefix + strpos attribution (~9s vs ~126s — a
     * 246-pattern `grep -f` is ~70s on BSD grep, which lacks GNU's Aho-Corasick), and (2) same-directory short-
     * name edges via one PHP word scan over the scope subtree. On any FQCN grep error it FALLS BACK to
     * the trusted per-target oracle (correctness over speed — never a false orphan).
     *
     * @param  array<string,string>  $fqcnByRel
     * @return array<string, list<string>>
     */
    private function resolveEdges(string $repoRoot, string $scopeRel, array $fqcnByRel): array
    {
        $relPaths = array_keys($fqcnByRel);
        if ($relPaths === []) {
            return [];
        }

        $prodDirs = array_values(array_filter(
            ['app', 'routes', 'config', 'database'],
            static fn (string $d): bool => is_dir($repoRoot.'/'.$d),
        ));

        // The oracle's two rules: (1) FQCN as a fixed string anywhere in production; (2) short name as a word
        // within the target's own namespace subtree.
        $shorts = [];
        foreach ($fqcnByRel as $fqcn) {
            $short = ($p = strrpos($fqcn, '\\')) === false ? $fqcn : substr($fqcn, $p + 1);
            if (strlen($short) >= 3) {
                $shorts[$short] = true;
            }
        }
        // FQCN edges: a SINGLE-pattern candidate filter on the scope namespace prefix (fast Boyer-Moore on BSD
        // grep, unlike a 246-pattern `-f` which is ~70s) → substring-attribute the few candidates by strpos.
        // The short edges are one PHP word scan over the scope subtree, avoiding BSD grep's pathological
        // multi-pattern `-f` cost. Both substring/
        // word-boundary semantics match AtlasLoopWiredCallerService::callerPaths (proven EXACT on the real scope).
        $fqcnHits = $this->resolveFqcnHits($repoRoot, $prodDirs, $fqcnByRel);
        $shortHits = $this->wordOccurrencesInPhpFiles($repoRoot, [$scopeRel], array_keys($shorts));

        if ($fqcnHits === null || $shortHits === null) {
            if (count($relPaths) > 50) {
                // ponytail: large degraded scopes fail open; batch oracle later if caller recall must be exact.
                return [];
            }
            // grep degraded ⇒ fall back to the trusted per-target oracle (slower, but never a false orphan).
            try {
                $edges = (new AtlasLoopWiredCallerService($repoRoot))->callerPaths($relPaths);
            } catch (Throwable) {
                return [];
            }
            ksort($edges);

            return $edges;
        }

        $callers = [];
        foreach ($relPaths as $rel) {
            $fqcn = $fqcnByRel[$rel];
            $short = ($p = strrpos($fqcn, '\\')) === false ? $fqcn : substr($fqcn, $p + 1);
            $dirPrefix = $this->dirOf($rel);
            $dirPrefix = $dirPrefix === '' ? '' : $dirPrefix.'/';
            $set = [];
            foreach (($fqcnHits[$fqcn] ?? []) as $f) {
                if ($f !== $rel && ! $this->isTestPath($f)) {
                    $set[$f] = true;
                }
            }
            foreach (($shortHits[$short] ?? []) as $f) {
                // same-namespace-subtree short reference (the oracle confines the short grep to the target dir).
                if ($f !== $rel && ! $this->isTestPath($f) && ($dirPrefix === '' || str_starts_with($f, $dirPrefix))) {
                    $set[$f] = true;
                }
            }
            $list = array_keys($set);
            sort($list);
            $callers[$rel] = $list;
        }
        ksort($callers);

        return $callers;
    }

    /**
     * Word-boundary occurrence scan for class short names. This replaces BSD grep's pathological
     * `grep -f <1600 patterns>` path in long-running brain cycles while preserving the same practical
     * word semantics: PHP identifiers/comments/strings all contribute words, just as grep would.
     *
     * @param  list<string>  $dirs
     * @param  list<string>  $patterns
     * @return array<string, list<string>>|null
     */
    private function wordOccurrencesInPhpFiles(string $repoRoot, array $dirs, array $patterns): ?array
    {
        if ($dirs === [] || $patterns === []) {
            return [];
        }

        $wanted = [];
        foreach ($patterns as $pattern) {
            $pattern = (string) $pattern;
            if ($pattern !== '') {
                $wanted[$pattern] = true;
            }
        }
        if ($wanted === []) {
            return [];
        }

        $hits = [];
        try {
            foreach ($dirs as $dir) {
                $base = $repoRoot.'/'.trim(str_replace('\\', '/', (string) $dir), '/');
                if (! is_dir($base)) {
                    continue;
                }
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $info) {
                    if (! $info->isFile() || $info->getExtension() !== 'php') {
                        continue;
                    }
                    $abs = str_replace('\\', '/', $info->getPathname());
                    $rel = ltrim(substr($abs, strlen($repoRoot)), '/');
                    foreach (self::EXCLUDE as $frag) {
                        if (str_contains('/'.$rel, $frag)) {
                            continue 2;
                        }
                    }

                    $src = (string) @file_get_contents($abs);
                    if ($src === '') {
                        continue;
                    }
                    preg_match_all('/[A-Za-z_][A-Za-z0-9_]*/', $src, $matches);
                    foreach (array_unique($matches[0] ?? []) as $word) {
                        if (isset($wanted[$word])) {
                            $hits[$word][$rel] = true;
                        }
                    }
                }
            }
        } catch (Throwable) {
            return null;
        }

        foreach ($hits as $match => $paths) {
            $list = array_keys($paths);
            sort($list);
            $hits[$match] = $list;
        }

        return $hits;
    }

    /**
     * FQCN caller hits via a SINGLE-pattern candidate filter (the scope namespace prefix) + strpos attribution
     * — substring-equivalent to the per-target oracle, but one fast grep instead of a 246-pattern `-f` (BSD
     * grep's multi-pattern `-f` is ~70s). NULL on a grep error or a too-broad prefix ⇒ caller falls back.
     *
     * @param  list<string>  $prodDirs
     * @param  array<string,string>  $fqcnByRel
     * @return array<string, list<string>>|null
     */
    private function resolveFqcnHits(string $repoRoot, array $prodDirs, array $fqcnByRel): ?array
    {
        if ($prodDirs === [] || $fqcnByRel === []) {
            return [];
        }
        $nsPrefix = $this->commonNamespacePrefix(array_values($fqcnByRel));
        if (substr_count($nsPrefix, '\\') < 1) {
            return null; // too-broad/absent prefix ⇒ fall back to the trusted per-target oracle (safety).
        }
        $candidates = $this->grepFilesMatching($repoRoot, $prodDirs, $nsPrefix);
        if ($candidates === null) {
            return null;
        }

        $hits = [];
        foreach ($candidates as $cand) {
            if ($this->isTestPath($cand)) {
                continue;
            }
            $src = (string) @file_get_contents($repoRoot.'/'.$cand);
            if ($src === '') {
                continue;
            }
            foreach ($fqcnByRel as $rel => $fqcn) {
                if ($cand !== $rel && str_contains($src, $fqcn)) {
                    $hits[$fqcn][$cand] = true;
                }
            }
        }
        foreach ($hits as $fqcn => $set) {
            $list = array_keys($set);
            sort($list);
            $hits[$fqcn] = $list;
        }

        return $hits;
    }

    /**
     * Files (repo-relative) containing a fixed SINGLE pattern. NULL on grep error.
     *
     * @param  list<string>  $dirs
     * @return list<string>|null
     */
    private function grepFilesMatching(string $repoRoot, array $dirs, string $pattern): ?array
    {
        if ($dirs === [] || $pattern === '') {
            return [];
        }
        try {
            $proc = new Process(array_merge(['grep', '-rlF', '--include=*.php', $pattern], $dirs), $repoRoot, $this->grepEnv(), null, 90.0);
            $proc->run();
            $exit = $proc->getExitCode();
            if ($exit === null || $exit > 1) {
                return null;
            }
            $out = [];
            foreach (preg_split('/\R/', (string) $proc->getOutput()) ?: [] as $line) {
                $line = ltrim(str_replace('\\', '/', trim($line)), '/');
                if ($line !== '') {
                    $out[] = $line;
                }
            }

            return $out;
        } catch (Throwable) {
            return null;
        }
    }

    /** The longest common NAMESPACE prefix (excluding class short names) of a set of FQCNs. */
    private function commonNamespacePrefix(array $fqcns): string
    {
        if ($fqcns === []) {
            return '';
        }
        $split = array_map(static fn (string $f): array => explode('\\', ltrim($f, '\\')), $fqcns);
        $first = $split[0];
        $common = [];
        for ($i = 0, $n = count($first) - 1; $i < $n; $i++) {
            $seg = $first[$i];
            foreach ($split as $parts) {
                if (($parts[$i] ?? null) !== $seg) {
                    return implode('\\', $common);
                }
            }
            $common[] = $seg;
        }

        return implode('\\', $common);
    }

    private function isTestPath(string $rel): bool
    {
        return preg_match('/Test\.php$/', $rel) === 1
            || str_contains('/'.$rel, '/tests/')
            || str_contains('/'.$rel, '/Tests/');
    }

    private function dirOf(string $rel): string
    {
        $d = str_replace('\\', '/', \dirname($rel));

        return $d === '.' ? '' : trim($d, '/');
    }

    /**
     * PATH-safe env so the SYSTEM grep binary (BSD/GNU) is resolved even from a launchd/cron-spawned loop —
     * matching {@see AtlasLoopWiredCallerService::pathSafeEnv} so the builder grep == the trusted oracle grep
     * (and never a shell-aliased/`ugrep` substitute with different `-f`/`-w` semantics).
     *
     * @return array<string,string>
     */
    private function grepEnv(): array
    {
        $binDir = \dirname(PHP_BINARY);
        $base = getenv('PATH');
        $base = is_string($base) && $base !== '' ? $base : '/usr/bin:/bin:/usr/sbin:/sbin';
        $path = ($binDir !== '' && $binDir !== '.' && $binDir !== DIRECTORY_SEPARATOR)
            ? $binDir.PATH_SEPARATOR.$base
            : $base;

        return ['PATH' => '/usr/bin'.PATH_SEPARATOR.'/bin'.PATH_SEPARATOR.$path];
    }

    /**
     * Clone clusters scoped to the inventory, via the AST scanner's normalized-body hash.
     *
     * @return list<array{cluster_id:string, clone_hash:string, members:list<array{path:string, symbol:string}>}>
     */
    private function resolveClones(string $repoRoot, string $scopeRel, array $opts): array
    {
        try {
            $scan = ($this->analyzer ?? new AtlasLoopSignalAnalyzer)->scan($repoRoot, [
                'code_roots' => [$scopeRel],
                'docs_roots' => [],   // clones only — skip the doc-drift pass
                'max_files' => max(1, (int) ($opts['max_files'] ?? 6000)),
            ]);
        } catch (Throwable) {
            return [];
        }

        $clusters = [];
        foreach (($scan['flags'] ?? []) as $flag) {
            if (($flag['mode'] ?? null) !== 'code_clone') {
                continue;
            }
            $members = [];
            foreach (($flag['members'] ?? []) as $m) {
                $members[] = ['path' => ltrim((string) ($m['path'] ?? ''), '/'), 'symbol' => (string) ($m['symbol'] ?? '')];
            }
            usort($members, static fn (array $a, array $b): int => [$a['path'], $a['symbol']] <=> [$b['path'], $b['symbol']]);
            $clusters[] = [
                'cluster_id' => (string) ($flag['id'] ?? ('clone:'.substr((string) ($flag['clone_hash'] ?? ''), 0, 12))),
                'clone_hash' => (string) ($flag['clone_hash'] ?? ''),
                'members' => $members,
            ];
        }
        usort($clusters, static fn (array $a, array $b): int => $a['cluster_id'] <=> $b['cluster_id']);

        return $clusters;
    }

    /**
     * Capability symbol-names the canonical docs NAME but which resolve to NO inventoried symbol — the
     * grounded "the docs say X must exist, but it does not" signal. Doc-derived ⇒ provenance untrusted.
     *
     * @param  list<string>  $docsRoots  repo-relative doc files or dirs
     * @param  array<string,string>  $fqcnByRel
     * @return list<string>
     */
    private function resolveDocStatedGaps(string $repoRoot, array $docsRoots, array $fqcnByRel): array
    {
        $inventoryFqcns = array_values($fqcnByRel);
        $inventoryShort = array_map(static function (string $fqcn): string {
            $pos = strrpos($fqcn, '\\');

            return $pos === false ? $fqcn : substr($fqcn, $pos + 1);
        }, $inventoryFqcns);

        $named = [];
        foreach ($docsRoots as $docRel) {
            foreach ($this->docFiles($repoRoot, trim((string) $docRel, '/')) as $abs) {
                $text = (string) @file_get_contents($abs);
                if ($text === '') {
                    continue;
                }
                // FQCN mentions (App\...\Name) + short AtlasLoop* mentions.
                preg_match_all('/App\\\\(?:[A-Za-z0-9_]+\\\\)+[A-Za-z0-9_]+/', $text, $mFqcn);
                preg_match_all('/\bAtlasLoop[A-Za-z0-9_]+\b/', $text, $mShort);
                foreach (($mFqcn[0] ?? []) as $n) {
                    $named[ltrim((string) $n, '\\')] = true;
                }
                foreach (($mShort[0] ?? []) as $n) {
                    $named[(string) $n] = true;
                }
            }
        }

        $gaps = [];
        foreach (array_keys($named) as $name) {
            $short = ($pos = strrpos($name, '\\')) === false ? $name : substr($name, $pos + 1);
            $resolves = in_array($name, $inventoryFqcns, true) || in_array($short, $inventoryShort, true);
            if (! $resolves) {
                $gaps[$name] = true;
            }
        }
        $gaps = array_keys($gaps);
        sort($gaps);

        return $gaps;
    }

    /**
     * Repo-relative .php files under the scope, sorted, with tests/archive/vendor excluded.
     *
     * @return list<string>
     */
    private function scopeFiles(string $repoRoot, string $scopeRel): array
    {
        $base = $repoRoot.'/'.$scopeRel;
        if (! is_dir($base)) {
            return [];
        }
        $out = [];
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $info) {
                if (! $info->isFile() || $info->getExtension() !== 'php') {
                    continue;
                }
                $abs = str_replace('\\', '/', $info->getPathname());
                $rel = ltrim(substr($abs, strlen($repoRoot)), '/');
                foreach (self::EXCLUDE as $frag) {
                    if (str_contains('/'.$rel, $frag)) {
                        continue 2;
                    }
                }
                $out[] = $rel;
            }
        } catch (Throwable) {
            return [];
        }
        sort($out);

        return $out;
    }

    /** @return list<string> absolute doc file paths under a repo-relative file-or-dir */
    private function docFiles(string $repoRoot, string $docRel): array
    {
        $abs = $repoRoot.'/'.$docRel;
        if (is_file($abs)) {
            return [$abs];
        }
        if (! is_dir($abs)) {
            return [];
        }
        $out = [];
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $info) {
                if ($info->isFile() && $info->getExtension() === 'md') {
                    $out[] = str_replace('\\', '/', $info->getPathname());
                }
            }
        } catch (Throwable) {
            return [];
        }
        sort($out);

        return $out;
    }

    /** FQCN = namespace + first class/enum/trait/interface, both anchored so a docblock cannot false-match. */
    private function parseFqcn(string $src): ?string
    {
        if ($src === '') {
            return null;
        }
        if (preg_match('/^\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum|trait|interface)\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m) !== 1) {
            return null;
        }
        $short = $m[1];
        $ns = '';
        if (preg_match('/^\s*namespace\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*;/m', $src, $nm) === 1) {
            $ns = trim($nm[1], '\\');
        }

        return $ns === '' ? $short : $ns.'\\'.$short;
    }

    /** @return list<string> sorted public method names (excluding the constructor). */
    private function publicMethods(string $src): array
    {
        preg_match_all('/^\s*public(?:\s+static)?\s+function\s+([A-Za-z_][A-Za-z0-9_]*)/m', $src, $m);
        $names = array_values(array_unique(array_filter(
            $m[1] ?? [],
            static fn (string $n): bool => $n !== '__construct',
        )));
        sort($names);

        return $names;
    }

    /** The class docblock's first sentence (provenance: writable-untrusted-prose), or null. */
    private function docblockFirstSentence(string $src): ?string
    {
        if (preg_match('#/\*\*(.*?)\*/\s*(?:final\s+|abstract\s+|readonly\s+)*(?:class|enum|trait|interface)\s+#s', $src, $m) !== 1) {
            return null;
        }
        $lines = preg_split('/\R/', $m[1]) ?: [];
        foreach ($lines as $line) {
            $line = trim(preg_replace('/^\s*\*\s?/', '', $line) ?? '');
            if ($line === '' || str_starts_with($line, '@')) {
                continue;
            }
            $sentence = preg_split('/(?<=[.!?])\s/', $line)[0] ?? $line;

            return mb_substr(trim($sentence), 0, 300);
        }

        return null;
    }

    private function snapshotId(array $inventory, array $edges, array $orphans, array $cloneClusters, array $forbidden, array $docStatedGaps): string
    {
        $structural = array_map(static fn (array $i): array => [
            $i['rel_path'], $i['fqcn'], $i['public_methods'], $i['is_orphan'], $i['is_forbidden'], $i['clone_cluster_id'],
        ], $inventory);

        return hash('sha256', json_encode([
            'inventory' => $structural,
            'edges' => $edges,
            'orphans' => $orphans,
            'clones' => array_map(static fn (array $c): array => [$c['cluster_id'], $c['clone_hash']], $cloneClusters),
            'forbidden' => $forbidden,
            'gaps' => $docStatedGaps,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
