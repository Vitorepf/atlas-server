<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\AtlasContextRankingSystemService;
use App\Services\Ai\Context\AtlasHybridRetrievalInfrastructureService;
use App\Services\Ai\Programming\ProgrammingProfessionalReranker;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use RuntimeException;
use Tests\TestCase;

/**
 * AUCRI `semantic_candidate` real-embedding wiring (the manifest-only placeholder
 * is now upgraded to REAL cosine scores when the local semantic_rag runtime is
 * available, and degrades honest — byte-identical placeholder — when it is not).
 */
final class AucriLocalSemanticScoringTest extends TestCase
{
    private const OBJECTIVE = 'corrigir bug no repo com teste falhando e evidence replay';

    public function test_available_runtime_scores_semantic_candidates_with_real_cosine_and_stamps_origin(): void
    {
        config(['atlas.aucri.local_semantic_scoring' => true]);
        $fake = new FakeSemanticRetrievalRuntime(available: true, score: 0.91);
        $this->app->instance(SemanticRetrievalRuntime::class, $fake);

        $report = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => self::OBJECTIVE,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
        ]);

        $semantic = collect(data_get($report, 'retrieval_report.candidates'))
            ->where('source_type', 'semantic_candidate')
            ->values();

        $this->assertNotEmpty($semantic, 'expected at least one semantic_candidate');
        foreach ($semantic as $candidate) {
            $this->assertSame(0.91, $candidate['score_hint']);
            $this->assertSame('local_semantic_vector', $candidate['score_origin']);
        }

        // Batched: exactly ONE retrieve() per report, carrying ALL semantic chunks.
        $this->assertSame(1, $fake->retrieveCalls);
        $this->assertCount(count($semantic), $fake->documentBatches[0]);

        // Raw objective text must never leak into the report payload.
        $this->assertStringNotContainsString(
            'corrigir bug no repo',
            json_encode($report, JSON_THROW_ON_ERROR),
        );
    }

    public function test_unavailable_runtime_keeps_placeholder_byte_identical(): void
    {
        // Path 1: feature ON but runtime unavailable (must never call retrieve()).
        config(['atlas.aucri.local_semantic_scoring' => true]);
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new FakeSemanticRetrievalRuntime(available: false, score: 0.91),
        );
        $unavailable = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => self::OBJECTIVE,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
        ]);

        // Path 2: feature OFF — the pre-wiring placeholder behavior.
        config(['atlas.aucri.local_semantic_scoring' => false]);
        $placeholder = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => self::OBJECTIVE,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
        ]);

        // Byte-identical modulo the volatile timestamp: the report hash is computed
        // over the full payload with generated_at excluded.
        $this->assertSame($placeholder['retrieval_report_hash'], $unavailable['retrieval_report_hash']);

        $semantic = collect(data_get($unavailable, 'retrieval_report.candidates'))
            ->where('source_type', 'semantic_candidate')
            ->values();
        $this->assertNotEmpty($semantic);
        foreach ($semantic as $candidate) {
            $this->assertSame(0.60, $candidate['score_hint']);
            $this->assertArrayNotHasKey('score_origin', $candidate);
        }
    }

    public function test_runtime_failure_degrades_honest_to_placeholder(): void
    {
        config(['atlas.aucri.local_semantic_scoring' => true]);
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new FakeSemanticRetrievalRuntime(available: true, score: 0.91, failRetrieve: true),
        );

        $report = app(AtlasHybridRetrievalInfrastructureService::class)->report([
            'objective' => self::OBJECTIVE,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
        ]);

        $semantic = collect(data_get($report, 'retrieval_report.candidates'))
            ->where('source_type', 'semantic_candidate')
            ->values();
        $this->assertNotEmpty($semantic);
        foreach ($semantic as $candidate) {
            $this->assertSame(0.60, $candidate['score_hint']);
            $this->assertArrayNotHasKey('score_origin', $candidate);
        }
    }

    public function test_ranking_uses_real_semantic_score_and_semantic_channel_bonus_applies_only_then(): void
    {
        // Real path.
        config(['atlas.aucri.local_semantic_scoring' => true]);
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new FakeSemanticRetrievalRuntime(available: true, score: 0.91),
        );
        $realPayload = app(AtlasContextRankingSystemService::class)->rank([
            'objective' => self::OBJECTIVE,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 12,
        ]);

        // Placeholder path (runtime unavailable).
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new FakeSemanticRetrievalRuntime(available: false, score: 0.91),
        );
        $placeholderPayload = app(AtlasContextRankingSystemService::class)->rank([
            'objective' => self::OBJECTIVE,
            'task_type' => 'debug',
            'domain' => 'developer',
            'risk_level' => 'low',
            'max_refs' => 12,
        ]);

        $realRef = collect(data_get($realPayload, 'rerank_result.selected_refs'))
            ->firstWhere('source_type', 'semantic_candidate');
        $placeholderRef = collect(data_get($placeholderPayload, 'rerank_result.selected_refs'))
            ->firstWhere('source_type', 'semantic_candidate');

        $this->assertNotNull($realRef, 'semantic_candidate must be selected on the real path');
        $this->assertNotNull($placeholderRef, 'semantic_candidate must be selected on the placeholder path');

        // The semantic component IS the real cosine score (vs the static manifest 0.60).
        $this->assertSame(0.91, data_get($realRef, 'score_components.semantic'));
        $this->assertSame(0.60, data_get($placeholderRef, 'score_components.semantic'));

        // Reranker semantic-channel bonus applies ONLY on the real path:
        // professional_rerank = min(1, reranker_score / 2);
        // real: (0.91 base + 0.22 local_semantic_vector bonus) / 2 = 0.565
        // placeholder: 0.60 base, no channel bonus            / 2 = 0.30
        $this->assertSame(0.565, data_get($realRef, 'score_components.professional_rerank'));
        $this->assertSame(0.30, data_get($placeholderRef, 'score_components.professional_rerank'));

        // Raw text never leaks on either path.
        $this->assertStringNotContainsString('corrigir bug no repo', json_encode($realPayload, JSON_THROW_ON_ERROR));
    }

    public function test_reranker_grants_semantic_bonus_to_local_semantic_vector_channel_only(): void
    {
        $reranker = new ProgrammingProfessionalReranker;

        $score = function (string $channel) use ($reranker): float {
            $result = $reranker->rerank(
                refs: [[
                    'source' => 'semantic_candidate',
                    'ref' => 'objective://query',
                    'score' => 0.91,
                    'reason' => 'asef_candidate_manifest_chunk',
                    'retrieval_channel' => $channel,
                ]],
                requiredSources: [],
                flow: 'programming.dev',
                maxRefs: 4,
            );

            return (float) data_get($result, 'ranked_refs.0.score');
        };

        $this->assertSame(1.13, $score('local_semantic_vector'));
        $this->assertSame(0.91, $score('manifest_pending_embedding'));
    }
}

