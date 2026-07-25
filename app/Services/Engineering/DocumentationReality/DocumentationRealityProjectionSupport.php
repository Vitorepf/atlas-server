<?php

declare(strict_types=1);

namespace App\Services\Engineering\DocumentationReality;

use App\Services\Engineering\EngineeringStringListNormalizer;

/**
 * Pure residual projection helpers for {@see \App\Services\Engineering\AtlasDocumentationRealitySystemService}.
 *
 * Extracted from the documentation-reality monstruo: evaluation-ref maps, readiness
 * checks/score, planes, readiness/integration/acceptance matrices, acceptance
 * commands/tests/owner docs, blockers, summary, authority tier, duplicates, and
 * certification hash.
 *
 * No I/O, no DI, no Eloquent, no config(), no clock, no filesystem, no provider.
 */
final class DocumentationRealityProjectionSupport
{
    public const EXPECTED_BLOCK_COUNT = 52;

    /**
     * An ADRS block is "integrated runtime" when its backing evaluation actually RAN and
     * produced a non-spec verdict.
     *
     * @var list<string>
     */
    public const INTEGRATED_EVALUATION_STATUSES = ['ready', 'review', 'drift_detected', 'degraded'];

    /**
     * Block name → evaluation key (SSOT for evaluationRef + isIntegrated).
     *
     * @var array<string,string>
     */
    public const EVALUATION_REF_BY_BLOCK_NAME = [
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

    /**
     * @var array<string,array<int,int>>
     */
    public const PLANES = [
        'truth_authority' => [1, 2, 3, 4, 38, 42, 48, 50],
        'operational_reality' => [5, 11, 12, 14, 16, 19, 20, 21, 24, 27, 33, 34, 35, 37, 39, 40, 41, 45, 47, 49, 51, 52],
        'ai_context_efficiency' => [10, 17, 22, 23, 25, 28, 30, 31, 43, 44],
        'human_cartography' => [6, 7, 8, 9, 18, 26, 29, 32, 36, 46],
        'feedback_learning' => [15, 26, 45, 47, 50],
        'governance_lifecycle' => [13, 43, 44, 48, 49, 50],
    ];

    /**
     * Canonical docs used for acceptance owner mapping (subset of host registry).
     *
     * @var array<string,string>
     */
    public const OWNER_DOC_PATHS = [
        'adrs' => 'docs/engineering-knowledge-base/atlas-documentation-reality-system.md',
        'acrui' => 'docs/engineering-knowledge-base/atlas-code-reality-usage-intelligence.md',
        'aurc' => 'docs/engineering-knowledge-base/atlas-universal-reality-cartography.md',
        'documentation_os' => 'docs/engineering-knowledge-base/atlas-ai-documentation-operating-system.md',
        'knowledge_governance' => 'docs/engineering-knowledge-base/atlas-ai-knowledge-governance-system.md',
    ];

    private function __construct()
    {
    }

    public static function evaluationRefForBlock(string $name): ?string
    {
        return self::EVALUATION_REF_BY_BLOCK_NAME[$name] ?? null;
    }

    /**
     * @param  array<string,array<string,mixed>>  $evaluations
     * @param  list<string>  $executingKeys
     * @param  list<string>  $partialKeys
     * @param  list<string>  $integratedStatuses
     */
    public static function isIntegratedRuntimeBlock(
        string $name,
        array $evaluations,
        array $executingKeys,
        array $partialKeys,
        array $integratedStatuses = self::INTEGRATED_EVALUATION_STATUSES,
    ): bool {
        $key = self::evaluationRefForBlock($name);
        if ($key === null || DocumentationRealityClassifySupport::executionFor($key, $executingKeys, $partialKeys) !== 'executes') {
            return false;
        }

        $evaluation = $evaluations[$key] ?? null;
        $status = is_array($evaluation) ? ($evaluation['status'] ?? null) : null;

        // 'spec' must NEVER enter the integrated set.
        return $status !== 'spec' && in_array($status, $integratedStatuses, true);
    }

    /**
     * @param  array<string,mixed>  $block
     * @param  array<string,string>|null  $upgrade
     * @return array<string,bool>
     */
    public static function readinessChecks(array $block, ?array $upgrade): array
    {
        return [
            'adrs_defined' => ($block['name'] ?? '') !== '',
            'function_defined' => ($block['function'] ?? '') !== '',
            'output_defined' => ($block['output'] ?? '') !== '',
            'plane_mapped' => self::blockPlanes((int) ($block['number'] ?? 0)) !== [],
            'upgrade_defined' => is_array($upgrade) && trim((string) ($upgrade['upgrade'] ?? '')) !== '',
            'proof_defined' => is_array($upgrade) && trim((string) ($upgrade['proof'] ?? '')) !== '',
        ];
    }

    /**
     * @param  array<string,bool>  $checks
     */
    public static function readinessScore(array $checks, bool $integrated): int
    {
        $passed = count(array_filter($checks));
        $base = (int) floor(($passed / max(count($checks), 1)) * 90);

        return min(100, $base + ($integrated ? 10 : 0));
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<int,int>>|null  $planes
     * @return array<string,array<string,mixed>>
     */
    public static function planes(array $blocks, ?array $planes = null): array
    {
        $planes ??= self::PLANES;
        $byNumber = [];
        foreach ($blocks as $block) {
            if (isset($block['number'])) {
                $byNumber[(int) $block['number']] = $block;
            }
        }

        $out = [];
        foreach ($planes as $plane => $numbers) {
            $planeBlocks = [];
            foreach ($numbers as $number) {
                if (isset($byNumber[$number])) {
                    $planeBlocks[] = $byNumber[$number];
                }
            }

            $out[$plane] = [
                'id' => $plane,
                'block_count' => count($planeBlocks),
                'average_readiness_score' => $planeBlocks === []
                    ? 0
                    : round(array_sum(array_column($planeBlocks, 'readiness_score')) / count($planeBlocks), 2),
                'blocks' => array_map(static fn (array $block): string => (string) $block['name'], $planeBlocks),
            ];
        }

        return $out;
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,mixed>|null  $nextUpgradeTarget  pure block ref from evaluations section
     * @return array<string,mixed>
     */
    public static function readinessMatrix(array $blocks, ?array $nextUpgradeTarget): array
    {
        $byLevel = [];
        foreach ($blocks as $block) {
            $level = (string) ($block['readiness_level'] ?? '');
            $byLevel[$level] = ($byLevel[$level] ?? 0) + 1;
        }

        $insufficient = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['evidence_sufficiency'] ?? null) !== 'sufficient_for_specification',
        ));

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
            'insufficient_blocks' => array_map(static fn (array $block): string => (string) $block['name'], $insufficient),
            'next_upgrade_target' => $nextUpgradeTarget,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<string,mixed>
     */
    public static function integrationSummary(array $blocks, array $evaluations): array
    {
        $integrated = array_values(array_filter($blocks, static fn (array $block): bool => ($block['readiness_level'] ?? null) === 'L4_integrated'));
        $partial = array_values(array_filter($blocks, static fn (array $block): bool => ($block['readiness_level'] ?? null) === 'L3_read_only'));
        $declaredSpec = array_values(array_filter($blocks, static fn (array $block): bool => ($block['execution'] ?? null) === 'declared'));
        $missingEvaluationRefs = array_values(array_filter($blocks, static fn (array $block): bool => ($block['evaluation_ref'] ?? null) === null));
        $danglingEvaluationRefs = array_values(array_filter($blocks, static function (array $block) use ($evaluations): bool {
            $ref = $block['evaluation_ref'] ?? null;

            return is_string($ref) && ! array_key_exists($ref, $evaluations);
        }));

        $integrationLiars = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['readiness_level'] ?? null) === 'L4_integrated' && ($block['execution'] ?? null) !== 'executes',
        ));

        return [
            'schema_version' => 'atlas.documentation_reality.integration_summary.v1',
            'status' => count($blocks) === self::EXPECTED_BLOCK_COUNT
                && $missingEvaluationRefs === []
                && $danglingEvaluationRefs === []
                && $integrationLiars === []
                ? 'ready'
                : 'blocked',
            'integrated_block_count' => count($integrated),
            'partial_runtime_block_count' => count($partial),
            'declared_spec_block_count' => count($declaredSpec),
            'expected_block_count' => self::EXPECTED_BLOCK_COUNT,
            'evaluation_count' => count($evaluations),
            'missing_evaluation_ref_count' => count($missingEvaluationRefs),
            'dangling_evaluation_ref_count' => count($danglingEvaluationRefs),
            'runtime_contract' => 'executing_blocks_are_materialized_by_real_signal;partial_blocks_run_a_narrow_real_check;declared_blocks_are_honest_specs_not_yet_runtime',
            'child_system_completion_claim' => 'not_claimed',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $evaluations
     * @param  list<string>  $executingKeys
     * @param  list<string>  $partialKeys
     * @param  list<string>  $integratedStatuses
     * @return array<string,mixed>
     */
    public static function blockAcceptanceMatrix(
        array $blocks,
        array $evaluations,
        array $executingKeys,
        array $partialKeys,
        array $integratedStatuses = self::INTEGRATED_EVALUATION_STATUSES,
    ): array {
        $items = array_map(function (array $block) use ($evaluations, $executingKeys, $partialKeys, $integratedStatuses): array {
            $evaluationRef = $block['evaluation_ref'] ?? null;
            $evaluation = is_string($evaluationRef) ? ($evaluations[$evaluationRef] ?? null) : null;
            $commands = self::acceptanceCommandsForBlock((string) $block['name']);
            $tests = self::acceptanceTestsForBlock((string) $block['name']);
            $execution = DocumentationRealityClassifySupport::executionFor(
                is_string($evaluationRef) ? $evaluationRef : null,
                $executingKeys,
                $partialKeys,
            );
            $status = is_array($evaluation) ? ($evaluation['status'] ?? null) : null;

            if ($execution === 'executes'
                && ($block['readiness_level'] ?? null) === 'L4_integrated'
                && in_array($status, $integratedStatuses, true)
                && $commands !== []
                && $tests !== []
                && ($block['integration_evidence'] ?? null) !== null) {
                $acceptance = 'accepted';
            } elseif ($execution === 'declared' && $status === 'spec') {
                $acceptance = 'declared';
            } elseif ($execution === 'partial') {
                $acceptance = 'partial_runtime';
            } else {
                $acceptance = 'incomplete';
            }

            return [
                'block_number' => $block['number'],
                'block_name' => $block['name'],
                'status' => $acceptance,
                'execution' => $execution,
                'readiness_level' => $block['readiness_level'],
                'evaluation_ref' => $evaluationRef,
                'evaluation_status' => $status ?? 'missing',
                'owner_doc' => self::ownerDocForBlock((string) $block['name']),
                'required_commands' => $commands,
                'required_tests' => $tests,
                'quality_floor' => [
                    'must_be_read_only' => true,
                    'must_have_source_refs' => true,
                    'must_have_evidence_refs' => true,
                    'must_not_claim_child_product_complete_without_child_cert' => true,
                    'must_fail_closed_when_evidence_missing' => true,
                ],
                'acceptance_policy' => 'accepted_only_when_executes_block_integrates_declared_specs_are_declared_partials_are_partial_runtime',
            ];
        }, $blocks);

        $accepted = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'accepted'));
        $declared = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'declared'));
        $partialRuntime = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'partial_runtime'));
        $incomplete = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'incomplete'));

        return [
            'schema_version' => 'atlas.documentation_reality.block_acceptance_matrix.v1',
            'status' => count($items) === self::EXPECTED_BLOCK_COUNT && count($incomplete) === 0 ? 'ready' : 'blocked',
            'expected_block_count' => self::EXPECTED_BLOCK_COUNT,
            'block_count' => count($items),
            'accepted_block_count' => count($accepted),
            'declared_block_count' => count($declared),
            'partial_runtime_block_count' => count($partialRuntime),
            'incomplete_block_count' => count($incomplete),
            'incomplete_blocks' => array_map(static fn (array $item): string => (string) $item['block_name'], $incomplete),
            'items' => $items,
            'claim_policy' => [
                'block_acceptance_is_runtime_acceptance_not_ui_product_completion' => true,
                'child_system_completion_requires_child_certification' => true,
                'writes' => false,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function acceptanceCommandsForBlock(string $name): array
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

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($commands);
    }

    /**
     * @return list<string>
     */
    public static function acceptanceTestsForBlock(string $name): array
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

        return EngineeringStringListNormalizer::uniqueNonEmptyStrings($tests);
    }

    public static function ownerDocForBlock(string $name): string
    {
        if (str_contains($name, 'ACRUI') || str_contains($name, 'Code') || str_contains($name, 'Duplicate') || str_contains($name, 'Legacy')) {
            return self::OWNER_DOC_PATHS['acrui'];
        }

        if (str_contains($name, 'AURC') || str_contains($name, 'Visual') || str_contains($name, 'Cartography') || str_contains($name, 'Human') || str_contains($name, 'Zoom')) {
            return self::OWNER_DOC_PATHS['aurc'];
        }

        if (str_contains($name, 'Operating System')) {
            return self::OWNER_DOC_PATHS['documentation_os'];
        }

        if (str_contains($name, 'Governance') || str_contains($name, 'Authority')) {
            return self::OWNER_DOC_PATHS['knowledge_governance'];
        }

        return self::OWNER_DOC_PATHS['adrs'];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,mixed>  $blockAcceptanceMatrix
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return list<array<string,mixed>>
     */
    public static function blockers(array $sources, array $blocks, array $blockAcceptanceMatrix, array $evaluations = []): array
    {
        $blockers = [];
        foreach ($sources as $source) {
            if (($source['exists'] ?? null) !== true) {
                $blockers[] = [
                    'reason' => 'missing_canonical_source',
                    'severity' => 'critical',
                    'path' => $source['path'],
                ];
            }
        }

        if (count($blocks) !== self::EXPECTED_BLOCK_COUNT) {
            $blockers[] = [
                'reason' => 'adrs_block_count_not_52',
                'severity' => 'critical',
                'actual' => count($blocks),
                'expected' => self::EXPECTED_BLOCK_COUNT,
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

        $driftGuard = $evaluations['drift_duplication_guard'] ?? null;
        if (is_array($driftGuard) && ($driftGuard['status'] ?? null) === 'drift_detected') {
            $blockers[] = [
                'reason' => 'documentation_reality_drift_detected',
                'severity' => 'high',
                'drift_count' => (int) ($driftGuard['drift_count'] ?? 0),
                'over_claim_drift_count' => (int) ($driftGuard['over_claim_drift_count'] ?? 0),
                'claimed_fact_drift_count' => (int) ($driftGuard['claimed_fact_drift_count'] ?? 0),
            ];
        }

        return $blockers;
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<int,array<string,mixed>>  $blockers
     * @param  array<string,array<int,int>>|null  $planes
     * @return array<string,mixed>
     */
    public static function summary(array $sources, array $blocks, array $blockers, ?array $planes = null): array
    {
        $planes ??= self::PLANES;

        $executingCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'executes',
        ));
        $partialCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'partial',
        ));
        $declaredSpecCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'declared',
        ));

        $integratedCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'executes' && ($block['readiness_level'] ?? null) === 'L4_integrated',
        ));

        $honestlyReportedCount = count(array_filter($blocks, static function (array $block): bool {
            $execution = $block['execution'] ?? null;
            if ($execution === 'partial' || $execution === 'declared') {
                return true;
            }

            return ($block['readiness_level'] ?? null) === 'L4_integrated';
        }));

        $blockCount = count($blocks);
        if ($blockCount === self::EXPECTED_BLOCK_COUNT && $executingCount + $partialCount + $declaredSpecCount !== self::EXPECTED_BLOCK_COUNT) {
            throw new \LogicException(sprintf(
                'documentation_reality summary invariant violated: executing(%d)+partial(%d)+declared_spec(%d) must equal 52, got %d.',
                $executingCount,
                $partialCount,
                $declaredSpecCount,
                $executingCount + $partialCount + $declaredSpecCount,
            ));
        }

        return [
            'source_count' => count($sources),
            'source_present_count' => count(array_filter($sources, static fn (array $source): bool => ($source['exists'] ?? null) === true)),
            'block_count' => $blockCount,
            'expected_block_count' => self::EXPECTED_BLOCK_COUNT,
            'block_with_upgrade_count' => count(array_filter($blocks, static fn (array $block): bool => ($block['upgrade'] ?? null) !== null)),
            'read_only_foundation_block_count' => $partialCount,
            'integrated_runtime_block_count' => $integratedCount,
            'executing_block_count' => $executingCount,
            'partial_runtime_block_count' => $partialCount,
            'declared_spec_block_count' => $declaredSpecCount,
            'honestly_classified_block_count' => $honestlyReportedCount,
            'plane_count' => count($planes),
            'blocker_count' => count($blockers),
        ];
    }

    /**
     * @param  array<string,array<int,int>>|null  $planes
     * @return list<string>
     */
    public static function blockPlanes(int $blockNumber, ?array $planes = null): array
    {
        $planes ??= self::PLANES;
        $matched = [];
        foreach ($planes as $plane => $numbers) {
            if (in_array($blockNumber, $numbers, true)) {
                $matched[] = $plane;
            }
        }

        return $matched;
    }

    public static function authorityTier(string $id): string
    {
        return match ($id) {
            'adrs' => 'tier_1_mother_contract',
            'adrs_block_registry', 'adrib', 'adr_bum', 'acrui', 'aurc', 'documentation_os', 'knowledge_governance' => 'tier_1_canonical_child',
            'system_graph', 'implemented_vs_scaffold', 'cartography_os' => 'tier_2_supporting_canonical',
            default => 'tier_unknown',
        };
    }

    /**
     * @param  array<int,mixed>  $values
     * @return list<string>
     */
    public static function duplicates(array $values): array
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
    public static function hash(array $payload): string
    {
        unset($payload['generated_at'], $payload['certification_hash']);

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
