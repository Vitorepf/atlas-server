<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;
use App\Services\Ai\Support\AiValueNormalizer;

final class FailureFingerprintDedupScorer
{
    private const DUPLICATE_THRESHOLD = 0.85;

    private const NEAR_DUPLICATE_THRESHOLD = 0.6;

    /**
     * @param  array{gate?: mixed, normalized_error?: mixed, failing_tests?: mixed, error_class?: mixed, changed_dirs?: mixed}  $a
     * @param  array{gate?: mixed, normalized_error?: mixed, failing_tests?: mixed, error_class?: mixed, changed_dirs?: mixed}  $b
     * @return array{confidence: float, verdict: string}
     */
    public function scoreDedup(array $a, array $b): array
    {
        $confidence = $this->clamp($this->baseScore($a, $b));

        return [
            'confidence' => $confidence,
            'verdict' => $this->verdict($confidence),
        ];
    }

    /**
     * Highest-first, first-match ordered rules.
     *
     * @param  array<string, mixed>  $a
     * @param  array<string, mixed>  $b
     */
    private function baseScore(array $a, array $b): float
    {
        $gateA = AiValueNormalizer::trimmedStringOrNull($a['gate'] ?? null) ?? '';
        $gateB = AiValueNormalizer::trimmedStringOrNull($b['gate'] ?? null) ?? '';
        $errorA = AiValueNormalizer::trimmedStringOrNull($a['normalized_error'] ?? null) ?? '';
        $errorB = AiValueNormalizer::trimmedStringOrNull($b['normalized_error'] ?? null) ?? '';
        $sameGate = $gateA !== '' && $gateA === $gateB;

        // (1) gate AND normalized_error equal, both non-empty.
        if ($sameGate && $errorA !== '' && $errorA === $errorB) {
            return 1.0;
        }

        // (2) same gate non-empty AND at least one shared failing_test.
        if ($sameGate && $this->hasOverlap(
            AtlasDevStringListNormalizer::trimmedStrings($a['failing_tests'] ?? []),
            AtlasDevStringListNormalizer::trimmedStrings($b['failing_tests'] ?? []),
        )) {
            return 0.85;
        }

        // (3) same error_class non-empty AND at least one overlapping changed_dir.
        $errorClassA = AiValueNormalizer::trimmedStringOrNull($a['error_class'] ?? null) ?? '';
        $errorClassB = AiValueNormalizer::trimmedStringOrNull($b['error_class'] ?? null) ?? '';
        $sameErrorClass = $errorClassA !== '' && $errorClassA === $errorClassB;

        if ($sameErrorClass && $this->hasOverlap(
            AtlasDevStringListNormalizer::trimmedStrings($a['changed_dirs'] ?? []),
            AtlasDevStringListNormalizer::trimmedStrings($b['changed_dirs'] ?? []),
        )) {
            return 0.6;
        }

        // (4) same gate only, non-empty.
        if ($sameGate) {
            return 0.45;
        }

        // (5) token-jaccard over normalized_error (whitespace-split, lowercased).
        return $this->tokenJaccard($errorA, $errorB);
    }

    private function tokenJaccard(string $errorA, string $errorB): float
    {
        $tokensA = $this->tokenSet($errorA);
        $tokensB = $this->tokenSet($errorB);

        $union = $tokensA + $tokensB;

        if ($union === []) {
            return 0.0;
        }

        $intersection = array_intersect_key($tokensA, $tokensB);

        return count($intersection) / count($union);
    }

    /**
     * @return array<string, true>
     */
    private function tokenSet(string $value): array
    {
        $tokens = preg_split('/\s+/', strtolower(trim($value)), -1, PREG_SPLIT_NO_EMPTY);

        if ($tokens === false) {
            return [];
        }

        $set = [];

        foreach ($tokens as $token) {
            $set[$token] = true;
        }

        return $set;
    }

    /**
     * @param  list<string>  $a
     * @param  list<string>  $b
     */
    private function hasOverlap(array $a, array $b): bool
    {
        if ($a === [] || $b === []) {
            return false;
        }

        return array_intersect($a, $b) !== [];
    }

    private function clamp(float $value): float
    {
        return min(max($value, 0.0), 1.0);
    }

    private function verdict(float $confidence): string
    {
        if ($confidence >= self::DUPLICATE_THRESHOLD) {
            return 'duplicate';
        }

        if ($confidence >= self::NEAR_DUPLICATE_THRESHOLD) {
            return 'near_duplicate';
        }

        return 'distinct';
    }
}
