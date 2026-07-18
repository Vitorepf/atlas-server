<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

use App\Services\Ai\Support\AiValueNormalizer;

/**
 * Pure, zero-dependency recall relevance scorer for normalized memory candidate rows.
 *
 * Mirrors the inlined recall relevance formula and the scope/type weight tables used by
 * App\Services\Ai\AtlasMemoryContextComposer so the same ordering can be computed and
 * audited in isolation, with no I/O, facades, clock or randomness.
 *
 * A normalized candidate row may carry:
 *   memory_type|type, scope_type, priority, importance, confidence, hybrid_score,
 *   age_days, source (registry|verbatim|semantic), score (semantic), title.
 */
final class AtlasMemoryRecallRelevanceScorer
{
    public const SCHEMA_VERSION = 'atlas.aaeos.memory_recall_ranking.v1';
    public const FIELD_ENGINEERING_RUN = 'engineering_run';
    public const FIELD_FEEDBACK = 'feedback';
    public const FIELD_HARNESS_LEARNING = 'harness_learning';
    public const FIELD_MEMORY_TYPE = 'memory_type';
    public const FIELD_PROJECT = 'project';
    public const FIELD_RANK = 'rank';
    public const FIELD_SESSION = 'session';
    public const FIELD_REQUIREMENT = 'requirement';
    public const FIELD_WORKSPACE = 'workspace';
    public const FIELD_VERBATIM = 'verbatim';
    public const FIELD_FAILURE = 'failure';
    public const FIELD_RELEVANCE_SCORE = 'relevance_score';
    public const FIELD_SCOPE = 'scope';
    public const FIELD_SCOPE_TYPE = 'scope_type';
    public const FIELD_SOURCE = 'source';
    public const FIELD_TASK = 'task';
    public const FIELD_TITLE = 'title';
    public const FIELD_TYPE = 'type';
    public const FIELD_USER = 'user';
    public const FIELD_GLOBAL = 'global';
    public const FIELD_REGISTRY = 'registry';
    public const FIELD_SEMANTIC = 'semantic';
    public const FIELD_HYBRID_SCORE = 'hybrid_score';
    public const FIELD_CONFIDENCE = 'confidence';
    public const FIELD_EVIDENCE = 'evidence';
    public const FIELD_IMPORTANCE = 'importance';
    public const FIELD_ISSUE = 'issue';
    public const FIELD_PREFERENCE = 'preference';
    public const FIELD_PRIORITY = 'priority';
    public const FIELD_RESOLUTION = 'resolution';
    public const FIELD_SCORE = 'score';
    public const FIELD_SEMANTIC_NOTE = 'semantic_note';
    public const FIELD_TECHNICAL_CONTEXT = 'technical_context';
    public const FIELD_COMMAND = 'command';

    /**
     * Compute the recall relevance score for a single normalized candidate row.
     *
     * Branches on the row 'source' (registry|verbatim|semantic); an absent or unknown
     * source falls back to the registry formula.
     *
     * @param  array<string,mixed>  $row
     */
    public function score(array $row): float
    {
        $source = $this->sourceOf($row);

        if ($source === self::FIELD_SEMANTIC) {
            $type = $this->typeOf($row, self::FIELD_SEMANTIC_NOTE);

            return $this->floatField($row, self::FIELD_SCORE, 0.55) * 100
                + $this->typeWeight($type);
        }

        $scope = $this->scopeOf($row);
        $type = $this->typeOf($row, $source === 'verbatim' ? 'verbatim' : 'memory');

        if ($source === 'verbatim') {
            return 82
                + $this->floatField($row, self::FIELD_HYBRID_SCORE, 0.0) * 24
                + $this->scopeWeight($scope)
                + $this->typeWeight($type);
        }

        return $this->floatField($row, self::FIELD_PRIORITY, 50.0)
            + $this->floatField($row, self::FIELD_IMPORTANCE, 3.0) * 10
            + $this->floatField($row, self::FIELD_CONFIDENCE, 0.7) * 10
            + $this->floatField($row, self::FIELD_HYBRID_SCORE, 0.0) * 30
            + $this->scopeWeight($scope)
            + $this->typeWeight($type);
    }

