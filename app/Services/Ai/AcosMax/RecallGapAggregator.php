<?php

declare(strict_types=1);

namespace App\Services\Ai\AcosMax;

use App\Services\Ai\Support\AiValueNormalizer;
use Illuminate\Support\Str;

final class RecallGapAggregator
{
    public const SCHEMA_VERSION = 'atlas.memory.recall_gap_aggregator.v1';

    public const WEAK_SCORE_FLOOR = 0.35;

    public const DEFAULT_MIN_OCCURRENCES = 3;

    public const STATUS_OK = 'ok';

    public const STATUS_INSUFFICIENT_SIGNAL = 'insufficient_signal';
    public const FIELD_SCHEMA_VERSION = 'schema_version';
    public const FIELD_CANDIDATE_TYPE = 'candidate_type';
    public const FIELD_QUERY_HASH = 'query_hash';
    public const FIELD_OCCURRENCES = 'occurrences';
    public const FIELD_SOURCE = 'source';
    public const FIELD_STATUS = 'status';
    public const FIELD_RAW_QUERY_STORED = 'raw_query_stored';
    public const FIELD_AUTO_CREATES_MEMORY = 'auto_creates_memory';
    public const FIELD_CANDIDATES = 'candidates';
    public const FIELD_QUERY = 'query';
    public const FIELD_TOP_SCORE = 'top_score';

    /**
     * @param  list<array<string,mixed>>  $events
     * @return array<string,mixed>
     */
    public static function aggregate(array $events, int $minOccurrences = self::DEFAULT_MIN_OCCURRENCES): array
    {
        $groups = [];
        foreach ($events as $event) {
            if ((AiValueNormalizer::finiteFloatOrNull($event[self::FIELD_TOP_SCORE] ?? null) ?? 0.0) >= self::WEAK_SCORE_FLOOR) {
                continue;
            }
            $normalized = self::normalize(AiValueNormalizer::trimmedStringOrNull($event[self::FIELD_QUERY] ?? null) ?? '');
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
                    self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
                    self::FIELD_CANDIDATE_TYPE => 'knowledge_gap',
                    self::FIELD_QUERY_HASH => $hash,
                    self::FIELD_OCCURRENCES => $count,
                    self::FIELD_SOURCE => [self::FIELD_RAW_QUERY_STORED => false, self::FIELD_AUTO_CREATES_MEMORY => false],
                ];
            }
        }

        return [
            self::FIELD_SCHEMA_VERSION => self::SCHEMA_VERSION,
            self::FIELD_STATUS => $candidates === [] ? self::STATUS_INSUFFICIENT_SIGNAL : self::STATUS_OK,
            self::FIELD_CANDIDATES => $candidates,
        ];
    }

    private static function normalize(string $query): string
    {
        return AiValueNormalizer::trimmedStringOrNull(preg_replace('/\s+/', ' ', Str::lower($query)) ?? '') ?? '';
    }
}
