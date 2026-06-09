<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\LocalRagPrecisionCorpusService;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use RuntimeException;
use Tests\TestCase;

/**
 * R8 — proves the HONEST independent retrieval-precision corpus.
 *
 * The point of R8 is that, unlike the pre-existing known-item lexical recall
 * test (query = the target's own title+summary), these queries are INDEPENDENT
 * of the target text, run through the REAL semantic engine, and produce a real
 * precision@k / recall@k — or an honest `attention`/unmeasured (never a
 * fabricated number) when the engine or corpus is absent.
 */
final class LocalRagPrecisionCorpusServiceTest extends TestCase
{
    public function test_real_semantic_engine_measures_precision_on_independent_queries(): void
    {
        $client = new SemanticRagRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped('semantic_rag runtime not set up — honest skip (not a fabricated score).');
        }

        // Use the REAL engine over the shipped corpus. No fabrication, no fake.
        $report = (new LocalRagPrecisionCorpusService($client))->report();

        $this->assertSame('atlas.local_rag.independent_precision_corpus_report.v1', $report['schema_version']);
        $this->assertTrue($report['measured'], json_encode($report['missing_reason'] ?? null));
        $this->assertFalse($report['unmeasured_honestly']);
        $this->assertSame('passed', $report['status']);
        $this->assertSame('real_semantic_retrieval_independent_queries', $report['evaluation_mode']);
        $this->assertGreaterThanOrEqual(15, $report['case_count']);
        $this->assertSame(0, $report['engine_error_count']);

        // REAL numbers, in range, and actually informative (> 0).
        $recallPrimary = (float) $report['metrics']['recall_at_primary_k'];
        $precisionAt1 = (float) $report['metrics']['precision_at_k']['1'];
        $this->assertGreaterThanOrEqual(0.80, $recallPrimary, 'real recall@primary_k must clear the honest floor');
        $this->assertLessThanOrEqual(1.0, $recallPrimary);
        $this->assertGreaterThanOrEqual(0.60, $precisionAt1, 'real precision@1 must clear the honest floor');
        $this->assertGreaterThan(0.0, (float) $report['metrics']['mean_reciprocal_rank']);

        // The anti-fake boundary held: real in-Python embeddings, not fabricated.
        $this->assertTrue($report['engine']['real_embeddings']);
        $this->assertFalse($report['engine']['fabricated_vectors']);
        $this->assertSame('python_ai_data', $report['engine']['runtime_family']);

        // Queries are genuinely independent of the targets.
        $this->assertTrue($report['query_independence']['independent']);
        $this->assertSame(0, $report['query_independence']['violation_count']);

