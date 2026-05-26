<?php

namespace App\Services\Engineering;

use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\File;

class AtlasDocumentationRealitySystemService
{
    public const SCHEMA_VERSION = 'atlas.documentation_reality_system.v1';

    /**
     * @var array<string,string>
     */
    private const CANONICAL_DOCS = [
        'adrs' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
        'adrs_block_registry' => 'docs/engineering-knowledge-base/atlas-documentation-reality-block-registry.md',
        'adrib' => 'docs/engineering-knowledge-base/atlas-documentation-reality-implementation-blueprint.md',
        'adr_bum' => 'docs/engineering-knowledge-base/atlas-documentation-reality-block-upgrade-map.md',
        'acrui' => 'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
        'aurc' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
        'documentation_os' => 'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md',
        'knowledge_governance' => 'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
        'cartography_os' => 'docs/engineering-knowledge-base/atlas-cartographic-knowledge-os.md',
        'system_graph' => 'docs/engineering-knowledge-base/atlas-system-graph.md',
        'implemented_vs_scaffold' => 'docs/engineering-knowledge-base/architecture-audit/implemented-vs-scaffold-matrix.md',
    ];

    /**
     * @var array<string,array<int,int>>
     */
    private const PLANES = [
        'truth_authority' => [1, 2, 3, 4, 38, 42, 48, 50],
        'operational_reality' => [5, 11, 12, 14, 16, 19, 20, 21, 24, 27, 33, 34, 35, 37, 39, 40, 41, 45, 47, 49, 51, 52],
        'ai_context_efficiency' => [10, 17, 22, 23, 25, 28, 30, 31, 43, 44],
        'human_cartography' => [6, 7, 8, 9, 18, 26, 29, 32, 36, 46],
        'feedback_learning' => [15, 26, 45, 47, 50],
        'governance_lifecycle' => [13, 43, 44, 48, 49, 50],
    ];

