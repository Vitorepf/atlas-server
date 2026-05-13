<?php

declare(strict_types=1);

namespace App\Services\Vault;

/**
 * Combines the canon (expected pieces) with the real filesystem (repo + vault)
 * and assembles the graph the cartography renders.
 *
 * Honest about gaps: if a canon piece has no .md, it ships with `missing_source: true`
 * and the cartography paints it in recRed. The cartography never lies about presence.
 */
final class GraphAssembler
{
    public function __construct(
        private readonly RepoVaultReader $repoReader,
        private readonly ObsidianVaultReader $vaultReader,
        private readonly CartographyCanon $canon,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function assemble(): array
    {
        $repoIndex = $this->repoReader->index();
        $vaultIndex = $this->vaultReader->index();
        $repoDocsPath = (string) config('atlas_vault.repo_docs_path');
        $vaultPath = (string) config('atlas_vault.obsidian_vault_path');

        $semanticGraph = $this->semanticGraph($repoIndex, $vaultIndex);

        $continents = array_map(function (array $c) use ($repoIndex, $vaultIndex, $semanticGraph): array {
            $resolved = $this->resolveAtom($c, $repoIndex, $vaultIndex);
            $resolved['count'] = $this->inferContinentCount(
                (string) $c['graph_id'], $semanticGraph
            );

            return $resolved;
        }, $this->canon->continents());

        $pipeline = array_map(
            fn (array $s) => $this->resolveAtom($s, $repoIndex, $vaultIndex),
            $this->canon->pipelineSteps()
        );

        $lanes = array_map(function (array $l) use ($repoIndex, $vaultIndex): array {
            $resolved = $this->resolveAtom($l, $repoIndex, $vaultIndex);
            // Expand each lane child node via the same canon→filesystem resolver.
            // Anything without a real .md surfaces as `missing_source: true` —
            // the cartography paints it in recRed, never as fake content.
            $rawNodes = isset($l['nodes']) && is_array($l['nodes']) ? $l['nodes'] : [];
            $resolved['nodes'] = array_map(
                fn (array $n) => $this->resolveAtom($n, $repoIndex, $vaultIndex),
                $rawNodes
            );

            return $resolved;
        }, $this->canon->lanes());

        $missingCount = 0;
        $foundCount = 0;
        $brokenPaths = [];
        // Count both lane top-level and lane child nodes against the audit.
        // Broken paths are surfaced so the Audit Panel (cmd+shift+A) shows
        // operators exactly where the canon expected a .md and didn't find one.
        $audit = function (array $piece) use (&$missingCount, &$foundCount, &$brokenPaths): void {
            if (! empty($piece['missing_source'])) {
                $missingCount++;
                $brokenPaths[] = [
                    'graph_id' => (string) ($piece['graph_id'] ?? ''),
                    'name' => (string) ($piece['name'] ?? ($piece['graph_id'] ?? '')),
                    'expected_path' => (string) ($piece['source_path'] ?? ''),
                    'graph_kind' => (string) ($piece['graph_kind'] ?? ''),
                ];
            } else {
                $foundCount++;
            }
        };
        foreach ([$pipeline, $continents] as $bucket) {
            foreach ($bucket as $piece) {
                $audit($piece);
            }
        }
        foreach ($lanes as $lane) {
            $audit($lane);
            foreach ($lane['nodes'] ?? [] as $node) {
                $audit($node);
            }
        }

        // Orphans = semantic nodes that declare a parent which isn't in the index.
        // (Nodes with no parent at all are top-level by design; not orphans.)
        $semanticIds = [];
        foreach ($semanticGraph['nodes'] as $n) {
            $semanticIds[(string) ($n['graph_id'] ?? '')] = true;
        }
        $orphanIds = [];
        foreach ($semanticGraph['nodes'] as $n) {
            $parent = (string) ($n['graph_parent'] ?? '');
            if ($parent === '') {
                continue;
            }
            if (! isset($semanticIds[$parent])) {
                $orphanIds[] = ['graph_id' => (string) $n['graph_id'], 'missing_parent' => $parent];
            }
        }

        $connections = $this->uniqueRelations(array_merge(
            $this->canon->connections(),
            $semanticGraph['relations']
        ));

        $payload = [
            'schema_version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'sources' => [
                'repo_docs_path' => $repoDocsPath,
                'obsidian_vault_path' => $vaultPath,
                'repo_indexed_count' => count($repoIndex),
                'vault_indexed_count' => count($vaultIndex),
            ],
            'source_health' => [
                'repo' => [
                    'root' => $repoDocsPath,
                    'readable' => is_dir($repoDocsPath) && is_readable($repoDocsPath),
                    'indexed_count' => count($repoIndex),
                    'errors' => [],
                ],
                'vault' => [
                    'root' => $vaultPath,
                    'readable' => is_dir($vaultPath) && is_readable($vaultPath),
                    'indexed_count' => count($vaultIndex),
                    'errors' => [],
                ],
            ],
            'audit' => [
                'pieces_found' => $foundCount,
                'pieces_missing' => $missingCount,
                'broken_paths' => $brokenPaths,
                'orphan_nodes' => $orphanIds,
                'orphan_count' => count($orphanIds),
                'semantic_node_count' => count($semanticGraph['nodes']),
                'semantic_relation_count' => count($semanticGraph['relations']),
            ],
            'universe' => $continents,
            'views' => [
                'atlas-ai-kernel' => [
                    'ribbon' => 'Atlas Kernel Pipeline',
                    'pipeline' => $pipeline,
                    'lanes' => $lanes,
                    'connections' => $connections,
                ],
            ],
            'semantic_graph' => $semanticGraph,
        ];

        // Deterministic checksum so the client can detect content changes
        // without diffing the whole payload. Computed over the canonical
        // surfaces only — excludes generated_at to avoid 1Hz churn.
        $payload['checksum'] = hash('sha256', json_encode([
            'audit' => $payload['audit'],
            'universe' => $payload['universe'],
            'views' => $payload['views'],
            'semantic_graph_count' => count($semanticGraph['nodes']),
        ], JSON_THROW_ON_ERROR));

        return $payload;
    }

