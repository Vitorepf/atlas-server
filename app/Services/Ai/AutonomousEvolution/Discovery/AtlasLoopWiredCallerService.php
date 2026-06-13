<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Ground truth for "is this target file actually CALLED by production code?".
 *
 * This is the load-bearing signal that turns the Evolution Loop from a janitor
 * polishing orphan scaffolding into a builder that hardens code that runs (the
 * measured 54%-orphan defect).
 *
 * TWO independent ground-truth sources, UNION'd for recall:
 *   1. FQCN GREP (primary): production files (app/ routes/ config/ database/,
 *      excluding the file itself and *Test.php) that contain the target's
 *      fully-qualified class name as a FIXED STRING. We grep the FQCN, never the
 *      bare short name — the short name collides catastrophically (a class named
 *      Intent/Builder/Process matches prose, comments, and unrelated symbols), which
 *      would let an orphan read as wired and inflate the honesty grade. The FQCN is
 *      globally unique, so a match is a real `use`/`\FQCN` reference.
 *   2. CODE-GRAPH (enrichment): inbound `depends_on` edges in
 *      ai_codebase_world_model_edges, FILTERED to the latest scope='atlas-server-symbols'
 *      world model (the scope filter is load-bearing — unscoped 3x-inflates), with
 *      test-namespace edges excluded. Catches same-namespace references the FQCN grep
 *      misses; the graph is incomplete, so grep stays primary and we take the MAX.
 *
 * TRI-STATE by design: a per-file result is `null` when NEITHER source produced any
 * signal (grep errored AND graph unavailable) — "unmeasured", so the merge value gate
 * can FAIL OPEN instead of treating a real hub as a false orphan. An int (including 0)
 * is a real measurement; 0 = confirmed orphan.
 */
final class AtlasLoopWiredCallerService
{
    /** Directories that count as production callers (NOT tests). */
    private const PRODUCTION_DIRS = ['app', 'routes', 'config', 'database'];

    private ?string $cachedScopeModelId = null;

    private bool $scopeResolved = false;

    public function __construct(private readonly ?string $repoRoot = null) {}

    /**
     * Measured production caller counts per file. Returns ONLY measured paths (null
     * results omitted) so a caller can use `$counts !== []` to detect total-degraded
     * infra. 0 means confirmed orphan.
     *
     * @param  list<string>  $relPaths
     * @return array<string,int>
     */
    public function callerCounts(array $relPaths): array
    {
        $resolved = $this->resolveMany($relPaths);

        $out = [];
        foreach ($resolved as $rel => $count) {
            if ($count !== null) {
                $out[$rel] = $count;
            }
        }

        return $out;
    }

    /**
     * Tri-state caller count for one file: null = unmeasured (fail-open), int = measured.
     */
    public function callerCount(string $relPath): ?int
    {
        $resolved = $this->resolveMany([$relPath]);

        return $resolved[ltrim($relPath, '/')] ?? null;
    }

    /**
     * @param  list<string>  $relPaths
     * @return array<string,?int>  relPath => measured count, or null when unmeasured
     */
    private function resolveMany(array $relPaths): array
    {
        $root = rtrim($this->repoRoot ?? base_path(), '/');
        $dirs = array_values(array_filter(
            self::PRODUCTION_DIRS,
            static fn (string $d): bool => is_dir($root.'/'.$d),
        ));

        $fqcnByPath = $this->fqcnMap($relPaths, $root);
        $graph = $this->graphCounts($fqcnByPath); // array<rel,int> for resolved FQCNs

        $out = [];
        foreach ($relPaths as $rel) {
            $rel = ltrim($rel, '/');
            $fqcn = $fqcnByPath[$rel] ?? null;
            $grep = ($fqcn !== null && $dirs !== []) ? $this->grepCallerCount($fqcn, $rel, $root, $dirs) : null;
            $graphCount = $graph[$rel] ?? null;

            $out[$rel] = ($grep === null && $graphCount === null)
                ? null // unmeasured — neither source produced a signal
                : max($grep ?? 0, $graphCount ?? 0);
        }

        return $out;
    }

