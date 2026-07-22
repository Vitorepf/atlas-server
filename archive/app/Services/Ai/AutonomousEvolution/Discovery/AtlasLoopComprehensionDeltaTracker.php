<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Discovery;

final class AtlasLoopComprehensionDeltaTracker
{
    private const HUB_SHIFT_THRESHOLD = 0.1;

    /**
     * @param  array<string,mixed>  $oldSnapshot
     * @param  array<string,mixed>  $newSnapshot
     * @return array<string,mixed>
     */
    public function diff(array $oldSnapshot, array $newSnapshot): array
    {
        return [
            'snapshot_before' => (string) ($oldSnapshot['snapshot_id'] ?? ''),
            'snapshot_after' => (string) ($newSnapshot['snapshot_id'] ?? ''),
            'classes_added' => $this->inventoryDiff($oldSnapshot, $newSnapshot),
            'classes_removed' => $this->inventoryDiff($newSnapshot, $oldSnapshot),
            'orphan_wiring_added' => $this->orphanDiff($newSnapshot, $oldSnapshot),
            'orphan_wiring_removed' => $this->orphanDiff($oldSnapshot, $newSnapshot),
            'supply_seam_added' => $this->entryDiff((array) ($oldSnapshot['supply_seams'] ?? []), (array) ($newSnapshot['supply_seams'] ?? []), $newSnapshot),
            'supply_seam_removed' => $this->entryDiff((array) ($newSnapshot['supply_seams'] ?? []), (array) ($oldSnapshot['supply_seams'] ?? []), $oldSnapshot),
            'doc_stated_gaps_added' => $this->stringDiff((array) ($oldSnapshot['doc_stated_gaps'] ?? []), (array) ($newSnapshot['doc_stated_gaps'] ?? [])),
            'doc_stated_gaps_removed' => $this->stringDiff((array) ($newSnapshot['doc_stated_gaps'] ?? []), (array) ($oldSnapshot['doc_stated_gaps'] ?? [])),
            'hub_centrality_shifts' => $this->hubShifts($oldSnapshot, $newSnapshot),
        ];
    }

    /**
     * @param  array<string,mixed>  $from
     * @param  array<string,mixed>  $to
     * @return list<array{fqcn:string, rel_path:string, snapshot_id:string}>
     */
    private function inventoryDiff(array $from, array $to): array
    {
        return $this->entryDiff((array) ($from['inventory'] ?? []), (array) ($to['inventory'] ?? []), $to);
    }

    /**
     * @param  array<string,mixed>  $from
     * @param  array<string,mixed>  $to
     * @return list<array{fqcn:string, rel_path:string, snapshot_id:string}>
     */
    private function orphanDiff(array $from, array $to): array
    {
        $fromEntries = array_values(array_filter((array) ($from['inventory'] ?? []), static fn (array $entry): bool => (bool) ($entry['is_orphan'] ?? false)));
        $toEntries = array_values(array_filter((array) ($to['inventory'] ?? []), static fn (array $entry): bool => (bool) ($entry['is_orphan'] ?? false)));

        return $this->entryDiff($fromEntries, $toEntries, $to);
    }

    /**
     * @param  array<int,array<string,mixed>>  $fromEntries
     * @param  array<int,array<string,mixed>>  $toEntries
     * @param  array<string,mixed>  $targetSnapshot
     * @return list<array{fqcn:string, rel_path:string, snapshot_id:string}>
     */
    private function entryDiff(array $fromEntries, array $toEntries, array $targetSnapshot): array
    {
        $fromMap = $this->entryMap($fromEntries);
        $toMap = $this->entryMap($toEntries);
        $diff = [];

        foreach ($toMap as $key => $entry) {
            if (isset($fromMap[$key])) {
                continue;
            }

            $diff[] = [
                'fqcn' => (string) ($entry['fqcn'] ?? ''),
                'rel_path' => (string) ($entry['rel_path'] ?? ''),
                'snapshot_id' => (string) ($targetSnapshot['snapshot_id'] ?? ''),
            ];
        }

        return $diff;
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,array<string,mixed>>
     */
    private function entryMap(array $entries): array
    {
        $map = [];
        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $fqcn = (string) ($entry['fqcn'] ?? '');
            $path = (string) ($entry['rel_path'] ?? '');
            $map[$fqcn.'|'.$path] = [
                'fqcn' => $fqcn,
                'rel_path' => $path,
            ];
        }

        ksort($map);

        return $map;
    }

    /**
     * @param  array<int,string>  $from
     * @param  array<int,string>  $to
     * @return list<string>
     */
    private function stringDiff(array $from, array $to): array
    {
        $from = array_values(array_unique(array_filter(array_map('strval', $from), static fn (string $value): bool => $value !== '')));
        $to = array_values(array_unique(array_filter(array_map('strval', $to), static fn (string $value): bool => $value !== '')));
        sort($from);
        sort($to);

        return array_values(array_diff($to, $from));
    }

    /**
     * @param  array<string,mixed>  $oldSnapshot
     * @param  array<string,mixed>  $newSnapshot
     * @return list<array{fqcn:string, before:float, after:float, delta:float, snapshot_id:string}>
     */
    private function hubShifts(array $oldSnapshot, array $newSnapshot): array
    {
        $before = (array) ($oldSnapshot['hub_centrality'] ?? []);
        $after = (array) ($newSnapshot['hub_centrality'] ?? []);
        $shifts = [];

        foreach ($after as $fqcn => $value) {
            $beforeValue = isset($before[$fqcn]) ? (float) $before[$fqcn] : 0.0;
            $afterValue = (float) $value;
            $delta = $afterValue - $beforeValue;
            if (abs($delta) < self::HUB_SHIFT_THRESHOLD) {
                continue;
            }

            $shifts[] = [
                'fqcn' => (string) $fqcn,
                'before' => $beforeValue,
                'after' => $afterValue,
                'delta' => $delta,
                'snapshot_id' => (string) ($newSnapshot['snapshot_id'] ?? ''),
            ];
        }

        return $shifts;
    }
}