    /**
     * Best-effort count of canonical pieces per continent. Honest about gaps:
     * worlds we don't catalog yet return 0 instead of inventing a number.
     *
     * - atlas      → semantic nodes with graph_world="atlas"
     * - memory     → semantic nodes with graph_world="vault" (AtlasVault)
     * - philosophy → subset of vault (placeholder · 0 until subset taxonomy)
     * - works/forge/risks → 0 (TODO: own world or graph_parent taxonomy)
     *
     * @param  array{worlds: list<string>, nodes: list<array<string, mixed>>, hierarchy: array<string, list<string>>, relations: list<array<string, mixed>>}  $semanticGraph
     */
    private function inferContinentCount(string $continentId, array $semanticGraph): int
    {
        $nodes = $semanticGraph['nodes'] ?? [];
        if ($continentId === 'atlas') {
            return count(array_filter($nodes, fn (array $n) => ($n['graph_world'] ?? '') === 'atlas'));
        }
        if ($continentId === 'memory') {
            return count(array_filter($nodes, fn (array $n) => ($n['graph_world'] ?? '') === 'vault'));
        }

        // Other continents intentionally return 0 until a richer taxonomy exists.
        // The cartography reads this as "no curated count yet" rather than zero peças.
        return 0;
    }

    /**
     * @param  array<string, mixed>  $canonEntry
     * @param  array<string, array<string, mixed>>  $repoIndex
     * @param  array<string, array<string, mixed>>  $vaultIndex
     * @return array<string, mixed>
     */
    private function resolveAtom(array $canonEntry, array $repoIndex, array $vaultIndex): array
    {
        $graphId = (string) $canonEntry['graph_id'];
        $expectedSource = (string) ($canonEntry['graph_source'] ?? 'repo');
        $lookupIds = $canonEntry['lookup_ids'] ?? [];
        if (! is_array($lookupIds)) {
            $lookupIds = [];
        }
        $candidates = array_merge([$graphId], $lookupIds);

        $found = null;
        $foundSource = null;
        $foundIdUsed = null;

        // try repo first if expected source is repo or mixed
        if (in_array($expectedSource, ['repo', 'mixed'], true)) {
            foreach ($candidates as $candidate) {
                if (isset($repoIndex[$candidate])) {
                    $found = $repoIndex[$candidate];
                    $foundSource = 'repo';
                    $foundIdUsed = $candidate;
                    break;
                }
            }
        }
        // try vault if expected source is vault, mixed, or repo fell through
        if ($found === null && in_array($expectedSource, ['vault', 'mixed', 'repo'], true)) {
            foreach ($candidates as $candidate) {
                if (isset($vaultIndex[$candidate])) {
                    $found = $vaultIndex[$candidate];
                    $foundSource = 'vault';
                    $foundIdUsed = $candidate;
                    break;
                }
            }
        }

        if ($found === null) {
            return array_merge($canonEntry, [
                'missing_source' => true,
                'source_path' => $canonEntry['expected_path'] ?? null,
                'graph_status' => 'missing',
                'mtime' => null,
                'frontmatter_id' => null,
            ]);
        }

        $fm = $found['frontmatter'];

        return array_merge($canonEntry, [
            'missing_source' => false,
            'graph_source' => $foundSource,
            'source_path' => $found['relative_path'],
            'frontmatter_id' => $foundIdUsed,
            'mtime' => $found['mtime'] ?? null,
            // Inject any canonical frontmatter fields the cartography can use directly
            'graph_title' => $fm['graph_title'] ?? null,
            'graph_world' => $fm['graph_world'] ?? null,
            'graph_layer' => $fm['graph_layer'] ?? null,
            'graph_kind' => $fm['graph_kind'] ?? null,
            'graph_parent' => $fm['graph_parent'] ?? null,
            'graph_status' => $fm['graph_status'] ?? null,
            'role' => $fm['summary'] ?? $fm['role'] ?? null,
            'status' => $fm['status'] ?? null,
            'parent' => $fm['parent'] ?? null,
            'depends_on' => $fm['depends_on'] ?? [],
            'flows_to' => $fm['flows_to'] ?? [],
            'unlocks' => $fm['unlocks'] ?? [],
            'governs' => $fm['governs'] ?? [],
            'evidence' => $fm['evidence'] ?? null,
            'risks' => $fm['risks'] ?? null,
            'next_actions' => $fm['next_actions'] ?? null,
            'allowed_changes' => $fm['allowed_changes'] ?? [],
            'forbidden_changes' => $fm['forbidden_changes'] ?? [],
            'required_tests' => $fm['required_tests'] ?? [],
            'requires_evidence' => $fm['requires_evidence'] ?? null,
            'risk_level' => $fm['risk_level'] ?? null,
            'visual_tags' => $fm['visual_tags'] ?? [],
            'ai_entrypoints' => $fm['ai_entrypoints'] ?? [],
            'ai_usage_notes' => $fm['ai_usage_notes'] ?? [],
            'quality_gates' => $fm['quality_gates'] ?? [],
            'failure_modes' => $fm['failure_modes'] ?? [],
            'observability_signals' => $fm['observability_signals'] ?? [],
            'canonical_doc' => $fm['canonical_doc'] ?? null,
            'repo_paths' => $fm['repo_paths'] ?? [],
            'tags' => $fm['tags'] ?? [],
            'title' => $fm['title'] ?? null,
            'summary' => $fm['summary'] ?? null,
        ]);
    }

