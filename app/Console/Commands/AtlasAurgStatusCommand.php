<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasRealityGraphStatusService;
use Illuminate\Console\Command;
use App\Support\YesNo;

/**
 * AURG Phase-2 / F4 — the brain's health surface (Salto 1, "AURG vivo").
 *
 * Read-only, local-only report over the fused store + the AURG-4D temporal chain:
 * live node/edge counts (by source_kind / kind / linker), last_ingest_at, chain
 * length + verifyChain(), growth deltas vs the previous full-sync snapshot tick,
 * and the real flag states (F3 injection, ingest-on-write, daily schedule). Every
 * number is computed at call time from the store/chain — nothing cached, nothing
 * fabricated. A missing store or a broken chain is REPORTED, not masked.
 */
class AtlasAurgStatusCommand extends Command
{
    protected $signature = 'atlas:aurg:status
        {--json : Emit the full JSON status report}';

    protected $description = 'AURG F4: honest health surface of the fused reality-graph brain (live store counts, temporal chain integrity, growth deltas, flag states).';

    public function handle(AtlasRealityGraphStatusService $status): int
    {
        $report = $status->status();

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('AURG status (F4) — enabled='.(YesNo::format($report['enabled'])));

        $store = (array) $report['store'];
        if (! (bool) ($store['available'] ?? false)) {
            $this->warn('  store: UNAVAILABLE ('.(string) ($store['reason'] ?? 'unknown').') — run the F1 migration, then atlas:aurg:ingest');
        } else {
            $this->info('  store:');
            $this->line('    nodes_total: '.$store['nodes_total'].'  edges_total: '.$store['edges_total']);
            $this->line('    nodes_by_source_kind: '.$this->inlineMap((array) $store['nodes_by_source_kind']));
            $this->line('    nodes_by_kind: '.$this->inlineMap((array) $store['nodes_by_kind']));
            $this->line('    edges_by_kind: '.$this->inlineMap((array) $store['edges_by_kind']));
            $this->line('    edges_by_source: '.$this->inlineMap((array) $store['edges_by_source']));
            $this->line('    linker_edges_total: '.$store['linker_edges_total']);
            $this->line('    provider_safe_nodes: '.$store['provider_safe_nodes'].'  sensitive_nodes: '.$store['sensitive_nodes']);
            $this->line('    last_ingest_at: '.((string) ($store['last_ingest_at'] ?? '') ?: 'never'));
        }

        $temporal = (array) $report['temporal'];
        $this->info('  temporal:');
        $this->line('    chain_length: '.$temporal['chain_length'].'  snapshot_ticks: '.$temporal['snapshot_ticks']);
        $temporal['chain_intact']
            ? $this->line('    chain_intact: yes (ticks_walked='.$temporal['ticks_walked'].')')
            : $this->warn('    chain_intact: NO — break at '.(string) ($temporal['chain_break_at'] ?? '<unknown>'));
        $last = $temporal['last_snapshot'];
        if (is_array($last)) {
            $this->line('    last_snapshot: '.$last['tick_id'].' @ '.$last['at'].' nodes='.$last['node_count'].' edges='.$last['edge_count']);
            $this->line('    snapshot_hash: '.substr((string) $last['snapshot_hash'], 0, 24).'…');
        } else {
            $this->line('    last_snapshot: none yet (a full atlas:aurg:ingest records one)');
        }
        $growth = (array) $temporal['growth'];
        $vsPrevious = $growth['vs_previous_snapshot'];
        $this->line(is_array($vsPrevious)
            ? '    growth_vs_previous: nodes '.$this->signed((int) $vsPrevious['nodes_delta']).' edges '.$this->signed((int) $vsPrevious['edges_delta']).' hash_changed='.(YesNo::format($vsPrevious['snapshot_hash_changed']))
            : '    growth_vs_previous: n/a (need two full-sync snapshot ticks)');
        $liveVsLast = $growth['live_vs_last_snapshot'];
        if (is_array($liveVsLast)) {
            $this->line('    live_vs_last_snapshot: nodes '.$this->signed((int) $liveVsLast['nodes_delta']).' edges '.$this->signed((int) $liveVsLast['edges_delta']));
        }

        $flags = (array) $report['flags'];
        $this->info('  flags:');
        foreach ($flags as $flag => $value) {
            $this->line('    '.$flag.': '.($value === null ? 'unknown' : ($value ? 'on' : 'off')));
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string,int>  $map
     */
    private function inlineMap(array $map): string
    {
        if ($map === []) {
            return '(none)';
        }
        $parts = [];
        foreach ($map as $key => $count) {
            $parts[] = $key.'='.$count;
        }

        return implode(' ', $parts);
    }

    private function signed(int $value): string
    {
        return $value >= 0 ? '+'.$value : (string) $value;
    }
}
