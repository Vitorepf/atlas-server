<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use Illuminate\Console\Command;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AURG Phase-2 / F2 — brain query with provenance (Salto 1, "AURG vivo").
 *
 * Queries the fused store (atlas_aurg_nodes/atlas_aurg_edges): hybrid seeds
 * (real memory vectors + per-term lexical), bounded BFS traversal and the
 * cross-layer node→edge→node chains, ranked by the Python graph_rank runtime
 * when available (honest 'unranked_*' otherwise — never fabricated scores).
 *
 * Local-only read surface: the default (unbounded) view may include
 * non-provider-safe refs with REDACTED labels; --provider-bound simulates the
 * provider view (the MCP tool atlas_aurg_query forces it).
 */
class AtlasAurgQueryCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:aurg:query
        {query : Natural multi-term query against the fused reality graph}
        {--depth= : BFS depth (default config atlas.aurg.query_depth, hard cap 3)}
        {--limit= : Max nodes returned (default config atlas.aurg.query_max_nodes)}
        {--provider-bound : Restrict to provider_safe nodes; sensitive domains and anything reachable only through them are excluded}
        {--expand= : MAXD-07 federated drill-down (comma list). Only "code" today: expand module nodes to top-N symbols read live from atlas_engineering_code_symbols; never persisted in the AURG store.}
        {--expand-per-module= : MAXD-07 per-module cap for --expand=code (default 5, hard cap 20).}
        {--json : Emit the full JSON result}';

    protected $description = 'AURG F2: query the fused reality-graph brain with provenance (hybrid seeds + bounded traversal + cross-layer paths + honest ranking).';

    public function handle(AtlasRealityGraphQueryService $service): int
    {
        if (! (bool) config('atlas.aurg.enabled', true)) {
            $this->warn('AURG fused store is disabled (set ATLAS_AURG_ENABLED=true).');

            return self::SUCCESS;
        }

        $query = trim((string) $this->argument('query'));
        if ($query === '') {
            $this->error('Query must not be empty.');

            return self::FAILURE;
        }

        $opts = ['provider_bound' => (bool) $this->option('provider-bound')];
        if (is_numeric($this->option('depth'))) {
            $opts['depth'] = (int) $this->option('depth');
        }
        if (is_numeric($this->option('limit'))) {
            $opts['max_nodes'] = (int) $this->option('limit');
        }
        $expand = trim((string) $this->option('expand'));
        if ($expand !== '') {
            $opts['expand'] = $expand;
        }
        if (is_numeric($this->option('expand-per-module'))) {
            $opts['expand_symbols_per_module'] = (int) $this->option('expand-per-module');
        }

        $result = $service->query($query, $opts);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($result));

            return self::SUCCESS;
        }

        $this->info('AURG brain query (F2)');
        $this->line('  query: '.$result['query']);
        $this->line('  terms: '.implode(', ', $result['terms']));
        $this->line('  provider_bound: '.(YesNo::format($result['provider_bound'])).'  depth: '.$result['depth'].'  ranking: '.$result['ranking']);

        $this->info('Seeds ('.count($result['seeds']).'):');
        foreach ($result['seeds'] as $seed) {
            $detail = $seed['via'] === AtlasRealityGraphQueryService::SEED_VIA_SEMANTIC
                ? 'similarity='.$seed['score']
                : 'terms='.implode(',', (array) ($seed['matched_terms'] ?? []));
            $this->line("  [{$seed['source_kind']}/{$seed['kind']}] {$seed['label']} ({$seed['via']}, {$detail})");
        }

        $this->info('Nodes ('.count($result['nodes']).'):');
        foreach (array_slice($result['nodes'], 0, 15) as $node) {
            $flags = ($node['seed'] ? ' seed' : '').($node['provider_safe'] ? '' : ' local-only');
            $rank = isset($node['rank']) ? ' score='.$node['rank']['score'] : '';
            $this->line("  [{$node['source_kind']}/{$node['kind']}] {$node['label']} (d{$node['depth']}{$flags}{$rank})");
        }
        if (count($result['nodes']) > 15) {
            $this->line('  ... '.(count($result['nodes']) - 15).' more (use --json for the full set)');
        }

        $this->info('Cross-layer paths ('.$result['counts']['cross_layer_paths'].' of '.$result['counts']['paths'].'):');
        $labels = [];
        foreach ($result['nodes'] as $node) {
            $labels[$node['id']] = $node['label'];
        }
        $shown = 0;
        foreach ($result['paths'] as $path) {
            if (! $path['cross_layer'] || $shown >= 10) {
                continue;
            }
            $chain = $labels[$path['nodes'][0]] ?? $path['nodes'][0];
            foreach ($path['hops'] as $hop) {
                $chain .= ' -('.$hop['edge_kind'].' '.$hop['confidence'].')-> '.($labels[$hop['to']] ?? $hop['to']);
            }
            $this->line('  '.$chain);
            $shown++;
        }

        $capsHit = array_keys(array_filter($result['caps_hit']));
        if ($capsHit !== []) {
            $this->warn('  caps hit: '.implode(', ', $capsHit));
        }

        return self::SUCCESS;
    }
}
