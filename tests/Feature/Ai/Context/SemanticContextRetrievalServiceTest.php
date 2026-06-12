<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Context;

use App\Services\Ai\Context\SemanticContextRetrievalService;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRetrievalRuntime;
use RuntimeException;
use Tests\TestCase;

/**
 * L3-6 — ad-hoc semantic retrieval over symbols+docs for the context-pack.
 *
 * Frozen contract:
 *   - flag OFF (default) -> lexical ranking unchanged (no engine call);
 *   - engine unavailable -> fail-open to lexical (no crash, no fake);
 *   - real receipt       -> semantic ordering, score_origin=local_semantic_vector;
 *   - fabricated receipt -> REJECTED, honest degrade to lexical (anti-fake guard);
 *   - LIVE lift          -> skips honestly without the venv (no fabricated lift).
 */
final class SemanticContextRetrievalServiceTest extends TestCase
{
    /** @var array<int,array{id:string,text:string}> */
    private const ITEMS = [
        ['id' => 'auth', 'text' => 'validate the user login session and refresh the credential token'],
        ['id' => 'crop', 'text' => 'the autumn wheat harvest exceeded the seasonal revenue forecast'],
        ['id' => 'cache', 'text' => 'evict stale entries from the in-memory lookup cache on write'],
    ];

    public function test_flag_off_returns_lexical_without_calling_the_engine(): void
    {
        config(['atlas.aobg.semantic_retrieval' => false]);
        $spy = new SpyRuntime(available: true);
        $service = new SemanticContextRetrievalService($spy);

        $result = $service->rank('login credential check', self::ITEMS, 3);

        $this->assertSame('lexical', $result['mode']);
        $this->assertSame(0, $spy->retrieveCalls, 'engine must not be called when the flag is off');
        $this->assertSame('lexical_token_overlap', $result['ranked'][0]['score_origin']);
    }

    public function test_engine_unavailable_fails_open_to_lexical(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        $spy = new SpyRuntime(available: false);
        $service = new SemanticContextRetrievalService($spy);

        $result = $service->rank('login credential check', self::ITEMS, 3);

        $this->assertSame('lexical', $result['mode']);
        $this->assertSame(0, $spy->retrieveCalls);
    }

    public function test_engine_failure_fails_open_to_lexical(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        $spy = new SpyRuntime(available: true, throwOnRetrieve: true);
        $service = new SemanticContextRetrievalService($spy);

        $result = $service->rank('login credential check', self::ITEMS, 3);

        $this->assertSame('lexical', $result['mode']);
        $this->assertSame(1, $spy->retrieveCalls, 'it tried the engine, then degraded honestly');
    }

    public function test_real_receipt_produces_semantic_ordering(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        // Real-receipt fake: auth most similar, crop least — opposite of insertion order.
        $spy = new SpyRuntime(available: true, realScores: ['auth' => 0.88, 'cache' => 0.41, 'crop' => 0.12]);
        $service = new SemanticContextRetrievalService($spy);

        $result = $service->rank('sign in flow', self::ITEMS, 3);

        $this->assertSame('semantic', $result['mode']);
        $this->assertSame(1, $spy->retrieveCalls);
        $this->assertSame('auth', $result['ranked'][0]['id']);
        $this->assertSame('crop', $result['ranked'][2]['id']);
        $this->assertSame('local_semantic_vector', $result['ranked'][0]['score_origin']);
        $this->assertSame(0.88, $result['ranked'][0]['score']);
    }

    public function test_fabricated_receipt_is_rejected_and_degrades_to_lexical(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        // A receipt that does NOT prove real embeddings must be refused.
        $spy = new SpyRuntime(available: true, realScores: ['auth' => 0.99], fabricated: true);
        $service = new SemanticContextRetrievalService($spy);

        $result = $service->rank('sign in flow', self::ITEMS, 3);

        $this->assertSame('lexical', $result['mode'], 'a fabricated boundary receipt must never be trusted');
    }

    public function test_live_semantic_retrieval_beats_or_matches_lexical_on_a_non_lexical_query(): void
    {
        config(['atlas.aobg.semantic_retrieval' => true]);
        $client = new SemanticRagRuntimeClient;
        if (! $client->available()) {
            $this->markTestSkipped(
                'semantic_rag venv not set up — live relevance lift cannot be measured; honest skip.'
            );
        }
        $service = new SemanticContextRetrievalService($client);

        // Query shares NO tokens with the relevant doc — lexical scores it 0, real
        // embeddings must still surface it. This is the anti-lexical-fake property.
        $items = [
            ['id' => 'auth', 'text' => 'authenticate the operator and issue a signed access token'],
            ['id' => 'crop', 'text' => 'the autumn wheat harvest exceeded the seasonal forecast'],
            ['id' => 'weather', 'text' => 'a cold front brings rain showers across the northern coast'],
        ];

        $result = $service->rank('sign-in credential verification', $items, 3);
        $this->assertSame('semantic', $result['mode'], 'live engine should run');
        $this->assertSame('auth', $result['ranked'][0]['id'], 'real embeddings must rank the relevant doc first');

        $lift = $service->relevanceLift('sign-in credential verification', $items, ['auth'], 1);
        $this->assertNotNull($lift);
        $this->assertSame('semantic', $lift['mode']);
        // Lexical scores 'auth' 0 for this query (no shared tokens), so semantic
        // recall@1 must be >= lexical recall@1 (a real, observed lift).
        $this->assertGreaterThanOrEqual($lift['lexical_recall_at_k'], $lift['semantic_recall_at_k']);
    }
}

/**
 * In-process boundary fake. With realScores set it emits a real-embeddings receipt;
 * with fabricated=true it emits a receipt that fails the anti-fake check.
 */
final class SpyRuntime implements SemanticRetrievalRuntime
{
    public int $retrieveCalls = 0;

    /**
     * @param  array<string,float>  $realScores
     */
    public function __construct(
        private readonly bool $available,
        private readonly array $realScores = [],
        private readonly bool $throwOnRetrieve = false,
        private readonly bool $fabricated = false,
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
        $this->retrieveCalls++;
        if ($this->throwOnRetrieve) {
            throw new RuntimeException('simulated engine failure');
        }

        $matches = [];
        foreach ($documents as $doc) {
            $id = (string) ($doc['id'] ?? '');
            $matches[] = ['id' => $id, 'score' => $this->realScores[$id] ?? 0.0, 'via' => 'semantic'];
        }

        return [
            'matches' => $matches,
            'boundary' => [
                'real_embeddings' => ! $this->fabricated,
                'fabricated_vectors' => $this->fabricated,
                'embeddings_engine_in_python' => ! $this->fabricated,
            ],
        ];
    }
}
