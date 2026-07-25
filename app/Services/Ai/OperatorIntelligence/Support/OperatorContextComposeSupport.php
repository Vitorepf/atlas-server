<?php

declare(strict_types=1);

namespace App\Services\Ai\OperatorIntelligence\Support;

/**
 * Pure context-composition helpers for operator profile injection (full-pass peel).
 */
final class OperatorContextComposeSupport
{
    public static function clampLimit(int $limit, int $min = 1, int $max = 50): int
    {
        return max($min, min($max, $limit));
    }

    /**
     * @return array{id:mixed,profile_key:mixed,privacy_class:mixed,reason:string}
     */
    public static function omitted(mixed $id, mixed $profileKey, mixed $privacyClass, string $reason): array
    {
        return [
            'id' => $id,
            'profile_key' => $profileKey,
            'privacy_class' => $privacyClass,
            'reason' => $reason,
        ];
    }

    /**
     * @param  list<string>  $allowedPrivacy
     */
    public static function privacyAllowed(string $privacyClass, array $allowedPrivacy): bool
    {
        return in_array($privacyClass, $allowedPrivacy, true);
    }

    /**
     * Rank key: flow match (2/0), then confidence, then updated timestamp.
     *
     * @return array{0:int,1:float,2:int}
     */
    public static function rankTuple(int $flowScore, float $confidence, int $updatedAtTs): array
    {
        return [$flowScore, $confidence, $updatedAtTs];
    }

    public static function flowScoreForMatch(bool $flowMatches): int
    {
        return $flowMatches ? 2 : 0;
    }
}
