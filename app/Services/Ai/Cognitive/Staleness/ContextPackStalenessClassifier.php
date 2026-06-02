<?php

declare(strict_types=1);

namespace App\Services\Ai\Cognitive\Staleness;

final class ContextPackStalenessClassifier
{
    private const SCHEMA_VERSION = 'atlas.cognitive.staleness.context_pack.v1';

    private const DEFAULT_MAX_FRESH_SECONDS = 86400;

    /**
     * @param array<string, mixed> $signals
     *
     * @return array{
     *     schema_version: string,
     *     severity: string,
     *     score: float,
     *     index_age_seconds: int,
     *     changed_files_since_index: int,
     *     recommended_action: string
     * }
     */
    public function classify(array $signals): array
    {
        $indexAgeSeconds = $this->nonNegativeInt($signals, 'index_age_seconds');
        $changedFiles = $this->nonNegativeInt($signals, 'changed_files_since_index');
        $lastQueryAgeSeconds = $this->nonNegativeInt($signals, 'last_query_age_seconds');

        $maxFreshSeconds = $this->nonNegativeInt($signals, 'max_fresh_seconds');
        if ($maxFreshSeconds <= 0) {
            $maxFreshSeconds = self::DEFAULT_MAX_FRESH_SECONDS;
        }

        $ageRatio = $indexAgeSeconds / $maxFreshSeconds;

        $severity = match (true) {
            $ageRatio >= 4.0 || $changedFiles >= 200 => 'critical',
            $ageRatio >= 2.0 || $changedFiles >= 50 => 'stale',
            $ageRatio >= 1.0 || $changedFiles >= 10 || $lastQueryAgeSeconds > $maxFreshSeconds => 'aging',
            default => 'fresh',
        };

        $score = round(min(1.0, max($ageRatio / 4, $changedFiles / 200)), 3);

        $recommendedAction = match ($severity) {
            'fresh' => 'none',
            'aging' => 'reindex_recommended',
            default => 'reindex_required',
        };

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'severity' => $severity,
            'score' => $score,
            'index_age_seconds' => $indexAgeSeconds,
            'changed_files_since_index' => $changedFiles,
            'recommended_action' => $recommendedAction,
        ];
    }

    /**
     * @param array<string, mixed> $signals
     */
    private function nonNegativeInt(array $signals, string $key): int
    {
        $value = $signals[$key] ?? 0;

        if (! is_int($value)) {
            $value = is_numeric($value) ? (int) $value : 0;
        }

        return max($value, 0);
    }
}
