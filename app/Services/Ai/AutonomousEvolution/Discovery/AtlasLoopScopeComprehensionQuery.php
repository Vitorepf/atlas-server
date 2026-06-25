<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * PART 1 — the in-request memoizing front-door to the scope-comprehension MODEL, and the LIVE query surface
 * Part 2 pulls ({@see ScopeComprehensionQuery}).
 *
 * WHY THIS EXISTS (the foundation, highest-leverage, zero migration): a single refill used to rebuild the
 * model once PER supply lane — the dedup lane, the orphan-wiring lane and the doc-gap lane each `new`-ed a
 * builder and called the ~9s `build()` for the SAME scope. Three identical builds (~27s) for one refill.
 * This query shares ONE memoized model per (scopeRoot, opts) for the lifetime of the request, so lanes with
 * identical inputs pay the build cost once.
 *
 * THE PÉTREO INVARIANT IT INHERITS: comprehension emits FACTS, NEVER a scalar/rank. The model is a pure
 * pass-through (byte-identical to a fresh build). `level_vector` is a vector of BOOLEANS; `transitionsFor`
 * returns NAMED transitions from a FIXED deterministic map (no score, no ordering by worth — WHICH transition
 * is worth most is the Part-2 model-bound judgment). The query never computes a number.
 *
 * HONEST MEMO KEY: the cache key is the (normalized) scopeRoot. repoRoot + opts are fixed per query instance,
 * so two lanes that build with DIFFERENT opts (the doc-gap lane uses `docs_roots`, the dedup/orphan lanes do
 * not — a genuinely different model with different `doc_stated_gaps`) hold SEPARATE query instances and are
 * never collapsed. The cache is scoped to the request: a long-lived owner (e.g. the singleton refiller) MUST
 * call {@see reset()} at the top of each refill so the model can never be served stale across refills.
 */
final class AtlasLoopScopeComprehensionQuery implements ScopeComprehensionQuery
{
    /** The FIXED, deterministic fact->transition map (pétreo; adding a transition is an explicit design act). */
    private const TRANSITION_MAP = [
        // level_vector key (or its negation) => the NAMED transition it unlocks (a supply-lane that already exists).
        'orphan' => 'orphan->wired',          // is_orphan == true
        'in_clone' => 'clone->unified',       // clone_cluster != null
        'doc_gap_open' => 'gap->satisfied',   // doc_gap_open == true
        'gate_clean' => 'regressed->green',   // gate_clean == FALSE
        'has_test' => 'untested->tested',     // has_test == FALSE
    ];

    /** @var array<string, AtlasLoopScopeComprehensionModel> scopeRoot-key => memoized model (this request only) */
    private array $memo = [];

    /** @var array<string, array{built_at:int, mtimes:array<string,int>}> scopeRoot-key => freshness meta at build */
    private array $builtMeta = [];

    /** How many times this query actually delegated to the (expensive) builder — observability + the memo proof. */
    private int $buildCount = 0;

    /**
     * @param  array{docs_roots?:list<string>, max_files?:int}  $opts  the build options, FIXED for this query instance
     * @param  ?ScopeRuntimeFacts  $runtimeFacts  the FREE runtime-fact source; null => the safe "no evidence"
     *                                            defaults (gate_clean true, last_merge_clean false). A Part-2
     *                                            consumer passes the live {@see AtlasLoopScopeRuntimeFacts}.
     */
    public function __construct(
        private readonly AtlasLoopScopeComprehensionModelBuilder $builder,
        private readonly string $repoRoot,
        private readonly array $opts = [],
        private readonly ?ScopeRuntimeFacts $runtimeFacts = null,
        private readonly ?AtlasLoopScopeComprehensionReadModel $readModel = null,
    ) {
    }

    /**
     * The hydrated comprehension model for a scope root, built at most once per (scopeRoot, opts) this request.
     * Byte-identical to {@see AtlasLoopScopeComprehensionModelBuilder::build} — the query only caches it.
     */
    public function model(string $scopeRoot): AtlasLoopScopeComprehensionModel
    {
        $key = $this->key($scopeRoot);
        if (! isset($this->memo[$key])) {
            $this->memo[$key] = $this->builder->build($this->repoRoot, $scopeRoot, $this->opts);
            $this->builtMeta[$key] = ['built_at' => $this->now(), 'mtimes' => $this->inputMtimes($scopeRoot)];
            $this->buildCount++;
            // B1/P1-B: write-through the proven facts to the persistent read-model (durable time-series record).
            $this->readModel?->put($this->memo[$key], $scopeRoot, $this->builtMeta[$key]['built_at']);
        }

        return $this->memo[$key];
    }