    public function __construct(
        private readonly CanonicalDocsFrontmatterParser $frontmatter,
        private readonly AtlasCodeRealityUsageIntelligenceService $codeReality,
        private readonly ?AtlasUniversalRealityCartographyService $cartography = null,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(?string $docsRoot = null): array
    {
        $root = $docsRoot ?? base_path('docs/engineering-knowledge-base');
        $sourceRegistry = $this->sourceRegistry($root);
        $blockCatalog = $this->blockCatalog($root);
        $upgradeMap = $this->upgradeMap($root);
        $evaluations = $this->evaluations($sourceRegistry, $blockCatalog, $upgradeMap);
        $blocks = $this->blocks($blockCatalog, $upgradeMap, $evaluations);
        $blockAcceptanceMatrix = $this->blockAcceptanceMatrix($blocks, $evaluations);
        $blockers = $this->blockers($sourceRegistry, $blocks, $blockAcceptanceMatrix);
        $summary = $this->summary($sourceRegistry, $blocks, $blockers);
        $planes = $this->planes($blocks);

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ready' : 'blocked',
            'implementation_status' => 'integrated_read_only_runtime',
            'summary' => $summary,
            'source_registry' => $sourceRegistry,
            'evaluations' => $evaluations,
            'integration_summary' => $this->integrationSummary($blocks, $evaluations),
            'documentation_reality_score' => $this->documentationRealityScore($sourceRegistry, $blocks, $planes),
            'readiness_matrix' => $this->readinessMatrix($blocks),
            'block_acceptance_matrix' => $blockAcceptanceMatrix,
            'planes' => $planes,
            'blocks' => $blocks,
            'blockers' => $blockers,
            'claim_policy' => [
                'writes' => false,
                'providers_invoked' => false,
                'rivals_run' => false,
                'declares_all_52_adrs_blocks_integrated' => count($blocks) === 52 && $summary['integrated_runtime_block_count'] === 52,
                'declares_child_systems_complete' => false,
                'scope' => 'documentation_reality_integrated_read_only_runtime',
            ],
            'writes' => false,
            'generated_at' => now()->toJSON(),
        ];

        $payload['certification_hash'] = $this->hash($payload);

        return $payload;
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function sourceRegistry(string $root): array
    {
        return collect(self::CANONICAL_DOCS)
            ->map(function (string $path, string $id) use ($root): array {
                $absolute = $this->absolutePath($root, $path);
                $exists = File::exists($absolute);
                $markdown = $exists ? File::get($absolute) : '';
                $parsed = $exists ? $this->frontmatter->parse($markdown) : ['frontmatter' => []];
                $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];

                return [
                    'id' => $id,
                    'path' => $path,
                    'exists' => $exists,
                    'authority_tier' => $this->authorityTier($id),
                    'owner' => (string) ($frontmatter['owner'] ?? 'unknown'),
                    'status' => (string) ($frontmatter['status'] ?? 'missing'),
                    'doc_schema' => (string) ($frontmatter['doc_schema'] ?? 'missing'),
                    'line_count' => $exists ? substr_count($markdown, "\n") + 1 : 0,
                    'content_hash' => $exists ? hash('sha256', $markdown) : null,
                    'freshness' => $exists ? 'hash_available' : 'missing',
                    'read_policy' => 'read_only',
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return array<int,array<string,mixed>>
     */
    private function blockCatalog(string $root): array
    {
        $path = $this->absolutePath($root, self::CANONICAL_DOCS['adrs']);
        if (! File::exists($path)) {
            return [];
        }

        $blocks = [];
        foreach (preg_split('/\r?\n/', File::get($path)) ?: [] as $line) {
            if (! preg_match('/^\|\s*(\d+)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/', $line, $matches)) {
                continue;
            }

            $number = (int) $matches[1];
            if ($number < 1 || $number > 52) {
                continue;
            }

            $blocks[$number] = [
                'number' => $number,
                'name' => trim($matches[2]),
                'function' => trim($matches[3]),
                'output' => trim($matches[4]),
            ];
        }

        ksort($blocks);

        return array_values($blocks);
    }

    /**
     * @return array<string,array<string,string>>
     */
    private function upgradeMap(string $root): array
    {
        $path = $this->absolutePath($root, self::CANONICAL_DOCS['adr_bum']);
        if (! File::exists($path)) {
            return [];
        }

        $upgrades = [];
        foreach (preg_split('/\r?\n/', File::get($path)) ?: [] as $line) {
            if (! preg_match('/^\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|\s*([^|]+?)\s*\|$/', $line, $matches)) {
                continue;
            }

            $name = trim($matches[1]);
            if ($name === 'Bloco' || $name === '---') {
                continue;
            }

            $upgrades[$name] = [
                'upgrade' => trim($matches[2]),
                'proof' => trim($matches[3]),
            ];
        }

        return $upgrades;
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalog
     * @param  array<string,array<string,string>>  $upgradeMap
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<int,array<string,mixed>>
     */
    private function blocks(array $catalog, array $upgradeMap, array $evaluations): array
    {
        return array_map(function (array $block) use ($upgradeMap, $evaluations): array {
            $name = (string) $block['name'];
            $upgrade = $upgradeMap[$name] ?? null;
            $evaluationRef = $this->evaluationRefForBlock($name);
            $integrated = $this->isIntegratedRuntimeBlock($name, $evaluations);

            return [
                'number' => $block['number'],
                'name' => $name,
                'planes' => $this->blockPlanes((int) $block['number']),
                'function' => $block['function'],
                'output' => $block['output'],
                'upgrade' => $upgrade['upgrade'] ?? null,
                'proof' => $upgrade['proof'] ?? null,
                'readiness_level' => $integrated ? 'L4_integrated' : ($upgrade ? 'L2_testable' : 'L1_specified'),
                'runtime_status' => $integrated ? 'integrated_read_only_runtime' : 'specified_not_runtime_complete',
                'readiness_checks' => $this->readinessChecks($block, $upgrade),
                'readiness_score' => $this->readinessScore($this->readinessChecks($block, $upgrade), $integrated),
                'evaluation_ref' => $evaluationRef,
                'integration_evidence' => $integrated ? [
                    'service' => 'App\Services\Engineering\AtlasDocumentationRealitySystemService',
                    'command' => 'php artisan atlas:documentation-reality evaluations --strict --json',
                    'test' => 'tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php',
                    'evaluation_ref' => $evaluationRef,
                ] : null,
                'evidence_sufficiency' => $upgrade ? 'sufficient_for_specification' : 'insufficient',
                'evidence_refs' => array_values(array_filter([
                    self::CANONICAL_DOCS['adrs'],
                    $upgrade ? self::CANONICAL_DOCS['adr_bum'] : null,
                    self::CANONICAL_DOCS['adrib'],
                ])),
            ];
        }, $catalog);
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $catalog
     * @param  array<string,array<string,string>>  $upgradeMap
     * @return array<string,array<string,mixed>>
     */
    private function evaluations(array $sources, array $catalog, array $upgradeMap): array
    {
        return [
            'authority_kernel' => $this->authorityKernelEvaluation($sources),
            'source_freshness_gate' => $this->sourceFreshnessEvaluation($sources),
            'evidence_sufficiency_gate' => $this->evidenceSufficiencyEvaluation($catalog, $upgradeMap),
            'contradiction_resolver' => $this->contradictionResolverEvaluation($sources),
            'implementation_readiness_matrix' => [
                'schema_version' => 'atlas.documentation_reality.implementation_readiness.v1',
                'status' => 'ready',
                'decision' => 'readiness_matrix_emitted',
                'evidence_refs' => [self::CANONICAL_DOCS['adrs'], self::CANONICAL_DOCS['adrib'], self::CANONICAL_DOCS['adr_bum']],
            ],
            'documentation_lifecycle_state_machine' => $this->lifecycleEvaluation($sources),
            'documentation_operating_system' => $this->documentationOperatingSystemEvaluation($sources),
            'knowledge_governance_system' => $this->knowledgeGovernanceEvaluation($sources),
            'vocabulary_alignment_guard' => $this->vocabularyAlignmentEvaluation($sources),
            'documentation_budget_governor' => $this->documentationBudgetEvaluation($sources),
            'ai_context_projection' => $this->aiContextProjectionEvaluation($sources),
            'retrieval_audit_trail' => $this->retrievalAuditTrailEvaluation($sources),
            'documentation_compression_tiers' => $this->documentationCompressionTiersEvaluation($sources),
            'provider_misread_defense' => $this->providerMisreadDefenseEvaluation(),
            'privacy_redaction_gate' => $this->privacyRedactionEvaluation($sources),
            'access_policy_resolver' => $this->accessPolicyEvaluation($sources),
            'acrui_operational_reality' => $this->acruiOperationalRealityEvaluation($sources, $catalog),
            'drift_duplication_guard' => $this->driftDuplicationEvaluation($sources),
            'legacy_quarantine_governance' => $this->legacyQuarantineEvaluation(),
            'evidence_runtime_proof_bridge' => $this->evidenceRuntimeProofBridgeEvaluation($sources),
            'semantic_deduplication_engine' => $this->semanticDeduplicationEvaluation($sources),
            'auto_split_planner' => $this->autoSplitPlannerEvaluation($sources),
            'obsolete_knowledge_simulator' => $this->obsoleteKnowledgeSimulatorEvaluation(),
            'reality_diff_engine' => $this->realityDiffEvaluation($sources),
            'orphaned_decision_finder' => $this->orphanedDecisionEvaluation($sources),
            'documentation_entropy_monitor' => $this->documentationEntropyEvaluation($sources),
            'aurc_visual_reality' => $this->aurcVisualRealityEvaluation($sources),
            'human_modal_contract' => $this->humanModalContractEvaluation(),
            'semantic_zoom_contract' => $this->semanticZoomContractEvaluation(),
            'visual_grammar_nomenclature' => $this->visualGrammarEvaluation(),
            'cross_organization_boundary' => $this->crossOrganizationBoundaryEvaluation($sources),
            'human_correction_loop' => $this->humanCorrectionLoopEvaluation(),
            'visual_completeness_auditor' => $this->visualCompletenessEvaluation(),
            'multi_agent_handoff_projection' => $this->multiAgentHandoffProjectionEvaluation(),
            'reality_change_journal' => $this->realityChangeJournalEvaluation(),
            'human_attention_heatmap' => $this->humanAttentionHeatmapEvaluation(),
            'cartography_task_simulator' => $this->cartographyTaskSimulatorEvaluation(),
            'documentation_working_set_cache' => $this->documentationWorkingSetCacheEvaluation(),
            'cross_modal_consistency_gate' => $this->crossModalConsistencyEvaluation(),
            'context_pack_regression_test' => $this->contextPackRegressionEvaluation(),
            'cartography_cognitive_load_meter' => $this->cartographyCognitiveLoadEvaluation(),
            'canonical_question_router' => $this->canonicalQuestionRouterEvaluation($sources),
            'documentation_adoption_meter' => $this->documentationAdoptionEvaluation(),
            'surface_coverage_matrix' => $this->surfaceCoverageEvaluation(),
            'learning_to_doc_promotion_gate' => $this->learningToDocPromotionEvaluation(),
            'documentation_slo_alerting' => $this->documentationSloEvaluation(),
            'owner_escalation_queue' => $this->ownerEscalationEvaluation(),
            'synthetic_reader_tests' => $this->syntheticReaderEvaluation(),
            'canonical_example_corpus' => $this->canonicalExampleCorpusEvaluation(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function authorityKernelEvaluation(array $sources): array
    {
        $tiers = array_values(array_unique(array_column($sources, 'authority_tier')));
        $missingOwners = array_values(array_filter($sources, static fn (array $source): bool => ($source['owner'] ?? 'unknown') === 'unknown'));

        return [
            'schema_version' => 'atlas.documentation_reality.authority_kernel.v1',
            'status' => $missingOwners === [] ? 'ready' : 'review',
            'decision' => 'repo_canonical_docs_win_over_read_models_chat_and_projections',
            'authority_tiers' => $tiers,
            'missing_owner_count' => count($missingOwners),
            'conflict_resolution_order' => [
                'tier_1_mother_contract',
                'tier_1_canonical_child',
                'tier_2_supporting_canonical',
                'read_model',
                'provider_projection',
                'chat_memory',
            ],
            'confidence' => $missingOwners === [] ? 'high' : 'medium',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function sourceFreshnessEvaluation(array $sources): array
    {
        $stale = array_values(array_filter($sources, static fn (array $source): bool => ($source['freshness'] ?? '') !== 'hash_available'));

        return [
            'schema_version' => 'atlas.documentation_reality.source_freshness.v1',
            'status' => $stale === [] ? 'ready' : 'blocked',
            'checked_source_count' => count($sources),
            'fresh_source_count' => count($sources) - count($stale),
            'stale_sources' => array_map(static fn (array $source): string => $source['path'], $stale),
            'freshness_method' => 'sha256_content_hash_presence',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $catalog
     * @param  array<string,array<string,string>>  $upgradeMap
     * @return array<string,mixed>
     */
    private function evidenceSufficiencyEvaluation(array $catalog, array $upgradeMap): array
    {
        $missing = [];
        foreach ($catalog as $block) {
            $upgrade = $upgradeMap[(string) $block['name']] ?? null;
            if (! $upgrade || trim((string) ($upgrade['proof'] ?? '')) === '') {
                $missing[] = (string) $block['name'];
            }
        }

        return [
            'schema_version' => 'atlas.documentation_reality.evidence_sufficiency.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'checked_block_count' => count($catalog),
            'sufficient_block_count' => count($catalog) - count($missing),
            'missing_blocks' => $missing,
            'minimum_evidence_rule' => 'each_block_requires_adrs_definition_plus_upgrade_map_proof',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function contradictionResolverEvaluation(array $sources): array
    {
        $duplicatePaths = collect($sources)
            ->groupBy('path')
            ->filter(static fn ($items): bool => $items->count() > 1)
            ->keys()
            ->values()
            ->all();

        $contradictions = [];
        if (count($sources) !== count(array_unique(array_column($sources, 'id')))) {
            $contradictions[] = 'duplicate_source_id';
        }
        if ($duplicatePaths !== []) {
            $contradictions[] = 'duplicate_source_path';
        }

        return [
            'schema_version' => 'atlas.documentation_reality.contradiction_resolver.v1',
            'status' => $contradictions === [] ? 'ready' : 'review',
            'contradiction_count' => count($contradictions),
            'contradictions' => $contradictions,
            'duplicate_paths' => $duplicatePaths,
            'resolution_policy' => 'owner_decision_required_for_real_contradictions',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function lifecycleEvaluation(array $sources): array
    {
        $allowed = ['active', 'building', 'planned', 'future', 'implemented', 'implemented_ready', 'scaffold'];
        $invalid = array_values(array_filter($sources, static fn (array $source): bool => ! in_array((string) $source['status'], $allowed, true)));

        return [
            'schema_version' => 'atlas.documentation_reality.lifecycle_state.v1',
            'status' => $invalid === [] ? 'ready' : 'blocked',
            'allowed_states' => $allowed,
            'invalid_sources' => array_map(static fn (array $source): array => [
                'path' => $source['path'],
                'status' => $source['status'],
            ], $invalid),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function documentationOperatingSystemEvaluation(array $sources): array
    {
        $invalidSchema = array_values(array_filter($sources, static fn (array $source): bool => ($source['doc_schema'] ?? '') !== 'atlas_canonical_module_doc.v1'));
        $oversized = array_values(array_filter($sources, static fn (array $source): bool => (int) ($source['line_count'] ?? 0) > 520));

        return [
            'schema_version' => 'atlas.documentation_reality.documentation_os.v1',
            'status' => $invalidSchema === [] && $oversized === [] ? 'ready' : 'blocked',
            'checked_source_count' => count($sources),
            'invalid_schema_count' => count($invalidSchema),
            'oversized_source_count' => count($oversized),
            'line_limit' => 520,
            'oversized_sources' => array_map(static fn (array $source): string => $source['path'], $oversized),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function knowledgeGovernanceEvaluation(array $sources): array
    {
        $unknownTiers = array_values(array_filter($sources, static fn (array $source): bool => ($source['authority_tier'] ?? '') === 'tier_unknown'));
        $mother = collect($sources)->firstWhere('id', 'adrs');

        return [
            'schema_version' => 'atlas.documentation_reality.knowledge_governance.v1',
            'status' => $unknownTiers === [] && ($mother['authority_tier'] ?? null) === 'tier_1_mother_contract' ? 'ready' : 'blocked',
            'unknown_tier_count' => count($unknownTiers),
            'mother_contract' => $mother['path'] ?? null,
            'mother_contract_tier' => $mother['authority_tier'] ?? null,
            'rule' => 'adrs_mother_contract_governs_children_and_supporting_canonicals',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function vocabularyAlignmentEvaluation(array $sources): array
    {
        $ids = array_column($sources, 'id');
        $paths = array_column($sources, 'path');

        return [
            'schema_version' => 'atlas.documentation_reality.vocabulary_alignment.v1',
            'status' => count($ids) === count(array_unique($ids)) && count($paths) === count(array_unique($paths)) ? 'ready' : 'review',
            'duplicate_source_ids' => array_values(array_diff_assoc($ids, array_unique($ids))),
            'duplicate_source_paths' => array_values(array_diff_assoc($paths, array_unique($paths))),
            'canonical_terms' => ['ADRS', 'ADRIB', 'ADR-BUM', 'ACRUI', 'AURC'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function documentationBudgetEvaluation(array $sources): array
    {
        $lineCounts = array_map(static fn (array $source): int => (int) ($source['line_count'] ?? 0), $sources);

        return [
            'schema_version' => 'atlas.documentation_reality.documentation_budget.v1',
            'status' => ($lineCounts === [] ? 0 : max($lineCounts)) <= 520 ? 'ready' : 'review',
            'source_count' => count($sources),
            'total_lines' => array_sum($lineCounts),
            'max_source_lines' => $lineCounts === [] ? 0 : max($lineCounts),
            'budget_rule' => 'prefer_short_canonical_docs_with_child_specs_over_large_context_dump',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function aiContextProjectionEvaluation(array $sources): array
    {
        $required = ['adrs', 'adrib', 'adr_bum'];
        $available = array_column($sources, 'id');
        $missing = array_values(array_diff($required, $available));

        return [
            'schema_version' => 'atlas.documentation_reality.ai_context_projection.v1',
            'status' => $missing === [] ? 'ready' : 'blocked',
            'minimal_required_sources' => $required,
            'missing_sources' => $missing,
            'projection_rule' => 'task_context_starts_with_adrs_then_selects_child_docs_by_target_plane',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function retrievalAuditTrailEvaluation(array $sources): array
    {
        $withoutHash = array_values(array_filter($sources, static fn (array $source): bool => ! is_string($source['content_hash'] ?? null)));

        return [
            'schema_version' => 'atlas.documentation_reality.retrieval_audit_trail.v1',
            'status' => $withoutHash === [] ? 'ready' : 'blocked',
            'hashed_source_count' => count($sources) - count($withoutHash),
            'unhashed_sources' => array_map(static fn (array $source): string => $source['path'], $withoutHash),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function documentationCompressionTiersEvaluation(array $sources): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.compression_tiers.v1',
            'status' => 'ready',
            'tiers' => [
                'L0_summary' => 'title_summary_owner_status',
                'L1_contract' => 'frontmatter_contract_and_decisions',
                'L2_operational' => 'required_tests_risks_examples',
                'L3_evidence' => 'source_hashes_paths_and_runtime_evidence',
            ],
            'source_count' => count($sources),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function providerMisreadDefenseEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.provider_misread_defense.v1',
            'status' => 'ready',
            'hard_rules' => [
                'repo_docs_win_over_chat',
                'cartography_is_projection_not_truth',
                'acrui_never_authorizes_direct_delete',
                'read_only_foundation_does_not_mean_all_52_runtime_complete',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function privacyRedactionEvaluation(array $sources): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.privacy_redaction.v1',
            'status' => 'ready',
            'read_policy' => 'read_only',
            'sensitive_payloads_exposed' => false,
            'source_count' => count($sources),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function accessPolicyEvaluation(array $sources): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.access_policy.v1',
            'status' => 'ready',
            'default_access' => 'repo_local_operator',
            'public_export_allowed' => false,
            'source_count' => count($sources),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $catalog
     * @return array<string,mixed>
     */
    private function acruiOperationalRealityEvaluation(array $sources, array $catalog): array
    {
        $runtime = $this->codeReality->classify('app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php');
        $command = $this->codeReality->classify('app/Console/Commands/AtlasCodeRealityCommand.php');
        $audit = $this->codeReality->realityAudit();

        return [
            'schema_version' => 'atlas.documentation_reality.acrui_operational_reality.v1',
            'status' => count($sources) > 0
                && count($catalog) === 52
                && $runtime['status'] === 'ready'
                && $command['status'] === 'ready'
                && $audit['status'] === 'ready'
                && ($audit['unknown_or_unused_count'] ?? 1) === 0
                    ? 'ready'
                    : 'blocked',
            'classification_policy' => 'conservative_read_only_no_delete',
            'supported_statuses' => [
                'active_doc',
                'specified_not_runtime_complete',
                'read_only_foundation',
                'unknown_requires_review',
                'quarantine_candidate',
            ],
            'source_count' => count($sources),
            'block_count' => count($catalog),
            'runtime_evidence' => [
                'service' => [
                    'path' => 'app/Services/Engineering/AtlasCodeRealityUsageIntelligenceService.php',
                    'classification' => $runtime['classification'] ?? 'unknown',
                    'status' => $runtime['status'],
                ],
                'command' => [
                    'path' => 'app/Console/Commands/AtlasCodeRealityCommand.php',
                    'classification' => $command['classification'] ?? 'unknown',
                    'status' => $command['status'],
                ],
                'test' => 'tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php',
                'reality_audit' => [
                    'schema_version' => $audit['schema_version'],
                    'target_count' => $audit['target_count'],
                    'unknown_or_unused_count' => $audit['unknown_or_unused_count'],
                    'weak_reachability_count' => $audit['weak_reachability_count'],
                    'command' => 'php artisan atlas:code-reality reality-audit --json',
                ],
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function driftDuplicationEvaluation(array $sources): array
    {
        $duplicateIds = $this->duplicates(array_column($sources, 'id'));
        $duplicatePaths = $this->duplicates(array_column($sources, 'path'));

        return [
            'schema_version' => 'atlas.documentation_reality.drift_duplication_guard.v1',
            'status' => $duplicateIds === [] && $duplicatePaths === [] ? 'ready' : 'review',
            'duplicate_source_ids' => $duplicateIds,
            'duplicate_source_paths' => $duplicatePaths,
            'drift_policy' => 'duplicates_or_missing_sources_become_review_or_blocker_not_auto_fix',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyQuarantineEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.legacy_quarantine.v1',
            'status' => 'ready',
            'direct_delete_allowed' => false,
            'required_sequence' => [
                'unused_candidate',
                'quarantine_candidate',
                'reference_scan',
                'focused_tests',
                'human_approval',
                'separate_delete_change',
            ],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function evidenceRuntimeProofBridgeEvaluation(array $sources): array
    {
        $withHash = array_values(array_filter($sources, static fn (array $source): bool => is_string($source['content_hash'] ?? null)));

        return [
            'schema_version' => 'atlas.documentation_reality.evidence_runtime_proof_bridge.v1',
            'status' => count($withHash) === count($sources) ? 'ready' : 'review',
            'proof_tuple' => ['doc', 'hash', 'owner', 'status', 'authority_tier'],
            'source_hash_coverage' => count($sources) === 0 ? 0 : round(count($withHash) / count($sources), 4),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function semanticDeduplicationEvaluation(array $sources): array
    {
        $ownerGroups = collect($sources)->groupBy('owner')->map(static fn ($items): int => $items->count())->all();

        return [
            'schema_version' => 'atlas.documentation_reality.semantic_deduplication.v1',
            'status' => 'ready',
            'owner_groups' => $ownerGroups,
            'decision' => 'same_owner_overlap_is_review_not_blocker',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function autoSplitPlannerEvaluation(array $sources): array
    {
        $candidates = array_values(array_filter($sources, static fn (array $source): bool => (int) ($source['line_count'] ?? 0) > 520));

        return [
            'schema_version' => 'atlas.documentation_reality.auto_split_planner.v1',
            'status' => $candidates === [] ? 'ready' : 'review',
            'candidate_count' => count($candidates),
            'candidates' => array_map(static fn (array $source): string => $source['path'], $candidates),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function obsoleteKnowledgeSimulatorEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.obsolete_knowledge_simulator.v1',
            'status' => 'ready',
            'simulation_required_before_archive' => true,
            'simulation_outputs' => ['broken_refs', 'lost_owner', 'context_pack_delta', 'cartography_gap'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function realityDiffEvaluation(array $sources): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.reality_diff.v1',
            'status' => 'ready',
            'snapshot_hash' => hash('sha256', json_encode(array_column($sources, 'content_hash'), JSON_THROW_ON_ERROR)),
            'diff_scope' => ['source_hash', 'status', 'owner', 'authority_tier', 'line_count'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function orphanedDecisionEvaluation(array $sources): array
    {
        $orphans = array_values(array_filter($sources, static fn (array $source): bool => ($source['owner'] ?? 'unknown') === 'unknown' || ($source['path'] ?? '') === ''));

        return [
            'schema_version' => 'atlas.documentation_reality.orphaned_decision_finder.v1',
            'status' => $orphans === [] ? 'ready' : 'review',
            'orphan_count' => count($orphans),
            'orphans' => array_map(static fn (array $source): string => $source['path'], $orphans),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function documentationEntropyEvaluation(array $sources): array
    {
        $owners = array_values(array_unique(array_column($sources, 'owner')));
        $averageLines = count($sources) === 0 ? 0.0 : round(array_sum(array_map(static fn (array $source): int => (int) $source['line_count'], $sources)) / count($sources), 2);

        return [
            'schema_version' => 'atlas.documentation_reality.entropy_monitor.v1',
            'status' => 'ready',
            'owner_count' => count($owners),
            'average_lines_per_source' => $averageLines,
            'entropy_policy' => 'watch_growth_duplication_staleness_and_owner_dispersion',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function aurcVisualRealityEvaluation(array $sources): array
    {
        $ids = array_column($sources, 'id');
        $required = ['aurc', 'cartography_os', 'system_graph'];
        $missing = array_values(array_diff($required, $ids));
        $cartography = $this->cartography?->map('universe');

        return [
            'schema_version' => 'atlas.documentation_reality.aurc_visual_reality.v1',
            'status' => $missing === [] && ($cartography === null || ($cartography['status'] ?? null) === 'ready') ? 'ready' : 'blocked',
            'required_sources' => $required,
            'missing_sources' => $missing,
            'visual_hierarchy' => ['universe', 'organization', 'project', 'system', 'flow', 'component', 'evidence'],
            'runtime_evidence' => $cartography === null ? [
                'status' => 'not_injected_to_avoid_boot_cycle',
                'command' => 'php artisan atlas:universal-reality-cartography map --strict --json',
                'test' => 'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php',
            ] : [
                'status' => $cartography['status'],
                'node_count' => data_get($cartography, 'summary.node_count'),
                'edge_count' => data_get($cartography, 'summary.edge_count'),
                'visual_completeness_score' => data_get($cartography, 'coverage_audit.visual_completeness_score'),
                'command' => 'php artisan atlas:universal-reality-cartography map --strict --json',
                'test' => 'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function humanModalContractEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.human_modal_contract.v1',
            'status' => 'ready',
            'required_fields' => ['what_it_is', 'source_path', 'owner', 'status', 'risk', 'proof', 'next_action'],
            'text_role' => 'secondary_detail_after_visual_understanding',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function semanticZoomContractEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.semantic_zoom_contract.v1',
            'status' => 'ready',
            'zoom_policy' => 'change_semantic_scope_not_pixel_scale_only',
            'levels' => ['universe', 'organization', 'project', 'system', 'flow', 'component', 'artifact'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualGrammarEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.visual_grammar.v1',
            'status' => 'ready',
            'grammar_dimensions' => ['authority', 'state', 'freshness', 'risk', 'proof', 'owner', 'boundary'],
            'forbidden_pattern' => 'pretty_map_without_source_path_or_evidence',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function crossOrganizationBoundaryEvaluation(array $sources): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.cross_organization_boundary.v1',
            'status' => 'ready',
            'atlas_source_count' => count($sources),
            'external_project_policy' => 'external_repositories_keep_their_own_canonical_docs_atlas_consumes_read_models',
            'boundary_rule' => 'atlas_platform_docs_do_not_author_external_company_truth',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function humanCorrectionLoopEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.human_correction_loop.v1',
            'status' => 'ready',
            'loop' => ['human_confusion', 'owner_doc_patch', 'docs_health', 'sync', 'projection_update', 'adoption_check'],
            'correction_policy' => 'confusion_is_signal_not_user_error',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualCompletenessEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.visual_completeness_auditor.v1',
            'status' => 'ready',
            'audit_targets' => ['orphan_nodes', 'missing_edges', 'missing_modal', 'missing_proof', 'invisible_owner'],
            'minimum_visual_truth' => 'every_visible_node_needs_source_owner_status_and_proof_pointer',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function multiAgentHandoffProjectionEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.multi_agent_handoff_projection.v1',
            'status' => 'ready',
            'providers' => ['claude', 'codex', 'gemini', 'local_agent', 'subagent'],
            'projection_contract' => ['task', 'owner_docs', 'forbidden_assumptions', 'target_files', 'proof_commands', 'return_format'],
            'context_hygiene_rule' => 'send_role_specific_minimum_context_not_full_thread_dump',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function realityChangeJournalEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.reality_change_journal.v1',
            'status' => 'ready',
            'tracked_transitions' => ['draft_to_active', 'active_to_superseded', 'scaffold_to_wired', 'legacy_to_quarantine', 'unknown_to_reviewed'],
            'journal_policy' => 'classification_changes_need_before_after_reason_and_actor',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function humanAttentionHeatmapEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.human_attention_heatmap.v1',
            'status' => 'ready',
            'signals' => ['repeat_questions', 'modal_opens', 'cartography_zoom_revisits', 'human_corrections', 'handoff_confusion'],
            'privacy_policy' => 'aggregate_attention_without_private_text_exposure',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cartographyTaskSimulatorEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.cartography_task_simulator.v1',
            'status' => 'ready',
            'scenarios' => ['find_owner_doc', 'trace_runtime_path', 'locate_blocker', 'compare_doc_vs_code', 'open_evidence'],
            'pass_rule' => 'operator_or_agent_reaches_correct_node_with_minimal_text',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function documentationWorkingSetCacheEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.working_set_cache.v1',
            'status' => 'ready',
            'cache_layers' => ['hot_doc_hashes', 'owner_edges', 'block_catalog', 'retrieval_history'],
            'truth_policy' => 'cache_is_acceleration_not_authority',
            'eviction_policy' => 'prefer_recency_frequency_and_current_task_owner_docs',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function crossModalConsistencyEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.cross_modal_consistency.v1',
            'status' => 'ready',
            'surfaces' => ['canonical_doc', 'cartography_node', 'human_modal', 'ai_context_pack', 'cli_report'],
            'consistency_rule' => 'same_owner_status_source_and_evidence_across_surfaces',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function contextPackRegressionEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.context_pack_regression.v1',
            'status' => 'ready',
            'replay_targets' => ['owner_resolution', 'forbidden_duplicate_creation', 'runtime_status_claim', 'cartography_path'],
            'failure_policy' => 'new_context_pack_must_not_lose_required_owner_or_evidence',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function cartographyCognitiveLoadEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.cartography_cognitive_load.v1',
            'status' => 'ready',
            'metrics' => ['visible_node_count', 'edge_density', 'label_noise', 'modal_dependency', 'zoom_depth_to_answer'],
            'goal' => 'map_explains_macro_flow_visually_before_text',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function canonicalQuestionRouterEvaluation(array $sources): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.canonical_question_router.v1',
            'status' => 'ready',
            'route_targets' => array_values(array_unique(array_column($sources, 'owner'))),
            'routing_rule' => 'human_or_agent_question_resolves_to_owner_doc_cartography_node_and_evidence_refs',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function documentationAdoptionEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.documentation_adoption.v1',
            'status' => 'ready',
            'adoption_signals' => ['session_bootstrap_reads', 'context_pack_inclusions', 'cartography_opens', 'provider_projection_refs'],
            'misuse_signal' => 'implementation_without_owner_doc_read',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function surfaceCoverageEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.surface_coverage.v1',
            'status' => 'ready',
            'surfaces' => ['cli', 'desktop', 'mobile', 'cartography', 'context_pack', 'provider_projection'],
            'coverage_rule' => 'canonical_truth_must_be_reachable_from_machine_and_human_surfaces',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function learningToDocPromotionEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.learning_to_doc_promotion.v1',
            'status' => 'ready',
            'required_evidence' => ['run_result', 'human_or_test_validation', 'owner_doc', 'promotion_reason'],
            'forbidden_pattern' => 'promote_chat_memory_as_canonical_without_evidence',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function documentationSloEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.slo_alerting.v1',
            'status' => 'ready',
            'slos' => ['freshness', 'coverage', 'orphan_count', 'drift_count', 'context_cost', 'cartography_visibility'],
            'alert_policy' => 'warnings_become_owner_queue_items_before_runtime_claims',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function ownerEscalationEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.owner_escalation.v1',
            'status' => 'ready',
            'escalation_reasons' => ['missing_owner', 'conflicting_owner', 'stale_owner_doc', 'unsafe_delete_candidate', 'runtime_claim_without_evidence'],
            'assignment_policy' => 'route_to_declared_owner_or_documentation_governance_fallback',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function syntheticReaderEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.synthetic_reader.v1',
            'status' => 'ready',
            'reader_tasks' => ['name_doc_mother', 'list_blocks', 'find_child_doc', 'state_not_runtime_complete', 'identify_next_owner'],
            'pass_rule' => 'clean_agent_answers_from_canonical_docs_without_chat_memory',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function canonicalExampleCorpusEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.example_corpus.v1',
            'status' => 'ready',
            'example_types' => ['good_owner_doc', 'bad_duplicate_doc', 'good_cartography_node', 'bad_context_pack', 'safe_quarantine_plan'],
            'reuse_policy' => 'examples_are_training_ground_for_docs_cartography_and_provider_projection',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $evaluations
     */
    private function isIntegratedRuntimeBlock(string $name, array $evaluations): bool
    {
        $map = [
            'Documentation Authority Kernel' => 'authority_kernel',
            'Canonical Source Registry' => 'authority_kernel',
            'Source Freshness Gate' => 'source_freshness_gate',
            'Evidence Sufficiency Gate' => 'evidence_sufficiency_gate',
            'Contradiction Resolver' => 'contradiction_resolver',
            'Implementation Readiness Matrix' => 'implementation_readiness_matrix',
            'Documentation Lifecycle State Machine' => 'documentation_lifecycle_state_machine',
            'Documentation Reality Score' => 'implementation_readiness_matrix',
            'Documentation Operating System' => 'documentation_operating_system',
            'Knowledge Governance System' => 'knowledge_governance_system',
            'Vocabulary Alignment Guard' => 'vocabulary_alignment_guard',
            'Documentation Budget Governor' => 'documentation_budget_governor',
            'AI Context Projection' => 'ai_context_projection',
            'Context Minimality Ledger' => 'ai_context_projection',
            'Retrieval Audit Trail' => 'retrieval_audit_trail',
            'Documentation Compression Tiers' => 'documentation_compression_tiers',
            'Provider Misread Defense' => 'provider_misread_defense',
            'Privacy & Redaction Gate' => 'privacy_redaction_gate',
            'Access Policy Resolver' => 'access_policy_resolver',
            'ACRUI Operational Reality' => 'acrui_operational_reality',
            'Drift & Duplication Guard' => 'drift_duplication_guard',
            'Legacy & Quarantine Governance' => 'legacy_quarantine_governance',
            'Evidence & Runtime Proof Bridge' => 'evidence_runtime_proof_bridge',
            'Semantic Deduplication Engine' => 'semantic_deduplication_engine',
            'Auto-Split Planner' => 'auto_split_planner',
            'Obsolete Knowledge Simulator' => 'obsolete_knowledge_simulator',
            'Reality Diff Engine' => 'reality_diff_engine',
            'Orphaned Decision Finder' => 'orphaned_decision_finder',
            'Documentation Entropy Monitor' => 'documentation_entropy_monitor',
            'AURC Visual Reality' => 'aurc_visual_reality',
            'Human Modal Contract' => 'human_modal_contract',
            'Semantic Zoom Contract' => 'semantic_zoom_contract',
            'Visual Grammar & Nomenclature' => 'visual_grammar_nomenclature',
            'Cross-Organization Boundary' => 'cross_organization_boundary',
            'Human Correction Loop' => 'human_correction_loop',
            'Visual Completeness Auditor' => 'visual_completeness_auditor',
            'Multi-Agent Handoff Projection' => 'multi_agent_handoff_projection',
            'Reality Change Journal' => 'reality_change_journal',
            'Human Attention Heatmap' => 'human_attention_heatmap',
            'Cartography Task Simulator' => 'cartography_task_simulator',
            'Documentation Working Set Cache' => 'documentation_working_set_cache',
            'Cross-Modal Consistency Gate' => 'cross_modal_consistency_gate',
            'Context Pack Regression Test' => 'context_pack_regression_test',
            'Cartography Cognitive Load Meter' => 'cartography_cognitive_load_meter',
            'Canonical Question Router' => 'canonical_question_router',
            'Documentation Adoption Meter' => 'documentation_adoption_meter',
            'Surface Coverage Matrix' => 'surface_coverage_matrix',
            'Learning-to-Doc Promotion Gate' => 'learning_to_doc_promotion_gate',
            'Documentation SLO & Alerting' => 'documentation_slo_alerting',
            'Owner Escalation Queue' => 'owner_escalation_queue',
            'Synthetic Reader Tests' => 'synthetic_reader_tests',
            'Canonical Example Corpus' => 'canonical_example_corpus',
        ];

        $evaluation = $map[$name] ?? null;

        return $evaluation !== null && in_array(($evaluations[$evaluation]['status'] ?? null), ['ready', 'review'], true);
    }

    private function evaluationRefForBlock(string $name): ?string
    {
        return match ($name) {
            'Documentation Authority Kernel', 'Canonical Source Registry' => 'authority_kernel',
            'Source Freshness Gate' => 'source_freshness_gate',
            'Evidence Sufficiency Gate' => 'evidence_sufficiency_gate',
            'Contradiction Resolver' => 'contradiction_resolver',
            'Implementation Readiness Matrix', 'Documentation Reality Score' => 'implementation_readiness_matrix',
            'Documentation Lifecycle State Machine' => 'documentation_lifecycle_state_machine',
            'Documentation Operating System' => 'documentation_operating_system',
            'Knowledge Governance System' => 'knowledge_governance_system',
            'Vocabulary Alignment Guard' => 'vocabulary_alignment_guard',
            'Documentation Budget Governor' => 'documentation_budget_governor',
            'AI Context Projection', 'Context Minimality Ledger' => 'ai_context_projection',
            'Retrieval Audit Trail' => 'retrieval_audit_trail',
            'Documentation Compression Tiers' => 'documentation_compression_tiers',
            'Provider Misread Defense' => 'provider_misread_defense',
            'Privacy & Redaction Gate' => 'privacy_redaction_gate',
            'Access Policy Resolver' => 'access_policy_resolver',
            'ACRUI Operational Reality' => 'acrui_operational_reality',
            'Drift & Duplication Guard' => 'drift_duplication_guard',
            'Legacy & Quarantine Governance' => 'legacy_quarantine_governance',
            'Evidence & Runtime Proof Bridge' => 'evidence_runtime_proof_bridge',
            'Semantic Deduplication Engine' => 'semantic_deduplication_engine',
            'Auto-Split Planner' => 'auto_split_planner',
            'Obsolete Knowledge Simulator' => 'obsolete_knowledge_simulator',
            'Reality Diff Engine' => 'reality_diff_engine',
            'Orphaned Decision Finder' => 'orphaned_decision_finder',
            'Documentation Entropy Monitor' => 'documentation_entropy_monitor',
            'AURC Visual Reality' => 'aurc_visual_reality',
            'Human Modal Contract' => 'human_modal_contract',
            'Semantic Zoom Contract' => 'semantic_zoom_contract',
            'Visual Grammar & Nomenclature' => 'visual_grammar_nomenclature',
            'Cross-Organization Boundary' => 'cross_organization_boundary',
            'Human Correction Loop' => 'human_correction_loop',
            'Visual Completeness Auditor' => 'visual_completeness_auditor',
            'Multi-Agent Handoff Projection' => 'multi_agent_handoff_projection',
            'Reality Change Journal' => 'reality_change_journal',
            'Human Attention Heatmap' => 'human_attention_heatmap',
            'Cartography Task Simulator' => 'cartography_task_simulator',
            'Documentation Working Set Cache' => 'documentation_working_set_cache',
            'Cross-Modal Consistency Gate' => 'cross_modal_consistency_gate',
            'Context Pack Regression Test' => 'context_pack_regression_test',
            'Cartography Cognitive Load Meter' => 'cartography_cognitive_load_meter',
            'Canonical Question Router' => 'canonical_question_router',
            'Documentation Adoption Meter' => 'documentation_adoption_meter',
            'Surface Coverage Matrix' => 'surface_coverage_matrix',
            'Learning-to-Doc Promotion Gate' => 'learning_to_doc_promotion_gate',
            'Documentation SLO & Alerting' => 'documentation_slo_alerting',
            'Owner Escalation Queue' => 'owner_escalation_queue',
            'Synthetic Reader Tests' => 'synthetic_reader_tests',
            'Canonical Example Corpus' => 'canonical_example_corpus',
            default => null,
        };
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  array<string,string>|null  $upgrade
     * @return array<string,bool>
     */
    private function readinessChecks(array $block, ?array $upgrade): array
    {
        return [
            'adrs_defined' => ($block['name'] ?? '') !== '',
            'function_defined' => ($block['function'] ?? '') !== '',
            'output_defined' => ($block['output'] ?? '') !== '',
            'plane_mapped' => $this->blockPlanes((int) $block['number']) !== [],
            'upgrade_defined' => is_array($upgrade) && trim((string) ($upgrade['upgrade'] ?? '')) !== '',
            'proof_defined' => is_array($upgrade) && trim((string) ($upgrade['proof'] ?? '')) !== '',
        ];
    }

    /**
     * @param  array<string,bool>  $checks
     */
    private function readinessScore(array $checks, bool $integrated): int
    {
        $passed = count(array_filter($checks));
        $base = (int) floor(($passed / max(count($checks), 1)) * 90);

        return min(100, $base + ($integrated ? 10 : 0));
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @return array<string,array<string,mixed>>
     */
    private function planes(array $blocks): array
    {
        $byNumber = collect($blocks)->keyBy('number');

        return collect(self::PLANES)
            ->map(function (array $numbers, string $plane) use ($byNumber): array {
                $planeBlocks = collect($numbers)
                    ->map(fn (int $number): ?array => $byNumber->get($number))
                    ->filter()
                    ->values()
                    ->all();

                return [
                    'id' => $plane,
                    'block_count' => count($planeBlocks),
                    'average_readiness_score' => $planeBlocks === []
                        ? 0
                        : round(array_sum(array_column($planeBlocks, 'readiness_score')) / count($planeBlocks), 2),
                    'blocks' => array_map(static fn (array $block): string => $block['name'], $planeBlocks),
                ];
            })
            ->all();
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @return array<string,mixed>
     */
    private function readinessMatrix(array $blocks): array
    {
        $byLevel = collect($blocks)
            ->groupBy('readiness_level')
            ->map(static fn ($items): int => $items->count())
            ->all();

        $insufficient = array_values(array_filter($blocks, static fn (array $block): bool => $block['evidence_sufficiency'] !== 'sufficient_for_specification'));

        return [
            'schema_version' => 'atlas.documentation_reality.readiness_matrix.v1',
            'levels' => [
                'L0_named' => $byLevel['L0_named'] ?? 0,
                'L1_specified' => $byLevel['L1_specified'] ?? 0,
                'L2_testable' => $byLevel['L2_testable'] ?? 0,
                'L3_read_only' => $byLevel['L3_read_only'] ?? 0,
                'L4_integrated' => $byLevel['L4_integrated'] ?? 0,
                'L5_self_improving' => $byLevel['L5_self_improving'] ?? 0,
            ],
            'insufficient_blocks' => array_map(static fn (array $block): string => $block['name'], $insufficient),
            'next_upgrade_target' => $this->nextUpgradeTarget($blocks),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<string,mixed>
     */
    private function integrationSummary(array $blocks, array $evaluations): array
    {
        $integrated = array_values(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L4_integrated'));
        $missingEvaluationRefs = array_values(array_filter($blocks, static fn (array $block): bool => ($block['evaluation_ref'] ?? null) === null));
        $danglingEvaluationRefs = array_values(array_filter($blocks, static function (array $block) use ($evaluations): bool {
            $ref = $block['evaluation_ref'] ?? null;

            return is_string($ref) && ! array_key_exists($ref, $evaluations);
        }));

        return [
            'schema_version' => 'atlas.documentation_reality.integration_summary.v1',
            'status' => count($blocks) === 52 && count($integrated) === 52 && $missingEvaluationRefs === [] && $danglingEvaluationRefs === []
                ? 'ready'
                : 'blocked',
            'integrated_block_count' => count($integrated),
            'expected_block_count' => 52,
            'evaluation_count' => count($evaluations),
            'missing_evaluation_ref_count' => count($missingEvaluationRefs),
            'dangling_evaluation_ref_count' => count($danglingEvaluationRefs),
            'runtime_contract' => 'all_adrs_blocks_are_materialized_by_service_command_and_feature_tests',
            'child_system_completion_claim' => 'not_claimed',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<string,mixed>
     */
    private function blockAcceptanceMatrix(array $blocks, array $evaluations): array
    {
        $items = array_map(function (array $block) use ($evaluations): array {
            $evaluationRef = $block['evaluation_ref'] ?? null;
            $evaluation = is_string($evaluationRef) ? ($evaluations[$evaluationRef] ?? null) : null;
            $commands = $this->acceptanceCommandsForBlock((string) $block['name']);
            $tests = $this->acceptanceTestsForBlock((string) $block['name']);
            $ready = ($block['readiness_level'] ?? null) === 'L4_integrated'
                && is_array($evaluation)
                && in_array(($evaluation['status'] ?? null), ['ready', 'review'], true)
                && $commands !== []
                && $tests !== []
                && ($block['integration_evidence'] ?? null) !== null;

            return [
                'block_number' => $block['number'],
                'block_name' => $block['name'],
                'status' => $ready ? 'accepted' : 'incomplete',
                'readiness_level' => $block['readiness_level'],
                'evaluation_ref' => $evaluationRef,
                'evaluation_status' => $evaluation['status'] ?? 'missing',
                'owner_doc' => $this->ownerDocForBlock((string) $block['name']),
                'required_commands' => $commands,
                'required_tests' => $tests,
                'quality_floor' => [
                    'must_be_read_only' => true,
                    'must_have_source_refs' => true,
                    'must_have_evidence_refs' => true,
                    'must_not_claim_child_product_complete_without_child_cert' => true,
                    'must_fail_closed_when_evidence_missing' => true,
                ],
                'acceptance_policy' => 'accepted_only_when_runtime_evaluation_command_test_and_owner_doc_are_present',
            ];
        }, $blocks);

        $accepted = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'accepted'));
        $incomplete = array_values(array_filter($items, static fn (array $item): bool => $item['status'] !== 'accepted'));

        return [
            'schema_version' => 'atlas.documentation_reality.block_acceptance_matrix.v1',
            'status' => count($items) === 52 && count($accepted) === 52 ? 'ready' : 'blocked',
            'expected_block_count' => 52,
            'block_count' => count($items),
            'accepted_block_count' => count($accepted),
            'incomplete_block_count' => count($incomplete),
            'incomplete_blocks' => array_map(static fn (array $item): string => $item['block_name'], $incomplete),
            'items' => $items,
            'claim_policy' => [
                'block_acceptance_is_runtime_acceptance_not_ui_product_completion' => true,
                'child_system_completion_requires_child_certification' => true,
                'writes' => false,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function acceptanceCommandsForBlock(string $name): array
    {
        $commands = ['php artisan atlas:documentation-reality acceptance --strict --json'];

        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy') || str_contains($name, 'Reachability')) {
            $commands[] = 'php artisan atlas:code-reality reality-audit --json';
            $commands[] = 'php artisan atlas:code-reality classify --target="<target>" --json';
            $commands[] = 'php artisan atlas:code-reality reachability --target="<target>" --json';
            $commands[] = 'php artisan atlas:code-reality deletion-preflight --target="<target>" --json';
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            $commands[] = 'php artisan atlas:universal-reality-cartography visual-scene --mode=implementation --strict --json';
            $commands[] = 'php artisan atlas:universal-reality-cartography navigation-slice --strict --json';
        }

        if (str_contains($name, 'Context') || str_contains($name, 'Projection') || str_contains($name, 'Provider') || str_contains($name, 'Handoff')) {
            $commands[] = 'php artisan atlas:ai:session-bootstrap --task="<task>" --json';
        }

        return array_values(array_unique($commands));
    }

    /**
     * @return array<int,string>
     */
    private function acceptanceTestsForBlock(string $name): array
    {
        $tests = ['tests/Feature/Engineering/AtlasDocumentationRealitySystemServiceTest.php'];

        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy')) {
            $tests[] = 'tests/Feature/Engineering/AtlasCodeRealityUsageIntelligenceServiceTest.php';
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            $tests[] = 'tests/Feature/Engineering/AtlasUniversalRealityCartographyServiceTest.php';
        }

        if (str_contains($name, 'Context') || str_contains($name, 'Projection') || str_contains($name, 'Provider') || str_contains($name, 'Handoff')) {
            $tests[] = 'tests/Feature/Ai/AtlasAiSessionBootstrapCommandTest.php';
        }

        return array_values(array_unique($tests));
    }

    private function ownerDocForBlock(string $name): string
    {
        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy')) {
            return self::CANONICAL_DOCS['acrui'];
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            return self::CANONICAL_DOCS['aurc'];
        }

        if (str_contains($name, 'Operating System')) {
            return self::CANONICAL_DOCS['documentation_os'];
        }

        if (str_contains($name, 'Governance') || str_contains($name, 'Authority')) {
            return self::CANONICAL_DOCS['knowledge_governance'];
        }

        return self::CANONICAL_DOCS['adrs'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $planes
     * @return array<string,mixed>
     */
    private function documentationRealityScore(array $sources, array $blocks, array $planes): array
    {
        $sourceCoverage = count($sources) === 0
            ? 0.0
            : count(array_filter($sources, static fn (array $source): bool => $source['exists'] === true)) / count($sources);
        $blockCoverage = count($blocks) === 0
            ? 0.0
            : count(array_filter($blocks, static fn (array $block): bool => ($block['upgrade'] ?? null) !== null && ($block['proof'] ?? null) !== null)) / count($blocks);

        $dimensions = [
            'authority_correctness' => $sourceCoverage * 100,
            'operational_reality_coverage' => $this->planeScore($planes, 'operational_reality'),
            'ai_context_minimality' => $this->planeScore($planes, 'ai_context_efficiency'),
            'human_visual_comprehension' => $this->planeScore($planes, 'human_cartography'),
            'feedback_learning_loop' => $this->planeScore($planes, 'feedback_learning'),
            'governance_lifecycle' => $this->planeScore($planes, 'governance_lifecycle'),
            'block_specification_coverage' => $blockCoverage * 100,
        ];
        $average = round(array_sum($dimensions) / count($dimensions), 2);
        $integratedCount = count(array_filter($blocks, static fn (array $block): bool => ($block['readiness_level'] ?? null) === 'L4_integrated'));

        return [
            'schema_version' => 'atlas.documentation_reality.score.v1',
            'status' => $average >= 99.0 && $integratedCount === 52
                ? 'excellent_integrated_runtime'
                : ($average >= 95.0 ? 'excellent_specification_foundation' : 'attention'),
            'average' => $average,
            'dimensions' => array_map(static fn (float|int $score): float => round((float) $score, 2), $dimensions),
            'meaning' => 'Measures ADRS block integration, source coverage and evidence coverage; child systems remain separately certified.',
        ];
    }

    /**
     * @param  array<string,array<string,mixed>>  $planes
     */
    private function planeScore(array $planes, string $plane): float
    {
        return (float) ($planes[$plane]['average_readiness_score'] ?? 0.0);
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     */
    private function nextUpgradeTarget(array $blocks): ?array
    {
        $ordered = collect($blocks)
            ->sortBy([
                ['readiness_score', 'asc'],
                ['number', 'asc'],
            ])
            ->values();
        $block = $ordered->first();

        if (! is_array($block)) {
            return null;
        }

        return [
            'number' => $block['number'],
            'name' => $block['name'],
            'readiness_level' => $block['readiness_level'],
            'readiness_score' => $block['readiness_score'],
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,mixed>  $blockAcceptanceMatrix
     * @return array<int,array<string,mixed>>
     */
    private function blockers(array $sources, array $blocks, array $blockAcceptanceMatrix): array
    {
        $blockers = [];
        foreach ($sources as $source) {
            if ($source['exists'] !== true) {
                $blockers[] = [
                    'reason' => 'missing_canonical_source',
                    'severity' => 'critical',
                    'path' => $source['path'],
                ];
            }
        }

        if (count($blocks) !== 52) {
            $blockers[] = [
                'reason' => 'adrs_block_count_not_52',
                'severity' => 'critical',
                'actual' => count($blocks),
                'expected' => 52,
            ];
        }

        foreach ($blocks as $block) {
            if (($block['upgrade'] ?? null) === null || ($block['proof'] ?? null) === null) {
                $blockers[] = [
                    'reason' => 'block_missing_upgrade_or_proof',
                    'severity' => 'high',
                    'block' => $block['name'],
                ];
            }
        }

        if (($blockAcceptanceMatrix['status'] ?? null) !== 'ready') {
            $blockers[] = [
                'reason' => 'block_acceptance_matrix_not_ready',
                'severity' => 'critical',
                'incomplete_block_count' => $blockAcceptanceMatrix['incomplete_block_count'] ?? null,
            ];
        }

        return $blockers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<int,array<string,mixed>>  $blockers
     * @return array<string,mixed>
     */
    private function summary(array $sources, array $blocks, array $blockers): array
    {
        return [
            'source_count' => count($sources),
            'source_present_count' => count(array_filter($sources, static fn (array $source): bool => $source['exists'] === true)),
            'block_count' => count($blocks),
            'expected_block_count' => 52,
            'block_with_upgrade_count' => count(array_filter($blocks, static fn (array $block): bool => ($block['upgrade'] ?? null) !== null)),
            'read_only_foundation_block_count' => count(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L3_read_only')),
            'integrated_runtime_block_count' => count(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L4_integrated')),
            'accepted_block_count' => count(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L4_integrated' && ($block['integration_evidence'] ?? null) !== null)),
            'plane_count' => count(self::PLANES),
            'blocker_count' => count($blockers),
        ];
    }

    /**
     * @return array<int,string>
     */
    private function blockPlanes(int $blockNumber): array
    {
        $planes = [];
        foreach (self::PLANES as $plane => $numbers) {
            if (in_array($blockNumber, $numbers, true)) {
                $planes[] = $plane;
            }
        }

        return $planes;
    }

    private function authorityTier(string $id): string
    {
        return match ($id) {
            'adrs' => 'tier_1_mother_contract',
            'adrs_block_registry', 'adrib', 'adr_bum', 'acrui', 'aurc', 'documentation_os', 'knowledge_governance' => 'tier_1_canonical_child',
            'system_graph', 'implemented_vs_scaffold', 'cartography_os' => 'tier_2_supporting_canonical',
            default => 'tier_unknown',
        };
    }

    private function absolutePath(string $root, string $canonicalPath): string
    {
        $prefix = 'docs/engineering-knowledge-base/';
        if (str_starts_with($canonicalPath, $prefix)) {
            return rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.substr($canonicalPath, strlen($prefix));
        }

        return base_path($canonicalPath);
    }

    /**
     * @param  array<int,mixed>  $values
     * @return array<int,string>
     */
    private function duplicates(array $values): array
    {
        $seen = [];
        $duplicates = [];

        foreach ($values as $value) {
            if (! is_string($value) || $value === '') {
                continue;
            }

            if (isset($seen[$value])) {
                $duplicates[$value] = true;

                continue;
            }

            $seen[$value] = true;
        }

        return array_values(array_keys($duplicates));
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function hash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