    /**
     * Resolve each file's FQCN. Prefer the code-graph symbol_name (already fully
     * qualified); fall back to parsing `namespace` + the first class/enum/trait/interface
     * declaration (anchored to a line start so a docblock mention of "class" can't match).
     *
     * @param  list<string>  $relPaths
     * @return array<string,string>
     */
    private function fqcnMap(array $relPaths, string $root): array
    {
        $out = [];
        $needParse = [];
        foreach ($relPaths as $rel) {
            $rel = ltrim($rel, '/');
            $needParse[$rel] = true;
        }

        if (DatabaseTableAvailability::has('atlas_engineering_code_symbols')) {
            foreach (array_keys($needParse) as $rel) {
                try {
                    $name = DB::table('atlas_engineering_code_symbols')
                        ->where('file_path', 'like', '%/'.$rel)
                        ->orWhere('file_path', $rel)
                        ->whereIn('symbol_type', ['class', 'enum', 'trait', 'interface'])
                        ->orderByDesc('indexed_at')
                        ->value('symbol_name');
                } catch (Throwable) {
                    $name = null;
                }
                if (is_string($name) && $name !== '') {
                    $out[$rel] = ltrim($name, '\\');
                    unset($needParse[$rel]);
                }
            }
        }

        foreach (array_keys($needParse) as $rel) {
            $fqcn = $this->parseFqcn($root.'/'.$rel);
            if ($fqcn !== null) {
                $out[$rel] = $fqcn;
            }
        }

        return $out;
    }

