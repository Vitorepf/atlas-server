<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\TestCase;

final class AtlasCartographyContractTest extends TestCase
{
    public function test_note_endpoint_accepts_obsidian_wikilink_graph_ids(): void
    {
        $response = $this->getJson('/atlas-cartography/note/'.rawurlencode('[[Scope Validator]]'));

        $response
            ->assertOk()
            ->assertJsonPath('graph_id', '[[Scope Validator]]')
            ->assertJsonStructure([
                'graph_id',
                'exists',
                'source',
                'source_path',
                'body',
                'frontmatter',
                'modified_at',
            ]);
    }

    public function test_note_endpoint_rejects_path_segments(): void
    {
        $this->getJson('/atlas-cartography/note/'.rawurlencode('../secret'))
            ->assertStatus(400)
            ->assertJsonPath('error', 'invalid graph_id');
    }

    public function test_graph_exposes_real_source_roots(): void
    {
        $response = $this->getJson('/atlas-cartography/graph');

        $response
            ->assertOk()
            ->assertJsonPath('sources.repo_docs_path', (string) config('atlas_vault.repo_docs_path'))
            ->assertJsonPath('sources.obsidian_vault_path', (string) config('atlas_vault.obsidian_vault_path'))
            ->assertJsonStructure([
                'schema_version',
                'generated_at',
                'sources' => [
                    'repo_docs_path',
                    'obsidian_vault_path',
                    'repo_indexed_count',
                    'vault_indexed_count',
                ],
                'audit' => [
                    'pieces_found',
                    'pieces_missing',
                ],
                'universe',
                'views',
                'semantic_graph',
            ]);
    }

    public function test_graph_prefers_promoted_docs_over_archived_duplicate_graph_ids(): void
    {
        $graph = $this->getJson('/atlas-cartography/graph')
            ->assertOk()
            ->json();

        $nodes = collect(data_get($graph, 'semantic_graph.nodes', []))->keyBy('graph_id');
        $canonicalIndex = $nodes->get('atlas-ai-canonical-architecture-index');

        $this->assertIsArray($canonicalIndex);
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md',
            data_get($canonicalIndex, 'source_path')
        );
        $this->assertSame('atlas', data_get($canonicalIndex, 'graph_parent'));
        $this->assertContains(
            'atlas-ai-canonical-architecture-index',
            data_get($graph, 'semantic_graph.hierarchy.atlas', [])
        );
        $this->assertContains(
            'atlas-ai-documentation-operating-system',
            data_get($graph, 'semantic_graph.hierarchy.atlas-ai-canonical-architecture-index', [])
        );
    }
}
