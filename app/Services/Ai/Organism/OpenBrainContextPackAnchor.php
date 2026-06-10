<?php

declare(strict_types=1);

namespace App\Services\Ai\Organism;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use Throwable;

/**
 * AOBG N4.F1 — production {@see OrganismBrainAnchor} over the REAL fused brain.
 *
 * Delegates to {@see AtlasOpenBrainContextPackService::packFor()} (code-graph +
 * reality-graph + memory, curated top-K, provider-safe). Extracts the provider-safe
 * brain_refs (AURG paths) so a proposal can cite-or-omit its anchors.
 *
 * Fail-open: if the brain is unavailable, returns an honest-empty pack (never fabricates
 * context). The organism still proposes, recording brain_refs=[] honestly.
 */
final class OpenBrainContextPackAnchor implements OrganismBrainAnchor
{
    public function __construct(private readonly AtlasOpenBrainContextPackService $contextPack) {}

    public function anchor(string $intent, array $opts = []): array
    {
        try {
            $pack = $this->contextPack->packFor($intent, $opts);
        } catch (Throwable) {
            return ['brain_refs' => [], 'sources_present' => [], 'honest_empty' => true];
        }

        $refs = [];
        foreach ((array) ($pack['reality_graph_paths'] ?? []) as $path) {
            if (is_string($path) && trim($path) !== '') {
                $refs[] = trim($path);
            }
        }
        foreach ((array) ($pack['code_graph'] ?? []) as $item) {
            $id = is_array($item) ? (string) ($item['id'] ?? '') : '';
            if ($id !== '') {
                $refs[] = $id;
            }
        }

        return [
            'code_graph' => $pack['code_graph'] ?? [],
            'reality_graph_paths' => $pack['reality_graph_paths'] ?? [],
            'memory' => $pack['memory'] ?? [],
            'brain_refs' => array_values(array_unique($refs)),
            'sources_present' => $pack['provenance']['sources_present'] ?? [],
            'honesty' => $pack['honesty'] ?? AtlasOpenBrainContextPackService::HONESTY_LABEL,
        ];
    }
}