    /**
     * Parse FQCN = namespace + short class name, both anchored to line starts so
     * comments/docblocks cannot produce a false declaration.
     */
    private function parseFqcn(string $absPath): ?string
    {
        if (! is_file($absPath)) {
            return null;
        }
        $src = @file_get_contents($absPath, false, null, 0, 65536);
        if (! is_string($src) || $src === '') {
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

    /**
     * Count DISTINCT production files (excluding the target itself and tests) that
     * reference the class. Two greps, UNION'd by file set:
     *   1. FQCN as a fixed string across ALL production dirs — collision-free (the full
     *      namespace path is globally unique), catches cross-namespace `use`/`\FQCN`.
     *   2. The bare short name (word-boundary) restricted to the target's OWN namespace
     *      directory only — same-namespace callers reference the short name without a
     *      `use`, and confining `-w` to that small directory keeps the short-name
     *      collision (the Throwable/Process/Intent disaster) from ever happening.
     * ?int: null on grep error (unmeasured => the gate fails open), int (incl 0) on success.
     */
    private function grepCallerCount(string $fqcn, string $relPath, string $root, array $dirs): ?int
    {
        $short = ($pos = strrpos($fqcn, '\\')) !== false ? substr($fqcn, $pos + 1) : $fqcn;
        $nsDir = trim(str_replace('\\', '/', \dirname($relPath)), '/');

        try {
            $files = [];

            // 1. FQCN fixed-string across all production dirs.
            $byFqcn = $this->grepFiles(array_merge(['grep', '-rlF', '--include=*.php', $fqcn], $dirs), $root);
            if ($byFqcn === null) {
                return null;
            }
            foreach ($byFqcn as $f) {
                $files[$f] = true;
            }

            // 2. Short name (word boundary) confined to the target's own namespace dir.
            if (strlen($short) >= 3 && $nsDir !== '' && $nsDir !== '.' && is_dir($root.'/'.$nsDir)) {
                $byShort = $this->grepFiles(['grep', '-rlw', '--include=*.php', $short, $nsDir], $root);
                if (is_array($byShort)) {
                    foreach ($byShort as $f) {
                        $files[$f] = true;
                    }
                }
            }

            $count = 0;
            foreach (array_keys($files) as $line) {
                if (str_contains($line, $relPath)) {
                    continue; // the file itself
                }
                if (preg_match('/Test\.php$/', $line) === 1 || str_contains($line, '/tests/')) {
                    continue; // tests are not production callers
                }
                $count++;
            }

            return $count;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Run a grep -l invocation and return the matched file lines, or null on a real error.
     *
     * @param  list<string>  $argv
     * @return list<string>|null
     */
    private function grepFiles(array $argv, string $root): ?array
    {
        $process = new Process($argv, $root, $this->pathSafeEnv(), null, 30.0);
        $process->run();
        $exit = $process->getExitCode();
        if ($exit === null || $exit > 1) {
            return null; // grep error (2+) => unmeasured; exit 1 = no matches (empty, measured)
        }

        $out = [];
        foreach (preg_split('/\R/', trim((string) $process->getOutput())) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /**
     * Scoped inbound depends_on edge counts (test edges excluded), keyed by relPath.
     *
     * @param  array<string,string>  $fqcnByPath
     * @return array<string,int>
     */
    private function graphCounts(array $fqcnByPath): array
    {
        if ($fqcnByPath === []
            || ! DatabaseTableAvailability::has('ai_codebase_world_model_edges')) {
            return [];
        }
        $scopeId = $this->scopeModelId();
        if ($scopeId === null) {
            return [];
        }

        try {
            $out = [];
            foreach ($fqcnByPath as $rel => $fqcn) {
                $node = 'sym:'.$fqcn;
                $froms = DB::table('ai_codebase_world_model_edges')
                    ->where('world_model_id', $scopeId)
                    ->where('to_node_id', $node)
                    ->where('edge_type', 'depends_on')
                    ->where('from_node_id', '!=', $node)
                    ->pluck('from_node_id');

                $count = 0;
                foreach ($froms as $from) {
                    if (! is_string($from)) {
                        continue;
                    }
                    // Exclude test callers (Tests\ namespace AND bare ...Test#hash symbols).
                    if (str_starts_with($from, 'sym:Tests\\') || str_starts_with($from, 'sym:Tests/')) {
                        continue;
                    }
                    if (preg_match('/Test(#|$)/', $from) === 1) {
                        continue;
                    }
                    $count++;
                }
                $out[$rel] = $count;
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * The latest atlas-server-symbols world-model id (cached). Scoping here is what
     * makes the graph count honest instead of 3x-inflated.
     */
    private function scopeModelId(): ?string
    {
        if ($this->scopeResolved) {
            return $this->cachedScopeModelId;
        }
        $this->scopeResolved = true;

        if (! DatabaseTableAvailability::has('ai_codebase_world_models')) {
            return $this->cachedScopeModelId = null;
        }

        try {
            $id = DB::table('ai_codebase_world_models')
                ->where('scope', 'atlas-server-symbols')
                ->orderByDesc('created_at')
                ->value('id');

            return $this->cachedScopeModelId = (is_string($id) && $id !== '') ? $id : null;
        } catch (Throwable) {
            return $this->cachedScopeModelId = null;
        }
    }

    /**
     * PATH-safe env so a launchd/cron-spawned process still resolves `grep`.
     *
     * @return array<string,string>
     */
    private function pathSafeEnv(): array
    {
        $binDir = \dirname(PHP_BINARY);
        $base = getenv('PATH');
        $base = is_string($base) && $base !== '' ? $base : '/usr/bin:/bin:/usr/sbin:/sbin';
        $path = ($binDir !== '' && $binDir !== '.' && $binDir !== DIRECTORY_SEPARATOR)
            ? $binDir.PATH_SEPARATOR.$base
            : $base;

        return ['PATH' => '/usr/bin'.PATH_SEPARATOR.'/bin'.PATH_SEPARATOR.$path];
    }
}
