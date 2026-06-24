<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution;

/**
 * Fact-only frontier gap detector: it never scores ambition, it only connects already-persisted evidence ids.
 */
final class AtlasLoopFrontierGapModel
{
    private const DEFAULT_WINDOW = 3;

    /**
     * @param  array<string,mixed>  $runtimeFacts
     * @return list<array{record_type:string,gap_id:string,scope:string,evidence_refs:list<string>,plateau_signal:bool,last_movement_at:?string}>
     */
    public function compute(array $runtimeFacts = [], int $window = self::DEFAULT_WINDOW): array
    {
        $window = max(3, $window);
        $bucketsByScope = $this->groupByScope($this->listFrom($runtimeFacts, 'capability_trend', 'buckets'));
        $abstainsByScope = $this->groupByScope($this->listFrom($runtimeFacts, 'origination_pipeline', 'cycles'));
        $attemptsByScope = $this->groupByScope($this->listFrom($runtimeFacts, 'attempt_ledger', 'rows', 'attempts'));
        $originatorByScope = $this->groupByScope($this->listFrom($runtimeFacts, 'comprehension_originator', 'orphans', 'inventory'));

        $scopes = array_values(array_unique(array_merge(array_keys($bucketsByScope), array_keys($abstainsByScope))));
        sort($scopes, SORT_STRING);

        $gaps = [];
        foreach ($scopes as $scope) {
            $flat = $this->flatWindow($bucketsByScope[$scope] ?? [], $window);
            $abstained = $this->consecutiveAbstains($abstainsByScope[$scope] ?? [], $window);
            if (! $flat['plateau'] || ! $abstained['starved']) {
                continue;
            }

            $evidenceRefs = array_values(array_unique(array_filter([
                ...$flat['evidence_refs'],
                ...$abstained['evidence_refs'],
                ...$this->refs($attemptsByScope[$scope] ?? []),
                ...$this->refs($originatorByScope[$scope] ?? []),
            ], static fn (string $ref): bool => $ref !== '')));
            sort($evidenceRefs, SORT_STRING);
            if ($evidenceRefs === []) {
                continue;
            }

            $lastMovementAt = $this->lastMovementAt($bucketsByScope[$scope] ?? []);
            $gaps[] = [
                'record_type' => 'FrontierGap',
                'gap_id' => 'frontier_gap:'.sha1($scope.'|'.implode('|', $evidenceRefs)),
                'scope' => $scope,
                'evidence_refs' => $evidenceRefs,
                'plateau_signal' => true,
                'last_movement_at' => $lastMovementAt,
            ];
        }

        return $gaps;
    }

    /**
     * @param  array<string,mixed>  $facts
     * @return list<array<string,mixed>>
     */
    private function listFrom(array $facts, string $section, string ...$keys): array
    {
        $source = $facts[$section] ?? [];
        if (! is_array($source)) {
            return [];
        }

        foreach ($keys as $key) {
            $values = $source[$key] ?? null;
            if (is_array($values)) {
                return array_values(array_filter($values, 'is_array'));
            }
        }

        return array_is_list($source) ? array_values(array_filter($source, 'is_array')) : [];
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array<string,list<array<string,mixed>>>
     */
    private function groupByScope(array $rows): array
    {
        $grouped = [];
        foreach ($rows as $row) {
            $scope = $this->scope($row);
            if ($scope === '') {
                continue;
            }
            $grouped[$scope][] = $row;
        }
        ksort($grouped, SORT_STRING);

        return $grouped;
    }

    /** @param array<string,mixed> $row */
    private function scope(array $row): string
    {
        return trim((string) ($row['scope'] ?? $row['target_path'] ?? $row['target'] ?? $row['symbol'] ?? ''));
    }

    /**
     * @param  list<array<string,mixed>>  $buckets
     * @return array{plateau:bool,evidence_refs:list<string>}
     */
    private function flatWindow(array $buckets, int $window): array
    {
        usort($buckets, static fn (array $left, array $right): int => ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0)));
        $nonEmpty = array_values(array_filter($buckets, static fn (array $bucket): bool => (int) ($bucket['total'] ?? 0) > 0));
        $tail = array_slice($nonEmpty, -$window);
        if (count($tail) < $window) {
            return ['plateau' => false, 'evidence_refs' => []];
        }

        $rates = array_map(static fn (array $bucket): string => number_format((float) ($bucket['rate'] ?? 0.0), 4, '.', ''), $tail);
        if (count(array_unique($rates)) !== 1) {
            return ['plateau' => false, 'evidence_refs' => []];
        }

        return ['plateau' => true, 'evidence_refs' => $this->refs($tail)];
    }

    /**
     * @param  list<array<string,mixed>>  $cycles
     * @return array{starved:bool,evidence_refs:list<string>}
     */
    private function consecutiveAbstains(array $cycles, int $window): array
    {
        usort($cycles, static fn (array $left, array $right): int => strcmp(
            (string) ($left['occurred_at'] ?? $left['created_at'] ?? $left['id'] ?? ''),
            (string) ($right['occurred_at'] ?? $right['created_at'] ?? $right['id'] ?? ''),
        ));
        $tail = array_slice($cycles, -$window);
        if (count($tail) < $window) {
            return ['starved' => false, 'evidence_refs' => []];
        }

        foreach ($tail as $cycle) {
            if (! $this->isAbstainAndAsk($cycle)) {
                return ['starved' => false, 'evidence_refs' => []];
            }
        }

        return ['starved' => true, 'evidence_refs' => $this->refs($tail)];
    }

    /** @param array<string,mixed> $cycle */
    private function isAbstainAndAsk(array $cycle): bool
    {
        $action = trim((string) ($cycle['action'] ?? ''));
        $reason = trim((string) ($cycle['reason'] ?? $cycle['status'] ?? ''));

        return $action === 'abstain' || $reason === 'abstain_and_ask' || $reason === 'operator_question';
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return list<string>
     */
    private function refs(array $rows): array
    {
        $refs = [];
        foreach ($rows as $row) {
            $ref = trim((string) ($row['evidence_id'] ?? $row['id'] ?? $row['ref'] ?? ''));
            if ($ref !== '') {
                $refs[] = $ref;
            }
        }

        return array_values(array_unique($refs));
    }

    /**
     * @param  list<array<string,mixed>>  $buckets
     */
    private function lastMovementAt(array $buckets): ?string
    {
        usort($buckets, static fn (array $left, array $right): int => ((int) ($left['index'] ?? 0)) <=> ((int) ($right['index'] ?? 0)));
        $previousRate = null;
        $lastMovementAt = null;

        foreach ($buckets as $bucket) {
            if ((int) ($bucket['total'] ?? 0) <= 0) {
                continue;
            }
            $rate = number_format((float) ($bucket['rate'] ?? 0.0), 4, '.', '');
            if ($previousRate !== null && $rate !== $previousRate) {
                $lastMovementAt = trim((string) ($bucket['ended_at'] ?? $bucket['updated_at'] ?? '')) ?: null;
            }
            $previousRate = $rate;
        }

        return $lastMovementAt;
    }
}
