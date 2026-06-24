<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\PatternEmergence;

use InvalidArgumentException;

final class AtlasLoopCrossCyclePatternStabilityChecker
{
    /**
     * @param  array{
     *     cycle_ids?: list<string>,
     *     patterns?: list<array{
     *         token?: mixed,
     *         source_episode_ids?: mixed
     *     }>
     * }  $minerPayload
     */
    public function __construct(
        private readonly array $minerPayload,
    ) {}

    /**
     * @return array{
     *     cycle_ids: list<string>,
     *     stable_patterns: list<array{
     *         token: string,
     *         longest_consecutive_run: int,
     *         start_cycle_id: string,
     *         end_cycle_id: string
     *     }>
     * }
     */
    public function stabilityFor(int $k): array
    {
        if ($k < 1) {
            throw new InvalidArgumentException('k must be >= 1.');
        }

        $cycleIds = $this->cycleIds();
        if (count($cycleIds) < $k) {
            throw new AtlasLoopCrossCyclePatternStabilityCheckerInsufficientCyclesException($k, count($cycleIds));
        }

        $stablePatterns = [];
        foreach ($this->patterns() as $pattern) {
            $token = trim((string) ($pattern['token'] ?? ''));
            if ($token === '') {
                continue;
            }

            $sourceIds = $this->normalizeSourceEpisodeIds($pattern['source_episode_ids'] ?? []);
            $bestRun = $this->longestRunWindow($cycleIds, $sourceIds);
            if ($bestRun === null || $bestRun['longest_consecutive_run'] < $k) {
                continue;
            }

            $stablePatterns[$token] = [
                'token' => $token,
                'longest_consecutive_run' => $bestRun['longest_consecutive_run'],
                'start_cycle_id' => $bestRun['start_cycle_id'],
                'end_cycle_id' => $bestRun['end_cycle_id'],
            ];
        }

        ksort($stablePatterns, SORT_STRING);

        return [
            'cycle_ids' => $cycleIds,
            'stable_patterns' => array_values($stablePatterns),
        ];
    }

    /**
     * @return list<string>
     */
    private function cycleIds(): array
    {
        $cycleIds = $this->minerPayload['cycle_ids'] ?? [];
        if (! is_array($cycleIds)) {
            return [];
        }

        $normalized = [];
        foreach ($cycleIds as $cycleId) {
            $cycleId = trim((string) $cycleId);
            if ($cycleId === '') {
                continue;
            }

            if (! in_array($cycleId, $normalized, true)) {
                $normalized[] = $cycleId;
            }
        }

        return $normalized;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function patterns(): array
    {
        $patterns = $this->minerPayload['patterns'] ?? [];

        return is_array($patterns) ? array_values(array_filter($patterns, is_array(...))) : [];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function normalizeSourceEpisodeIds(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $normalized = [];
        foreach ($value as $episodeId) {
            $episodeId = trim((string) $episodeId);
            if ($episodeId === '') {
                continue;
            }

            if (! in_array($episodeId, $normalized, true)) {
                $normalized[] = $episodeId;
            }
        }

        return $normalized;
    }

    /**
     * @param  list<string>  $cycleIds
     * @param  list<string>  $sourceIds
     * @return array{
     *     longest_consecutive_run: int,
     *     start_cycle_id: string,
     *     end_cycle_id: string
     * }|null
     */
    private function longestRunWindow(array $cycleIds, array $sourceIds): ?array
    {
        $present = array_fill_keys($sourceIds, true);
        $currentRun = 0;
        $currentStartIndex = null;
        $bestRun = 0;
        $bestStartIndex = null;
        $bestEndIndex = null;

        foreach ($cycleIds as $index => $cycleId) {
            if (isset($present[$cycleId])) {
                if ($currentRun === 0) {
                    $currentStartIndex = $index;
                }

                $currentRun++;
                if ($currentRun > $bestRun) {
                    $bestRun = $currentRun;
                    $bestStartIndex = $currentStartIndex;
                    $bestEndIndex = $index;
                }

                continue;
            }

            $currentRun = 0;
            $currentStartIndex = null;
        }

        if ($bestRun === 0 || $bestStartIndex === null || $bestEndIndex === null) {
            return null;
        }

        return [
            'longest_consecutive_run' => $bestRun,
            'start_cycle_id' => $cycleIds[$bestStartIndex],
            'end_cycle_id' => $cycleIds[$bestEndIndex],
        ];
    }
}

final class AtlasLoopCrossCyclePatternStabilityCheckerInsufficientCyclesException extends InvalidArgumentException
{
    public function __construct(int $required, int $available)
    {
        parent::__construct(sprintf(
            'Pattern stability checker requires at least %d cycles; %d available.',
            $required,
            $available,
        ));
    }
}
