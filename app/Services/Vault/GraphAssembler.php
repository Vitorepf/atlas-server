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
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function assemble(): array
    {
        $repoIndex = $this->repoReader->index();
        $vaultIndex = $this->vaultReader->index();

        $continents = array_map(
            fn (array $c) => $this->resolveAtom($c, $repoIndex, $vaultIndex),
            $this->canon->continents()
        );

        $pipeline = array_map(
            fn (array $s) => $this->resolveAtom($s, $repoIndex, $vaultIndex),
            $this->canon->pipelineSteps()
        );

        $lanes = array_map(
            fn (array $l) => $this->resolveAtom($l, $repoIndex, $vaultIndex),
            $this->canon->lanes()
        );

        $missingCount = 0;
        $foundCount = 0;
        foreach ([$pipeline, $lanes, $continents] as $bucket) {
            foreach ($bucket as $piece) {
                if (! empty($piece['missing_source'])) {
                    $missingCount++;
                } else {
                    $foundCount++;
                }
            }
        }

        $semanticGraph = $this->semanticGraph($repoIndex, $vaultIndex);
        $connections = $this->uniqueRelations(array_merge(
            $this->canon->connections(),
            $semanticGraph['relations']
        ));

        return [
            'schema_version' => 1,
            'generated_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'sources' => [
                'repo_docs_path' => (string) config('atlas_vault.repo_docs_path'),
                'obsidian_vault_path' => (string) config('atlas_vault.obsidian_vault_path'),
                'repo_indexed_count' => count($repoIndex),
                'vault_indexed_count' => count($vaultIndex),
            ],
            'audit' => [
                'pieces_found' => $foundCount,
                'pieces_missing' => $missingCount,
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
