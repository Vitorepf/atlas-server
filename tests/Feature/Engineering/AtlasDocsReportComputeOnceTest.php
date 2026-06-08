<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use App\Services\Engineering\EngineeringDocumentationHealthService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * PERF CONTRACT (no behavior change) — the two heavy documentation reports are computed ONCE
 * per request and reused by every caller, even across the DIFFERENT autowired instances one
 * POST /ai/interactions create builds.
 *
 * Before this fix, AtlasSessionBootstrapService::bootstrap() recomputed the SAME analysis many
 * times per request with identical input:
 *   - AtlasDocumentationRealitySystemService::report() ran 3x (feature-placement gate +
 *     session-bootstrap gate + cartography map), each ~9-10s; and
 *   - EngineeringDocumentationHealthService::report() ran 2x (docs gate + docs split plan).
 * That ~30s of redundant filesystem scanning + authority adjudication is what pushed the create
 * past PHP's 30s/128M web limits and fatal'd.
 *
 * The fix collapses that N -> 1 with a per-instance fast path plus a shared cache, keyed by the
 * docs corpus — the SAME proven pattern as AtlasAaeosImplementationEvidenceResolver. The two
 * services tune the SHARED tier differently and this test pins each one's actual contract:
 *   - EngineeringDocumentationHealthService::report() shares REQUEST-SCOPED (container `scoped`,
 *     reset between requests by forgetScopedInstances), so a NEW request recomputes once.
 *   - AtlasDocumentationRealitySystemService::report() shares CROSS-REQUEST (Cache::remember keyed
 *     by a stat-only corpus signature), so a new request on an UNCHANGED corpus is served from the
 *     cache store (the repeat-create speedup) and only a corpus change / cache flush recomputes —
 *     never stale, because the signature moves the instant any doc is added/edited/deleted.
 *
 * The test pins the collapse with a counting frontmatter parser: every full corpus scan parses
 * each .md doc exactly once, so a recompute would DOUBLE the parse count. We assert a second
 * report() in the same request adds ZERO parses (both services), and that each service's SHARED
 * tier behaves per its contract above.
 *
 * If the redundant recomputation were reintroduced, the parse count scales with the number of
 * report() calls — which is the regression this test exists to prevent.
 */
final class AtlasDocsReportComputeOnceTest extends TestCase
{
    public function test_documentation_reality_report_computes_once_per_request_across_instances(): void
    {
        $parser = $this->bindCountingParser();

        // FIRST caller in the request computes the full report (>=1 corpus scan -> parses run).
        $first = app(AtlasDocumentationRealitySystemService::class)->report();
        $afterFirst = $parser->calls;
        $this->assertGreaterThan(0, $afterFirst, 'the first report must actually scan + parse the corpus');

        // A SECOND, DIFFERENT autowired instance in the SAME request (exactly what the create
        // path builds: feature-placement, session-bootstrap, cartography each inject their own)
        // must reuse the request-scoped result — ZERO additional parses.
        $second = app(AtlasDocumentationRealitySystemService::class)->report();
        $this->assertSame(
            $afterFirst,
            $parser->calls,
            'a second documentation-reality report in the same request must reuse the cached '
                .'computation (no extra corpus parse) — this is the 3x -> 1x collapse',
        );

        // IDENTICAL RESULTS: the correctness contract. Same array, including the single
        // generated_at + certification_hash one computeReport() produced.
        $this->assertSame($first, $second);

        // NEXT request, UNCHANGED corpus: the report is now cached CROSS-REQUEST keyed by the
        // stat-only corpus signature (root@sha256(relpath:mtime:size)*), so clearing the
        // request-scoped container instances does NOT force a recompute — an unchanged corpus is
        // correctly served from the cross-request cache store with ZERO additional parses. This is
        // the whole point of the speedup: the second-and-later create on an unchanged corpus pays a
        // cache read, not the ~9-10s scan. (The no-stale half — a real doc add/edit/delete moves the
        // signature and recomputes — is proven in AtlasDocumentationRealityReportCacheTest.)
        $this->app->forgetScopedInstances();
        $before = $parser->calls;
        app(AtlasDocumentationRealitySystemService::class)->report();
        $this->assertSame(
            $before,
            $parser->calls,
            'an unchanged corpus must be served from the cross-request cache (no extra corpus parse) '
                .'even after the request-scoped instances are cleared — the repeat-request speedup',
        );

        // And clearing the cross-request CACHE STORE (not just the container instances) is what
        // forces a recompute — confirming the reuse above came from the cache store, and that a
        // flush/TTL-expiry/corpus-change correctly re-scans (never a permanently frozen global).
        Cache::flush();
        $beforeFlush = $parser->calls;
        app(AtlasDocumentationRealitySystemService::class)->report();
        $this->assertGreaterThan(
            $beforeFlush,
            $parser->calls,
            'flushing the cross-request cache store must recompute the report (re-parse the corpus)',
        );
    }

    public function test_documentation_health_report_computes_once_per_request_across_instances(): void
    {
        $parser = $this->bindCountingParser();

        // FIRST caller computes (the filesystem walk + per-doc frontmatter parse).
        app(EngineeringDocumentationHealthService::class)->report();
        $afterFirst = $parser->calls;
        $this->assertGreaterThan(0, $afterFirst, 'the first docs-health report must actually scan + parse the corpus');

        // SECOND, DIFFERENT instance in the SAME request reuses the cached scan — ZERO extra parses.
        $second = app(EngineeringDocumentationHealthService::class)->report();
        $this->assertSame(
            $afterFirst,
            $parser->calls,
            'a second docs-health report in the same request must reuse the cached scan '
                .'(no extra corpus parse) — this is the 2x -> 1x collapse',
        );

        // IDENTICAL RESULTS.
        $first = app(EngineeringDocumentationHealthService::class)->report();
        $this->assertSame($first, $second);

        // NEXT request recomputes exactly once (scoped reset, never stale).
        $this->app->forgetScopedInstances();
        $before = $parser->calls;
        app(EngineeringDocumentationHealthService::class)->report();
        $this->assertGreaterThan(
            $before,
            $parser->calls,
            'a new request must recompute the docs-health report (scoped cache reset)',
        );
    }

    /**
     * Bind a counting frontmatter parser as the shared singleton every report collaborator
     * (the health scan, the authority-audit scan, the source registry) resolves, so every
     * corpus parse increments one shared counter regardless of which instance ran it.
     */
    private function bindCountingParser(): CountingFrontmatterParser
    {
        $parser = new CountingFrontmatterParser;
        // class_alias makes these two names the SAME class, but they are distinct container
        // keys — bind both so every collaborator resolves the counting instance regardless of
        // which name its constructor type-hints.
        $this->app->instance(CanonicalDocsFrontmatterParser::class, $parser);
        $this->app->instance(\App\Services\Semantic\FrontmatterParser::class, $parser);

        return $parser;
    }
}

/**
 * A frontmatter parser that counts every parse() call but otherwise behaves exactly like the
 * real one — so results are byte-identical while we observe how many corpus parses happened.
 * Extends the canonical alias so PHP loads the class_alias and the subclass satisfies every
 * `CanonicalDocsFrontmatterParser` / `FrontmatterParser` type hint.
 */
final class CountingFrontmatterParser extends CanonicalDocsFrontmatterParser
{
    public int $calls = 0;

    /**
     * @return array<string,mixed>
     */
    public function parse(string $markdown): array
    {
        $this->calls++;

        return parent::parse($markdown);
    }
}
