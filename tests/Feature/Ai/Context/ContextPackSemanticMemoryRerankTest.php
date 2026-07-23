<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Memory\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\RuntimeBoundary\SemanticCrossEncoderRuntime;
use App\Services\Ai\RuntimeBoundary\SemanticLateInteractionRuntime;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * L3-6 wiring proof: the context-pack memory section honours the semantic
 * re-rank flag and fail-opens.
 *
 * The memory recall is stubbed (the pack's source-fusion is locked elsewhere);
 * here we isolate that when atlas.aobg.semantic_retrieval is ON and the engine
 * returns a real receipt, the recalled items are REORDERED by semantic score and
 * the memory provenance honestly reports retrieval_mode=semantic — and when the
 * flag is OFF the original recall order and mode=lexical stand.
 */
final class ContextPackSemanticMemoryRerankTest extends TestCase
{
    /**
     * Recall order: crop, auth, cache. Fidelidade ao runtime: o recall híbrido REAL sempre
     * entrega `score` — e é o score que autoriza a entrega pelo floor de relevância do pack
     * (item sem score cai no floor lexical; ver AtlasOpenBrainContextPackService).
     *
     * @var array<int,array<string,mixed>>
     */
    private const RECALL = [
        ['title' => 'crop note', 'summary' => 'autumn wheat harvest revenue forecast', 'body' => '', 'type' => 'technical_context', 'scope' => 'global', 'source_type' => 'memory_entry', 'content_hash' => 'h_crop', 'privacy_class' => 'normal', 'score' => 12.5],
        ['title' => 'auth note', 'summary' => 'validate user login session and credential token', 'body' => '', 'type' => 'decision', 'scope' => 'global', 'source_type' => 'memory_entry', 'content_hash' => 'h_auth', 'privacy_class' => 'normal', 'score' => 11.0],
        ['title' => 'cache note', 'summary' => 'evict stale entries from the lookup cache', 'body' => '', 'type' => 'technical_context', 'scope' => 'global', 'source_type' => 'memory_entry', 'content_hash' => 'h_cache', 'privacy_class' => 'normal', 'score' => 9.75],
    ];

    private function bindMemory(): void
    {
        $memory = Mockery::mock(AtlasHybridMemoryRetrievalService::class);
        $memory->shouldReceive('recall')->andReturn([
            'recall' => self::RECALL,
            'summary' => ['policy' => 'provider_safe_only', 'recall_count' => 3, 'redacted_ref_count' => 0],
        ]);
        $this->app->instance(AtlasHybridMemoryRetrievalService::class, $memory);
    }

    public function test_flag_off_keeps_recall_order_and_reports_lexical(): void
    {
        config(['atlas.aobg.semantic_retrieval' => false]);
        $this->bindMemory();
        // Engine that would reorder if consulted — proving OFF means untouched.
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new RerankSpyRuntime(available: true, realScores: ['mem_1' => 0.9, 'mem_0' => 0.1, 'mem_2' => 0.5]),
        );

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor('sign in credential flow', [
            'workspace' => 'atlas-server',
        ]);

        $titles = array_column((array) data_get($pack, 'memory'), 'title');
        $this->assertSame(['crop note', 'auth note', 'cache note'], $titles, 'flag off must keep recall order');
        $this->assertSame('lexical', data_get($pack, 'provenance.memory.retrieval_mode'));
    }

    public function test_flag_on_with_real_receipt_reorders_by_semantic_score(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        $this->bindMemory();
        // mem_0=crop, mem_1=auth, mem_2=cache. Make auth the top semantic hit.
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new RerankSpyRuntime(available: true, realScores: ['mem_1' => 0.92, 'mem_2' => 0.40, 'mem_0' => 0.08]),
        );

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor('sign in credential flow', [
            'workspace' => 'atlas-server',
        ]);

        $titles = array_column((array) data_get($pack, 'memory'), 'title');
        $this->assertSame(['auth note', 'cache note', 'crop note'], $titles, 'semantic score must reorder memory items');
        $this->assertSame('semantic', data_get($pack, 'provenance.memory.retrieval_mode'));
    }

    public function test_flag_on_but_engine_unavailable_fails_open_to_recall_order(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        $this->bindMemory();
        $this->app->instance(SemanticRetrievalRuntime::class, new RerankSpyRuntime(available: false));

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor('sign in credential flow', [
            'workspace' => 'atlas-server',
        ]);

        $titles = array_column((array) data_get($pack, 'memory'), 'title');
        $this->assertSame(['crop note', 'auth note', 'cache note'], $titles);
        $this->assertSame('lexical', data_get($pack, 'provenance.memory.retrieval_mode'));
    }

    public function test_late_interaction_stage_reranks_top_window_to_configured_top_k(): void
    {
        config([
            'atlas.aobg.semantic_retrieval' => false,
            'atlas.aobg.late_interaction_rerank' => true,
            'atlas.aobg.late_interaction_candidate_window' => 20,
            'atlas.aobg.late_interaction_top_k' => 2,
        ]);
        $this->bindMemory();
        // mem_0=crop, mem_1=auth, mem_2=cache. The stage should return only top-2.
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new RerankSpyRuntime(
                available: true,
                lateInteractionScores: ['mem_2' => 0.99, 'mem_1' => 0.90, 'mem_0' => 0.10],
            ),
        );

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor('stale lookup cache credentials', [
            'workspace' => 'atlas-server',
        ]);

        $titles = array_column((array) data_get($pack, 'memory'), 'title');
        $this->assertSame(['cache note', 'auth note'], $titles);
        $this->assertSame('late_interaction', data_get($pack, 'provenance.memory.retrieval_mode'));
    }

    public function test_cross_encoder_stage_reranks_top_window_to_precision3(): void
    {
        config([
            'atlas.aobg.semantic_retrieval' => false,
            'atlas.aobg.cross_encoder_rerank' => true,
            'atlas.aobg.cross_encoder_candidate_window' => 30,
            'atlas.aobg.cross_encoder_top_k' => 3,
            'atlas.aobg.late_interaction_rerank' => false,
        ]);
        $this->bindMemory();
        // mem_0=crop, mem_1=auth, mem_2=cache. Cross-encoder should produce top-3.
        $this->app->instance(
            SemanticRetrievalRuntime::class,
            new RerankSpyRuntime(
                available: true,
                crossEncoderScores: ['mem_2' => 0.99, 'mem_1' => 0.90, 'mem_0' => 0.10],
            ),
        );

        $pack = app(AtlasOpenBrainContextPackService::class)->packFor('stale lookup cache credentials', [
            'workspace' => 'atlas-server',
        ]);

        $titles = array_column((array) data_get($pack, 'memory'), 'title');
        $this->assertSame(['cache note', 'auth note', 'crop note'], $titles);
        $this->assertSame('cross_encoder', data_get($pack, 'provenance.memory.retrieval_mode'));
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}

