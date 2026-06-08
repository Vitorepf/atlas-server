<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Engineering\CodeGraph\CodeGraphAnalytics;
use App\Services\Engineering\CodeGraph\CrossDomainGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Console\Command;
use Throwable;

/**
 * AP-814 · M-8 Fase-1 — build (read-only) the cross-domain entity graph from the
 * real domain handoffs + run the existing domain-agnostic analytics over it.
 *
 * Flag-gated (atlas.cross_domain_graph.enabled, default OFF). Read-only: nothing is
 * persisted or crossed to a provider in Fase-1.
 */
class AtlasCrossDomainGraphBuildCommand extends Command
{
    protected $signature = 'atlas:cross-domain:graph-build {--persist : Persist the assembled graph into the cross-domain world model (gated by atlas.cross_domain_graph.persist)} {--json : Emit a JSON report}';

    protected $description = 'AP-814 M-8: assemble the cross-domain entity graph (domains + handoffs + mesh edges + entities), run god-node analytics, and optionally persist into the cross-domain world model — flag-gated.';

    public function handle(CrossDomainTaxonomyMap $taxonomy, CodeGraphAnalytics $analytics): int
    {
        $json = (bool) $this->option('json');

        // Mesh is optional + resolved defensively — a mesh hiccup must never break
        // this read-only command (the handoff edges still build without it).
        $mesh = null;
        try {
            $mesh = app(AtlasCrossDomainMeshService::class);
        } catch (Throwable) {
            // fall through: handoff-only graph.
        }
        $ingestion = new CrossDomainGraphIngestionService($taxonomy, $mesh);

        if (! (bool) config('atlas.cross_domain_graph.enabled', false)) {
            $payload = [
                'status' => 'disabled',
                'reason' => 'atlas.cross_domain_graph.enabled is false',
                'hint' => 'set ATLAS_CROSS_DOMAIN_GRAPH_ENABLED=true to activate (AP-814).',
            ];
            $json ? $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->warn('Cross-domain graph is disabled (set ATLAS_CROSS_DOMAIN_GRAPH_ENABLED=true).');

            return self::SUCCESS;
        }

        $maxDomains = (int) config('atlas.cross_domain_graph.max_domains', CrossDomainGraphIngestionService::DEFAULT_MAX_DOMAINS);
        $maxEdges = (int) config('atlas.cross_domain_graph.max_edges', CrossDomainGraphIngestionService::DEFAULT_MAX_EDGES);

        $graph = $ingestion->gather($maxDomains, $maxEdges);
        $god = $analytics->godNodes($graph['edges'], 10);
        $godNodes = is_array($god['god_nodes'] ?? null) ? $god['god_nodes'] : [];

        $payload = [
            'status' => 'ok',
            'schema_version' => $graph['schema_version'],
            'stats' => $graph['stats'],
            'god_nodes' => $godNodes,
        ];

        // Fase-3: optional persist into the cross-domain world model (REUSE of the
        // world-model tables) — gated by both the --persist flag AND config.persist.
        if ($this->option('persist') && (bool) config('atlas.cross_domain_graph.persist', false)) {
            try {
                $persister = app(\App\Services\Engineering\CodeGraph\CrossDomainWorldModelPersister::class);
                $persisted = $persister->persist($graph['nodes'], $graph['edges']);
                $payload['persisted'] = [
                    'world_model_id' => $persisted['world_model_id'],
                    'node_count' => $persisted['node_count'],
                    'edge_count' => $persisted['edge_count'],
                ];
            } catch (Throwable $e) {
                $payload['persisted'] = ['error' => substr($e->getMessage(), 0, 120)];
            }
        }

        if ($json) {
            $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }

        $this->info('Cross-domain graph (read-only, AP-814 Fase-1)');
        foreach ($graph['stats'] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }
        if ($godNodes !== []) {
            $this->info('Top cross-domain god-nodes (by degree):');
            foreach ($godNodes as $node) {
                $this->line('  '.($node['node_id'] ?? '?').' — degree '.($node['degree'] ?? 0));
            }
        }

        return self::SUCCESS;
    }
}
