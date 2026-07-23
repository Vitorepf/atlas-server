<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Ai\Reality\AtlasRealityGraphIngestionService;
use App\Services\Ai\Reality\AtlasUnifiedRealityGraphTemporalService;
use Illuminate\Console\Command;
use Throwable;

/**
 * AURG Phase-2 / F1 — fused-store ingestion (Salto 1, "AURG vivo").
 *
 * Federates bounded provider-safe projections of the 5 real read-models
 * (memory / code / domains / evidence / strategic) into atlas_aurg_nodes +
 * atlas_aurg_edges and runs the deterministic cite-or-omit cross-layer linkers.
 * Local-only: reads local read-models, writes local brain tables, never touches
 * a provider. Idempotent; --prune removes nodes whose source rows vanished
 * (scoped per source kind).
 *
 * F4 (temporal): a FULL sync (--source=all) additionally appends one REAL
 * snapshot tick to the AURG-4D chain — non-null snapshot_hash built by the
 * canonical Builder over the store's bounded id+hash state, plus honest counts
 * (the growth signal `atlas:aurg:status` reads). Partial --source syncs never
 * tick (they'd hash a deliberately partial view of a full-state chain). Tick
 * failure never fails an ingest that already succeeded — it is REPORTED.
 */
class AtlasAurgIngestCommand extends Command
{
    protected $signature = 'atlas:aurg:ingest
        {--source=all : Which source to sync (all|memory|code|domains|evidence|strategic)}
        {--prune : Remove brain nodes whose source row vanished (scoped to the synced source kinds)}
        {--json : Emit a JSON stats report}';

    protected $description = 'AURG F1: ingest bounded provider-safe projections of the 5 read-models into the fused reality-graph store (atlas_aurg_nodes/atlas_aurg_edges) + deterministic cross-layer linkers.';

    public function handle(): int
    {
        $json = (bool) $this->option('json');

        if (! (bool) config('atlas.aurg.enabled', true)) {
            $payload = [
                'status' => 'disabled',
                'reason' => 'atlas.aurg.enabled is false',
                'hint' => 'set ATLAS_AURG_ENABLED=true to activate.',
            ];
            $json
                ? $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->warn('AURG fused store is disabled (set ATLAS_AURG_ENABLED=true).');

            return self::SUCCESS;
        }

        $source = strtolower(trim((string) $this->option('source')));
        $sources = $source === '' || $source === 'all'
            ? AtlasRealityGraphIngestionService::SOURCES
            : [$source];
        $invalid = array_diff($sources, AtlasRealityGraphIngestionService::SOURCES);
        if ($invalid !== []) {
            $this->error('Invalid --source "'.$source.'". Allowed: all|'.implode('|', AtlasRealityGraphIngestionService::SOURCES));

            return self::FAILURE;
        }

        // Mesh is optional + resolved defensively — a mesh hiccup must never break
        // ingestion (domain nodes still build; only allowed-crossing edges are skipped).
        $mesh = null;
        try {
            $mesh = app(AtlasCrossDomainMeshService::class);
        } catch (Throwable) {
            // fall through: domains without mesh edges.
        }

        $service = new AtlasRealityGraphIngestionService(
            app(\App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap::class),
            app(\App\Services\Ai\MemoryGovernance\AtlasMemoryPrivacyService::class),
            $mesh,
        );

        $stats = $service->sync($sources, (bool) $this->option('prune'));

        // F4 temporal: only the FULL sync ticks the 4D chain (real snapshot_hash
        // over the whole fused store). Fail-open: the ingest already succeeded.
        $temporal = null;
        if ($sources === AtlasRealityGraphIngestionService::SOURCES) {
            try {
                $temporal = $service->recordTemporalSnapshot(
                    app(AtlasUnifiedRealityGraphTemporalService::class),
                    'atlas',
                    'aurg full sync (atlas:aurg:ingest'.((bool) $this->option('prune') ? ' --prune' : '').')',
                );
            } catch (Throwable $exception) {
                report($exception);
                $temporal = ['recorded' => false, 'reason' => 'tick_failed'];
            }
        }

        $payload = ['status' => 'ok'] + $stats + ($temporal !== null ? ['temporal' => $temporal] : []);

        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('AURG fused-store ingest (F1)');
        foreach ($stats['sources'] as $name => $counts) {
            $this->line("  {$name}: nodes={$counts['nodes']} edges={$counts['edges']}");
        }
        $this->info('Cross-layer linkers (deterministic, cite-or-omit):');
        foreach ($stats['linkers'] as $name => $count) {
            $this->line("  {$name}: {$count}");
        }
        $this->info('Store totals:');
        foreach ($stats['totals'] as $name => $count) {
            $this->line("  {$name}: {$count}");
        }
        if (($stats['pruned']['nodes'] ?? 0) > 0 || ($stats['pruned']['edges'] ?? 0) > 0) {
            $this->line('  pruned: nodes='.$stats['pruned']['nodes'].' edges='.$stats['pruned']['edges']);
        }
        if ($temporal !== null) {
            (bool) ($temporal['recorded'] ?? false)
                ? $this->line('  temporal tick: '.$temporal['tick_id'].' snapshot_hash='.substr((string) $temporal['snapshot_hash'], 0, 16).'… nodes='.$temporal['node_count'].' edges='.$temporal['edge_count'])
                : $this->warn('  temporal tick: NOT recorded ('.(string) ($temporal['reason'] ?? 'unknown').')');
        }
        $this->line('  duration_ms: '.$stats['duration_ms']);

        return self::SUCCESS;
    }
}
