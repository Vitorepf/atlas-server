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
                    'connections' => $this->canon->connections(),
                ],
            ],
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
            'role' => $fm['summary'] ?? $fm['role'] ?? null,
            'status' => $fm['status'] ?? null,
            'parent' => $fm['parent'] ?? null,
            'depends_on' => $fm['depends_on'] ?? [],
            'unlocks' => $fm['unlocks'] ?? [],
            'evidence' => $fm['evidence'] ?? null,
            'risks' => $fm['risks'] ?? null,
            'next_actions' => $fm['next_actions'] ?? null,
            'canonical_doc' => $fm['canonical_doc'] ?? null,
            'repo_paths' => $fm['repo_paths'] ?? [],
            'tags' => $fm['tags'] ?? [],
            'title' => $fm['title'] ?? null,
            'summary' => $fm['summary'] ?? null,
        ]);
    }
}
