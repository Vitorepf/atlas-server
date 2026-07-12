<?php

declare(strict_types=1);

namespace Tests\Unit\Semantic;

use App\Services\Ai\RuntimeBoundary\PythonManifestRuntimeClient;
use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use App\Services\Semantic\EmbeddingService;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Tests\TestCase;

final class EmbeddingServiceTest extends TestCase
{
    public function test_openai_provider_returns_real_provider_vector_without_hash_fallback(): void
    {
        config()->set('atlas.semantic_memory.embedding_provider', 'openai');
        config()->set('atlas.semantic_memory.embedding_api_key', 'test-key');
        config()->set('atlas.semantic_memory.embedding_fallback_enabled', false);
        config()->set('atlas.semantic_memory.embedding_dimensions', 4);

        Http::fake([
            'https://api.openai.com/v1/embeddings' => Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2]],
                ],
            ]),
        ]);

        $service = app(EmbeddingService::class);

        $this->assertSame([0.1, 0.2, 0.0, 0.0], $service->embedText('provider-safe text'));
        $this->assertSame('openai', data_get($service->lastInfo(), 'provider'));
        $this->assertTrue(data_get($service->lastInfo(), 'semantic'));
        $this->assertFalse(data_get($service->lastInfo(), 'fallback'));
    }

    public function test_key_present_does_not_trigger_external_fallback_when_opt_in_is_off(): void
    {
        config()->set('atlas.semantic_memory.embedding_provider', 'semantic_rag');
        config()->set('atlas.semantic_memory.embedding_api_key', 'test-key');
        config()->set('atlas.semantic_memory.embedding_fallback_enabled', false);
        config()->set('atlas.semantic_memory.embedding_cache_enabled', false);

        Http::fake();
        $service = new EmbeddingService(new SemanticRagRuntimeClient(new PythonManifestRuntimeClient(
            '__missing_semantic_rag_runtime__',
            'atlas-test',
            'semantic runtime unavailable',
            'semantic_rag',
        )));

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No real embedding provider available');

        try {
            $service->embedText('provider-safe text');
        } finally {
            Http::assertSentCount(0);
            $this->assertSame('opt_in_off', $service->externalFallbackStatus());
        }
    }

    public function test_embedding_info_reports_external_fallback_opt_in_off(): void
    {
        config()->set('atlas.semantic_memory.embedding_fallback_enabled', false);

        $this->artisan('atlas:semantic:embedding-info', ['--json' => true])
            ->expectsOutputToContain('"external_fallback": "opt_in_off"')
            ->assertExitCode(0);
    }
}
