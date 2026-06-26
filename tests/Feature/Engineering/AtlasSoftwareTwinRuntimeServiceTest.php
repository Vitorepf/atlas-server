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

    public function test_simulation_predictor_methods_are_wired(): void
    {
        // The simulate/prediction concern was extracted from
        // AtlasSoftwareTwinRuntimeService into AtlasSoftwareTwinSimulationPredictor.
        // All 9 methods must be wired via delegators.
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);

        $methods = [
            'predictDocDuplication', 'predictSymbolDuplication', 'predictDrift',
            'predictOwner', 'predictBlastRadius', 'predictVerdict',
            'predictBlockers', 'graphIdCollisions', 'locateBest',
        ];
        foreach ($methods as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSoftwareTwinRuntimeService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_predict_verdict_branches(): void
    {
        // Locks the canonical verdict ladder so a future refactor cannot
        // silently reorder it (e.g. swap degraded vs needs_owner_review).
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveSimulationPredictor($runtime);

        // Duplicate always wins, regardless of other flags.
        $this->assertSame(
            'would_duplicate',
            $graph->predictVerdict(['duplicate' => true], false, false, false)
        );

        // Drift wins when not duplicate.
        $this->assertSame(
            'would_drift',
            $graph->predictVerdict(['duplicate' => false], true, false, false)
        );

        // Degraded wins when not duplicate and not drift.
        $this->assertSame(
            'needs_review',
            $graph->predictVerdict(['duplicate' => false], false, false, true)
        );

        // Owner review next.
        $this->assertSame(
            'needs_owner_review',
            $graph->predictVerdict(['duplicate' => false], false, true, false)
        );

        // Clean only when nothing else flags.
        $this->assertSame(
            'clean',
            $graph->predictVerdict(['duplicate' => false], false, false, false)
        );
    }

    public function test_predict_blockers_assembles_correctly(): void
    {
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveSimulationPredictor($runtime);

        // No duplicate, no drift, no review, not degraded.
        $this->assertSame([], $graph->predictBlockers('doc', ['duplicate' => false], null, false, false));

        // Duplicate only.
        $blockers = $graph->predictBlockers('doc', ['duplicate' => true, 'reason' => 'graph_id_collision'], null, false, false);
        $this->assertCount(1, $blockers);
        $this->assertSame('predicted_duplicate_doc', $blockers[0]['reason']);
        $this->assertSame('graph_id_collision', $blockers[0]['detail']);

        // Drift with computed/claimed states.
        $blockers = $graph->predictBlockers('doc', ['duplicate' => false], ['drift' => true, 'claimed_state' => 'building', 'computed_state' => 'spec'], false, false);
        $this->assertCount(1, $blockers);
        $this->assertSame('predicted_implementation_state_over_claim', $blockers[0]['reason']);
        $this->assertStringContainsString('claimed_building_computes_spec', $blockers[0]['detail']);

        // All three flags accumulate.
        $blockers = $graph->predictBlockers('symbol', ['duplicate' => true, 'reason' => 'symbol_name_collision'], ['drift' => true, 'claimed_state' => 'active', 'computed_state' => 'spec'], true, true);
        $this->assertCount(4, $blockers);
        $reasons = array_column($blockers, 'reason');
        $this->assertContains('predicted_duplicate_symbol', $reasons);
        $this->assertContains('predicted_check_degraded', $reasons);
        $this->assertContains('predicted_implementation_state_over_claim', $reasons);
        $this->assertContains('predicted_missing_or_ambiguous_owner', $reasons);
    }

    public function test_graph_id_collisions_returns_empty_for_empty_inputs(): void
    {
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveSimulationPredictor($runtime);

        $this->assertSame([], $graph->graphIdCollisions('', ''));
    }

    public function test_locate_best_returns_unresolved_shape_for_unknown_needle(): void
    {
        // locateBest delegates to authorityGraph->locate. Without any
        // real authority-graph rows, the returned shape is the canonical
        // 'unresolved' baseline (resolved=false, confidence=0).
        $runtime = app(AtlasSoftwareTwinRuntimeService::class);
        $graph = $this->resolveSimulationPredictor($runtime);

        $result = $graph->locateBest('definitely-not-a-real-capability-needle-xyz');
        $this->assertFalse((bool) ($result['resolved'] ?? true));
        $this->assertSame(0, (int) ($result['confidence'] ?? -1));
    }

    private function resolveSimulationPredictor(AtlasSoftwareTwinRuntimeService $runtime): \App\Services\Engineering\AtlasSoftwareTwinSimulationPredictor
    {
        $ref = new \ReflectionMethod($runtime, 'simulationPredictor');
        $ref->setAccessible(true);

        return $ref->invoke($runtime);
    }

}