        // Per-case structure carries real per-k numbers.
        $firstCase = $report['cases'][0];
        $this->assertArrayHasKey('precision_at_k', $firstCase);
        $this->assertArrayHasKey('recall_at_k', $firstCase);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $firstCase['query_hash']);
    }

    public function test_reports_attention_and_zero_when_real_engine_is_unavailable_never_fabricates(): void
    {
        // A fake engine that is NOT available => the harness must fail honest.
        $unavailable = new class implements SemanticRetrievalRuntime
        {
            public function available(): bool
            {
                return false;
            }

            public function retrieve(array $documents, string $query, int $k = 5, bool $graphExpand = false, float $graphThreshold = 0.6): array
            {
                throw new RuntimeException('must not be called when unavailable');
            }
        };

        $report = (new LocalRagPrecisionCorpusService($unavailable))->report();

        $this->assertSame('attention', $report['status']);
        $this->assertFalse($report['measured']);
        $this->assertTrue($report['unmeasured_honestly']);
        $this->assertSame('semantic_rag_runtime_unavailable', $report['missing_reason']);
        // Honest 0.0 across the board — no invented score.
        $this->assertSame(0.0, $report['metrics']['recall_at_primary_k']);
        $this->assertSame(0.0, $report['metrics']['precision_at_primary_k']);
        $this->assertSame(0.0, $report['metrics']['mean_reciprocal_rank']);
        $this->assertSame(0, $report['case_count']);
        $this->assertFalse($report['checks']['real_engine_used']);
        $this->assertTrue($report['limits']['no_fabricated_score']);
        // The independence proof still runs (it does not need the engine).
        $this->assertTrue($report['query_independence']['independent']);
        $this->assertSame('set_up_semantic_rag_runtime_then_remeasure_precision', $report['next_action']);
    }

    public function test_reports_attention_when_corpus_fixture_is_missing(): void
    {
        config()->set(
            'atlas.semantic_memory.independent_precision_corpus_path',
            storage_path('framework/testing/does-not-exist-precision-corpus.json'),
        );

        // Even with a "present" engine the harness is honest about a missing corpus.
        $engine = new class implements SemanticRetrievalRuntime
        {
            public function available(): bool
            {
                return true;
            }

            public function retrieve(array $documents, string $query, int $k = 5, bool $graphExpand = false, float $graphThreshold = 0.6): array
            {
                throw new RuntimeException('must not be called without a corpus');
            }
        };

        $report = (new LocalRagPrecisionCorpusService($engine))->report();

        $this->assertSame('attention', $report['status']);
        $this->assertFalse($report['measured']);
        $this->assertTrue($report['unmeasured_honestly']);
        $this->assertSame('corpus_fixture_missing_or_invalid', $report['missing_reason']);
        $this->assertSame(0, $report['case_count']);
    }

    public function test_deterministic_fake_engine_computes_real_precision_recall_and_mrr_math(): void
    {
        // Point at a tiny known corpus so the precision/recall/MRR arithmetic is
        // verifiable without depending on the (machine-specific) Python engine.
        $corpus = $this->writeTempCorpus([
            'k_values' => [1, 3],
            'documents' => [
                ['id' => 'd1', 'text' => 'alpha document about the first topic only'],
                ['id' => 'd2', 'text' => 'beta document about the second topic only'],
                ['id' => 'd3', 'text' => 'gamma document about the third topic only'],
            ],
            'cases' => [
                // Engine ranks the relevant doc FIRST -> P@1=1, R@1=1, rank=1.
                ['id' => 'c_hit_top', 'query' => 'find the leading subject', 'relevant_ids' => ['d1']],
                // Engine ranks the relevant doc SECOND -> P@1=0, R@3=1, rank=2.
                ['id' => 'c_hit_second', 'query' => 'locate the trailing subject', 'relevant_ids' => ['d2']],
            ],
        ]);
        config()->set('atlas.semantic_memory.independent_precision_corpus_path', $corpus);

        $engine = new class implements SemanticRetrievalRuntime
        {
            public function available(): bool
            {
                return true;
            }

            public function retrieve(array $documents, string $query, int $k = 5, bool $graphExpand = false, float $graphThreshold = 0.6): array
            {
                // Deterministic rankings keyed off the query.
                $order = str_contains($query, 'leading')
                    ? ['d1', 'd2', 'd3']
                    : ['d1', 'd2', 'd3']; // 'trailing' -> relevant d2 sits at rank 2
                $matches = array_map(static fn (string $id, int $i): array => [
                    'id' => $id,
                    'score' => 1.0 - ($i * 0.1),
                    'via' => 'semantic',
                ], $order, array_keys($order));

                return [
                    'matches' => $matches,
                    'boundary' => [
                        'real_embeddings' => true,
                        'fabricated_vectors' => false,
                        'embeddings_engine_in_python' => true,
                    ],
                ];
            }
        };

        $report = (new LocalRagPrecisionCorpusService($engine))->report();

        $this->assertTrue($report['measured']);
        // c_hit_top: P@1=1, R@1=1. c_hit_second: P@1=0, R@1=0. Mean P@1 = 0.5.
        $this->assertSame(0.5, $report['metrics']['precision_at_k']['1']);
        $this->assertSame(0.5, $report['metrics']['recall_at_k']['1']);
        // At k=3 both relevant docs are found -> mean recall@3 = 1.0.
        $this->assertSame(1.0, $report['metrics']['recall_at_k']['3']);
        // MRR = mean(1/1, 1/2) = 0.75.
        $this->assertSame(0.75, $report['metrics']['mean_reciprocal_rank']);
        // primary_k is the max declared k (3).
        $this->assertSame(3, $report['primary_k']);
        $this->assertSame(1.0, $report['metrics']['recall_at_primary_k']);
    }

    public function test_shipped_corpus_queries_are_independent_of_their_targets(): void
    {
        // The headline R8 guarantee: the SHIPPED corpus is not a lexical
        // known-item test. No query may be a substring of, or share too high a
        // token overlap with, its relevant document(s).
        $corpus = json_decode(
            (string) file_get_contents(resource_path('atlas/local_rag/independent_precision_corpus.v1.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $engine = new class implements SemanticRetrievalRuntime
        {
            public function available(): bool
            {
                return false; // independence proof does not need the engine
            }

            public function retrieve(array $documents, string $query, int $k = 5, bool $graphExpand = false, float $graphThreshold = 0.6): array
            {
                return [];
            }
        };

        $service = new LocalRagPrecisionCorpusService($engine);
        $independence = $service->queryIndependenceReport(
            $corpus['documents'],
            $corpus['cases'],
            (float) $corpus['independence_contract']['max_token_overlap'],
        );

        $this->assertTrue(
            $independence['independent'],
            'Shipped corpus has lexical violations: '.json_encode($independence['violations']),
        );
        $this->assertSame(0, $independence['violation_count']);
        $this->assertGreaterThan(0, $independence['checked_pair_count']);
        $this->assertLessThanOrEqual(
            (float) $corpus['independence_contract']['max_token_overlap'],
            (float) $independence['max_observed_token_overlap'],
        );
    }

    public function test_independence_guard_catches_a_lexical_substring_query(): void
    {
        // Anti-Goodhart: prove the guard actually bites. A query copied from the
        // target text MUST be flagged (this is exactly the tautological pattern
        // R8 exists to forbid).
        $engine = new class implements SemanticRetrievalRuntime
        {
            public function available(): bool
            {
                return false;
            }

            public function retrieve(array $documents, string $query, int $k = 5, bool $graphExpand = false, float $graphThreshold = 0.6): array
            {
                return [];
            }
        };

        $service = new LocalRagPrecisionCorpusService($engine);

        $documents = [
            ['id' => 'd1', 'text' => 'git revert creates a fresh commit that cancels out an earlier one'],
        ];
        $cheatingCases = [
            // Verbatim substring of the target -> tautological -> must be flagged.
            ['id' => 'c_cheat', 'query' => 'git revert creates a fresh commit', 'relevant_ids' => ['d1']],
        ];

        $independence = $service->queryIndependenceReport($documents, $cheatingCases);

        $this->assertFalse($independence['independent']);
        $this->assertSame(1, $independence['violation_count']);
        $this->assertSame('query_is_substring_of_target', $independence['violations'][0]['reason']);
    }

    /**
     * @param  array<string,mixed>  $corpus
     */
    private function writeTempCorpus(array $corpus): string
    {
        $corpus['schema_version'] ??= 'atlas.local_rag.independent_precision_corpus.v1';
        $corpus['corpus_id'] ??= 'temp_test_corpus';
        $path = tempnam(sys_get_temp_dir(), 'r8corpus').'.json';
        file_put_contents($path, json_encode($corpus, JSON_THROW_ON_ERROR));

        return $path;
    }
}
