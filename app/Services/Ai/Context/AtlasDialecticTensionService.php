<?php

declare(strict_types=1);

namespace App\Services\Ai\Context;

use App\Models\AtlasMemoryEntryRelation;
use App\Services\Ai\Support\DatabaseTableAvailability;
use Throwable;

/**
 * T4-S5 (Obra #17) — dialectic contradiction engine. When a context pack DELIVERS two
 * recalled memories that the brain recorded as an OPEN conflict (a `conflict` relation
 * with status `open` in the D3 relations graph), it must not hand them over as if both
 * were settled truth — it MARKS the open tension. Consumes the conflict edges the D3
 * governance/auto-relation producer (B2d) already writes; invents nothing.
 *
 * Deterministic, provider-safe, fail-open: no relations table (or any fault) → empty list,
 * pack delivered exactly as before, never a throw.
 */
final class AtlasDialecticTensionService
{
    /**
     * Open tensions among the delivered entries: pairs in an OPEN `conflict` relation
     * (either direction). Titles come from the delivered set so the mark is provider-safe.
     *
     * @param  array<int,array{id?:mixed,title?:mixed}>  $entries  delivered memory entries (need id + title)
     * @return list<array{source:string,target:string,reason:string}>
     */
    public function openTensions(array $entries): array
    {
        try {
            if (! DatabaseTableAvailability::has('atlas_memory_entry_relations')) {
                return [];
            }

            $titleById = [];
            foreach ($entries as $entry) {
                $id = trim((string) ($entry['id'] ?? ''));
                if ($id === '') {
                    continue;
                }
                $title = trim((string) ($entry['title'] ?? ''));
                $titleById[$id] = $title !== '' ? $title : $id;
            }

            $ids = array_keys($titleById);
            if (count($ids) < 2) {
                return []; // a tension needs two sides both present in the pack
            }

            $relations = AtlasMemoryEntryRelation::query()
                ->where('relation_type', 'conflict')
                ->where('status', 'open')
                ->whereIn('source_memory_entry_id', $ids)
                ->whereIn('target_memory_entry_id', $ids)
                ->get();

            $out = [];
            $seen = [];
            foreach ($relations as $relation) {
                $sourceId = (string) $relation->source_memory_entry_id;
                $targetId = (string) $relation->target_memory_entry_id;
                if (! isset($titleById[$sourceId], $titleById[$targetId]) || $sourceId === $targetId) {
                    continue;
                }
                $key = $sourceId < $targetId ? $sourceId.'|'.$targetId : $targetId.'|'.$sourceId;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $reason = trim((string) ($relation->reason ?? ''));
                $out[] = [
                    'source' => $titleById[$sourceId],
                    'target' => $titleById[$targetId],
                    'reason' => $reason !== '' ? $reason : 'conflito aberto (não resolvido)',
                ];
            }

            return $out;
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Provider-safe markdown lines marking each open tension, or [] when there is none.
     *
     * @param  array<int,array{id?:mixed,title?:mixed}>  $entries
     * @return list<string>
     */
    public function tensionMarks(array $entries): array
    {
        $lines = [];
        foreach ($this->openTensions($entries) as $tension) {
            $lines[] = sprintf(
                '- ⚠️ tensão aberta (não resolvida): "%s" ⟷ "%s" — %s',
                $tension['source'],
                $tension['target'],
                $tension['reason'],
            );
        }

        return $lines;
    }
}
