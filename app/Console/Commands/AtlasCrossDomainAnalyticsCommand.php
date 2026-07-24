<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use App\Services\Engineering\CodeGraph\CodeGraphRuntimeInvoker;
use App\Services\Engineering\CodeGraph\CrossDomainGraphIngestionService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;
use Illuminate\Console\Command;
use Throwable;
use App\Console\Concerns\EmitsCanonicalJson;

/**
 * AP-814 · M-8 Fase-3 — run the EXISTING domain-agnostic python graph algorithms
 * (Brandes betweenness / community detection) over the cross-domain edge set via
 * the governed PHP→python_ai_data boundary ({@see CodeGraphRuntimeInvoker}).
 *
 * The point this command PROVES: the heavy graph runtime that was built for the
 * code graph is domain-agnostic — it computes a number over `{edges:[...]}` it is
 * handed and nothing else. So the SAME runtime, behind the SAME governed venv seam,
 * works over the cross-domain graph WITHOUT a new runtime, model, or any decision
 * leaking into python. The brain (PHP) assembles + privacy-tags the edges here; the
 * muscle only ranks them.
 *
 * Flag-gated (atlas.cross_domain_graph.enabled, default OFF). Read-only: it assembles
 * the cross-domain edges, hands them across the boundary, and prints the ranking.
 * Nothing is persisted or crossed to a provider. If the venv/python is unavailable
 * the invoke returns 'blocked' (or, on a process error, we report 'runtime_unavailable')
 * — never fatal.
 */
class AtlasCrossDomainAnalyticsCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:cross-domain:analytics
        {--op=betweenness : Domain-agnostic graph op to run (betweenness|communities)}
        {--json : Emit a JSON report}';

    protected $description = 'AP-814 M-8 Fase-3: run the existing domain-agnostic python graph algorithms (betweenness/communities) over the cross-domain edge set via the governed venv boundary — read-only, flag-gated, reusing the code-graph runtime (no new runtime).';

    /** @var list<string> */
    private const ALLOWED_OPS = ['betweenness', 'communities'];

    public function handle(CrossDomainTaxonomyMap $taxonomy, CodeGraphRuntimeInvoker $invoker): int
    {
        $json = (bool) $this->option('json');
        $op = strtolower(trim((string) $this->option('op')));

        if (! in_array($op, self::ALLOWED_OPS, true)) {
            $payload = [
                'status' => 'invalid_op',
                'op' => $op,
                'allowed_ops' => self::ALLOWED_OPS,
            ];
            $json ? $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->error("Unsupported --op '{$op}'. Allowed: ".implode(', ', self::ALLOWED_OPS).'.');

            return self::FAILURE;
        }

        // Flag gate — clean 'disabled' SUCCESS, mirroring AtlasCrossDomainGraphBuildCommand.
        if (! (bool) config('atlas.cross_domain_graph.enabled', false)) {
            $payload = [
                'status' => 'disabled',
                'op' => $op,
                'reason' => 'atlas.cross_domain_graph.enabled is false',
                'hint' => 'set ATLAS_CROSS_DOMAIN_GRAPH_ENABLED=true to activate (AP-814).',
            ];
            $json ? $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->warn('Cross-domain analytics is disabled (set ATLAS_CROSS_DOMAIN_GRAPH_ENABLED=true).');

            return self::SUCCESS;
        }

        // Mesh is optional + resolved defensively — a mesh hiccup must never break this
        // read-only command (handoff edges still build without it).
        $mesh = null;
        try {
            $mesh = app(AtlasCrossDomainMeshService::class);
        } catch (Throwable) {
            // fall through: handoff-only graph.
        }
        $ingestion = new CrossDomainGraphIngestionService($taxonomy, $mesh);

        $maxDomains = (int) config('atlas.cross_domain_graph.max_domains', CrossDomainGraphIngestionService::DEFAULT_MAX_DOMAINS);
        $maxEdges = (int) config('atlas.cross_domain_graph.max_edges', CrossDomainGraphIngestionService::DEFAULT_MAX_EDGES);

        $graph = $ingestion->gather($maxDomains, $maxEdges);
        $edges = $graph['edges'];

        // Hand the edge set across the governed boundary. The python op only needs
        // {edges:[...]}; it never sees domain identity, policy, or any decision.
        $result = null;
        try {
            // AP-815 A2: the brain mints a Decision Receipt authorizing this exact
            // op+edges before the governed boundary; invoke() blocks without it.
            $receipt = CodeGraphRuntimeInvoker::mintReceipt($op, ['edges' => $edges], 'atlas:cross-domain:analytics');
            $result = $invoker->invoke($op, ['edges' => $edges], ['timeout_seconds' => 30], $receipt);
        } catch (Throwable $e) {
            $payload = [
                'status' => 'runtime_unavailable',
                'op' => $op,
                'reason' => $e->getMessage(),
                'edge_count' => count($edges),
            ];
            $json ? $this->line((string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES))
                : $this->warn("Cross-domain analytics runtime unavailable for '{$op}': ".$e->getMessage());

            return self::SUCCESS;
        }

        $runtimeStatus = (string) ($result['status'] ?? 'unknown');

        $payload = [
            'status' => $runtimeStatus,
            'op' => $op,
            'schema_version' => $graph['schema_version'],
            'stats' => $graph['stats'],
            'runtime' => [
                'status' => $runtimeStatus,
                'metrics' => $result['metrics'] ?? [],
                'artifacts' => $result['artifacts'] ?? [],
                'findings' => $result['findings'] ?? [],
            ],
        ];

        if ($json) {
            $this->line($this->encode($payload));

            return self::SUCCESS;
        }

        $this->info("Cross-domain analytics — op={$op} (read-only, AP-814 Fase-3)");
        foreach ($graph['stats'] as $key => $value) {
            $this->line("  {$key}: {$value}");
        }
        $this->line("  runtime status: {$runtimeStatus}");

        if ($runtimeStatus === CodeGraphRuntimeInvoker::STATUS_BLOCKED) {
            $reason = $result['findings'][0]['reason'] ?? 'unknown';
            $this->warn("  runtime blocked: {$reason} (this is honest, not fatal).");

            return self::SUCCESS;
        }

        $ranked = $this->extractRanked($result);
        if ($ranked !== []) {
            $this->info('Top ranked cross-domain nodes:');
            foreach ($ranked as $entry) {
                if (! is_array($entry)) {
                    continue;
                }
                $node = (string) ($entry['node_id'] ?? $entry['node'] ?? '?');
                $score = $entry['score'] ?? $entry['value'] ?? $entry['community'] ?? '';
                $this->line("  {$node} — {$score}");
            }
        }

        return self::SUCCESS;
    }

    /**
     * Pull the ranked list out of the atlas.runtime.result.v1 artifacts, defensively.
     *
     * @param  array<string,mixed>  $result
     * @return list<mixed>
     */
    private function extractRanked(array $result): array
    {
        $artifacts = is_array($result['artifacts'] ?? null) ? $result['artifacts'] : [];
        foreach ($artifacts as $artifact) {
            if (! is_array($artifact)) {
                continue;
            }
            $payload = is_array($artifact['result'] ?? null) ? $artifact['result'] : [];
            $ranked = $payload['ranked'] ?? $payload['communities'] ?? null;
            if (is_array($ranked)) {
                return array_values($ranked);
            }
        }

        return [];
    }
}
