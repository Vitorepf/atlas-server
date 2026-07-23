<?php

declare(strict_types=1);

namespace App\Services\Ai\OpenBrainContextPack;

use App\Services\Ai\AtlasOpenBrainContextPackService;
use App\Services\Ai\Reality\AtlasRealityGraphQueryService;
use App\Services\Ai\Support\AiValueNormalizer;
use Throwable;

/**
 * GOD-DEBULK split of {@see \App\Services\Ai\AtlasOpenBrainContextPackService}.
 * Verbatim reality family extracted from the AOBG context-pack
 * façade; behavior-preserving (private helpers -> Support; public API stays on the façade).
 */
final class RealitySection
{
    public function __construct(
        private readonly Support $support,
        private readonly AtlasRealityGraphQueryService $realityGraph,
    ) {}

    /**
     * Reality-graph (AURG) section — provider_bound is FORCED true: this output
     * crosses to an external AI, so sensitive domains (and anything reachable
     * only through them) are structurally excluded by the query itself.
     *
     * Returns the cross-layer paths with provenance + a compact node label map
     * (so a path's node ids are readable) — never raw source payloads.
     *
     * @return array{present:bool, paths:array<int,array<string,mixed>>, chars:int, provenance:array<string,mixed>}
     */
    public function realitySection(string $task, string $workspaceId): array
    {
        $empty = [
            'present' => false,
            'paths' => [],
            'chars' => 0,
            'provenance' => ['provider_bound' => true, 'note' => AtlasOpenBrainContextPackService::HONESTY_LABEL],
        ];

        if ($task === '' || ! (bool) config('atlas.aurg.enabled', true)) {
            return $empty;
        }

        try {
            // provider_bound is non-relaxable here: gateway output is external.
            $result = $this->realityGraph->query($task, [
                'provider_bound' => true,
                'workspace_id' => $workspaceId,
            ]);
        } catch (Throwable) {
            return $empty;
        }

        $labelById = [];
        foreach ((array) ($result['nodes'] ?? []) as $node) {
            if (is_array($node) && isset($node['id'])) {
                $labelById[(string) $node['id']] = [
                    'label' => (string) ($node['label'] ?? ''),
                    'source_kind' => (string) ($node['source_kind'] ?? ''),
                    'origin' => (string) data_get($node, 'meta.origin', ''),
                ];
            }
        }

        $paths = [];
        $chars = 0;
        $rawPathCount = 0;
        $sameLayerPathCount = 0;
        $sessionEchoPathCount = 0;
        $docMissionPathCount = 0;
        foreach ((array) ($result['paths'] ?? []) as $path) {
            if (! is_array($path)) {
                continue;
            }
            $rawPathCount++;
            if (! (bool) ($path['cross_layer'] ?? false)) {
                $sameLayerPathCount++;

                continue;
            }
            $nodeIds = array_map('strval', (array) ($path['nodes'] ?? []));
            $chain = [];
            foreach ($nodeIds as $nodeId) {
                // Graph labels are UNTRUSTED display text — a node label can be a past operator
                // prompt. Neutralize before it ever reaches the model context.
                $label = AtlasOpenBrainContextPackService::sanitizeGraphLabel((string) ($labelById[$nodeId]['label'] ?? ''));
                $chain[] = [
                    'id' => $nodeId,
                    'label' => $label,
                    'source_kind' => $labelById[$nodeId]['source_kind'] ?? '',
                    'origin' => $labelById[$nodeId]['origin'] ?? '',
                ];
            }
            // A path that runs into a session-capture artifact (a raw past-prompt / interrupted
            // marker) is session ECHO, not an architectural cross-layer path. Dropping it stops the
            // brain from replaying old operator prompts — some instruction-shaped — back into context.
            if (AtlasOpenBrainContextPackService::isSessionArtifactPath($path, $chain)) {
                $sessionEchoPathCount++;

                continue;
            }
            if (AtlasOpenBrainContextPackService::isDocumentationMissionPath($task, $path, $chain)) {
                $docMissionPathCount++;

                continue;
            }
            $entry = [
                'target' => (string) ($path['target'] ?? ''),
                'seed' => (string) ($path['seed'] ?? ''),
                'depth' => (int) ($path['depth'] ?? 0),
                'cross_layer' => (bool) ($path['cross_layer'] ?? false),
                'chain' => $chain,
                'hops' => $this->normalizeHops($path['hops'] ?? []),
            ];
            $chars += strlen((string) json_encode($entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            $paths[] = $entry;
        }

        return [
            'present' => $paths !== [],
            'paths' => $paths,
            'chars' => $chars,
            'provenance' => [
                'provider_bound' => (bool) ($result['provider_bound'] ?? true),
                'workspace_id' => (string) ($result['workspace_id'] ?? $workspaceId),
                'ranking' => (string) ($result['ranking'] ?? ''),
                'seeds' => count((array) ($result['seeds'] ?? [])),
                'nodes' => count((array) ($result['nodes'] ?? [])),
                'raw_paths' => $rawPathCount,
                'same_layer_paths_omitted' => $sameLayerPathCount,
                'session_echo_paths_omitted' => $sessionEchoPathCount,
                'doc_mission_paths_omitted' => $docMissionPathCount,
                'cross_layer_paths' => (int) data_get($result, 'counts.cross_layer_paths', 0),
                'note' => AtlasOpenBrainContextPackService::HONESTY_LABEL,
            ],
        ];
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    public function normalizeHops(mixed $hops): array
    {
        $out = [];
        foreach ((array) $hops as $hop) {
            if (! is_array($hop)) {
                continue;
            }
            $out[] = [
                'from' => (string) ($hop['from'] ?? ''),
                'to' => (string) ($hop['to'] ?? ''),
                'edge_kind' => (string) ($hop['edge_kind'] ?? ''),
                'confidence' => round(AiValueNormalizer::finiteFloatOrNull($hop['confidence'] ?? null) ?? 0.0, 4),
                'direction' => (string) ($hop['direction'] ?? ''),
            ];
        }

        return $out;
    }
}
