<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use Illuminate\Support\Str;

final class RecallGapAggregator
{
    public const SCHEMA_VERSION = 'atlas.memory.recall_gap_aggregator.v1';

    public const WEAK_SCORE_FLOOR = 0.35;

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public static function aggregate(array $events, int $minOccurrences = 3): array
    {
        $groups = [];
        foreach ($events as $event) {
            if ((float) ($event['top_score'] ?? 0.0) >= self::WEAK_SCORE_FLOOR) {
                continue;
            }
            $normalized = self::normalize((string) ($event['query'] ?? ''));
            if ($normalized === '') {
                continue;
            }
            $hash = sha1($normalized);
            $groups[$hash] = ($groups[$hash] ?? 0) + 1;
        }

        $candidates = [];
        foreach ($groups as $hash => $count) {
            if ($count >= $minOccurrences) {
                $candidates[] = [
                    'schema_version' => self::SCHEMA_VERSION,
                    'candidate_type' => 'knowledge_gap',
                    'query_hash' => $hash,
                    'occurrences' => $count,
                    'source' => ['raw_query_stored' => false, 'auto_creates_memory' => false],
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $candidates === [] ? 'insufficient_signal' : 'ok',
            'candidates' => $candidates,
        ];
    }

    private static function normalize(string $query): string
    {
        return trim((string) preg_replace('/\s+/', ' ', Str::lower($query)));
    }
}
