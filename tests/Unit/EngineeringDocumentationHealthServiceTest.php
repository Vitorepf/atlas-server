<?php

namespace Tests\Unit;

use App\Services\Engineering\EngineeringDocumentationHealthService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Tests\TestCase;

class EngineeringDocumentationHealthServiceTest extends TestCase
{
    private const AGENTIC_AUTHORITY_MAP = 'docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md';

    private const AGENTIC_INVENTORY = 'docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md';

    public function test_status_value_warning_fires_for_non_canonical_status_on_canonical_module_docs(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-foo.md', ['status' => 'draft']),
            $this->canonicalDoc('atlas-bar.md', ['status' => 'scaffold']),
            $this->canonicalDoc('atlas-baz.md', ['status' => 'active']),
        ];

        $report = $service->analyzeDocs($docs);

        $rules = collect($report['warnings'])->pluck('rule')->all();
        $this->assertContains('status_value_non_canonical', $rules);
        $offending = collect($report['warnings'])
            ->where('rule', 'status_value_non_canonical')
            ->pluck('path')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-foo.md', $offending);
        $this->assertContains('docs/engineering-knowledge-base/atlas-bar.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-baz.md', $offending);
    }

    public function test_status_value_warning_silent_for_archived_or_source_material(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-archived.md', ['status' => 'archived']),
            $this->canonicalDoc('atlas-source.md', ['status' => 'source_material']),
        ];

        $report = $service->analyzeDocs($docs);

        $this->assertSame([], collect($report['warnings'])
            ->where('rule', 'status_value_non_canonical')
            ->values()
            ->all());
    }

    public function test_future_planned_warning_fires_when_runtime_status_is_unclear(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-vision.md', [
                'status' => 'future',
                'summary' => 'Documento ambiguo sem marca de runtime.',
            ], 'Corpo sem marcador algum.'),
            $this->canonicalDoc('atlas-clear-future.md', [
                'status' => 'future',
                'summary' => 'Tese estrategica nao construida; visao futura para Layer 0.5.',
            ], 'Corpo livre.'),
            $this->canonicalDoc('atlas-impl-state.md', [
                'status' => 'planned',
                'summary' => 'Sem marcador no summary.',
                'implementation_state' => 'pending',
            ], 'Corpo livre.'),
        ];

        $report = $service->analyzeDocs($docs);

        $offending = collect($report['warnings'])
            ->where('rule', 'future_planned_not_runtime_unclear')
            ->pluck('path')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-vision.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-clear-future.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-impl-state.md', $offending);
    }

    public function test_deprecated_without_successor_warning_fires(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-old.md', [
                'status' => 'deprecated',
                'summary' => 'Doc legada da era inicial.',
            ]),
            $this->canonicalDoc('atlas-old-with-pointer.md', [
                'status' => 'deprecated',
                'summary' => 'Substituida por atlas-newer e Meta 6.',
            ]),
            $this->canonicalDoc('atlas-old-with-field.md', [
                'status' => 'deprecated',
                'summary' => 'Doc sem palavra-chave no summary.',
                'superseded_by' => 'atlas-newer',
            ]),
        ];

        $report = $service->analyzeDocs($docs);

        $offending = collect($report['warnings'])
            ->where('rule', 'deprecated_without_successor')
            ->pluck('path')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-old.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-old-with-pointer.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-old-with-field.md', $offending);
    }

    public function test_schema_citation_warning_fires_when_evidence_or_repo_paths_empty_on_active_doc(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-active-without-evidence.md', [
                'status' => 'active',
                'evidence' => [],
                'repo_paths' => [],
            ], 'Implementa contrato atlas.ai.mission.v1 em producao.'),
            $this->canonicalDoc('atlas-active-with-evidence.md', [
                'status' => 'active',
                'evidence' => ['external:service-foo'],
                'repo_paths' => ['external:service-foo'],
            ], 'Implementa atlas.ai.mission.v1.'),
        ];

        $report = $service->analyzeDocs($docs);

        $rules = collect($report['warnings'])->pluck('rule')->all();
        $this->assertContains('schema_cited_without_evidence', $rules);
        $offending = collect($report['warnings'])
            ->where('rule', 'schema_cited_without_evidence')
            ->pluck('path')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-active-without-evidence.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-active-with-evidence.md', $offending);
    }

    public function test_schema_citation_warning_fires_when_status_is_draft_without_state_declaration(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-draft-cites-schema.md', [
                'status' => 'draft',
            ], 'Usa atlas.dual_core.route_decision.v1 sem declarar estado.'),
            $this->canonicalDoc('atlas-draft-with-blocker.md', [
                'status' => 'draft',
                'blocker' => 'aguardando aprovacao do plano',
            ], 'Usa atlas.dual_core.route_decision.v1; estado em construcao.'),
        ];

        $report = $service->analyzeDocs($docs);

        $offending = collect($report['warnings'])
            ->where('rule', 'schema_cited_without_runtime_declaration')
            ->pluck('path')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-draft-cites-schema.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-draft-with-blocker.md', $offending);
    }

    public function test_ambiguous_naming_warning_fires_when_doc_mentions_cluster_terms_without_glossary(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-forge-doc.md', [
                'status' => 'active',
                'title' => 'Atlas Forge Runtime Notes',
                'related_paths' => ['docs/engineering-knowledge-base/atlas-something-else.md'],
            ], 'Discute ForgeRivals e Obra Command Center sem referencia ao glossario.'),
            $this->canonicalDoc('atlas-forge-with-glossary.md', [
                'status' => 'active',
                'title' => 'Atlas Forge Runtime Notes',
                'related_paths' => ['docs/engineering-knowledge-base/atlas-canonical-glossary-and-naming.md'],
            ], 'Discute ForgeRivals e Obra.'),
            $this->canonicalDoc('atlas-neutro.md', [
                'status' => 'active',
                'title' => 'Atlas Neutral Topic',
            ], 'Documento sem termos ambiguos.'),
        ];

        $report = $service->analyzeDocs($docs);

        $offending = collect($report['warnings'])
            ->whereIn('rule', ['ambiguous_naming_in_title', 'missing_glossary_reference'])
            ->pluck('path')
            ->all();
        $this->assertContains('docs/engineering-knowledge-base/atlas-forge-doc.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-forge-with-glossary.md', $offending);
        $this->assertNotContains('docs/engineering-knowledge-base/atlas-neutro.md', $offending);
    }

    public function test_glossary_doc_itself_does_not_warn(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-canonical-glossary-and-naming.md', [
                'status' => 'active',
                'title' => 'Atlas Canonical Glossary and Naming',
            ], 'Define termos como Atlas Forge, Atlas Code Forge, Obra Command Center, ForgeRivals.'),
        ];

        $report = $service->analyzeDocs($docs);

        $offending = collect($report['warnings'])
            ->whereIn('rule', ['ambiguous_naming_in_title', 'missing_glossary_reference'])
            ->all();
        $this->assertSame([], $offending);
    }

    public function test_warnings_do_not_inflate_violation_counters(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-draft.md', ['status' => 'draft']),
        ];

        $report = $service->analyzeDocs($docs);

        $this->assertGreaterThan(0, $report['summary']['warning_count']);
        $this->assertSame(0, $report['summary']['frontmatter_violation_count']);
        $this->assertSame(0, $report['summary']['canonical_module_violation_count']);
        $this->assertSame(0, $report['summary']['canonical_module_coverage_violation_count']);
    }

    public function test_cartography_nomenclature_contract_is_required_bootstrap_doc(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-ai-session-bootstrap.md'),
            $this->canonicalDoc('atlas-ai-documentation-operating-system.md'),
            $this->canonicalDoc('atlas-canonical-module-doc-v1.md'),
            $this->canonicalDoc('atlas-documentation-creation-gate.md'),
            $this->canonicalDoc('atlas-ai-knowledge-governance-system.md'),
            $this->canonicalDoc('atlas-ai-runtime-language-boundaries.md'),
            $this->canonicalDoc('atlas-ai-qualitative-levels-roadmap.md'),
            $this->canonicalDoc('atlas-ai-canonical-architecture-index.md'),
            $this->canonicalDoc('START_HERE.md'),
            $this->canonicalDoc('README.md'),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-cartography-nomenclature-contract.md: required documentation bootstrap file is missing',
            $report['violations'],
        );
    }

    public function test_documentation_creation_gate_is_required_bootstrap_doc(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-ai-session-bootstrap.md'),
            $this->canonicalDoc('atlas-ai-documentation-operating-system.md'),
            $this->canonicalDoc('atlas-canonical-module-doc-v1.md'),
            $this->canonicalDoc('atlas-cartography-nomenclature-contract.md'),
            $this->canonicalDoc('atlas-ai-knowledge-governance-system.md'),
            $this->canonicalDoc('atlas-ai-runtime-language-boundaries.md'),
            $this->canonicalDoc('atlas-ai-qualitative-levels-roadmap.md'),
            $this->canonicalDoc('atlas-ai-canonical-architecture-index.md'),
            $this->canonicalDoc('START_HERE.md'),
            $this->canonicalDoc('README.md'),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-documentation-creation-gate.md: required documentation bootstrap file is missing',
            $report['violations'],
        );
    }

    public function test_agentic_engineering_authority_guard_blocks_core_docs_without_authority_links(): void
    {
        $service = $this->makeService();
        $docs = $this->completeAgenticAuthorityFixtureDocs([
            'atlas-programming-governance-system.md' => [
                'related_paths' => ['docs/engineering-knowledge-base/atlas-programming-governance-system-runbook.md'],
            ],
        ]);

        $report = $service->analyzeDocs($docs);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-programming-governance-system.md: Agentic Engineering authority chain must reference [docs/engineering-knowledge-base/atlas-agentic-software-engineering-authority-map.md] (Programming Governance must stay the governed programming flow below Agentic Engineering)',
            $report['violations'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-programming-governance-system.md: Agentic Engineering authority chain must reference [docs/engineering-knowledge-base/atlas-agentic-engineering-documentation-inventory.md] (Programming Governance must stay the governed programming flow below Agentic Engineering)',
            $report['violations'],
        );
        $this->assertSame(2, $report['summary']['agentic_engineering_authority_violation_count']);
    }

    public function test_agentic_engineering_authority_guard_accepts_complete_authority_chain(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs($this->completeAgenticAuthorityFixtureDocs());

        $this->assertSame(0, $report['summary']['agentic_engineering_authority_violation_count']);
        $this->assertSame([], collect($report['violations'])
            ->filter(fn (string $violation): bool => str_contains($violation, 'Agentic Engineering authority chain'))
            ->values()
            ->all());
    }

    public function test_patamar_and_version_collection_fields_must_be_lists(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-bad-patamar-version.md', [
                'patamar_after' => 'Self-Programming OS',
                'versions' => 'V0/V3/V4/V6',
            ]),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-bad-patamar-version.md: optional canonical module field [patamar_after] must be a list',
            $report['violations'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-bad-patamar-version.md: optional canonical module field [versions] must be a list',
            $report['violations'],
        );
    }

    public function test_macro_layer_requires_all_four_naming_fields(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-macro-missing-names.md', [
                'macro_layer' => true,
                'product_name' => 'Atlas Macro Product',
            ]),
            $this->canonicalDoc('atlas-macro-complete.md', [
                'macro_layer' => true,
                'product_name' => 'Atlas Macro Product',
                'runtime_acronym' => 'AMP',
                'internal_product_name' => 'Atlas Macro Surface',
                'technical_runtime' => 'AtlasMacroRuntimeService',
            ]),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-macro-missing-names.md: macro structural layer missing required naming field [runtime_acronym]',
            $report['violations'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-macro-missing-names.md: macro structural layer missing required naming field [internal_product_name]',
            $report['violations'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-macro-missing-names.md: macro structural layer missing required naming field [technical_runtime]',
            $report['violations'],
        );
        $this->assertNotContains(
            'docs/engineering-knowledge-base/atlas-macro-complete.md: macro structural layer missing required naming field [product_name]',
            $report['violations'],
        );
    }

    public function test_macro_layer_field_must_be_boolean(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-bad-macro-marker.md', [
                'macro_layer' => 'true',
                'product_name' => 'Atlas Macro Product',
                'runtime_acronym' => 'AMP',
                'internal_product_name' => 'Atlas Macro Surface',
                'technical_runtime' => 'AtlasMacroRuntimeService',
            ]),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-bad-macro-marker.md: canonical module field [macro_layer] must be boolean',
            $report['violations'],
        );
    }

    public function test_human_gold_docs_require_human_fields_and_relations(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-documentation-reality-system.md', [
                'graph_id' => 'atlas-documentation-reality-system',
                'human_summary' => '',
                'human_what' => 'Area-mae da verdade documental.',
                'human_purpose' => 'Evitar bagunca documental.',
                'human_input' => 'Docs canonicos.',
                'human_output' => 'Mapa de blocos.',
                'human_change_when' => 'Quando a governanca mudar.',
                'human_block_when' => 'Quando faltar fonte.',
                'depends_on' => [],
            ]),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-documentation-reality-system.md: human gold doc missing field [human_summary]',
            $report['violations'],
        );
        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-documentation-reality-system.md: human gold doc relation [depends_on] must be a non-empty list',
            $report['violations'],
        );
        $this->assertSame(2, $report['summary']['human_gold_violation_count']);
    }

    public function test_human_gold_docs_reject_internal_prompt_summaries(): void
    {
        $service = $this->makeService();
        $report = $service->analyzeDocs([
            $this->canonicalDoc('atlas-documentation-reality-system.md', [
                'graph_id' => 'atlas-documentation-reality-system',
                'summary' => 'Me manda um prompt completo para o Claude.',
                'human_summary' => 'Define a verdade documental para humanos e IAs.',
                'human_what' => 'Area-mae da verdade documental.',
                'human_purpose' => 'Evitar bagunca documental.',
                'human_input' => 'Docs canonicos.',
                'human_output' => 'Mapa de blocos.',
                'human_change_when' => 'Quando a governanca mudar.',
                'human_block_when' => 'Quando faltar fonte.',
            ]),
        ]);

        $this->assertContains(
            'docs/engineering-knowledge-base/atlas-documentation-reality-system.md: human gold doc field [summary] looks like internal prompt/task text',
            $report['violations'],
        );
    }

    public function test_summary_carries_warning_count_and_list(): void
    {
        $service = $this->makeService();
        $docs = [
            $this->canonicalDoc('atlas-vision.md', ['status' => 'future'], 'corpo simples'),
            $this->canonicalDoc('atlas-deprecated.md', [
                'status' => 'deprecated',
                'summary' => 'sem marcador de sucessor.',
            ]),
        ];

        $report = $service->analyzeDocs($docs);

        $this->assertSame(count($report['warnings']), $report['summary']['warning_count']);
        $this->assertNotSame([], $report['warnings']);
        foreach ($report['warnings'] as $entry) {
            $this->assertArrayHasKey('rule', $entry);
            $this->assertArrayHasKey('path', $entry);
            $this->assertArrayHasKey('message', $entry);
        }
    }

    private function makeService(): EngineeringDocumentationHealthService
    {
        return new EngineeringDocumentationHealthService(new CanonicalDocsFrontmatterParser);
    }

    /**
     * @param  array<string,array<string,mixed>>  $overridesByFile
     * @return array<int,array<string,mixed>>
     */
    private function completeAgenticAuthorityFixtureDocs(array $overridesByFile = []): array
    {
        $mapAndInventory = [self::AGENTIC_AUTHORITY_MAP, self::AGENTIC_INVENTORY];
        $defaultRefs = ['related_paths' => $mapAndInventory];
        $docs = [];

        foreach ([
            'atlas-ai-session-bootstrap.md',
            'atlas-ai-documentation-operating-system.md',
            'atlas-documentation-creation-gate.md',
            'atlas-canonical-module-doc-v1.md',
            'atlas-cartography-nomenclature-contract.md',
            'atlas-ai-knowledge-governance-system.md',
            'atlas-ai-runtime-language-boundaries.md',
            'atlas-ai-qualitative-levels-roadmap.md',
            'atlas-ai-canonical-architecture-index.md',
        ] as $file) {
            $docs[$file] = $this->canonicalDoc($file, $overridesByFile[$file] ?? []);
        }

        $authorityFiles = [
            'START_HERE.md' => $defaultRefs,
            'README.md' => $defaultRefs,
            'atlas-agentic-software-engineering-authority-map.md' => [
                'related_paths' => [self::AGENTIC_INVENTORY],
            ],
            'atlas-agentic-engineering-documentation-inventory.md' => [
                'related_paths' => [self::AGENTIC_AUTHORITY_MAP],
            ],
            'atlas-agentic-engineering-os.md' => $defaultRefs,
            'atlas-dev-index.md' => $defaultRefs,
            'atlas-programming-governance-system.md' => $defaultRefs,
            'atlas-programming-forge-flow.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
            'atlas-forge-continuum-os.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
            'atlas-forge-operating-system.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
            'atlas-desktop-code-surface.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
            'atlas-code-category-evolution.md' => $defaultRefs,
            'atlas-temporal-engineering-operating-system.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
            'atlas-programming-superiority-architecture.md' => $defaultRefs,
            'atlas-intelligence-factory-os.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
            'atlas-agentic-workcell-runtime.md' => ['related_paths' => [self::AGENTIC_AUTHORITY_MAP]],
        ];

        foreach ($authorityFiles as $file => $frontmatter) {
            $docs[$file] = $this->canonicalDoc($file, array_replace(
                $frontmatter,
                $overridesByFile[$file] ?? [],
            ));
        }

        return array_values($docs);
    }

    /**
     * Build a minimal canonical_module doc array matching the shape
     * produced by scanDocs() but with overrides for fast unit testing.
     *
     * @param  array<string,mixed>  $overrides
     */
    private function canonicalDoc(string $relativeFilename, array $overrides = [], string $bodyExtra = ''): array
    {
        $slug = preg_replace('/\.md$/', '', $relativeFilename) ?? $relativeFilename;
        $defaults = [
            // top-level required
            'id' => $slug,
            'type' => 'engineering_knowledge',
            'title' => 'Atlas Test Doc '.$slug,
            'status' => 'active',
            'category' => 'architecture',
            'priority' => 50,
            'summary' => 'Summary placeholder.',
            'tags' => ['atlas-ai'],
            'capabilities' => ['testing'],
            'decisions' => ['fixture decision'],
            'maintenance' => ['regenerate when fixture changes'],
            'related_paths' => ['docs/engineering-knowledge-base/atlas-ai-canonical-architecture-index.md'],
            // canonical_module required
            'doc_schema' => 'atlas_canonical_module_doc.v1',
            'graph_id' => $slug,
            'graph_title' => 'Atlas Test Doc '.$slug,
            'graph_world' => 'atlas',
            'graph_layer' => 'system',
            'graph_kind' => 'module',
            'graph_parent' => 'atlas-ai-canonical-architecture-index',
            'graph_status' => 'active',
            'graph_source' => 'repo',
            'owner' => 'architecture',
            'repo_paths' => ['external:fixture'],
            'allowed_changes' => ['fixture'],
            'forbidden_changes' => ['fixture'],
            'depends_on' => ['atlas-ai-canonical-architecture-index'],
            'flows_to' => ['atlas-ai-canonical-architecture-index'],
            'unlocks' => ['fixture'],
            'governs' => ['fixture'],
            'evidence' => ['external:fixture'],
            'required_tests' => ['php artisan test'],
            'requires_evidence' => true,
            'risk_level' => 'low',
            'next_actions' => ['fixture'],
        ];
        $frontmatter = array_replace($defaults, $overrides);
        if (in_array($frontmatter['graph_status'] ?? null, ['active'], true) && isset($overrides['status'])) {
            $frontmatter['graph_status'] = $this->graphStatusFor((string) $overrides['status']);
        }
        $path = 'docs/engineering-knowledge-base/'.$relativeFilename;
        $body = $this->canonicalBodySkeleton().($bodyExtra !== '' ? "\n".$bodyExtra : '');

        return [
            'path' => $path,
            'line_count' => 50,
            'status' => (string) ($frontmatter['status'] ?? 'missing'),
            'type' => (string) ($frontmatter['type'] ?? 'engineering_knowledge'),
            'category' => (string) ($frontmatter['category'] ?? 'architecture'),
            'frontmatter' => $frontmatter,
            'frontmatter_errors' => [],
            'body' => $body,
            'limit' => 520,
        ];
    }

    private function graphStatusFor(string $status): string
    {
        return in_array($status, ['planned', 'future', 'building', 'active', 'deprecated'], true)
            ? $status
            : 'active';
    }

    private function canonicalBodySkeleton(): string
    {
        $sections = [
            'Resumo', 'Papel no Atlas', 'Onde Se Encaixa', 'Contratos',
            'Fluxo', 'Regras para IA', 'Escopo de Implementacao',
            'Dependencias', 'Evidencias', 'Riscos', 'Exemplos',
            'Proximas Acoes',
        ];
        $body = '';
        foreach ($sections as $section) {
            $body .= "## {$section}\n\nFixture section.\n\n";
        }

        return $body;
    }
}
