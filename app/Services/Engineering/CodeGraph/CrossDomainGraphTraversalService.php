<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeGraph;

use App\Services\Ai\CrossDomain\AtlasCrossDomainMeshService;
use Throwable;

/**
 * M-8 Fase-2 — cross-domain TRAVERSAL gated by the EXISTING ARPTL veto (AP-814).
 *
 * This is the "killer query" the roadmap promised — *from this node, what is
 * reachable ACROSS domains, under governance* — that graphify "can't even
 * formulate" (it has no domains, no privacy, no veto).
 *
 * The crux: every CROSS-domain hop is gated by the real
 * {@see AtlasCrossDomainMeshService::evaluate()} (pure, idempotent, deterministic) —
 * REUSED, not reimplemented. Intra-domain hops are free. The mesh governs its 15
 * domains; the 6 registry-only canonical domains it doesn't know get a CONSERVATIVE
 * floor (deny anything sensitive/secret/cyber, deny a sensitive source) so a domain
 * the mesh can't vouch for can never leak. Vetoed crossings are RECORDED (audit),
 * never silently dropped.
 *
 * Read-only, bounded (depth/nodes), deterministic, FAIL-OPEN (on any error the
 * traversal degrades to what it safely resolved). It crosses nothing to a provider.
 */
final class CrossDomainGraphTraversalService
{
    public const SCHEMA = 'atlas.code_graph.cross_domain_traversal.v1';

    /** @var list<string> */
    private const SENSITIVE_PRIVACY = ['sensitive', 'secret', 'cyber'];

    public function __construct(
        private readonly CrossDomainGraphIngestionService $ingestion,
        private readonly CrossDomainTaxonomyMap $taxonomy,
        private readonly ?AtlasCrossDomainMeshService $mesh = null,
    ) {}

    /**
     * Bounded BFS from a seed node, gating each cross-domain hop via ARPTL.
     *
     * @return array<string,mixed>
     */
    public function traverse(string $seedNodeId, string $privacyClass = 'normal', ?int $maxDepth = null, ?int $maxNodes = null): array
    {
        $privacy = $this->normalizePrivacy($privacyClass);
        $maxDepth = $maxDepth ?? $this->configInt('atlas.cross_domain_graph.traversal_max_depth', 4);
        $maxNodes = $maxNodes ?? $this->configInt('atlas.cross_domain_graph.traversal_max_nodes', 60);

        $graph = $this->ingestion->gather();
        $nodeSet = array_flip(array_column($graph['nodes'], 'node_id'));

        if (! isset($nodeSet[$seedNodeId])) {
            return [
                'ok' => false,
                'schema_version' => self::SCHEMA,
                'error' => 'seed_not_found',
                'seed' => $seedNodeId,
                'privacy_class' => $privacy,
            ];
        }

        // Adjacency in deterministic edge order (gather() already sorts).
        $adjacency = [];
        foreach ($graph['edges'] as $edge) {
            $adjacency[$edge['from_node_id']][] = $edge;
        }

        $visited = [$seedNodeId => 0];
        $queue = [[$seedNodeId, 0]];
        $traversed = [];
        $vetoed = [];
        $reachableDomains = [];
        if (($d = $this->domainOf($seedNodeId)) !== null) {
            $reachableDomains[$d] = true;
        }

        while ($queue !== []) {
            [$node, $depth] = array_shift($queue);
            if ($depth >= $maxDepth || count($visited) > $maxNodes) {
                continue;
            }
            foreach ($adjacency[$node] ?? [] as $edge) {
                $to = (string) ($edge['to_node_id'] ?? '');
                if ($to === '') {
                    continue;
                }
                $fromDom = $this->domainOf($node);
                $toDom = $this->domainOf($to);

                // Gate ONLY cross-domain hops; intra-domain edges are free.
                if ($fromDom !== null && $toDom !== null && $fromDom !== $toDom) {
                    $gate = $this->crossingAllowed($fromDom, $toDom, $privacy);
                    if (! $gate['allowed']) {
                        $vetoed[] = [
                            'from' => $node,
                            'to' => $to,
                            'edge_type' => (string) ($edge['edge_type'] ?? ''),
                            'reason' => $gate['reason'],
                        ];

                        continue;
                    }
                }

                $traversed[] = ['from' => $node, 'to' => $to, 'edge_type' => (string) ($edge['edge_type'] ?? '')];
                if ($toDom !== null) {
                    $reachableDomains[$toDom] = true;
                }
                if (! isset($visited[$to]) && count($visited) <= $maxNodes) {
                    $visited[$to] = $depth + 1;
                    $queue[] = [$to, $depth + 1];
                }
            }
        }

        ksort($reachableDomains);

        return [
            'ok' => true,
            'schema_version' => self::SCHEMA,
            'seed' => $seedNodeId,
            'privacy_class' => $privacy,
            'visited' => array_keys($visited),
            'reachable_domains' => array_keys($reachableDomains),
            'edges_traversed' => $traversed,
            'vetoed_crossings' => $vetoed,
            'stats' => [
                'visited_count' => count($visited),
                'edges_traversed' => count($traversed),
                'vetoed_count' => count($vetoed),
                'reachable_domain_count' => count($reachableDomains),
            ],
        ];
    }

