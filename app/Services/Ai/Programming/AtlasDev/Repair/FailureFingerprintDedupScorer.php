<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Repair;

use App\Services\Ai\Programming\AtlasDev\Support\AtlasDevStringListNormalizer;

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
        $gateA = $this->stringField($a, 'gate');
        $gateB = $this->stringField($b, 'gate');
        $errorA = $this->stringField($a, 'normalized_error');
        $errorB = $this->stringField($b, 'normalized_error');
        $sameGate = $gateA !== '' && $gateA === $gateB;

        // (1) gate AND normalized_error equal, both non-empty.
        if ($sameGate && $errorA !== '' && $errorA === $errorB) {
            return 1.0;
        }

        // (2) same gate non-empty AND at least one shared failing_test.
        if ($sameGate && $this->hasOverlap(
            $this->listField($a, 'failing_tests'),
            $this->listField($b, 'failing_tests'),
        )) {
            return 0.85;
        }

        // (3) same error_class non-empty AND at least one overlapping changed_dir.
        $errorClassA = $this->stringField($a, 'error_class');
        $errorClassB = $this->stringField($b, 'error_class');
        $sameErrorClass = $errorClassA !== '' && $errorClassA === $errorClassB;

        if ($sameErrorClass && $this->hasOverlap(
            $this->listField($a, 'changed_dirs'),
            $this->listField($b, 'changed_dirs'),
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

    /**
     * @param  array<string, mixed>  $payload
     */
    private function stringField(array $payload, string $key): string
    {
        $value = $payload[$key] ?? '';

        if (! is_string($value)) {
            return '';
        }

        return trim($value);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function listField(array $payload, string $key): array
    {
        $value = $payload[$key] ?? [];

        if (! is_array($value)) {
            return [];
        }

        return AtlasDevStringListNormalizer::trimmedStrings($value);
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