    /**
     * Builds the source-driven graph used by the cartography zoom model:
     * world -> system -> flow -> module -> gear -> subcomponent.
     *
     * @param  array<string, array<string, mixed>>  $repoIndex
     * @param  array<string, array<string, mixed>>  $vaultIndex
     * @return array{worlds: list<string>, nodes: list<array<string, mixed>>, hierarchy: array<string, list<string>>, relations: list<array{from: string, to: string, kind: string}>}
     */
    private function semanticGraph(array $repoIndex, array $vaultIndex): array
    {
        $nodes = [];
        $hierarchy = [];
        $relations = [];
        $worlds = [];

        foreach ([['repo', $repoIndex], ['vault', $vaultIndex]] as [$source, $index]) {
            foreach ($index as $id => $entry) {
                $fm = $entry['frontmatter'] ?? [];
                if (! is_array($fm)) {
                    continue;
                }
                $graphId = $fm['graph_id'] ?? $id;
                if (! is_string($graphId) || $graphId === '') {
                    continue;
                }
                if (isset($nodes[$graphId])) {
                    // Source authority: repo docs win over AtlasVault for technical graph ids.
                    // The Vault remains indexed for human notes that do not collide.
                    continue;
                }

                $node = $this->semanticNode($graphId, $source, $entry);
                $nodes[$graphId] = $node;

                $world = (string) ($node['graph_world'] ?: $this->defaultWorldForSource($source));
                if ($world !== '') {
                    $worlds[$world] = true;
                }

                $parent = (string) ($node['graph_parent'] ?: '');
                if ($parent !== '') {
                    $hierarchy[$parent] ??= [];
                    $hierarchy[$parent][] = $graphId;
                }

                foreach ($this->listStrings($fm['depends_on'] ?? []) as $target) {
                    $relations[] = ['from' => $graphId, 'to' => $target, 'kind' => 'depends_on'];
                }
                foreach ($this->listStrings($fm['flows_to'] ?? []) as $target) {
                    $relations[] = ['from' => $graphId, 'to' => $target, 'kind' => 'flows_to'];
                }
                foreach ($this->listStrings($fm['unlocks'] ?? []) as $target) {
                    $relations[] = ['from' => $graphId, 'to' => $target, 'kind' => 'unlocks'];
                }
                foreach ($this->listStrings($fm['governs'] ?? []) as $target) {
                    $relations[] = ['from' => $graphId, 'to' => $target, 'kind' => 'governs'];
                }
            }
        }

        ksort($nodes);
        ksort($hierarchy);
        foreach ($hierarchy as $parent => $children) {
            $hierarchy[$parent] = array_values(array_unique($children));
            sort($hierarchy[$parent]);
        }

        return [
            'worlds' => array_values(array_keys($worlds)),
            'nodes' => array_values($nodes),
            'hierarchy' => $hierarchy,
            'relations' => $this->uniqueRelations($relations),
        ];
    }

