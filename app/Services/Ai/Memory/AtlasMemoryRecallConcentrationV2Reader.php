<?php

declare(strict_types=1);

namespace App\Services\Ai\Memory;

use App\Services\Ai\Support\DatabaseTableAvailability;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * ASI-12 v2 — multi-actor concentration reader.
 *
 * Aggregates recall-path usages by actor within a window and reports both the
 * raw per-actor counts AND a volume-normalized concentration index that
 * survives skewed volumes. The v1 aggregate (`AtlasMemoryRecallConcentrationDemotion`)
 * keeps running byte-identical: this is a NEW series (dual-read), never a
 * substitution.
 *
 * The normalization principle (anti-Goodhart, "sensors survive their own
 * volume"):
 *   - Global (v1) concentration = share of ALL usages a single entry
 *     absorbs. With one loud actor (10:1 volume vs another), a memory quiet
 *     for the quiet actor can look dominant simply because the loud actor
 *     spammed it.
 *   - Per-actor share = share WITHIN that actor's usage stream.
 *   - `max_per_actor_share` = the highest per-actor share achieved by ANY
 *     memory across ALL qualifying actors. If a memory sees 10:0 usage from
 *     one loud actor, per-actor share of THAT actor spikes but the global
 *     v1 reading hides it. v2 exposes it.
 */
final class AtlasMemoryRecallConcentrationV2Reader
{
    public const SCHEMA = 'atlas.memory.recall_concentration_v2';

    /**
     * @param  array{window_days?:int,min_recalls?:int,min_actor_recalls?:int}  $options
     * @return array<string,mixed>
     */
    public function read(array $options = []): array
    {
        $windowDays = max(1, (int) ($options['window_days'] ?? config('atlas.semantic_memory.recall_concentration_window_days', 45)));
        $minRecalls = max(1, (int) ($options['min_recalls'] ?? config('atlas.semantic_memory.recall_concentration_min_recalls', 100)));
        $minActorRecalls = max(1, (int) ($options['min_actor_recalls'] ?? 10));

        $base = [
            'schema' => self::SCHEMA,
            'window_days' => $windowDays,
            'min_recalls' => $minRecalls,
            'min_actor_recalls' => $minActorRecalls,
            'status' => 'insufficient_signal',
            'reason' => null,
            'total_usages' => 0,
            'actors' => [],
            'per_actor' => [],
            'per_memory' => [],
            'aggregated' => [
                'top_memory_id' => null,
                'top_share_global' => 0.0,
                'top_share_max_per_actor' => 0.0,
            ],
            'dual_read_note' => 'v1 aggregate (AtlasMemoryRecallConcentrationDemotion) unchanged; v2 is informative, not a gate.',
        ];

        if (! DatabaseTableAvailability::has('atlas_memory_entry_usages')) {
            $base['reason'] = 'usages_table_unavailable';

            return $base;
        }
        if (! Schema::hasColumn('atlas_memory_entry_usages', 'actor')) {
            $base['reason'] = 'actor_column_missing';

            return $base;
        }

        $since = now()->subDays($windowDays);

        $rows = DB::table('atlas_memory_entry_usages')
            ->where('source_type', AtlasMemoryUsageService::SOURCE_TYPE_RECALLED_PRE_FILTER)
            ->where('created_at', '>=', $since)
            ->select('memory_entry_id', 'actor')
            ->get()
            ->all();

        $total = count($rows);
        if ($total < $minRecalls) {
            $base['total_usages'] = $total;
            $base['reason'] = 'below_min_recalls';

            return $base;
        }

        // Aggregate: per-actor counts, per-memory counts, per (actor, memory).
        $perActor = [];        // actor => count
        $perMemory = [];       // memory_id => count
        $perPair = [];         // "actor|memory" => count
        foreach ($rows as $row) {
            $actor = trim((string) ($row->actor ?? ''));
            if ($actor === '') {
                $actor = AtlasMemoryActorTagger::ACTOR_UNKNOWN;
            }
            $memoryId = (string) $row->memory_entry_id;

            $perActor[$actor] = ($perActor[$actor] ?? 0) + 1;
            $perMemory[$memoryId] = ($perMemory[$memoryId] ?? 0) + 1;
            $key = $actor.'|'.$memoryId;
            $perPair[$key] = ($perPair[$key] ?? 0) + 1;
        }

        // Per-actor concentration: for each memory, take the max of its
        // shares within any QUALIFYING actor stream (>= min_actor_recalls).
        $maxPerActorShareByMemory = [];
        foreach ($perPair as $key => $count) {
            [$actor, $memoryId] = explode('|', $key, 2);
            $actorTotal = $perActor[$actor] ?? 0;
            if ($actorTotal < $minActorRecalls) {
                continue;
            }
            $share = $actorTotal > 0 ? $count / $actorTotal : 0.0;
            if (! isset($maxPerActorShareByMemory[$memoryId]) || $share > $maxPerActorShareByMemory[$memoryId]['share']) {
                $maxPerActorShareByMemory[$memoryId] = ['share' => $share, 'actor' => $actor, 'count' => $count];
            }
        }

        // Global v1-style share.
        $globalShareByMemory = [];
        foreach ($perMemory as $memoryId => $count) {
            $globalShareByMemory[$memoryId] = $total > 0 ? $count / $total : 0.0;
        }

        // Rank per-memory: report top-K by max-per-actor share (v2's whole point).
        $perMemoryOut = [];
        foreach ($perMemory as $memoryId => $count) {
            $perMemoryOut[] = [
                'memory_id' => $memoryId,
                'count' => $count,
                'global_share' => round($globalShareByMemory[$memoryId] ?? 0.0, 4),
                'max_per_actor_share' => round(($maxPerActorShareByMemory[$memoryId]['share'] ?? 0.0), 4),
                'max_per_actor' => $maxPerActorShareByMemory[$memoryId]['actor'] ?? null,
                'max_per_actor_count' => $maxPerActorShareByMemory[$memoryId]['count'] ?? null,
            ];
        }
        usort($perMemoryOut, fn (array $a, array $b) => $b['max_per_actor_share'] <=> $a['max_per_actor_share']);

        $top = $perMemoryOut[0] ?? null;

        return array_merge($base, [
            'status' => 'ok',
            'total_usages' => $total,
            'actors' => array_keys($perActor),
            'per_actor' => array_map(
                fn (int $count, string $actor) => ['actor' => $actor, 'count' => $count, 'share_of_total' => round($count / max(1, $total), 4)],
                $perActor,
                array_keys($perActor),
            ),
            'per_memory' => array_slice($perMemoryOut, 0, 25),
            'aggregated' => [
                'top_memory_id' => $top['memory_id'] ?? null,
                'top_share_global' => $top['global_share'] ?? 0.0,
                'top_share_max_per_actor' => $top['max_per_actor_share'] ?? 0.0,
            ],
        ]);
    }
}
