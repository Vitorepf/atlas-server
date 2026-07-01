<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction\ExternalBrain;

/**
 * Priority allocator: fills a compression batch from ranked candidates while enforcing a
 * portfolio diversity floor so the engine never ships an all-delete or all-proof batch even
 * when one kind of work dominates the ROI-ranked queue. A brain that only ever mines the single
 * highest-ROI kind starves the other work types (proof prework, rollback, knowledge sync) that
 * keep destructive compression safe.
 *
 * Input contract:
 *   candidates: list<array{
 *     id?:         string,
 *     kind?:       string  ('delete'|'merge'|'inline'|'proof'|'knowledge_sync'),
 *     roi_score?:  float,
 *     risk_level?: string  ('low'|'medium'|'high', default 'medium'),
 *   }>
 *   batch_size?:          int  (default 5)
 *   min_kind_diversity?:  int  (default 2, capped at the number of distinct kinds present)
 *
 * Selection: fill batch_size slots by roi_score descending. If the filled batch represents
 * fewer distinct kinds than the diversity target, the highest-ROI unselected candidate from
 * each missing kind is swapped in, displacing the lowest-ROI selected candidate from whichever
 * kind currently holds more than one slot — never dropping below one slot for any kind already
 * represented.
 *
 * Pure PHP, deterministic, no I/O.
 */
final class AtlasExternalBrainCompressionPortfolioAllocator
{
    public const SCHEMA = 'atlas.external_brain.compression_portfolio_allocator.v1';

    public const DEFAULT_BATCH_SIZE = 5;
    public const DEFAULT_MIN_KIND_DIVERSITY = 2;

    /**
     * @param  array{candidates?: list<array<string,mixed>>, batch_size?: int, min_kind_diversity?: int}  $facts
     * @return array{schema:string, batch:list<array<string,mixed>>, kind_counts:array<string,int>, rebalanced:bool, diversity_target:int}
     */
    public function allocate(array $facts): array
    {
        $batchSize = max(0, (int) ($facts['batch_size'] ?? self::DEFAULT_BATCH_SIZE));
        $minDiversityRequested = max(1, (int) ($facts['min_kind_diversity'] ?? self::DEFAULT_MIN_KIND_DIVERSITY));

        $candidates = [];
        foreach ((array) ($facts['candidates'] ?? []) as $candidate) {
            if (! is_array($candidate)) {
                continue;
            }
            $id = trim((string) ($candidate['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $candidates[] = [
                'id'         => $id,
                'kind'       => (string) ($candidate['kind'] ?? 'delete'),
                'roi_score'  => (float) ($candidate['roi_score'] ?? 0.0),
                'risk_level' => (string) ($candidate['risk_level'] ?? 'medium'),
            ];
        }

        usort($candidates, static fn (array $a, array $b): int => $b['roi_score'] === $a['roi_score']
            ? strcmp((string) $a['id'], (string) $b['id'])
            : $b['roi_score'] <=> $a['roi_score']);

        $distinctKinds = array_values(array_unique(array_column($candidates, 'kind')));
        $diversityTarget = min($minDiversityRequested, count($distinctKinds));

        $selected = array_slice($candidates, 0, $batchSize);
        $remaining = array_slice($candidates, $batchSize);
        $rebalanced = false;

        foreach ($distinctKinds as $kind) {
            if ($this->distinctKindCount($selected) >= $diversityTarget) {
                break;
            }
            if ($this->kindPresent($selected, $kind)) {
                continue;
            }

            $bestOfKindIndex = $this->bestUnselectedIndexForKind($remaining, $kind);
            if ($bestOfKindIndex === null) {
                continue; // no candidate of this kind available at all
            }

            $displaceIndex = $this->worstDisplaceableIndex($selected);
            if ($displaceIndex === null) {
                break; // batch too small / every kind already down to one slot — cannot diversify further
            }

            $displaced = $selected[$displaceIndex];
            $selected[$displaceIndex] = $remaining[$bestOfKindIndex];
            unset($remaining[$bestOfKindIndex]);
            $remaining[] = $displaced;
            $remaining = array_values($remaining);
            $rebalanced = true;
        }

        $kindCounts = [];
        foreach ($selected as $item) {
            $kindCounts[$item['kind']] = ($kindCounts[$item['kind']] ?? 0) + 1;
        }
        ksort($kindCounts);

        return [
            'schema'            => self::SCHEMA,
            'batch'             => array_values($selected),
            'kind_counts'       => $kindCounts,
            'rebalanced'        => $rebalanced,
            'diversity_target'  => $diversityTarget,
        ];
    }

    /** @param  list<array<string,mixed>>  $selected */
    private function distinctKindCount(array $selected): int
    {
        return count(array_unique(array_column($selected, 'kind')));
    }

    /** @param  list<array<string,mixed>>  $selected */
    private function kindPresent(array $selected, string $kind): bool
    {
        foreach ($selected as $item) {
            if ($item['kind'] === $kind) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array<string,mixed>>  $remaining */
    private function bestUnselectedIndexForKind(array $remaining, string $kind): ?int
    {
        foreach ($remaining as $index => $item) {
            if ($item['kind'] === $kind) {
                return $index;
            }
        }

        return null;
    }

    /**
     * Finds the lowest-ROI selected candidate belonging to a kind that currently holds more
     * than one slot — displacing it never drops that kind below one slot.
     *
     * @param  list<array<string,mixed>>  $selected
     */
    private function worstDisplaceableIndex(array $selected): ?int
    {
        $kindCounts = [];
        foreach ($selected as $item) {
            $kindCounts[$item['kind']] = ($kindCounts[$item['kind']] ?? 0) + 1;
        }

        $worstIndex = null;
        $worstRoi = null;
        foreach ($selected as $index => $item) {
            if (($kindCounts[$item['kind']] ?? 0) <= 1) {
                continue;
            }
            if ($worstRoi === null || $item['roi_score'] < $worstRoi) {
                $worstRoi = $item['roi_score'];
                $worstIndex = $index;
            }
        }

        return $worstIndex;
    }
}
