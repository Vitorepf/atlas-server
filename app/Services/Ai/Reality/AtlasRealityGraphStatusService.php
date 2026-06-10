<?php

declare(strict_types=1);

namespace App\Services\Ai\Reality;

use App\Models\AtlasAurgEdge;
use App\Models\AtlasAurgNode;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * AURG Phase-2 — F4 status (Salto 1, "AURG vivo"): the product's health surface.
 *
 * EVERYTHING here is computed LIVE from the fused store (atlas_aurg_nodes /
 * atlas_aurg_edges) and the append-only AURG-4D temporal chain — no cached
 * numbers, no fabricated values, no defaults dressed up as measurements:
 *
 *  - store      node/edge counts by source_kind and by kind, linker edge counts
 *               (edges grouped by their deterministic producer), provider-safety
 *               split, last_ingest_at (max updated_at actually observed). When the
 *               brain tables are absent the answer is available=false — never a
 *               zero pretending the store is empty.
 *  - temporal   chain length + verifyChain() (the REAL hash-chain walk), the last
 *               snapshot_recorded tick (the F4 full-sync tick carrying a non-null
 *               snapshot_hash + honest graph counts in delta_summary), and growth:
 *               last-vs-previous snapshot tick deltas AND live-store-vs-last-tick
 *               deltas. Ticks that carry no counts (the legacy rationale_event
 *               writers) are reported in the chain but never used to invent a delta.
 *  - flags      the real config flags (F3 injection, ingest-on-write, schedule
 *               gate, query ranking) + schedule_registered probed from the LIVE
 *               scheduler event list (null when the scheduler cannot be inspected —
 *               an honest "unknown", not a guess).
 *
 * Read-only and local-only. Consumed by `atlas:aurg:status`.
 */
