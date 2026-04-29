<?php

namespace App\Services\Semantic;

class EmbeddingService
{
    /**
     * @return array<int, float>
     */
    public function embedText(string $text): array
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
            return $vector;
        }

        return array_map(fn (float $value): float => round($value / $norm, 6), $vector);
    }

    public function vectorLiteral(array $vector): string
    {
        return '['.implode(',', array_map(
            fn (float|int $value): string => rtrim(rtrim(sprintf('%.6F', (float) $value), '0'), '.') ?: '0',
            $vector,
        )).']';
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
}
