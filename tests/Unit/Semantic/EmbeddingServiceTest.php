<?php

declare(strict_types=1);

namespace Tests\Unit\Semantic;

use App\Services\Semantic\EmbeddingService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EmbeddingServiceTest extends TestCase
{
    public function test_openai_provider_returns_real_provider_vector_without_hash_fallback(): void
    {
        config()->set('atlas.semantic_memory.embedding_provider', 'openai');
        config()->set('atlas.semantic_memory.embedding_api_key', 'test-key');
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
}
