<?php

namespace App\Services\Semantic;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class EmbeddingService
{
    private array $lastInfo = [
        'provider' => 'local_hash',
        'model' => 'local-hash-v1',
        'semantic' => false,
        'fallback' => false,
    ];

    /**
     * @return array<int, float>
     */
    public function embedText(string $text): array
    {
        if ($this->shouldUseOpenAi()) {
            try {
                return $this->embedWithOpenAi($text);
            } catch (\Throwable $throwable) {
                report($throwable);

                if (! (bool) config('atlas.semantic_memory.embedding_fallback_enabled', true)) {
                    throw $throwable;
                }
            }
        }

        return $this->embedWithLocalHash($text, fallback: $this->shouldUseOpenAi());
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
            throw new \RuntimeException('Embedding provider returned an empty vector.');
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
     * @return array<int, float>
     */
    private function embedWithLocalHash(string $text, bool $fallback = false): array
    {
        $dimensions = (int) config('atlas.semantic_memory.embedding_dimensions', 1536);
        $vector = array_fill(0, $dimensions, 0.0);
        $tokens = $this->tokens($text);

        foreach ($tokens as $token) {
            $hash = crc32($token);
            $index = $hash % $dimensions;
            $sign = ($hash & 1) === 1 ? 1.0 : -1.0;
            $vector[$index] += $sign;
        }

        $norm = sqrt(array_reduce($vector, fn (float $carry, float $value): float => $carry + ($value * $value), 0.0));
        if ($norm <= 0.0) {
            $this->lastInfo = [
                'provider' => 'local_hash',
                'model' => 'local-hash-v1',
                'semantic' => false,
                'fallback' => $fallback,
                'dimensions' => $dimensions,
            ];

            return $vector;
        }

        $this->lastInfo = [
            'provider' => 'local_hash',
            'model' => 'local-hash-v1',
            'semantic' => false,
            'fallback' => $fallback,
            'dimensions' => $dimensions,
        ];

        return array_map(fn (float $value): float => round($value / $norm, 6), $vector);
    }

    /**
     * @return array<int, string>
     */
    private function tokens(string $text): array
    {
        $text = mb_strtolower($text);
        preg_match_all('/[\pL\pN]{3,}/u', $text, $matches);

        return array_values(array_unique($matches[0] ?? []));
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