    /**
     * Scope weight table (mirror of AtlasMemoryContextComposer::scopeWeight).
     */
    public function scopeWeight(string $scope): int
    {
        return match ($scope) {
            self::FIELD_TASK => 22,
            self::FIELD_ENGINEERING_RUN => 20,
            self::FIELD_PROJECT => 16,
            self::FIELD_WORKSPACE => 12,
            self::FIELD_SESSION => 10,
            self::FIELD_USER => 8,
            default => 4,
        };
    }

    /**
     * Type weight table (mirror of AtlasMemoryContextComposer::typeWeight).
     */
    public function typeWeight(string $type): int
    {
        return match ($type) {
            'decision', self::FIELD_RESOLUTION, self::FIELD_REQUIREMENT => 16,
            self::FIELD_ISSUE, self::FIELD_FAILURE => 14,
            self::FIELD_TECHNICAL_CONTEXT, self::FIELD_COMMAND, self::FIELD_EVIDENCE, self::FIELD_HARNESS_LEARNING => 11,
            self::FIELD_PREFERENCE, self::FIELD_FEEDBACK => 8,
            default => 5,
        };
    }

    /**
     * Standalone tiebreak comparator: score DESC, then source ASC, then title ASC (strcmp).
     *
     * Returns -1, 0 or 1.
     *
     * @param  array<string,mixed>  $a
     * @param  array<string,mixed>  $b
     */
    public function compare(array $a, array $b): int
    {
        $scoreA = $this->score($a);
        $scoreB = $this->score($b);

        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }

        $sourceCompare = strcmp($this->sourceOf($a), $this->sourceOf($b));
        if ($sourceCompare !== 0) {
            return $sourceCompare < 0 ? -1 : 1;
        }

        $titleCompare = strcmp($this->titleOf($a), $this->titleOf($b));
        if ($titleCompare === 0) {
            return 0;
        }

        return $titleCompare < 0 ? -1 : 1;
    }

    /**
     * Rank candidate rows: score DESC, source ASC, title ASC. Each returned row is the
     * input row annotated with 'relevance_score' (3dp) and a 1-based 'rank'.
     *
     * @param  array<int,array<string,mixed>>  $rows
     * @return array<int,array<string,mixed>>
     */
    public function rank(array $rows): array
    {
        $ordered = array_values($rows);

        usort($ordered, fn (array $a, array $b): int => $this->compare($a, $b));

        $ranked = [];
        $position = 0;

        foreach ($ordered as $row) {
            $position++;
            $row[self::FIELD_RELEVANCE_SCORE] = round($this->score($row), 3);
            $row[self::FIELD_RANK] = $position;
            $ranked[] = $row;
        }

        return $ranked;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function sourceOf(array $row): string
    {
        $source = AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SOURCE] ?? null) ?? '';

        return match ($source) {
            'semantic', self::FIELD_VERBATIM => $source,
            default => self::FIELD_REGISTRY,
        };
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function scopeOf(array $row): string
    {
        return AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_SCOPE_TYPE] ?? $row[self::FIELD_SCOPE] ?? null) ?? self::FIELD_GLOBAL;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function typeOf(array $row, string $default): string
    {
        return AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_MEMORY_TYPE] ?? $row[self::FIELD_TYPE] ?? null) ?? $default;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function titleOf(array $row): string
    {
        return AiValueNormalizer::trimmedStringOrNull($row[self::FIELD_TITLE] ?? null) ?? '';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function floatField(array $row, string $key, float $default): float
    {
        return AiValueNormalizer::finiteFloatOrNull($row[$key] ?? null) ?? $default;
    }
}
