<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Vault\RepoVaultReader;
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

        $graph = $response->json();
        $brokenPaths = collect(data_get($graph, 'audit.broken_paths', []));

        $this->assertSame(
            [],
            $brokenPaths
                ->reject(fn (array $entry): bool => str_starts_with((string) ($entry['expected_path'] ?? ''), 'AtlasVault/'))
                ->values()
                ->all(),
            'Repo-backed cartography pieces must resolve to real docs. Only unavailable external AtlasVault folders may remain missing.'
        );

        $this->assertSame(2, data_get($graph, 'audit.pieces_missing'));
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

    public function test_graph_exposes_structured_gear_flow_for_visual_drilldown(): void
    {
        $graph = $this->getJson('/atlas-cartography/graph')
            ->assertOk()
            ->json();

        $pipeline = collect(data_get($graph, 'views.atlas-ai-kernel.pipeline', []));
        $atlasDecide = $pipeline->firstWhere('graph_id', 'atlas-decide');
        $nodes = collect(data_get($graph, 'semantic_graph.nodes', []))->keyBy('graph_id');

        $this->assertIsArray($atlasDecide);
        $this->assertCount(7, data_get($atlasDecide, 'gear_flow', []));
        $this->assertSame('Intento + risco', data_get($atlasDecide, 'gear_flow.0.name'));

        $contextNode = collect(data_get($atlasDecide, 'gear_flow', []))
            ->firstWhere('graph_id', 'atlas-decide-context-signals');

        $this->assertIsArray($contextNode);
        $this->assertCount(4, data_get($contextNode, 'gear_flow', []));
        $this->assertSame('Context Builder', data_get($contextNode, 'gear_flow.0.name'));

        $atlasAiPipeline = $nodes->get('atlas-ai-pipeline');
        $atlasAiVisualMap = $nodes->get('atlas-ai-flow-visual-map');

        $this->assertIsArray($atlasAiPipeline);
        $pipelineFlow = collect(data_get($atlasAiPipeline, 'gear_flow', []));
        $this->assertSame(
            [
                'Input',
                'Intent',
                'Domain',
                'Domain Profile',
                'Flow Profile',
                'Context',
                'Policy',
                'Decide',
                'Executor',
                'Gate',
                'Repair / Escalation',
                'Evidence',
                'Learning',
                'Output',
            ],
            $pipelineFlow->pluck('name')->all(),
            'Atlas AI Pipeline must expose the documented macro flow to mobile cartography.'
        );
        $this->assertSame('atlas-decide', data_get($pipelineFlow->firstWhere('name', 'Decide'), 'target_graph_id'));
        $this->assertSame('context-builder', data_get($pipelineFlow->firstWhere('name', 'Context'), 'target_graph_id'));

        $this->assertIsArray($atlasAiVisualMap);
        $visualMapFlow = collect(data_get($atlasAiVisualMap, 'gear_flow', []));
        $this->assertSame(
            [
                'Surface',
                'Atlas Input',
                'Operation Envelope',
                'Intent / Routing',
                'Business Context',
                'Domain Plane',
                'Domain Profile',
                'Flow Profile',
                'Context Builder',
                'Policy / Profile',
                'Atlas Decide',
                'Decision Receipt',
                'Runtime / Executor',
                'Quality Gates',
                'Repair / Escalation',
                'Evidence Ledger',
                'Learning / Proposals',
                'Output Renderer',
            ],
            $visualMapFlow->pluck('name')->all(),
            'Atlas AI Flow Visual Map must expose the V3 visual flow exactly.'
        );
        $this->assertSame(
            'atlas-decide',
            data_get($visualMapFlow->firstWhere('name', 'Atlas Decide'), 'target_graph_id'),
            'The visual Atlas Decide gear must link to the canonical atlas-decide node for tap drilldown.'
        );
        $this->assertSame(
            'surface-plane',
            data_get($visualMapFlow->firstWhere('name', 'Surface'), 'target_graph_id'),
            'The visual Surface gear must link to the canonical surface-plane node.'
        );

        $lanes = collect(data_get($graph, 'views.atlas-ai-kernel.lanes', []));
        $domainPlane = $lanes->firstWhere('graph_id', 'domain-plane');
        $programmingHarness = $lanes->firstWhere('graph_id', 'capabilities');

        $this->assertSame(
            'atlas-ai-programming-domain',
            data_get(collect(data_get($domainPlane, 'nodes', []))->firstWhere('graph_id', 'dom-prog'), 'frontmatter_id'),
            'Lane child aliases must expose the resolved canonical frontmatter_id so mobile taps open the real Programming documentation.'
        );
        $this->assertSame(
            'atlas-ai-self-construction-os',
            data_get(collect(data_get($programmingHarness, 'nodes', []))->firstWhere('graph_id', 'cap-prog'), 'frontmatter_id'),
            'Capability lane child aliases must expose the real canonical document instead of opening a shallow lateral wrapper.'
        );
    }

    public function test_graph_exposes_human_documentation_fields_for_mobile_modal(): void
    {
        $graph = $this->getJson('/atlas-cartography/graph')
            ->assertOk()
            ->json();

        $nodes = collect(data_get($graph, 'semantic_graph.nodes', []))->keyBy('graph_id');
        $atlasDecide = $nodes->get('atlas-decide');
        $qualityGates = $nodes->get('quality-gates');

        $this->assertIsArray($atlasDecide);
        $this->assertContains('model_selection', data_get($atlasDecide, 'capabilities', []));
        $this->assertContains('php artisan atlas:engineering:knowledge docs-health --json', data_get($atlasDecide, 'required_tests', []));
        $this->assertContains('docs/engineering-knowledge-base/system-graph/atlas-decide.md', data_get($atlasDecide, 'repo_paths', []));
        $this->assertContains('docs/engineering-knowledge-base/atlas-ai-model-selection-strategy.md', data_get($atlasDecide, 'related_paths', []));
        $this->assertContains('Permitir que UI escolha provider/modelo sem Decision Receipt.', data_get($atlasDecide, 'forbidden_changes', []));
        $this->assertTrue((bool) data_get($atlasDecide, 'requires_evidence'));
        $this->assertSame('atlas-kernel', data_get($atlasDecide, 'owner'));
        $this->assertSame('kernel', data_get($atlasDecide, 'category'));
        $this->assertSame(98, data_get($atlasDecide, 'priority'));
        $this->assertSame('atlas_canonical_module_doc.v1', data_get($atlasDecide, 'doc_schema'));
        $this->assertContains('Validar com docs-health apos qualquer alteracao.', data_get($atlasDecide, 'maintenance', []));

        $this->assertIsArray($qualityGates);
        $this->assertContains('qa_evidence', data_get($qualityGates, 'capabilities', []));
        $this->assertContains('docs-health status ok', data_get($qualityGates, 'observability_signals', []));

        $selfConstructionOs = $nodes->get('atlas-ai-self-construction-os');
        $this->assertIsArray($selfConstructionOs);
        $this->assertSame('Self-Construction OS', data_get($selfConstructionOs, 'patamar_current'));
        $this->assertSame('Self-Programming OS', data_get($selfConstructionOs, 'patamar_next'));
        $this->assertStringContainsString(
            'patamar de maturidade, nao versao',
            (string) data_get($selfConstructionOs, 'version_note'),
            'The primary Self-Construction OS modal must show Self-Programming OS as next patamar, not as version or next visual step.'
        );

        $selfProgrammingSafety = $nodes->get('atlas-ai-self-construction-self-programming-safety-contract');
        $this->assertIsArray($selfProgrammingSafety);
        $this->assertNull(
            data_get($selfProgrammingSafety, 'patamar_current'),
            'The Self-Programming Safety Contract supports the next patamar but must not be mislabeled as a patamar itself.'
        );
        $this->assertStringContainsString(
            'contrato de safety nao e patamar por si so',
            (string) data_get($selfProgrammingSafety, 'version_note')
        );

        $selfConstructionMap = $nodes->get('atlas-programming-self-construction-forge-map-v1');
        $this->assertIsArray($selfConstructionMap);
        $this->assertSame('Self-Construction OS', data_get($selfConstructionMap, 'patamar_current'));
        $this->assertSame('Self-Programming OS', data_get($selfConstructionMap, 'patamar_next'));

        $vox = $nodes->get('atlas-vox-operational-thinking-interface');
        $this->assertIsArray($vox);
        $this->assertSame('Atlas Vox', data_get($vox, 'version_family'));
        $this->assertContains('V0 dictation pura com Kernel e receipt R0.', data_get($vox, 'versions', []));
        $this->assertStringContainsString('nao sao patamares canonicos', (string) data_get($vox, 'version_note'));

        $nomenclature = $nodes->get('atlas-cartography-nomenclature-contract');
        $this->assertIsArray($nomenclature);
        $this->assertSame(
            'atlas-cartographic-knowledge-os',
            data_get($nomenclature, 'graph_parent'),
            'The nomenclature contract must sit under Cartographic Knowledge OS, not under a missing atlas-cartography parent.'
        );
        $this->assertContains(
            'atlas-cartography-nomenclature-contract',
            data_get($graph, 'semantic_graph.hierarchy.atlas-cartographic-knowledge-os', [])
        );

        $marketing = $nodes->get('atlas-ai-marketing-domain');
        $this->assertIsArray($marketing);
        $this->assertSame('building', data_get($marketing, 'graph_status'));
        $this->assertStringContainsString('review-only', (string) data_get($marketing, 'summary'));
        $this->assertContains(
            'Publicar conteudo externo automaticamente.',
            data_get($marketing, 'forbidden_changes', [])
        );
    }

    public function test_graph_distinguishes_atlas_vox_from_voice_realtime_for_cartography(): void
    {
        $graph = $this->getJson('/atlas-cartography/graph')
            ->assertOk()
            ->json();

        $nodes = collect(data_get($graph, 'semantic_graph.nodes', []))->keyBy('graph_id');
        $vox = $nodes->get('atlas-vox-operational-thinking-interface');
        $voiceRealtime = $nodes->get('atlas-ai-voice-realtime-surface');
        $boundaryAdr = $nodes->get('adr-0003-vox-vs-voice-realtime-surface-boundary');

        $this->assertIsArray($vox);
        $this->assertIsArray($voiceRealtime);
        $this->assertIsArray($boundaryAdr);

        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-vox-operational-thinking-interface.md',
            data_get($vox, 'source_path')
        );
        $this->assertSame(
            'docs/engineering-knowledge-base/atlas-ai-voice-realtime-surface.md',
            data_get($voiceRealtime, 'source_path')
        );
        $this->assertNotSame(data_get($vox, 'source_path'), data_get($voiceRealtime, 'source_path'));

        $this->assertContains(
            'atlas-ai-voice-realtime-surface',
            data_get($vox, 'depends_on', []),
            'Atlas Vox must point to Voice Realtime as a dependency, not pretend to be the same surface.'
        );
        $this->assertSame(
            'atlas-vox-operational-thinking-interface',
            data_get($boundaryAdr, 'graph_parent'),
            'The Vox vs Voice boundary ADR must appear under Atlas Vox so cartography can explain the distinction.'
        );
        $this->assertContains(
            'adr-0003-vox-vs-voice-realtime-surface-boundary',
            data_get($graph, 'semantic_graph.hierarchy.atlas-vox-operational-thinking-interface', [])
        );
        $this->assertContains(
            'atlas-ai-voice-realtime-canon-de-fala',
            data_get($graph, 'semantic_graph.hierarchy.atlas-ai-voice-realtime-surface', [])
        );

        $voxFlowNames = collect(data_get($vox, 'gear_flow', []))->pluck('name')->all();
        $voxFlow = collect(data_get($vox, 'gear_flow', []));
        $voiceFlow = collect(data_get($voiceRealtime, 'gear_flow', []));
        $voiceFlowNames = $voiceFlow->pluck('name')->all();

        $this->assertSame(
            [
                'Fala humana',
                'Intent Packet',
                'Kernel + Policy',
                'Decision Receipt',
                'Acao governada',
                'Memoria revisavel',
                'Fronteira Voice Realtime',
            ],
            $voxFlowNames,
            'Atlas Vox must expose a product/architecture visual flow, not a generic text fallback.'
        );
        $this->assertSame(
            [
                'Mobile Voice',
                'LiveKit Agents SDK',
                'Operation Envelope',
                'Atlas Decide',
                'Decision Receipt',
                'Response / TTS',
                'Evidence + Learning',
            ],
            $voiceFlowNames,
            'Voice Realtime must expose its own technical audio/runtime flow, distinct from Atlas Vox.'
        );
        $this->assertNotContains('LiveKit Agents SDK', $voxFlowNames);
        $this->assertNotContains('Fala humana', $voiceFlowNames);
        $this->assertSame('atlas-ai-voice-realtime-canon-de-fala', data_get($voxFlow->firstWhere('name', 'Fala humana'), 'target_graph_id'));
        $this->assertSame('intent-routing', data_get($voxFlow->firstWhere('name', 'Intent Packet'), 'target_graph_id'));
        $this->assertSame('decision-receipt', data_get($voxFlow->firstWhere('name', 'Decision Receipt'), 'target_graph_id'));
        $this->assertSame('atlas-ai-voice-realtime-surface', data_get($voxFlow->firstWhere('name', 'Fronteira Voice Realtime'), 'target_graph_id'));
        $this->assertSame('atlas-ai-voice-realtime-canon-de-fala', data_get($voiceFlow->firstWhere('name', 'Mobile Voice'), 'target_graph_id'));
        $this->assertSame('adr-0002-voice-realtime-sdk-loop-kernel-response-path', data_get($voiceFlow->firstWhere('name', 'LiveKit Agents SDK'), 'target_graph_id'));
        $this->assertSame('operation-envelope', data_get($voiceFlow->firstWhere('name', 'Operation Envelope'), 'target_graph_id'));
        $this->assertSame('atlas-decide', data_get($voiceFlow->firstWhere('name', 'Atlas Decide'), 'target_graph_id'));
        $this->assertSame('decision-receipt', data_get($voiceFlow->firstWhere('name', 'Decision Receipt'), 'target_graph_id'));
        $this->assertSame('adr-0002-voice-realtime-sdk-loop-kernel-response-path', data_get($voiceFlow->firstWhere('name', 'Response / TTS'), 'target_graph_id'));
    }

    public function test_visual_gear_flow_targets_resolve_to_real_semantic_nodes(): void
    {
        $graph = $this->getJson('/atlas-cartography/graph')
            ->assertOk()
            ->json();

        $nodes = collect(data_get($graph, 'semantic_graph.nodes', []));
        $semanticIds = $nodes
            ->pluck('graph_id')
            ->filter()
            ->values()
            ->all();

        $semanticLookup = array_fill_keys($semanticIds, true);
        $missingTargets = [];

        $collectTargets = function (array $items, string $owner) use (&$collectTargets, &$missingTargets, $semanticLookup): void {
            foreach ($items as $item) {
                $target = (string) data_get($item, 'target_graph_id', '');
                if ($target !== '' && ! isset($semanticLookup[$target])) {
                    $missingTargets[] = $owner.' -> '.data_get($item, 'graph_id', 'unknown').' targets '.$target;
                }

                $nested = data_get($item, 'gear_flow', []);
                if (is_array($nested) && $nested !== []) {
                    $collectTargets($nested, $owner.' / '.(string) data_get($item, 'graph_id', 'nested'));
                }
            }
        };

        foreach ($nodes as $node) {
            $flow = data_get($node, 'gear_flow', []);
            if (is_array($flow) && $flow !== []) {
                $collectTargets($flow, (string) data_get($node, 'graph_id', 'unknown'));
            }
        }

        $this->assertSame(
            [],
            $missingTargets,
            'Every gear_flow target_graph_id must resolve to a real semantic node so tap drilldown never opens a synthetic dead end.'
        );
    }

    public function test_graph_exposes_every_active_repo_graph_id_to_mobile_cartography(): void
    {
        $graph = $this->getJson('/atlas-cartography/graph')
            ->assertOk()
            ->json();

        $this->assertSame(
            0,
            data_get($graph, 'audit.orphan_count'),
            'Cartography graph must not ship active nodes with missing parents; invisible orphans make the mobile map lie by omission.'
        );

        /** @var RepoVaultReader $repoReader */
        $repoReader = app(RepoVaultReader::class);
        $repoIndex = $repoReader->index();

        $semanticIds = collect(data_get($graph, 'semantic_graph.nodes', []))
            ->pluck('graph_id')
            ->filter()
            ->values()
            ->all();

        $semanticLookup = array_fill_keys($semanticIds, true);
        $missing = [];

        foreach ($repoIndex as $id => $entry) {
            $frontmatter = $entry['frontmatter'] ?? [];
            $status = (string) ($frontmatter['graph_status'] ?? $frontmatter['status'] ?? '');
            $graphId = (string) ($frontmatter['graph_id'] ?? $id);

            if ($graphId === '') {
                continue;
            }
            if (! in_array($status, ['active', 'building'], true)) {
                continue;
            }
            if (! isset($semanticLookup[$graphId])) {
                $missing[] = $graphId.' @ '.($entry['relative_path'] ?? 'unknown');
            }
        }

        $this->assertSame(
            [],
            $missing,
            'Every active/building repo graph_id must appear in semantic_graph so mobile cartography/search cannot hide documentation.'
        );
    }
}