    /**
     * The per-unit `level_vector` — SIX booleans, never a scalar. Structural facts (orphan/in_clone/
     * doc_gap_open) read straight from the model; the runtime facts (has_test/gate_clean/last_merge_clean)
     * degrade to their SAFE default here (no runtime source wired in this layer) and are filled by the
     * runtime-facts step. The safe defaults are the honest "no evidence" values — a degraded read never
     * fabricates a "needs work" transition.
     *
     * @return array{has_test:bool, gate_clean:bool, orphan:bool, in_clone:bool, doc_gap_open:bool, last_merge_clean:bool}
     */
    public function levelVector(string $fqcn): array
    {
        $fqcn = ltrim($fqcn, '\\');
        $model = $this->resolveModelFor($fqcn);
        $item = $model !== null ? $this->inventoryItem($model, $fqcn) : null;

        return [
            // Runtime facts — SAFE defaults until the runtime-facts source is wired (honest "no evidence"):
            //   has_test=true  => never a spurious untested->tested; gate_clean=true => never a spurious
            //   regressed->green; last_merge_clean=false => "no clean merge recorded" (informational only).
            'has_test' => $this->hasTest($fqcn, $model),
            'gate_clean' => $this->gateClean($fqcn, $model),
            // Structural facts — straight from the model's non-gameable oracles:
            'orphan' => $item !== null ? (bool) $item['is_orphan'] : false,
            'in_clone' => $item !== null && $item['clone_cluster_id'] !== null,
            'doc_gap_open' => $model !== null && in_array($fqcn, $model->docStatedGaps, true),
            'last_merge_clean' => $this->lastMergeClean($fqcn, $model),
        ];
    }

    /**
     * The NAMED next-level transitions for a unit, from the FIXED map. Each is a grounded fact carrying its
     * trigger `from_fact` + descriptive `evidence`; NONE carries a score (anti-Goodhart: the substrate says
     * WHICH transitions exist, never which is worth more).
     *
     * @return list<array{transition:string, from_fact:string, evidence:array<string,mixed>}>
     */
    public function transitionsFor(string $fqcn): array
    {
        $fqcn = ltrim($fqcn, '\\');
        $lv = $this->levelVector($fqcn);
        $model = $this->resolveModelFor($fqcn);
        $relPath = $model !== null ? $this->relPathFor($model, $fqcn) : null;

        $out = [];
        if ($lv['orphan']) {
            $out[] = $this->transition('orphan', ['fqcn' => $fqcn, 'wired_caller_paths' => $relPath !== null ? ($model?->callerPathsFor($relPath) ?? []) : []]);
        }
        if ($lv['in_clone']) {
            $item = $model !== null ? $this->inventoryItem($model, $fqcn) : null;
            $out[] = $this->transition('in_clone', ['clone_cluster_id' => $item['clone_cluster_id'] ?? null]);
        }
        if ($lv['doc_gap_open']) {
            $out[] = $this->transition('doc_gap_open', ['doc_stated_gap' => $fqcn]);
        }
        if ($lv['gate_clean'] === false) {
            $out[] = $this->transition('gate_clean', ['fqcn' => $fqcn]);
        }
        if ($lv['has_test'] === false) {
            $out[] = $this->transition('has_test', ['fqcn' => $fqcn]);
        }

        return $out;
    }

