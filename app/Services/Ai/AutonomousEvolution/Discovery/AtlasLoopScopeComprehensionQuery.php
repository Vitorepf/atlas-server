<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

/**
 * PART 1 · P1-A — the in-request memoizing front-door to the scope-comprehension MODEL.
 *
 * WHY THIS EXISTS (the foundation, highest-leverage, zero migration): a single refill used to rebuild the
 * model once PER supply lane — the dedup lane, the orphan-wiring lane and the doc-gap lane each `new`-ed a
 * builder and called the ~9s `build()` for the SAME scope. Three identical builds (~27s) for one refill.
 * This query shares ONE memoized model per (scopeRoot, opts) for the lifetime of the request, so lanes with
 * identical inputs pay the build cost once.
 *
 * THE PÉTREO INVARIANT IT INHERITS: the query is a pure pass-through cache over
 * {@see AtlasLoopScopeComprehensionModelBuilder}. The model it serves is BYTE-IDENTICAL to a fresh build — it
 * adds no field, drops no field, and emits NO scalar/rank of its own. "Comprehension emits FACTS, never a
 * number" is preserved because the query never computes a fact; it only memoizes the builder's facts.
 *
 * HONEST MEMO KEY: the cache key is the (normalized) scopeRoot. repoRoot + opts are fixed per query instance,
 * so two lanes that build with DIFFERENT opts (the doc-gap lane uses `docs_roots`, the dedup/orphan lanes do
 * not — a genuinely different model with different `doc_stated_gaps`) hold SEPARATE query instances and are
 * never collapsed. The cache is scoped to the request: a long-lived owner (e.g. the singleton refiller) MUST
 * call {@see reset()} at the top of each refill so the model can never be served stale across refills.
 */
final class AtlasLoopScopeComprehensionQuery
{
    /** @var array<string, AtlasLoopScopeComprehensionModel> scopeRoot-key => memoized model (this request only) */
    private array $memo = [];

    /** How many times this query actually delegated to the (expensive) builder — observability + the memo proof. */
    private int $buildCount = 0;

    /**
     * @param  array{docs_roots?:list<string>, max_files?:int}  $opts  the build options, FIXED for this query instance
     */
    public function __construct(
        private readonly AtlasLoopScopeComprehensionModelBuilder $builder,
        private readonly string $repoRoot,
        private readonly array $opts = [],
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
            $this->buildCount++;
        }

        return $this->memo[$key];
    }

    /** The number of real builder delegations so far — 1 across N identical model() calls proves the memo. */
    public function buildCount(): int
    {
        return $this->buildCount;
    }

    /** Drop every memoized model. A request-scoped owner calls this at the top of each refill (anti-stale). */
    public function reset(): void
    {
        $this->memo = [];
    }

    /** Normalize a scope root to the SAME shape the builder normalizes it to, so '/app/Scope/' == 'app/Scope'. */
    private function key(string $scopeRoot): string
    {
        return trim(str_replace('\\', '/', $scopeRoot), '/');
    }
}
