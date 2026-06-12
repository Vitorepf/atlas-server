<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\AtlasHybridMemoryRetrievalService;
use App\Services\Ai\AtlasOpenBrainContextPackService;
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
    /** @var array<int,array<string,string>> recall order: auth, crop, cache */
    private const RECALL = [
        ['title' => 'crop note', 'summary' => 'autumn wheat harvest revenue forecast', 'body' => '', 'type' => 'technical_context', 'scope' => 'global', 'source_type' => 'memory_entry', 'content_hash' => 'h_crop', 'privacy_class' => 'normal'],
        ['title' => 'auth note', 'summary' => 'validate user login session and credential token', 'body' => '', 'type' => 'decision', 'scope' => 'global', 'source_type' => 'memory_entry', 'content_hash' => 'h_auth', 'privacy_class' => 'normal'],
        ['title' => 'cache note', 'summary' => 'evict stale entries from the lookup cache', 'body' => '', 'type' => 'technical_context', 'scope' => 'global', 'source_type' => 'memory_entry', 'content_hash' => 'h_cache', 'privacy_class' => 'normal'],
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
final class RerankSpyRuntime implements SemanticRetrievalRuntime
{
    /**
     * @param  array<string,float>  $realScores
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $realScores = [],
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
}