/**
 * In-process boundary fake emitting a real-embeddings receipt with controllable
 * per-id scores. Distinct name to avoid clashing with the sibling test's spy.
 */
final class RerankSpyRuntime implements SemanticCrossEncoderRuntime, SemanticLateInteractionRuntime, SemanticRetrievalRuntime
{
    /**
     * @param  array<string,float>  $realScores
     * @param  array<string,float>  $lateInteractionScores
     * @param  array<string,float>  $crossEncoderScores
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $realScores = [],
        private readonly array $lateInteractionScores = [],
        private readonly array $crossEncoderScores = [],
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

        $matches = [];
        foreach ($documents as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $matches[] = ['id' => $id, 'score' => $this->realScores[$id] ?? 0.0, 'via' => 'semantic'];
        }

        return [
            'matches' => $matches,
            'boundary' => [
                'real_embeddings' => true,
                'fabricated_vectors' => false,
                'embeddings_engine_in_python' => true,
            ],
        ];
    }

    public function lateInteractionRerank(array $documents, string $query, int $k = 5): array
    {
        if (! $this->available) {
            throw new RuntimeException('lateInteractionRerank() called on an unavailable runtime — contract violation.');
        }

        $matches = [];
        foreach ($documents as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $matches[] = ['id' => $id, 'score' => $this->lateInteractionScores[$id] ?? 0.0, 'via' => 'late_interaction'];
        }

        return [
            'matches' => $matches,
            'boundary' => [
                'real_embeddings' => true,
                'fabricated_vectors' => false,
                'embeddings_engine_in_python' => true,
                'late_interaction' => true,
            ],
        ];
    }

    public function crossEncoderRerank(array $documents, string $query, int $k = 3): array
    {
        if (! $this->available) {
            throw new RuntimeException('crossEncoderRerank() called on an unavailable runtime — contract violation.');
        }

        $matches = [];
        foreach ($documents as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $matches[] = ['id' => $id, 'score' => $this->crossEncoderScores[$id] ?? 0.0, 'via' => 'cross_encoder'];
        }

        return [
            'matches' => $matches,
            'boundary' => [
                'real_embeddings' => true,
                'fabricated_vectors' => false,
                'embeddings_engine_in_python' => true,
                'cross_encoder' => true,
            ],
        ];
    }
}
