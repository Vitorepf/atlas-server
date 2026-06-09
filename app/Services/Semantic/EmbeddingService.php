<?php

namespace App\Services\Semantic;

use App\Services\Ai\RuntimeBoundary\SemanticRagRuntimeClient;
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
    ];

    private SemanticRagRuntimeClient $client;

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

        // Sovereign default: the REAL Python semantic_rag runtime (local learned embeddings).
        if ($provider === 'semantic_rag' && $this->client->available()) {
            try {
                return $this->embedWithSemanticRag($text);
            } catch (Throwable $throwable) {
                report($throwable);
                if (! (bool) config('atlas.semantic_memory.embedding_fallback_enabled', true)) {
                    throw $throwable;
                }
                // fall through to OpenAI (also real) — never to a hash fake.
            }
        }

        if (($provider === 'openai' || $allowExternalProvider) && $this->shouldUseOpenAi()) {
            return $this->embedWithOpenAi($text);
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
        $maxChars = (int) config('atlas.semantic_memory.max_embedding_chars', 12000);
        $result = $this->client->embed([Str::limit($text, $maxChars, '')]);
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

    private function shouldUseOpenAi(): bool
    {
        return config('atlas.semantic_memory.embedding_provider', 'local_hash') === 'openai'
            && trim((string) config('atlas.semantic_memory.embedding_api_key')) !== '';
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
}
