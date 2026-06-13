<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfImprovement;

final class LearningPacketConflictDetector
{
    /**
     * @param  list<array<string, mixed>>  $packets
     * @return array{has_conflict: bool, conflicts: list<array{change_key: string, positive_index: int, negative_index: int, stale_winner_index: int, verdict: string}>}
     */
    public function detect(array $packets): array
    {
        $positiveByKey = [];
        $negativeByKey = [];

        $index = 0;
        foreach ($packets as $packet) {
            $currentIndex = $index;
            $index++;

            $changeKey = $this->changeKey($packet);
            if ($changeKey === '') {
                continue;
            }

            $polarity = $this->polarity($packet);
            if ($polarity === 'positive') {
                $positiveByKey[$changeKey] = $currentIndex;
            } elseif ($polarity === 'negative') {
                $negativeByKey[$changeKey] = $currentIndex;
            }
        }

        $conflicts = [];
        foreach ($positiveByKey as $changeKey => $positiveIndex) {
            if (! array_key_exists($changeKey, $negativeByKey)) {
                continue;
            }

            $negativeIndex = $negativeByKey[$changeKey];

            $conflicts[] = [
                'change_key' => $changeKey,
                'positive_index' => $positiveIndex,
                'negative_index' => $negativeIndex,
                'stale_winner_index' => max($positiveIndex, $negativeIndex),
                'verdict' => 'most_recent_wins',
            ];
        }

        usort($conflicts, static function (array $left, array $right): int {
            return strcmp($left['change_key'], $right['change_key']);
        });

        return [
            'has_conflict' => $conflicts !== [],
            'conflicts' => $conflicts,
        ];
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function changeKey(array $packet): string
    {
        $candidate = $this->stringOrNull($packet, 'new_rule_candidate');
        if ($candidate !== null && trim($candidate) !== '') {
            return strtolower(trim($candidate));
        }

        $whatChanged = $this->stringOrNull($packet, 'what_changed');
        if ($whatChanged !== null && trim($whatChanged) !== '') {
            return strtolower(trim($whatChanged));
        }

        return '';
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function polarity(array $packet): string
    {
        $confidence = $this->floatValue($packet, 'confidence');
        $rollbackEmpty = $this->rollbackIsEmpty($packet);

        if ($confidence >= 0.7 && $rollbackEmpty) {
            return 'positive';
        }

        if (! $rollbackEmpty || $confidence <= 0.2) {
            return 'negative';
        }

        return 'neutral';
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function rollbackIsEmpty(array $packet): bool
    {
        $rollback = $this->stringOrNull($packet, 'rollback_recommendation');

        return $rollback === null || trim($rollback) === '';
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function stringOrNull(array $packet, string $key): ?string
    {
        $value = $packet[$key] ?? null;

        return is_string($value) ? $value : null;
    }

    /**
     * @param  array<string, mixed>  $packet
     */
    private function floatValue(array $packet, string $key): float
    {
        $value = $packet[$key] ?? 0.0;

        return is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)) ? (float) $value : 0.0;
    }
}