    /**
     * @param  array<string, mixed>  $entry
     * @return array<string, mixed>
     */
    private function semanticNode(string $graphId, string $source, array $entry): array
    {
        $fm = is_array($entry['frontmatter'] ?? null) ? $entry['frontmatter'] : [];

        return [
            'graph_id' => $graphId,
            'graph_title' => $fm['graph_title'] ?? $fm['title'] ?? $graphId,
            'graph_world' => $fm['graph_world'] ?? $this->defaultWorldForSource($source),
            'graph_layer' => $fm['graph_layer'] ?? null,
            'graph_kind' => $fm['graph_kind'] ?? null,
            'graph_parent' => $fm['graph_parent'] ?? null,
            'graph_status' => $fm['graph_status'] ?? $fm['status'] ?? null,
            'graph_source' => $source,
            'source_path' => $entry['relative_path'] ?? null,
            'summary' => $fm['summary'] ?? $fm['role'] ?? null,
            'depends_on' => $this->listStrings($fm['depends_on'] ?? []),
            'flows_to' => $this->listStrings($fm['flows_to'] ?? []),
            'unlocks' => $this->listStrings($fm['unlocks'] ?? []),
            'governs' => $this->listStrings($fm['governs'] ?? []),
            'risk_level' => $fm['risk_level'] ?? null,
            'evidence' => $this->listStrings($fm['evidence'] ?? []),
            'next_actions' => $this->listStrings($fm['next_actions'] ?? []),
            'visual_tags' => $this->listStrings($fm['visual_tags'] ?? []),
            'ai_entrypoints' => $this->listStrings($fm['ai_entrypoints'] ?? []),
            'ai_usage_notes' => $this->listStrings($fm['ai_usage_notes'] ?? []),
            'quality_gates' => $this->listStrings($fm['quality_gates'] ?? []),
            'failure_modes' => $this->listStrings($fm['failure_modes'] ?? []),
            'observability_signals' => $this->listStrings($fm['observability_signals'] ?? []),
            'mtime' => $entry['mtime'] ?? null,
        ];
    }

    private function defaultWorldForSource(string $source): string
    {
        return $source === 'vault' ? 'vault' : 'atlas';
    }

    /**
     * @return list<string>
     */
    private function listStrings(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : [$value];
        }
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(array_map(
            fn (mixed $item): string => trim((string) $item),
            $value
        ), fn (string $item): bool => $item !== ''));
    }

    /**
     * @param  list<array{from: string, to: string, kind: string}>  $relations
     * @return list<array{from: string, to: string, kind: string}>
     */
    private function uniqueRelations(array $relations): array
    {
        $seen = [];
        $unique = [];

        foreach ($relations as $relation) {
            $from = (string) ($relation['from'] ?? '');
            $to = (string) ($relation['to'] ?? '');
            $kind = (string) ($relation['kind'] ?? '');
            if ($from === '' || $to === '' || $kind === '') {
                continue;
            }
            $key = "{$from}\0{$to}\0{$kind}";
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = ['from' => $from, 'to' => $to, 'kind' => $kind];
        }

        return $unique;
    }
}
