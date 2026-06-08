<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasDocumentationRealitySystemService;
use ReflectionMethod;
use Tests\TestCase;

/**
 * FAIL-ON-STUB flip proof for the Batch-B promotion of 'canonical_question_router' in ADRS
 * (AtlasDocumentationRealitySystemService). The evaluator was a PARTIAL proxy: it returned the
 * distinct owners of the canonical sources as "route targets" (owner-presence, not routing). It is
 * now an EXECUTES evaluator that computes its FULL declared verb — every CANONICAL_QUESTIONS entry
 * is RESOLVED against the live source registry to its owner doc + cartography node (graph_id read
 * from real frontmatter) + content-hash evidence, and the status is DERIVED from that resolution.
 *
 * The promotion is REAL only if mutating the input flips the verdict — a constant cannot flip.
 * The integration test asserts every canonical question fully resolves over the REAL corpus and
 * that the block is classified executes with integration_evidence. The flip test drives the real
 * private resolver with a registry where one canonical answer doc is absent / un-owned, and
 * asserts the resolved count drops and the status degrades to 'review'. Reverting the method to
 * the old owner-presence proxy (which never resolves per-question) makes the planted gap invisible
 * and FAILS these tests — that divergence is the anti-stub / anti-tautology proof.
 *
 * sqlite :memory:, extends Tests\TestCase, NO RefreshDatabase, no DB tables touched (the router
 * reads only the canonical source registry + the docs' frontmatter on disk).
 */
final class AtlasDocumentationRealityCanonicalQuestionRouterFlipTest extends TestCase
{
    public function test_router_executes_its_full_verb_over_the_real_corpus(): void
    {
        $report = app(AtlasDocumentationRealitySystemService::class)->report();
        $eval = $report['evaluations']['canonical_question_router'];

        // FULL VERB: every canonical question resolves to owner doc + cartography node + evidence.
        $this->assertSame('ready', $eval['status']);
        $this->assertGreaterThanOrEqual(6, $eval['canonical_question_count']);
        $this->assertSame($eval['canonical_question_count'], $eval['resolved_route_count']);

        foreach ($eval['routes'] as $route) {
            $this->assertTrue($route['routable'], 'every canonical question must route over the real corpus: '.$route['question']);
            $this->assertNotNull($route['owner_doc'], 'route resolves a real owner doc');
            $this->assertNotNull($route['owner']);
            $this->assertNotNull($route['cartography_node'], 'route resolves a real cartography node (graph_id)');
            $this->assertNotNull($route['evidence_ref'], 'route carries content-hash evidence');
        }

        // The block is classified executes (not partial) and carries integration_evidence.
        $block = collect($report['blocks'])->firstWhere('name', 'Canonical Question Router');
        $this->assertNotNull($block);
        $this->assertSame('executes', $block['execution']);
        $this->assertNotNull($block['integration_evidence']);
    }

    public function test_router_verdict_flips_when_a_canonical_answer_doc_is_absent_or_unowned(): void
    {
        $service = app(AtlasDocumentationRealitySystemService::class);
        $sources = $service->report()['source_registry'];

        $resolve = new ReflectionMethod($service, 'canonicalQuestionRouterEvaluation');
        $resolve->setAccessible(true);

        $full = $resolve->invoke($service, $sources);
        $this->assertSame('ready', $full['status']);
        $this->assertSame($full['canonical_question_count'], $full['resolved_route_count']);

        // FLIP A — remove a canonical answer doc from the registry: its question goes unroutable
        // and the DERIVED status degrades. The old owner-presence proxy (distinct owners non-empty)
        // would stay 'ready' here, so this assertion is what fails on a revert to the stub.
        $withoutAdrs = array_values(array_filter(
            $sources,
            static fn (array $source): bool => ($source['id'] ?? null) !== 'adrs',
        ));
        $degraded = $resolve->invoke($service, $withoutAdrs);
        $this->assertSame('review', $degraded['status'], 'a missing canonical answer doc must degrade the router');
        $this->assertLessThan($full['resolved_route_count'], $degraded['resolved_route_count']);

        // FLIP B — un-own a present canonical answer doc: presence alone is not the verb; the route
        // needs a resolvable owner. The proxy (which only counts distinct owners) cannot see this.
        $unowned = array_map(
            static function (array $source): array {
                if (($source['id'] ?? null) === 'acrui') {
                    $source['owner'] = '';
                }

                return $source;
            },
            $sources,
        );
        $unownedEval = $resolve->invoke($service, array_values($unowned));
        $this->assertSame('review', $unownedEval['status'], 'an un-owned canonical answer doc must degrade the router');
        $this->assertLessThan($full['resolved_route_count'], $unownedEval['resolved_route_count']);
    }
}