    /**
     * Honest freshness of the memoized snapshot for a scope: stale:true (with the changed inputs) rather than
     * serving a stale photo in silence. Cheap (stat-only, no rebuild): it compares the build-time mtimes of the
     * scope's own .php files + the configured doc inputs against disk now.
     *
     * HONEST LIMITATION (consistent with the architecture: edges are a full re-grep, never per-file invalidated):
     * a change to a caller OUTSIDE the scope can shift edges/orphans without touching any watched file, so it is
     * NOT detected here. Staleness is a freshness signal for the scope's own units + doc inputs, not a proof of
     * total recompute equality.
     *
     * @return array{stale:bool, changed_units:list<string>, snapshot_age_s:int}
     */
    public function staleness(string $scopeRoot): array
    {
        $key = $this->key($scopeRoot);
        if (! isset($this->builtMeta[$key])) {
            // Nothing built yet => there is no fresh snapshot to serve => honestly stale.
            return ['stale' => true, 'changed_units' => [], 'snapshot_age_s' => 0];
        }

        $meta = $this->builtMeta[$key];
        $now = $this->inputMtimes($scopeRoot);
        $changed = [];
        foreach ($now as $rel => $mt) {
            if (! array_key_exists($rel, $meta['mtimes']) || $meta['mtimes'][$rel] !== $mt) {
                $changed[] = $rel; // added or modified since the build
            }
        }
        foreach ($meta['mtimes'] as $rel => $_) {
            if (! array_key_exists($rel, $now)) {
                $changed[] = $rel; // removed since the build
            }
        }
        $changed = array_values(array_unique($changed));
        sort($changed);

        return [
            'stale' => $changed !== [],
            'changed_units' => $changed,
            'snapshot_age_s' => max(0, $this->now() - $meta['built_at']),
        ];
    }

    /** The number of real builder delegations so far — 1 across N identical model() calls proves the memo. */
    public function buildCount(): int
    {
        return $this->buildCount;
    }

    /** Drop every memoized model + freshness meta. A request-scoped owner calls this at the top of each refill. */
    public function reset(): void
    {
        $this->memo = [];
        $this->builtMeta = [];
    }

    // --- runtime facts (SAFE defaults here; the runtime-facts step wires the real sources) -------------------

    /**
     * Does the unit have a test? Sourced from a runtime facts source that opts-in to
     * {@see ScopeRuntimeFactsWithTestPresence}, which delegates to the costed
     * {@see AtlasLoopTestPresenceOracle} (reads from {@see \App\Models\AtlasLoopTestCoverageEdge}).
     *
     * Safe degradation: when no runtime-facts source is wired, when the source doesn't implement
     * the optional extension, or when no comprehension model is available, this returns true (the
     * prior hardcoded default), so `untested->tested` stays inert and no spurious transition fires
     * on infra failure or backward-compatible callers.
     */
    private function hasTest(string $fqcn, ?AtlasLoopScopeComprehensionModel $model): bool
    {
        if ($this->runtimeFacts === null || $model === null) {
            return true;
        }
        if (! $this->runtimeFacts instanceof ScopeRuntimeFactsWithTestPresence) {
            return true; // backward-compatible runtime sources keep the prior safe default
        }
        $rel = $this->relPathFor($model, $fqcn);

        return $rel === null ? true : $this->runtimeFacts->hasTest($rel);
    }

    /** Did the unit's gate stay clean (no mutation-adequacy block)? Sourced from {@see ScopeRuntimeFacts}. */
    private function gateClean(string $fqcn, ?AtlasLoopScopeComprehensionModel $model): bool
    {
        if ($this->runtimeFacts === null || $model === null) {
            return true; // no source / no comprehension => safe "clean" (never a spurious regressed->green)
        }
        $rel = $this->relPathFor($model, $fqcn);

        return $rel === null ? true : ! $this->runtimeFacts->hasGateBlock($rel);
    }

    /** Was the unit's last merge to main clean? Sourced from {@see ScopeRuntimeFacts} (default "none recorded"). */
    private function lastMergeClean(string $fqcn, ?AtlasLoopScopeComprehensionModel $model): bool
    {
        if ($this->runtimeFacts === null || $model === null) {
            return false;
        }
        $rel = $this->relPathFor($model, $fqcn);

        return $rel !== null && $this->runtimeFacts->lastMergeClean($rel);
    }

    // --- helpers ---------------------------------------------------------------------------------------------

    /** Build ONE transition entry from the fixed map: {transition, from_fact, evidence} — never a score. */
    private function transition(string $fromFact, array $evidence): array
    {
        return [
            'transition' => self::TRANSITION_MAP[$fromFact],
            'from_fact' => $fromFact,
            'evidence' => $evidence,
        ];
    }

