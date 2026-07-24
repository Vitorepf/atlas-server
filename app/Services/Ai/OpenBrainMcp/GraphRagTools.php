<?php

namespace App\Services\Ai\OpenBrainMcp;

use App\Services\Ai\Compression\AtlasCcrStore;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Engineering\CodeGraph\CrossDomainGraphTraversalService;
use App\Services\Engineering\CodeGraph\CrossDomainTaxonomyMap;

/**
 * GOD-DEBULK FASE C: read-only graph/RAG retrieval tool family extracted verbatim
 * from AtlasOpenBrainMcpService — atlas_ccr_retrieve (AP-813 CCR original by hash),
 * atlas_cross_domain_query, atlas_aurg_query (AURG fused reality graph). All are
 * provider-safe/provider-bound reads over local services resolved via app().
 * Bodies byte-identical to the pre-split service; the façade delegates here.
 */
class GraphRagTools
{
    use OpenBrainMcpToolInput;

    /**
     * AP-813 · retrieve a CCR original by content hash. Read-only,
     * lossless-by-governance. Privacy gate mirrors the bridge-evidence secret-class
     * rule: a secret/sensitive original is NEVER surfaced over this provider-safe path.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function ccrRetrieve(array $arguments): array
    {
        $hash = $this->string($arguments['hash'] ?? null);
        if ($hash === null || $hash === '') {
            return ['ok' => false, 'tool' => 'atlas_ccr_retrieve', 'error' => 'hash_required'];
        }

        $store = app(AtlasCcrStore::class);
        $result = $store->retrieve($hash, [
            'correlation_id' => $this->string($arguments['correlation_id'] ?? null),
            'trace_id' => $this->string($arguments['trace_id'] ?? null),
            'recorded_by' => 'open_brain_mcp',
        ]);

        if (($result['found'] ?? false) !== true) {
            return ['ok' => false, 'tool' => 'atlas_ccr_retrieve', 'error' => 'original_not_found'];
        }

        $privacyClass = (string) ($result['privacy_class'] ?? 'internal');
        if (in_array($privacyClass, ['secret', 'sensitive'], true)) {
            return ['ok' => false, 'tool' => 'atlas_ccr_retrieve', 'error' => 'not_provider_safe', 'privacy_class' => $privacyClass];
        }

        return [
            'ok' => true,
            'tool' => 'atlas_ccr_retrieve',
            'hash' => $hash,
            'content_type' => $result['content_type'],
            'original' => $result['original'],
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * M-8 Fase-2 (AP-814): the cross-domain killer query — what is reachable across
     * domains under the ARPTL veto. Read-only, flag-gated. Provider-safe: returns
     * graph topology (domain labels + vetoes), never domain content.
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function crossDomainQuery(array $arguments): array
    {
        $tool = 'atlas_cross_domain_query';
        $seed = $this->string($arguments['seed'] ?? null);
        if ($seed === null || $seed === '') {
            return ['ok' => false, 'tool' => $tool, 'error' => 'seed_required'];
        }
        if (! (bool) config('atlas.cross_domain_graph.enabled', false)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'cross_domain_graph_disabled'];
        }

        $taxonomy = app(CrossDomainTaxonomyMap::class);
        // Accept "domain:finance", "finance", a mesh id, or a registry id.
        if (! str_starts_with($seed, 'domain:')) {
            $canonical = $taxonomy->canonical($seed);
            $seed = $canonical !== null ? 'domain:'.$canonical : $seed;
        }

        $privacy = $this->string($arguments['privacy_class'] ?? null) ?? 'normal';
        $traversal = app(CrossDomainGraphTraversalService::class);
        $result = $traversal->killerQuery($seed, $privacy);

        return [
            'ok' => (bool) ($result['ok'] ?? false),
            'tool' => $tool,
            'seed' => $seed,
            'query' => $result,
            'generated_at' => now()->toJSON(),
        ];
    }

    /**
     * AURG F2 (Salto 1): the fused reality-graph brain query with provenance.
     * Read-only. provider_bound is FORCED TRUE on this surface — MCP output can
     * land in provider prompts, and sensitive domains (plus anything reachable
     * only through them) are NEVER included in any provider prompt output. The
     * unbounded local view is the operator CLI (atlas:aurg:query).
     *
     * @param  array<string,mixed>  $arguments
     * @return array<string,mixed>
     */
    public function aurgQuery(array $arguments): array
    {
        $tool = 'atlas_aurg_query';
        $query = $this->string($arguments['query'] ?? null);
        if ($query === null) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'query_required'];
        }
        if (! (bool) config('atlas.aurg.enabled', true)) {
            return ['ok' => false, 'tool' => $tool, 'error' => 'aurg_disabled'];
        }

        $opts = ['provider_bound' => true]; // structural: never relaxable via MCP
        if (is_numeric($arguments['depth'] ?? null)) {
            $opts['depth'] = (int) $arguments['depth'];
        }
        if (is_numeric($arguments['limit'] ?? null)) {
            $opts['max_nodes'] = (int) $arguments['limit'];
        }
        $expand = $this->string($arguments['expand'] ?? null);
        if ($expand !== null && $expand !== '') {
            $opts['expand'] = $expand;
        }
        if (is_numeric($arguments['expand_per_module'] ?? null)) {
            $opts['expand_symbols_per_module'] = (int) $arguments['expand_per_module'];
        }

        $result = app(AtlasRealityGraphQueryService::class)->query($query, $opts);

        return [
            'ok' => true,
            'tool' => $tool,
            'provider_bound' => true,
            'result' => $result,
            'generated_at' => now()->toJSON(),
        ];
    }
}
