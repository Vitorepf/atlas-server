<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\PatternEmergence;

use InvalidArgumentException;

final class AtlasLoopCrossCyclePatternMiner
{
    /**
     * @param  list<array<string, mixed>>  $episodes
     */
    public function __construct(
        private readonly array $episodes = [],
        private readonly int $window = 3,
    ) {
        if ($this->window < 1) {
            throw new InvalidArgumentException('window must be >= 1.');
        }
    }

    /**
     * @return array{
     *     patterns: list<array{token:string,count:int,source_episode_ids:list<string>}>,
     *     window: int
     * }
     */
    public function mine(): array
    {
        if (count($this->episodes) < $this->window) {
            throw new AtlasLoopCrossCyclePatternMinerInsufficientEpisodesException($this->window, count($this->episodes));
        }

        $episodes = array_slice($this->episodes, -$this->window);
        $episodeTokens = [];
        foreach ($episodes as $episode) {
            $episodeId = trim((string) ($episode['episode_id'] ?? ''));
            if ($episodeId === '') {
                continue;
            }

            $tokens = array_values(array_unique($this->extractTokens($episode)));
            sort($tokens, SORT_STRING);
            $episodeTokens[$episodeId] = $tokens;
        }

        $patterns = [];
        $tokenMap = [];
        foreach ($episodeTokens as $episodeId => $tokens) {
            foreach ($tokens as $token) {
                $tokenMap[$token][] = $episodeId;
            }
        }

        ksort($tokenMap, SORT_STRING);
        foreach ($tokenMap as $token => $sourceEpisodeIds) {
            $sourceEpisodeIds = array_values(array_unique($sourceEpisodeIds));
            sort($sourceEpisodeIds, SORT_STRING);
            $count = count($sourceEpisodeIds);
            if ($count < 2) {
                continue;
            }

            $patterns[] = [
                'token' => $token,
                'count' => $count,
                'source_episode_ids' => $sourceEpisodeIds,
            ];
        }

        $cycleIds = array_values(array_keys($episodeTokens));
        sort($cycleIds, SORT_STRING);

        return [
            'patterns' => $patterns,
            'window' => $this->window,
            'cycle_ids' => $cycleIds,
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function extractTokens(mixed $value): array
    {
        if (is_string($value)) {
            $trimmed = trim($value);

            return $trimmed === '' ? [] : [$trimmed];
        }

        if (! is_array($value)) {
            return [];
        }

        $tokens = [];
        foreach ($value as $item) {
            array_push($tokens, ...$this->extractTokens($item));
        }

        return $tokens;
    }
}

final class AtlasLoopCrossCyclePatternMinerInsufficientEpisodesException extends InvalidArgumentException
{
    public function __construct(int $required, int $available)
    {
        parent::__construct(sprintf(
            'Pattern miner requires at least %d episodes; %d available.',
            $required,
            $available,
        ));
    }
}
