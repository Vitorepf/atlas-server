<?php

declare(strict_types=1);

namespace Tests\Unit\Semantic;

use App\Services\Semantic\EmbeddingService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

final class EmbeddingServiceCacheTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        config()->set('atlas.semantic_memory.embedding_provider', 'openai');
        config()->set('atlas.semantic_memory.embedding_api_key', 'test-key');
        config()->set('atlas.semantic_memory.embedding_model', 'model-a');
        config()->set('atlas.semantic_memory.embedding_dimensions', 3);
        config()->set('atlas.semantic_memory.embedding_cache_enabled', true);
        config()->set('atlas.semantic_memory.embedding_cache_ttl_seconds', 3600);
    }

    public function test_same_query_is_embedded_once_in_process_and_zero_times_from_persistent_cache(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            return Http::response([
                'data' => [
                    ['embedding' => [0.1, 0.2, 0.3]],
                ],
            ]);
        });

        $service = new EmbeddingService;

        self::assertSame([0.1, 0.2, 0.3], $service->embedText('repeat query'));
        self::assertSame([0.1, 0.2, 0.3], $service->embedText('repeat query'));
        self::assertSame(1, $calls, 'request memoization should serve the second call in the same PHP process');

        $nextRequestService = new EmbeddingService;
        self::assertSame([0.1, 0.2, 0.3], $nextRequestService->embedText('repeat query'));
        self::assertSame(1, $calls, 'persistent cache should serve a repeated query across service instances');
    }

    public function test_embedding_cache_key_includes_model_so_model_change_reembeds(): void
    {
        $calls = 0;
        Http::fake(function (Request $request) use (&$calls) {
            $calls++;

            return Http::response([
                'data' => [
                    ['embedding' => [0.1 * $calls, 0.2, 0.3]],
                ],
            ]);
        });

        self::assertSame([0.1, 0.2, 0.3], (new EmbeddingService)->embedText('same text'));

        config()->set('atlas.semantic_memory.embedding_model', 'model-b');

        self::assertSame([0.2, 0.2, 0.3], (new EmbeddingService)->embedText('same text'));
        self::assertSame(2, $calls);
    }
}
