<?php

declare(strict_types=1);

namespace App\Services\Ai\Aaeos\Cores;

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

        if ($source === 'semantic') {
            $type = $this->typeOf($row, 'semantic_note');

            return $this->floatField($row, 'score', 0.55) * 100
                + $this->typeWeight($type);
        }

        $scope = $this->scopeOf($row);
        $type = $this->typeOf($row, $source === 'verbatim' ? 'verbatim' : 'memory');

        if ($source === 'verbatim') {
            return 82
                + $this->floatField($row, 'hybrid_score', 0.0) * 24
                + $this->scopeWeight($scope)
                + $this->typeWeight($type);
        }

        return $this->floatField($row, 'priority', 50.0)
            + $this->floatField($row, 'importance', 3.0) * 10
            + $this->floatField($row, 'confidence', 0.7) * 10
            + $this->floatField($row, 'hybrid_score', 0.0) * 30
            + $this->scopeWeight($scope)
            + $this->typeWeight($type);
    }

    /**
     * Scope weight table (mirror of AtlasMemoryContextComposer::scopeWeight).
     */
    public function scopeWeight(string $scope): int
    {
        return match ($scope) {
            'task' => 22,
            'engineering_run' => 20,
            'project' => 16,
            'workspace' => 12,
            'session' => 10,
            'user' => 8,
            default => 4,
        };
    }

    /**
     * Type weight table (mirror of AtlasMemoryContextComposer::typeWeight).
     */
    public function typeWeight(string $type): int
    {
        return match ($type) {
            'decision', 'resolution', 'requirement' => 16,
            'issue', 'failure' => 14,
            'technical_context', 'command', 'evidence', 'harness_learning' => 11,
            'preference', 'feedback' => 8,
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
            $row['relevance_score'] = round($this->score($row), 3);
            $row['rank'] = $position;
            $ranked[] = $row;
        }

        return $ranked;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function sourceOf(array $row): string
    {
        $source = $row['source'] ?? '';

        return match (is_string($source) ? $source : '') {
            'semantic', 'verbatim' => $source,
            default => 'registry',
        };
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function scopeOf(array $row): string
    {
        $scope = $row['scope_type'] ?? $row['scope'] ?? 'global';

        return is_string($scope) ? $scope : 'global';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function typeOf(array $row, string $default): string
    {
        $type = $row['memory_type'] ?? $row['type'] ?? $default;

        return is_string($type) ? $type : $default;
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function titleOf(array $row): string
    {
        $title = $row['title'] ?? '';

        return is_string($title) ? $title : '';
    }

    /**
     * @param  array<string,mixed>  $row
     */
    private function floatField(array $row, string $key, float $default): float
    {
        $value = $row[$key] ?? $default;

        return is_numeric($value) ? (float) $value : $default;
    }
}