class AtlasRealityGraphStatusService
{
    public function __construct(
        private readonly AtlasUnifiedRealityGraphTemporalService $temporal,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function status(): array
    {
        $store = $this->storeStatus();
        $temporal = $this->temporalStatus($store);

        return [
            'enabled' => (bool) config('atlas.aurg.enabled', true),
            'store' => $store,
            'temporal' => $temporal,
            'flags' => $this->flagStatus(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    // ------------------------------------------------------------------
    // Store (live counts straight from the fused tables)
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function storeStatus(): array
    {
        if (! $this->tableExists('atlas_aurg_nodes') || ! $this->tableExists('atlas_aurg_edges')) {
            return ['available' => false, 'reason' => 'tables_missing'];
        }

        $edgesBySource = $this->groupCount('atlas_aurg_edges', 'source');
        $linkerTotal = 0;
        foreach ($edgesBySource as $source => $count) {
            if (str_starts_with((string) $source, 'linker_')) {
                $linkerTotal += $count;
            }
        }

        return [
            'available' => true,
            'nodes_total' => (int) AtlasAurgNode::query()->count(),
            'edges_total' => (int) AtlasAurgEdge::query()->count(),
            'nodes_by_source_kind' => $this->groupCount('atlas_aurg_nodes', 'source_kind'),
            'nodes_by_kind' => $this->groupCount('atlas_aurg_nodes', 'kind'),
            'edges_by_kind' => $this->groupCount('atlas_aurg_edges', 'kind'),
            // Linker edge counts: edges keyed by their deterministic producer
            // (linker_* = the cite-or-omit cross-layer linkers; the rest are
            // same-source ingest edges).
            'edges_by_source' => $edgesBySource,
            'linker_edges_total' => $linkerTotal,
            'provider_safe_nodes' => (int) AtlasAurgNode::query()->where('provider_safe', true)->count(),
            'sensitive_nodes' => (int) AtlasAurgNode::query()->where('sensitive', true)->count(),
            'last_ingest_at' => $this->lastIngestAt(),
        ];
    }

    /**
     * @return array<string,int>
     */
    private function groupCount(string $table, string $column): array
    {
        $rows = DB::table($table)
            ->select($column, DB::raw('count(*) as aggregate'))
            ->groupBy($column)
            ->orderBy($column)
            ->get();

        $out = [];
        foreach ($rows as $row) {
            $out[(string) $row->{$column}] = (int) $row->aggregate;
        }

        return $out;
    }

    private function lastIngestAt(): ?string
    {
        $raw = AtlasAurgNode::query()->max('updated_at');
        if ($raw === null) {
            return null;
        }

        try {
            return Carbon::parse((string) $raw)->toIso8601String();
        } catch (Throwable) {
            return (string) $raw;
        }
    }

    // ------------------------------------------------------------------
    // Temporal (the 4D chain: length, integrity, growth)
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $store
     * @return array<string,mixed>
     */
    private function temporalStatus(array $store): array
    {
        $verify = $this->temporal->verifyChain();
        $timeline = $this->temporal->timeline(0); // 0 = unsliced: the FULL chain
        $ticks = array_values(array_filter((array) ($timeline['ticks'] ?? []), 'is_array'));

        // F4 snapshot ticks: kind=snapshot_recorded AND a real snapshot_hash AND
        // honest counts in delta_summary. Legacy empty rationale_event ticks stay
        // in the chain but can never produce a growth number.
        $snapshotTicks = array_values(array_filter(
            $ticks,
            static fn (array $tick): bool => ($tick['kind'] ?? null) === AtlasUnifiedRealityGraphTemporalService::KIND_SNAPSHOT_RECORDED
                && is_string($tick['snapshot_hash'] ?? null)
                && ($tick['snapshot_hash'] ?? '') !== ''
                && is_numeric(($tick['delta_summary'] ?? [])['node_count'] ?? null),
        ));

        $last = $snapshotTicks !== [] ? $snapshotTicks[count($snapshotTicks) - 1] : null;
        $previous = count($snapshotTicks) >= 2 ? $snapshotTicks[count($snapshotTicks) - 2] : null;

        $lastSnapshot = null;
        if ($last !== null) {
            $lastSummary = (array) ($last['delta_summary'] ?? []);
            $lastSnapshot = [
                'tick_id' => (string) ($last['tick_id'] ?? ''),
                'at' => (string) ($last['at'] ?? ''),
                'snapshot_hash' => (string) ($last['snapshot_hash'] ?? ''),
                'node_count' => (int) ($lastSummary['node_count'] ?? 0),
                'edge_count' => (int) ($lastSummary['edge_count'] ?? 0),
                'truncated' => (bool) ($lastSummary['truncated'] ?? false),
            ];
        }

        $vsPrevious = null;
        if ($last !== null && $previous !== null) {
            $lastSummary = (array) ($last['delta_summary'] ?? []);
            $previousSummary = (array) ($previous['delta_summary'] ?? []);
            $vsPrevious = [
                'nodes_delta' => (int) ($lastSummary['node_count'] ?? 0) - (int) ($previousSummary['node_count'] ?? 0),
                'edges_delta' => (int) ($lastSummary['edge_count'] ?? 0) - (int) ($previousSummary['edge_count'] ?? 0),
                'snapshot_hash_changed' => ($last['snapshot_hash'] ?? null) !== ($previous['snapshot_hash'] ?? null),
                'previous_tick_id' => (string) ($previous['tick_id'] ?? ''),
                'latest_tick_id' => (string) ($last['tick_id'] ?? ''),
            ];
        }

        $liveVsLast = null;
        if ($last !== null && (bool) ($store['available'] ?? false)) {
            $lastSummary = (array) ($last['delta_summary'] ?? []);
            $liveVsLast = [
                'nodes_delta' => (int) ($store['nodes_total'] ?? 0) - (int) ($lastSummary['node_count'] ?? 0),
                'edges_delta' => (int) ($store['edges_total'] ?? 0) - (int) ($lastSummary['edge_count'] ?? 0),
            ];
        }

        return [
            'chain_length' => (int) ($timeline['tick_count'] ?? 0),
            'chain_intact' => (bool) $verify['chain_intact'],
            'chain_break_at' => $verify['chain_break_at'],
            'ticks_walked' => (int) $verify['ticks_walked'],
            'snapshot_ticks' => count($snapshotTicks),
            'last_snapshot' => $lastSnapshot,
            'growth' => [
                'vs_previous_snapshot' => $vsPrevious,
                'live_vs_last_snapshot' => $liveVsLast,
            ],
        ];
    }

    // ------------------------------------------------------------------
    // Flags (real config + live scheduler probe)
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>
     */
    private function flagStatus(): array
    {
        return [
            // F3 read-back: is the brain riding the live prompt path?
            'injection_include_reality_graph' => (bool) config('atlas.open_brain.injection.include_reality_graph', false),
            // F4 compounding: is the memory write path accruing into the brain?
            'ingest_on_write' => (bool) config('atlas.aurg.ingest_on_write', true),
            // F4 schedule gate + the LIVE registration probe.
            'schedule_enabled' => (bool) config('atlas.aurg.schedule_enabled', true),
            'schedule_registered' => $this->scheduleRegistered(),
            // F2 ranking kill-switch (Python graph_rank delegation).
            'query_rank_enabled' => (bool) config('atlas.aurg.query_rank_enabled', true),
        ];
    }

    /**
     * Probes the LIVE scheduler event list for the daily full sync. Honest
     * tri-state: true (found), false (scheduler inspected, not found), null
     * (scheduler not inspectable here — unknown, never guessed).
     */
    private function scheduleRegistered(): ?bool
    {
        try {
            $schedule = app(Schedule::class);
            foreach ($schedule->events() as $event) {
                if (str_contains((string) ($event->command ?? ''), 'atlas:aurg:ingest')) {
                    return true;
                }
            }

            return false;
        } catch (Throwable) {
            return null;
        }
    }

    private function tableExists(string $table): bool
    {
        try {
            return Schema::hasTable($table);
        } catch (Throwable) {
            return false;
        }
    }
}
