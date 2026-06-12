<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasHybridRetrievalInfrastructureService;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Tests\TestCase;

/**
 * L3-5 LIVE anti-fake proof at the AUCRI PHP -> Python boundary.
 *
 * The sibling AucriLocalSemanticScoringTest proves the WIRING contract with an
 * in-process fake. This test instead binds the REAL SemanticRagRuntimeClient and
 * drives the full AtlasHybridRetrievalInfrastructureService::report() path, proving:
 *
 *   1. through-path liveness: the AUCRI report's semantic_candidate carries a REAL
 *      cosine score stamped score_origin=local_semantic_vector — i.e. the static
 *      0.60 manifest placeholder was genuinely replaced by the live Python engine;
 *   2. the anti-fake discrimination property at the client the AUCRI path consumes:
 *      a related document out-ranks an unrelated one for the same query, which only
 *      holds with genuine local embeddings (the placeholder cannot discriminate).
 *
 * The AUCRI report itself scores each objective-chunk against the SAME objective
 * (self-similarity ~1.0 by construction), so the discrimination property is proven
 * at the boundary the report calls — SemanticRetrievalRuntime::retrieve() — with a
 * query distinct from its documents, which is exactly what the report invokes.
 *
 * Honest gate: when the semantic_rag venv is not set up the test skips (it cannot
 * fake a live lift). A skip is a true "incomplete", never a green-by-pretense.
 */
final class AucriLiveSemanticBoundaryTest extends TestCase
{
    public function test_aucri_report_carries_a_real_cosine_not_the_static_placeholder(): void
    {
        config(['atlas.aucri.local_semantic_scoring' => true]);

        $client = $this->liveClientOrSkip();
        $this->app->instance(SemanticRetrievalRuntime::class, $client);
        $this->app->instance(SemanticRagRuntimeClient::class, $client);

        $service = app(AtlasHybridRetrievalInfrastructureService::class);

        $report = $service->report([
            'objective' => 'fix the failing unit test in the authentication module and replay the evidence ledger',
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
        ]);

        $real = collect(data_get($report, 'retrieval_report.candidates'))
            ->where('source_type', 'semantic_candidate')
            ->where('score_origin', 'local_semantic_vector')
            ->values();

        $this->assertNotEmpty(
            $real,
            'the live engine must replace the static 0.60 placeholder with a real-scored semantic_candidate'
        );

        foreach ($real as $candidate) {
            // A genuine cosine in [0,1]; and provably NOT the static 0.60 manifest stub.
            $score = (float) $candidate['score_hint'];
            $this->assertGreaterThanOrEqual(0.0, $score);
            $this->assertLessThanOrEqual(1.0, $score);
            $this->assertNotSame(0.60, $score, 'a real cosine must not equal the static manifest placeholder');
        }
    }

    public function test_real_embeddings_discriminate_related_from_unrelated_through_the_aucri_boundary(): void
    {
        $client = $this->liveClientOrSkip();

        // The exact boundary the AUCRI report invokes: SemanticRetrievalRuntime::retrieve()
        // with a query distinct from its documents. Real embeddings rank the related
        // document first despite NON-overlapping vocabulary (anti-lexical-fake proof).
        $result = $client->retrieve(
            documents: [
                ['id' => 'related', 'text' => 'authenticate the user session and validate the login token'],
                ['id' => 'unrelated', 'text' => 'the quarterly harvest of wheat exceeded the autumn forecast'],
            ],
            query: 'sign in flow credential check',
            k: 2,
        );

        $matches = collect((array) ($result['matches'] ?? []))->keyBy('id');
        $this->assertTrue($result['boundary']['real_embeddings'] ?? false);
        $this->assertFalse($result['boundary']['fabricated_vectors'] ?? true);
        $this->assertGreaterThan(
            (float) data_get($matches, 'unrelated.score'),
            (float) data_get($matches, 'related.score'),
            'real embeddings must rank the semantically related doc above the unrelated one'
        );
    }

    private function liveClientOrSkip(): SemanticRagRuntimeClient
    {
        $client = new SemanticRagRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped(
                'semantic_rag venv not set up (scripts/setup-semantic-rag-runtime.sh) — '
                .'live semantic lift cannot be proven without the engine; honest skip, not a fake.'
            );
        }

        return $client;
    }
}