    /**
     * The killer query, framed: from a seed, what cross-domain reach exists UNDER
     * the ARPTL veto, and what was blocked.
     *
     * @return array<string,mixed>
     */
    public function killerQuery(string $seedNodeId, string $privacyClass = 'normal'): array
    {
        $r = $this->traverse($seedNodeId, $privacyClass);
        if (($r['ok'] ?? false) !== true) {
            return $r;
        }

        return [
            'ok' => true,
            'schema_version' => self::SCHEMA,
            'question' => "From {$r['seed']} under privacy={$r['privacy_class']}: what is reachable across domains, and what does ARPTL block?",
            'reachable_domains' => $r['reachable_domains'],
            'vetoed_crossings' => $r['vetoed_crossings'],
            'stats' => $r['stats'],
        ];
    }

    /**
     * Is a cross-domain hop allowed? Reuse the mesh veto when it governs BOTH
     * domains; otherwise a conservative floor for the mesh-ungoverned (registry-only)
     * canonical domains.
     *
     * @return array{allowed:bool, reason:list<string>}
     */
    private function crossingAllowed(string $fromCanonical, string $toCanonical, string $privacy): array
    {
        $all = $this->taxonomy->all();
        $meshFrom = $all[$fromCanonical]['mesh'] ?? null;
        $meshTo = $all[$toCanonical]['mesh'] ?? null;

        if ($this->mesh !== null && is_string($meshFrom) && is_string($meshTo)) {
            try {
                $decision = $this->mesh->evaluate($meshFrom, $meshTo, $privacy);

                return [
                    'allowed' => (bool) ($decision['approved'] ?? false),
                    'reason' => array_values((array) ($decision['reason'] ?? [])),
                ];
            } catch (Throwable) {
                // fall through to the conservative floor.
            }
        }

        // Conservative floor for domains the mesh does not govern: never carry
        // sensitive/secret/cyber, and never let a sensitive source out.
        if (in_array($privacy, self::SENSITIVE_PRIVACY, true)) {
            return ['allowed' => false, 'reason' => ['mesh_ungoverned_sensitive_denied']];
        }
        if ($this->taxonomy->isSensitive($fromCanonical) || $this->taxonomy->isSensitive($toCanonical)) {
            return ['allowed' => false, 'reason' => ['mesh_ungoverned_sensitive_endpoint_denied']];
        }

        return ['allowed' => true, 'reason' => ['mesh_ungoverned_public_normal_allowed']];
    }

    /** Resolve an int config value, fail-safe to the default when no app is booted. */
    private function configInt(string $key, int $default): int
    {
        try {
            return function_exists('config') ? (int) config($key, $default) : $default;
        } catch (Throwable) {
            return $default;
        }
    }

    private function domainOf(string $nodeId): ?string
    {
        if (! str_starts_with($nodeId, 'domain:')) {
            return null;
        }
        $rest = substr($nodeId, 7);
        if ($rest === '') {
            return null;
        }
        $colon = strpos($rest, ':');

        return $colon === false ? $rest : substr($rest, 0, $colon);
    }

    private function normalizePrivacy(string $privacy): string
    {
        $p = strtolower(trim($privacy));
        if (in_array($p, AtlasCrossDomainMeshService::PRIVACY_CLASSES, true)) {
            return $p;
        }
        // Atlas memory uses 'private'; map it onto the mesh's 'sensitive' (conservative).
        if ($p === 'private') {
            return AtlasCrossDomainMeshService::PRIVACY_SENSITIVE;
        }

        return AtlasCrossDomainMeshService::PRIVACY_NORMAL;
    }
}