    /** The first memoized model whose inventory OR doc_stated_gaps contains the fqcn (the live comprehension). */
    private function resolveModelFor(string $fqcn): ?AtlasLoopScopeComprehensionModel
    {
        $fqcn = ltrim($fqcn, '\\');
        foreach ($this->memo as $model) {
            if ($this->inventoryItem($model, $fqcn) !== null || in_array($fqcn, $model->docStatedGaps, true)) {
                return $model;
            }
        }

        return null;
    }

    /** The inventory row for an fqcn, or null if the fqcn is not an inventoried symbol (e.g. a doc-stated gap). */
    private function inventoryItem(AtlasLoopScopeComprehensionModel $model, string $fqcn): ?array
    {
        $fqcn = ltrim($fqcn, '\\');
        foreach ($model->inventory as $item) {
            if (ltrim((string) $item['fqcn'], '\\') === $fqcn) {
                return $item;
            }
        }

        return null;
    }

    private function relPathFor(AtlasLoopScopeComprehensionModel $model, string $fqcn): ?string
    {
        $item = $this->inventoryItem($model, $fqcn);

        return $item !== null ? (string) $item['rel_path'] : null;
    }

    /** Normalize a scope root to the SAME shape the builder normalizes it to, so '/app/Scope/' == 'app/Scope'. */
    private function key(string $scopeRoot): string
    {
        return trim(str_replace('\\', '/', $scopeRoot), '/');
    }

    /** Wall-clock seconds. Isolated so the snapshot-age math has one source of time. */
    private function now(): int
    {
        return time();
    }

    /**
     * The build-input freshness fingerprint: relPath => mtime for the scope's .php files + the configured doc
     * inputs (the same inputs the builder reads for inventory/clones/gaps). Stat-only, fail-open (an unreadable
     * tree yields an empty map, which simply reports no change rather than throwing).
     *
     * @return array<string, int>
     */
    private function inputMtimes(string $scopeRoot): array
    {
        $repoRoot = rtrim($this->repoRoot, '/');
        $scopeRel = $this->key($scopeRoot);
        $out = [];

        foreach ($this->phpFilesUnder($repoRoot.'/'.$scopeRel) as $abs) {
            $rel = ltrim(substr($abs, strlen($repoRoot)), '/');
            $mt = @filemtime($abs);
            if ($mt !== false) {
                $out[$rel] = $mt;
            }
        }
        foreach ($this->docInputFiles($repoRoot) as $abs) {
            $rel = ltrim(substr($abs, strlen($repoRoot)), '/');
            $mt = @filemtime($abs);
            if ($mt !== false) {
                $out[$rel] = $mt;
            }
        }
        ksort($out);

        return $out;
    }

    /** @return list<string> absolute .php paths under a dir, tests/archive/vendor excluded (fail-open to []). */
    private function phpFilesUnder(string $dir): array
    {
        if (! is_dir($dir)) {
            return [];
        }
        $exclude = ['/tests/', '/Tests/', '/archive/', '/vendor/', '/node_modules/'];
        $out = [];
        try {
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $info) {
                if (! $info->isFile() || $info->getExtension() !== 'php') {
                    continue;
                }
                $abs = str_replace('\\', '/', $info->getPathname());
                foreach ($exclude as $frag) {
                    if (str_contains($abs, $frag)) {
                        continue 2;
                    }
                }
                $out[] = $abs;
            }
        } catch (Throwable) {
            return [];
        }

        return $out;
    }

    /** @return list<string> absolute .md paths for the configured docs_roots (file or dir). Fail-open to []. */
    private function docInputFiles(string $repoRoot): array
    {
        $docsRoots = array_values(array_filter((array) ($this->opts['docs_roots'] ?? []), 'is_string'));
        $out = [];
        foreach ($docsRoots as $docRel) {
            $abs = $repoRoot.'/'.ltrim(trim($docRel, '/'), '/');
            if (is_file($abs)) {
                $out[] = str_replace('\\', '/', $abs);

                continue;
            }
            if (! is_dir($abs)) {
                continue;
            }
            try {
                $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($abs, FilesystemIterator::SKIP_DOTS));
                foreach ($it as $info) {
                    if ($info->isFile() && $info->getExtension() === 'md') {
                        $out[] = str_replace('\\', '/', $info->getPathname());
                    }
                }
            } catch (Throwable) {
                // fail-open: an unreadable docs dir simply contributes no freshness inputs
            }
        }

        return $out;
    }
}
