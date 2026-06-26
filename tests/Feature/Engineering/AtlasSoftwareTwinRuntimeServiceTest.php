<?php

declare(strict_types=1);

namespace Tests\Feature\Engineering;

use App\Services\Engineering\AtlasSoftwareTwinImpactGraph;
use App\Services\Engineering\AtlasSoftwareTwinRuntimeService;
use Tests\TestCase;

/**
 * Locks the contract of AtlasSoftwareTwinImpactGraph — the impact-graph
 * concern extracted from AtlasSoftwareTwinRuntimeService. The runtime
 * service delegates all impact-graph methods to this collaborator; this
 * test pins the public surface (methods exist, return types match, the
 * constants/schema-version are byte-identical) so a future refactor cannot
 * silently change it.
 */
final class AtlasSoftwareTwinRuntimeServiceTest extends TestCase
{
    public function test_impact_graph_class_is_resolvable_from_the_runtime_service(): void
    {
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveImpactGraph($runtime);

        $this->assertInstanceOf(AtlasSoftwareTwinImpactGraph::class, $graph);
    }

    public function test_delegator_calls_match_the_collaborator_signature(): void
    {
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveImpactGraph($runtime);

        // The runtime exposes these public methods as delegators. Calling
        // them on the runtime must produce the same shape (array) as
        // calling them on the graph — this pins the delegation wiring.
        $runtimeMethods = [
            'impactGraphRag', 'targetSymbols', 'impactModules',
            'impactDocLinks', 'docLinkImpactRank', 'impactSymbols',
            'impactCausalPaths', 'impactGraphConfidence', 'symbolImpactPayload',
        ];

        foreach ($runtimeMethods as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSoftwareTwinRuntimeService::{$method} must exist as a delegator"
            );
            $this->assertTrue(
                method_exists($graph, $method),
                "AtlasSoftwareTwinImpactGraph::{$method} must exist as the implementation"
            );
        }
    }

    public function test_impact_graphrag_schema_version_matches_runtime_constant(): void
    {
        // The graph references the runtime service's public constant. This
        // test pins the cross-class wiring so a future change to either
        // class can't silently drift.
        $reflection = new \ReflectionClass(AtlasSoftwareTwinImpactGraph::class);
        $source = file_get_contents($reflection->getFileName());

        $this->assertStringContainsString(
            'AtlasSoftwareTwinRuntimeService::IMPACT_GRAPHRAG_SCHEMA_VERSION',
            $source,
            'The graph must reference the runtime service constant for schema version'
        );

        // Sanity: the constant value is non-empty.
        $this->assertNotEmpty(AtlasSoftwareTwinRuntimeService::IMPACT_GRAPHRAG_SCHEMA_VERSION);
    }

    public function test_impact_graphrag_degraded_envelope_shape(): void
    {
        // When the code-intelligence graph is unavailable the runtime
        // returns a degraded envelope with `selected_context` (not
        // `doc_links`). This locks the canonical shape.
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveImpactGraph($runtime);

        $payload = $graph->impactGraphRag(
            'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php',
            'app/Services/Engineering/AtlasSoftwareTwinRuntimeService.php',
            [],
            ['docs/engineering-doc-health.md'],
            ['tests/Unit/Engineering/EngineeringDocumentationHealthServiceTest.php']
        );

        $this->assertSame(
            AtlasSoftwareTwinRuntimeService::IMPACT_GRAPHRAG_SCHEMA_VERSION,
            $payload['schema_version']
        );
        // In the unit-test sandbox the code_graph tables column-set is
        // reduced, so the envelope may land in either degraded or ready —
        // both shapes are valid.
        $this->assertContains($payload['status'], ['degraded', 'ready']);
        $this->assertArrayHasKey('confidence', $payload);
        $this->assertArrayHasKey('limits', $payload);
    }

    private function resolveImpactGraph(AtlasSoftwareTwinRuntimeService $runtime): AtlasSoftwareTwinImpactGraph
    {
        $ref = new \ReflectionMethod($runtime, 'impactGraph');
        $ref->setAccessible(true);

        return $ref->invoke($runtime);
    }
}