/**
 * Deterministic in-process fake of the local semantic retrieval boundary.
 * When constructed unavailable, retrieve() throws — proving callers honor the
 * available() contract and never invoke an unavailable runtime.
 */
final class FakeSemanticRetrievalRuntime implements SemanticRetrievalRuntime
{
    public int $retrieveCalls = 0;

    /** @var array<int,array<int,array<string,mixed>>> */
    public array $documentBatches = [];

    public function __construct(
        private readonly bool $available,
        private readonly float $score,
        private readonly bool $failRetrieve = false,
    ) {}

    public function available(): bool
    {
        return $this->available;
    }

    public function retrieve(
        array $documents,
        string $query,
        int $k = 5,
        bool $graphExpand = false,
        float $graphThreshold = 0.6,
    ): array {
        if (! $this->available) {
            throw new RuntimeException('retrieve() called on an unavailable runtime — contract violation.');
        }

        if ($this->failRetrieve) {
            throw new RuntimeException('simulated runtime failure');
        }

        $this->retrieveCalls++;
        $this->documentBatches[] = $documents;

        $matches = [];
        foreach ($documents as $document) {
            $matches[] = [
                'id' => (string) ($document['id'] ?? ''),
                'score' => $this->score,
                'via' => 'semantic',
            ];
        }

        return ['matches' => $matches];
    }
}
