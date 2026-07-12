<?php

namespace App\Services\Semantic;

use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class EmbeddingService
{
    private array $lastInfo = [
        'provider' => 'semantic_rag',
        'model' => 'pending',
        'semantic' => true,
        'fallback' => false,
        'external_fallback' => 'opt_in_off',
    ];

    private SemanticRagRuntimeClient $client;

    /** @var array<string,array{vector:array<int,float>,info:array<string,mixed>}> */
    private array $requestMemo = [];

    public function __construct(?SemanticRagRuntimeClient $client = null)
    {
        $this->client = $client ?? app(SemanticRagRuntimeClient::class);
    }

    /**
     * @return array<int, float>
     */
    public function embedText(string $text, bool $allowExternalProvider = true): array
    {
        $provider = (string) config('atlas.semantic_memory.embedding_provider', 'semantic_rag');
        $maxChars = (int) config('atlas.semantic_memory.max_embedding_chars', 12000);
        $input = Str::limit($text, $maxChars, '');

        // Sovereign default: the REAL Python semantic_rag runtime (local learned embeddings).
        if ($provider === 'semantic_rag' && $this->client->available()) {
            try {
                return $this->rememberEmbedding(
                    'semantic_rag',
                    $this->semanticRagModelKey(),
                    $input,
                    fn (): array => $this->embedWithSemanticRag($input),
                );
            } catch (Throwable $throwable) {
                report($throwable);
                if (! (bool) config('atlas.semantic_memory.embedding_fallback_enabled', false)) {
                    throw $throwable;
                }
                // fall through to OpenAI (also real) — never to a hash fake.
            }
        }

        if ($this->shouldUseOpenAi($provider, $allowExternalProvider)) {
            $model = (string) config('atlas.semantic_memory.embedding_model', 'text-embedding-3-small');

            return $this->rememberEmbedding(
                'openai',
                $model.'#'.((int) config('atlas.semantic_memory.embedding_dimensions', 1536)),
                $input,
                fn (): array => $this->embedWithOpenAi($input),
            );
        }

        // The crc32 hash fallback was RETIRED by canon: real embeddings or an explicit failure,
        // never a fabricated vector that pretends to be semantic.
        throw new RuntimeException(
            'No real embedding provider available: the semantic_rag Python runtime is not set up '
            .'(run scripts/setup-semantic-rag-runtime.sh) and no OpenAI key is configured. '
            .'The crc32 hash fake was retired (runtime_language_boundary canon).'
        );
    }

    /**
     * Real embeddings from the Python semantic_rag runtime (local model by default).
     *
     * @return array<int, float>
     */
    private function embedWithSemanticRag(string $text): array
    {
        $result = $this->client->embed([$text]);
        $vector = $result['vectors'][0] ?? null;
        if (! is_array($vector) || $vector === []) {
            throw new RuntimeException('semantic_rag returned an empty embedding vector.');
        }
        $boundary = is_array($result['boundary'] ?? null) ? $result['boundary'] : [];
        $this->lastInfo = [
            'provider' => (string) ($boundary['provider'] ?? 'semantic_rag'),
            'model' => (string) ($boundary['model'] ?? 'unknown'),
            'semantic' => true,
            'fallback' => false,
            'dimensions' => count($vector),
            'external_fallback' => $this->externalFallbackStatus(),
        ];

        return array_map('floatval', $vector);
    }

    public function lastInfo(): array
    {
        return $this->lastInfo;
    }

    public function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            fn (float|int $value): string => rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') ?: '0',
            $vector,
        )).']';
    }

    private function shouldUseOpenAi(string $provider, bool $allowExternalProvider): bool
    {
        $hasKey = trim((string) config('atlas.semantic_memory.embedding_api_key')) !== '';
        if (! $hasKey) {
            return false;
        }

        return $provider === 'openai'
            || ($allowExternalProvider && (bool) config('atlas.semantic_memory.embedding_fallback_enabled', false));
    }

    /**
     * @return array<int, float>
     */
    private function embedWithOpenAi(string $text): array
    {
        $model = (string) config('atlas.semantic_memory.embedding_model', 'text-embedding-3-small');
        $baseUrl = rtrim((string) config('atlas.semantic_memory.embedding_base_url', 'https://api.openai.com/v1'), '/');
        $dimensions = (int) config('atlas.semantic_memory.embedding_dimensions', 1536);
        $response = Http::withToken((string) config('atlas.semantic_memory.embedding_api_key'))
            ->acceptJson()
            ->timeout((int) config('atlas.semantic_memory.embedding_timeout_seconds', 20))
            ->post("{$baseUrl}/embeddings", [
                'model' => $model,
                'input' => Str::limit($text, (int) config('atlas.semantic_memory.max_embedding_chars', 12000), ''),
                'dimensions' => $dimensions,
            ]);

        $response->throw();

        $embedding = data_get($response->json(), 'data.0.embedding');
        if (! is_array($embedding) || $embedding === []) {
            throw new RuntimeException('Embedding provider returned an empty vector.');
        }

        $vector = $this->fitDimensions(array_map('floatval', $embedding), $dimensions);
        $this->lastInfo = [
            'provider' => 'openai',
            'model' => $model,
            'semantic' => true,
            'fallback' => false,
            'dimensions' => count($vector),
            'external_fallback' => $this->externalFallbackStatus(),
        ];

        return $vector;
    }

    /**
     * @param  array<int, float>  $vector
     * @return array<int, float>
     */
    private function fitDimensions(array $vector, int $dimensions): array
    {
        if (count($vector) === $dimensions) {
            return array_values($vector);
        }

        if (count($vector) > $dimensions) {
            return array_slice($vector, 0, $dimensions);
        }

        return array_pad($vector, $dimensions, 0.0);
    }

    /**
     * @param  callable():array<int,float>  $callback
     * @return array<int,float>
     */
    private function rememberEmbedding(string $provider, string $model, string $text, callable $callback): array
    {
        if (! (bool) config('atlas.semantic_memory.embedding_cache_enabled', true)) {
            return $callback();
        }

        $key = $this->embeddingCacheKey($provider, $model, $text);
        if (isset($this->requestMemo[$key])) {
            return $this->hydrateCachedEmbedding($this->requestMemo[$key]);
        }

        $cached = Cache::get($key);
        if (is_array($cached) && $this->isCachedEmbedding($cached)) {
            /** @var array{vector:array<int,float>,info:array<string,mixed>} $cached */
            $this->requestMemo[$key] = $cached;

            return $this->hydrateCachedEmbedding($cached);
        }

        $vector = $callback();
        $payload = [
            'vector' => array_values(array_map('floatval', $vector)),
            'info' => $this->lastInfo,
        ];

        $ttl = max(1, (int) config('atlas.semantic_memory.embedding_cache_ttl_seconds', 3600));
        Cache::put($key, $payload, now()->addSeconds($ttl));
        $this->requestMemo[$key] = $payload;

        return $vector;
    }

    private function embeddingCacheKey(string $provider, string $model, string $text): string
    {
        return 'atlas:semantic-embedding:v1:'.hash('sha256', $provider."\n".$model."\n".$text);
    }

    /**
     * @param  array<mixed>  $payload
     */
    private function isCachedEmbedding(array $payload): bool
    {
        return is_array($payload['vector'] ?? null)
            && ($payload['vector'] ?? []) !== []
            && is_array($payload['info'] ?? null);
    }

    /**
     * @param  array{vector:array<int,float>,info:array<string,mixed>}  $payload
     * @return array<int,float>
     */
    private function hydrateCachedEmbedding(array $payload): array
    {
        $vector = array_values(array_map('floatval', $payload['vector']));
        $this->lastInfo = $payload['info'] + [
            'provider' => 'cache',
            'model' => 'unknown',
            'semantic' => true,
            'fallback' => false,
            'dimensions' => count($vector),
            'external_fallback' => $this->externalFallbackStatus(),
        ];
        $this->lastInfo['dimensions'] = count($vector);

        return $vector;
    }

    private function semanticRagModelKey(): string
    {
        return (string) config(
            'atlas.semantic_memory.semantic_rag_model',
            'sentence-transformers/paraphrase-multilingual-MiniLM-L12-v2',
        );
    }

    public function externalFallbackStatus(): string
    {
        return (bool) config('atlas.semantic_memory.embedding_fallback_enabled', false)
            ? 'opt_in_on'
            : 'opt_in_off';
    }
}
