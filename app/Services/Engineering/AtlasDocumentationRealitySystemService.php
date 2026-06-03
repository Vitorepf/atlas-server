<?php

declare(strict_types=1);

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringCodeSymbol;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationEvidenceResolver;
use App\Services\Ai\Aaeos\AtlasAaeosImplementationTruthService;
use App\Services\Semantic\CanonicalDocsFrontmatterParser;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

class AtlasDocumentationRealitySystemService
{
    public const SCHEMA_VERSION = 'atlas.documentation_reality_system.v1';

    /**
     * An ADRS block is "integrated runtime" when its backing evaluation actually RAN and
     * produced a verdict — not only the green 'ready'/'review'. The Drift & Duplication
     * Guard (block #11) is now a real doc-vs-code detector that can honestly emit
     * 'drift_detected' (real divergence found) or 'degraded' (index unavailable, verdict
     * withheld). Both mean the guard is materialized and working, so they keep the block
     * integrated; the drift itself surfaces as a top-level blocker, not as a missing block.
     *
     * @var array<int,string>
     */
    private const INTEGRATED_EVALUATION_STATUSES = ['ready', 'review', 'drift_detected', 'degraded'];

    /**
     * Honest execution tiers. The previous report hardcoded a literal ready status in ~32 of the
     * 52 evaluation nodes and rolled that up into a "52/52 integrated / excellent_integrated_runtime"
     * claim. That was an over-claim: most of those nodes return a constant array describing what the
     * block WOULD do — they do not compute their declared verb from real input. The fix tells the
     * truth: every block is classified into exactly one of three execution tiers below, and its
     * 'status'/'execution' are DERIVED from that classification, never written as a literal inside an
     * *Evaluation() method.
     *
     * EXECUTES — the evaluator computes its declared verb from REAL input (real code index /
     * real source registry / real AAEOS ledger) and DERIVES its status from that result. These 11
     * keys are the only ones eligible for L4_integrated.
     *
     * @var array<int,string>
     */
    private const EXECUTING_EVALUATION_KEYS = [
        'source_freshness_gate',
        'evidence_sufficiency_gate',
        'documentation_lifecycle_state_machine',
        'documentation_operating_system',
        'documentation_budget_governor',
        'retrieval_audit_trail',
        'acrui_operational_reality',
        'drift_duplication_guard',
        'evidence_runtime_proof_bridge',
        'auto_split_planner',
        'aurc_visual_reality',
        // Batch A promotions — each now computes its FULL declared verb from the live
        // cross-source authority audit (EngineeringDocumentationAuthorityAuditService::report)
        // and DERIVES its status from that corpus-wide result (flip-proven: mutating the real
        // corpus flips the verdict). 'authority_kernel' backs BOTH block #1 (Documentation
        // Authority Kernel) and block #2 (Canonical Source Registry) via the alias map, so this
        // single key promotes two blocks.
        'authority_kernel',
        'knowledge_governance_system',
        'contradiction_resolver',
        'vocabulary_alignment_guard',
        'orphaned_decision_finder',
        'semantic_deduplication_engine',
    ];

    /**
     * PARTIAL — the evaluator runs a REAL but narrow/proxy signal over data it actually computes,
     * yet does NOT cover its full declared verb (e.g. owner-presence as a proxy for cross-source
     * authority adjudication; required-source presence as a proxy for a minimal projection; a
     * same-owner overlap histogram rather than true semantic dedup). It runs honestly but is NOT
     * integrated — it lands L3_read_only with a DERIVED status, never a literal. The four
     * formerly-hardcoded-ready-over-real-data methods (semantic_deduplication_engine,
     * reality_diff_engine, documentation_entropy_monitor, canonical_question_router) now DERIVE
     * their status from the data they already compute.
     *
     * @var array<int,string>
     */
    private const PARTIAL_EVALUATION_KEYS = [
        'ai_context_projection',
        'reality_diff_engine',
        'documentation_entropy_monitor',
        'canonical_question_router',
    ];

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
        // C3 keystone — the Drift & Duplication Guard composes the REAL AAEOS drift
        // ledger (over-claim drift) and resolves each canonical source doc's declared
        // evidence_refs against the code index (claimed-but-absent drift). Optional so
        // the existing constructor contract is preserved; resolved lazily from the
        // container when absent (and re-resolved in driftDuplicationEvaluation so a test
        // that swaps the binding after construction is honoured).
        private readonly ?AtlasAaeosImplementationTruthService $aaeosTruth = null,
        private readonly ?AtlasAaeosImplementationEvidenceResolver $evidenceResolver = null,
        // Batch A — the cross-source authority adjudicator. It scans the WHOLE canonical
        // corpus and emits a real conflict verdict (identity/runtime duplicate groups,
        // capability overlaps, owner gaps). Six formerly-partial evaluators now compute their
        // full declared verb from this signal. Light ctor (only the frontmatter parser, already
        // injected here); optional + lazily resolved from the container so the existing ctor
        // contract holds and a test can swap the binding before report() runs.
        private readonly ?EngineeringDocumentationAuthorityAuditService $authorityAudit = null,
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
        $evaluations = $this->evaluations($sourceRegistry, $blockCatalog, $upgradeMap, $root);
        $blocks = $this->blocks($blockCatalog, $upgradeMap, $evaluations);
        $blockAcceptanceMatrix = $this->blockAcceptanceMatrix($blocks, $evaluations);
        $blockers = $this->blockers($sourceRegistry, $blocks, $blockAcceptanceMatrix, $evaluations);
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
                // The retracted over-claim. This is now honestly FALSE: only 11 of the 52 blocks
                // actually execute and integrate. An external reader sees the retraction explicitly,
                // and the honest executes/partial/declared split is reported alongside it.
                'declares_all_52_adrs_blocks_integrated' => false,
                'executing_block_count' => $summary['executing_block_count'],
                'partial_runtime_block_count' => $summary['partial_runtime_block_count'],
                'declared_spec_block_count' => $summary['declared_spec_block_count'],
                'declares_child_systems_complete' => false,
                'scope' => 'documentation_reality_honest_executes_partial_declared_split',
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
            $execution = $this->executionFor($evaluationRef);

            return [
                'number' => $block['number'],
                'name' => $name,
                'planes' => $this->blockPlanes((int) $block['number']),
                'function' => $block['function'],
                'output' => $block['output'],
                'upgrade' => $upgrade['upgrade'] ?? null,
                'proof' => $upgrade['proof'] ?? null,
                // Honest readiness ladder: only an executes block that passes its real check is
                // L4_integrated; a partial block (real-but-narrow signal) is L3_read_only; a declared
                // spec falls to L2_testable (if it has an upgrade) or L1_specified.
                'readiness_level' => $integrated
                    ? 'L4_integrated'
                    : ($execution === 'partial'
                        ? 'L3_read_only'
                        : ($upgrade ? 'L2_testable' : 'L1_specified')),
                'runtime_status' => $integrated
                    ? 'integrated_read_only_runtime'
                    : ($execution === 'partial'
                        ? 'partial_real_signal_not_full_verb'
                        : 'specified_not_runtime_complete'),
                'execution' => $execution,
                'block_status' => $evaluationRef !== null ? ($evaluations[$evaluationRef]['status'] ?? 'missing') : 'missing',
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
    private function evaluations(array $sources, array $catalog, array $upgradeMap, string $root): array
    {
        // ONE corpus-wide authority adjudication, shared by every block whose verb is "resolve
        // authority/conflict across the whole canonical corpus". Scanning the ~700-doc corpus is
        // the expensive part, so it runs exactly once here and the result is threaded into the
        // six executing evaluators below — they DERIVE their status from this real signal.
        $authorityReport = $this->resolveAuthorityAudit()->report($root);

        $evaluations = [
            'authority_kernel' => $this->authorityKernelEvaluation($sources, $authorityReport),
            'source_freshness_gate' => $this->sourceFreshnessEvaluation($sources),
            'evidence_sufficiency_gate' => $this->evidenceSufficiencyEvaluation($catalog, $upgradeMap),
            'contradiction_resolver' => $this->contradictionResolverEvaluation($sources, $authorityReport),
            'implementation_readiness_matrix' => [
                'schema_version' => 'atlas.documentation_reality.implementation_readiness.v1',
                'status' => 'spec',
                'decision' => 'readiness_matrix_emitted',
                'evidence_refs' => [self::CANONICAL_DOCS['adrs'], self::CANONICAL_DOCS['adrib'], self::CANONICAL_DOCS['adr_bum']],
            ],
            'documentation_lifecycle_state_machine' => $this->lifecycleEvaluation($sources),
            'documentation_operating_system' => $this->documentationOperatingSystemEvaluation($sources),
            'knowledge_governance_system' => $this->knowledgeGovernanceEvaluation($sources, $authorityReport),
            'vocabulary_alignment_guard' => $this->vocabularyAlignmentEvaluation($sources, $authorityReport),
            'documentation_budget_governor' => $this->documentationBudgetEvaluation($sources),
            'ai_context_projection' => $this->aiContextProjectionEvaluation($sources),
            'retrieval_audit_trail' => $this->retrievalAuditTrailEvaluation($sources),
            'documentation_compression_tiers' => $this->documentationCompressionTiersEvaluation($sources),
            'provider_misread_defense' => $this->providerMisreadDefenseEvaluation(),
            'privacy_redaction_gate' => $this->privacyRedactionEvaluation($sources),
            'access_policy_resolver' => $this->accessPolicyEvaluation($sources),
            'acrui_operational_reality' => $this->acruiOperationalRealityEvaluation($sources, $catalog),
            'drift_duplication_guard' => $this->driftDuplicationEvaluation($sources, $root),
            'legacy_quarantine_governance' => $this->legacyQuarantineEvaluation(),
            'evidence_runtime_proof_bridge' => $this->evidenceRuntimeProofBridgeEvaluation($sources),
            'semantic_deduplication_engine' => $this->semanticDeduplicationEvaluation($sources, $authorityReport),
            'auto_split_planner' => $this->autoSplitPlannerEvaluation($sources),
            'obsolete_knowledge_simulator' => $this->obsoleteKnowledgeSimulatorEvaluation(),
            'reality_diff_engine' => $this->realityDiffEvaluation($sources),
            'orphaned_decision_finder' => $this->orphanedDecisionEvaluation($sources, $authorityReport),
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

        // CENTRAL HONESTY STAMP. The execution tier is assigned here, never hand-written per method,
        // so a declared stub cannot self-promote to 'executes'/'partial'. For declared keys we ALSO
        // force status to 'spec' — belt-and-suspenders: even if a stub body re-introduced a literal
        // ready value, this overrides it to the honest 'spec'. The 21 executes+partial methods keep their
        // own DERIVED (literal-free) status.
        foreach ($evaluations as $key => &$evaluation) {
            $execution = $this->executionFor($key);
            $evaluation['execution'] = $execution;
            if ($execution === 'declared') {
                $evaluation['status'] = 'spec';
            }
        }
        unset($evaluation);

        return $evaluations;
    }

    /**
     * Block #1 (Documentation Authority Kernel) + block #2 (Canonical Source Registry).
     *
     * FULL VERB: resolve a conflict between docs competing for the same authority slot and
     * return a winner + reason. The real signal is the corpus-wide authority audit: each
     * identity-duplicate (id / graph_id) or runtime-duplicate (technical_runtime) GROUP is a
     * genuine authority collision — >=2 canonical docs claiming the same identity/runtime slot.
     * For each collision we adjudicate the winner by the tier order this kernel owns
     * (registered canonical source tier > any-tier-with-owner > first stable path), breaking
     * ties by status-freshness (active/implemented over scaffold/planned) and owner presence,
     * and emit {winner_path, loser_paths, winning_tier, reason}. Status is DERIVED:
     *   - 'blocked'  when a blocker-grade collision exists (an unadjudicable authority clash);
     *   - 'review'   when the corpus is collision-free but the audit still has weak conflicts
     *                (owner gaps / capability overlaps) or a registered source lacks tier+owner
     *                — a weak winner that needs a human decision;
     *   - 'ready'    only when the audit is clean AND every registered source carries a known
     *                tier + owner.
     * Mutating the real corpus (plant a duplicate graph_id) flips ready/review -> blocked.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function authorityKernelEvaluation(array $sources, array $authorityReport): array
    {
        $tiers = array_values(array_unique(array_column($sources, 'authority_tier')));
        $missingOwners = array_values(array_filter($sources, static fn (array $source): bool => ($source['owner'] ?? 'unknown') === 'unknown'));
        $unknownTierSources = array_values(array_filter($sources, static fn (array $source): bool => ($source['authority_tier'] ?? '') === 'tier_unknown'));

        $conflicts = $this->adjudicateAuthorityConflicts($authorityReport);
        $blockerCount = (int) data_get($authorityReport, 'summary.blocker_count', 0);
        $reviewItemCount = (int) data_get($authorityReport, 'summary.review_item_count', 0);
        $auditStatus = (string) data_get($authorityReport, 'status', 'review');

        // DERIVED status, never a literal: a real collision is unadjudicable -> blocked; a clean
        // corpus with only weak conflicts/owner-gaps (or a registered source missing tier/owner)
        // is a weak winner -> review; ready only when nothing is contested and the ladder is whole.
        if ($conflicts !== [] || $blockerCount > 0) {
            $status = 'blocked';
        } elseif ($auditStatus === 'review' || $reviewItemCount > 0 || $missingOwners !== [] || $unknownTierSources !== []) {
            $status = 'review';
        } else {
            $status = 'ready';
        }

        // Confidence is high only when every adjudicated winner is tier-unique (no co-tier rival)
        // AND the corpus is clean; a contested or weak-winner corpus is at most medium/low.
        $allWinnersTierUnique = $conflicts === [] || array_reduce(
            $conflicts,
            static fn (bool $carry, array $conflict): bool => $carry && ($conflict['winner_tier_unique'] ?? false) === true,
            true,
        );
        $confidence = match (true) {
            $status === 'blocked' => 'low',
            $status === 'ready' && $allWinnersTierUnique => 'high',
            default => 'medium',
        };

        return [
            'schema_version' => 'atlas.documentation_reality.authority_kernel.v1',
            'status' => $status,
            'decision' => 'repo_canonical_docs_win_over_read_models_chat_and_projections',
            'authority_tiers' => $tiers,
            'missing_owner_count' => count($missingOwners),
            'unknown_tier_source_count' => count($unknownTierSources),
            // The adjudicated authority verdict — the real "veredito de autoridade".
            'conflict_count' => count($conflicts),
            'adjudicated_conflicts' => $conflicts,
            'authority_audit_status' => $auditStatus,
            'authority_blocker_count' => $blockerCount,
            'conflict_resolution_order' => [
                'tier_1_mother_contract',
                'tier_1_canonical_child',
                'tier_2_supporting_canonical',
                'read_model',
                'provider_projection',
                'chat_memory',
            ],
            'authority_confidence' => $confidence,
            'confidence' => $confidence,
        ];
    }

    /**
     * Adjudicate every authority-slot collision the corpus audit found. An identity collision
     * (same id / graph_id) or a runtime collision (same technical_runtime) between >=2 canonical
     * docs is a real conflict; we pick the winner by the kernel's tier order and report the rest
     * as losers with a reason. Empty on a clean corpus — the rows only materialize on a real clash.
     *
     * @param  array<string,mixed>  $authorityReport
     * @return array<int,array<string,mixed>>
     */
    private function adjudicateAuthorityConflicts(array $authorityReport): array
    {
        $groups = [];
        foreach ([
            'identity_id' => data_get($authorityReport, 'identity_duplicates.id', []),
            'identity_graph_id' => data_get($authorityReport, 'identity_duplicates.graph_id', []),
            'runtime_technical_runtime' => data_get($authorityReport, 'runtime_duplicates.technical_runtime', []),
        ] as $kind => $groupList) {
            foreach ((array) $groupList as $group) {
                $paths = array_values(array_filter((array) ($group['paths'] ?? [])));
                if (count($paths) < 2) {
                    continue;
                }

                $owners = array_values(array_filter((array) ($group['owners'] ?? [])));
                $ranked = $this->rankAuthorityCandidates($paths);
                $winner = $ranked[0];
                $losers = array_values(array_map(static fn (array $candidate): string => $candidate['path'], array_slice($ranked, 1)));
                $winnerTierUnique = ! collect(array_slice($ranked, 1))
                    ->contains(static fn (array $candidate): bool => $candidate['tier_rank'] === $winner['tier_rank']);

                $groups[] = [
                    'kind' => $kind,
                    'key' => (string) ($group['key'] ?? ''),
                    'winner_path' => $winner['path'],
                    'loser_paths' => $losers,
                    'winning_tier' => $winner['tier'],
                    'winner_tier_unique' => $winnerTierUnique,
                    'owners' => $owners,
                    'reason' => $winnerTierUnique
                        ? 'winner_holds_strictly_higher_authority_tier'
                        : 'co_tier_collision_requires_owner_supersede_decision',
                ];
            }
        }

        return $groups;
    }

    /**
     * Rank candidate doc paths for an authority collision by the kernel's own ladder:
     * a registered canonical source (known tier) outranks an unregistered corpus doc; among
     * registered sources the tier order (mother > child > supporting) decides; a stable path
     * sort breaks remaining ties so the adjudication is deterministic.
     *
     * @param  array<int,string>  $paths
     * @return array<int,array<string,mixed>>
     */
    private function rankAuthorityCandidates(array $paths): array
    {
        $tierRank = [
            'tier_1_mother_contract' => 3,
            'tier_1_canonical_child' => 2,
            'tier_2_supporting_canonical' => 1,
            'tier_unknown' => 0,
        ];

        $registeredByPath = [];
        foreach (self::CANONICAL_DOCS as $id => $path) {
            $registeredByPath[$path] = $this->authorityTier($id);
        }

        $candidates = array_map(function (string $path) use ($tierRank, $registeredByPath): array {
            $tier = $registeredByPath[$path] ?? 'tier_unknown';

            return [
                'path' => $path,
                'tier' => $tier,
                'tier_rank' => $tierRank[$tier] ?? 0,
            ];
        }, array_values($paths));

        usort($candidates, static function (array $a, array $b): int {
            return [$b['tier_rank'], $a['path']] <=> [$a['tier_rank'], $b['path']];
        });

        return $candidates;
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
     * Block #20 (Contradiction Resolver).
     *
     * FULL VERB: detect contradictory canonical docs and demand an owner decision or explicit
     * supersede -> a contradiction queue. The real signal is the corpus audit: two canonical
     * docs asserting the same id / graph_id / technical_runtime (identity contradiction) or the
     * same product_name + runtime_acronym (naming contradiction) are real contradictions. We
     * build the contradiction packet from those groups and DERIVE 'review' (owner decision
     * required) the instant >=1 exists, 'ready' only on a contradiction-free corpus. The old
     * intra-registry duplicate-id/path check is kept as a DEMOTED secondary contributor — by
     * construction the 11 registry keys/paths are unique, so it can essentially never fire; the
     * corpus signal is the load-bearing one (anti-tautology). Planting two docs sharing a
     * technical_runtime flips ready -> review while the old check stays blind.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function contradictionResolverEvaluation(array $sources, array $authorityReport): array
    {
        // DEMOTED secondary signal: intra-registry id/path duplicates (near-tautological).
        $duplicatePaths = collect($sources)
            ->groupBy('path')
            ->filter(static fn ($items): bool => $items->count() > 1)
            ->keys()
            ->values()
            ->all();
        $registryDuplicateIds = count($sources) !== count(array_unique(array_column($sources, 'id')));

        // LOAD-BEARING signal: real corpus contradictions (identity + runtime/naming collisions).
        $packet = array_merge(
            $this->contradictionRows('identity_id', data_get($authorityReport, 'identity_duplicates.id', [])),
            $this->contradictionRows('identity_graph_id', data_get($authorityReport, 'identity_duplicates.graph_id', [])),
            $this->contradictionRows('runtime_technical_runtime', data_get($authorityReport, 'runtime_duplicates.technical_runtime', [])),
            $this->contradictionRows('runtime_product_acronym', data_get($authorityReport, 'runtime_duplicates.product_acronym', [])),
        );

        return [
            'schema_version' => 'atlas.documentation_reality.contradiction_resolver.v1',
            'status' => $packet !== [] ? 'review' : 'ready',
            'contradiction_count' => count($packet),
            'contradiction_packet' => $packet,
            // Secondary (demoted) registry signal, surfaced but never the verdict on its own.
            'registry_duplicate_ids' => $registryDuplicateIds,
            'registry_duplicate_paths' => $duplicatePaths,
            'resolution_policy' => 'owner_decision_required_for_real_contradictions',
        ];
    }

    /**
     * Turn audit duplicate GROUPS into contradiction-queue rows demanding an owner decision.
     *
     * @return array<int,array<string,mixed>>
     */
    private function contradictionRows(string $kind, mixed $groups): array
    {
        return array_values(array_map(static fn (array $group): array => [
            'kind' => $kind,
            'key' => (string) ($group['key'] ?? ''),
            'conflicting_paths' => array_values(array_filter((array) ($group['paths'] ?? []))),
            'owners' => array_values(array_filter((array) ($group['owners'] ?? []))),
            'required_action' => 'declare_primary_owner_or_supersede',
        ], array_values(array_filter((array) $groups, static fn (array $group): bool => count(array_filter((array) ($group['paths'] ?? []))) >= 2))));
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
     * Block #4 (Knowledge Governance System).
     *
     * FULL VERB: define authority across repo docs, KB, Code Intelligence, Ledger, Obsidian and
     * projections -> a tested truth hierarchy / conflict matrix. The verdict is the CONJUNCTION
     * of two real signals:
     *   (a) the tier-ladder invariant this method already owns (mother = tier_1_mother_contract,
     *       zero tier_unknown registered sources) — proves the hierarchy is well-formed; and
     *   (b) the corpus-wide authority audit status — proves no two sources illegitimately claim
     *       the same authority slot (a duplicate id / graph_id / technical_runtime collision).
     * DERIVED status: 'blocked' when the ladder breaks OR the audit finds a real collision;
     * 'review' when the ladder holds but the audit only has weak review_items; 'ready' only when
     * the ladder holds AND the audit is clean. conflict_matrix_status + blocker_count make the
     * "matriz de conflito testada" proof real. Two plants flip it: break the mother tier ->
     * blocked (ladder branch), or add a duplicate-graph_id doc -> blocked (audit branch).
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function knowledgeGovernanceEvaluation(array $sources, array $authorityReport): array
    {
        $unknownTiers = array_values(array_filter($sources, static fn (array $source): bool => ($source['authority_tier'] ?? '') === 'tier_unknown'));
        $mother = collect($sources)->firstWhere('id', 'adrs');
        $ladderHolds = $unknownTiers === [] && ($mother['authority_tier'] ?? null) === 'tier_1_mother_contract';

        $auditStatus = (string) data_get($authorityReport, 'status', 'review');
        $blockerCount = (int) data_get($authorityReport, 'summary.blocker_count', 0);
        $reviewItemCount = (int) data_get($authorityReport, 'summary.review_item_count', 0);
        $auditCollision = $blockerCount > 0; // duplicate id/graph_id/technical_runtime among canonical docs

        // DERIVED: a broken ladder or a real corpus collision is unresolved authority -> blocked;
        // a whole ladder with only weak audit review_items is a human-decision -> review; ready
        // requires both the ladder AND a clean audit.
        if (! $ladderHolds || $auditCollision) {
            $status = 'blocked';
        } elseif ($auditStatus !== 'ready' || $reviewItemCount > 0) {
            $status = 'review';
        } else {
            $status = 'ready';
        }

        return [
            'schema_version' => 'atlas.documentation_reality.knowledge_governance.v1',
            'status' => $status,
            'unknown_tier_count' => count($unknownTiers),
            'mother_contract' => $mother['path'] ?? null,
            'mother_contract_tier' => $mother['authority_tier'] ?? null,
            'tier_ladder_holds' => $ladderHolds,
            // The tested conflict matrix — real corpus authority-collision signal.
            'conflict_matrix_status' => $auditCollision ? 'authority_collision' : ($reviewItemCount > 0 ? 'weak_conflicts_present' : 'clean'),
            'blocker_count' => $blockerCount,
            'rule' => 'adrs_mother_contract_governs_children_and_supporting_canonicals',
        ];
    }

    /**
     * Block #42 (Vocabulary Alignment Guard).
     *
     * FULL VERB: guarantee humans, AI, docs and Cartography speak the same names -> a single
     * language; detect dangerous synonyms / conflicting names before they reach doc/code ->
     * glossary diff + rename proposal. The real signal is the corpus audit's runtime_duplicates:
     * a product_acronym group or technical_runtime group with >=2 distinct docs is a real
     * vocabulary collision — the same runtime spoken of under colliding/duplicated canonical
     * names. We emit conflicting_name rows {name_key, paths, titles} as the glossary diff and a
     * rename_required action as the rename proposal, keeping the canonical_terms allow-list this
     * guard already declares. DERIVED 'review' when >=1 name collision exists, 'ready' when none.
     * Planting two docs with the same product_name + runtime_acronym flips ready -> review; the
     * old duplicate-id/path proxy stays blind (anti-stub divergence).
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function vocabularyAlignmentEvaluation(array $sources, array $authorityReport): array
    {
        $ids = array_column($sources, 'id');
        $paths = array_column($sources, 'path');

        // LOAD-BEARING signal: real conflicting-name detections across the corpus.
        $nameConflicts = array_merge(
            $this->nameConflictRows('product_acronym', data_get($authorityReport, 'runtime_duplicates.product_acronym', [])),
            $this->nameConflictRows('technical_runtime', data_get($authorityReport, 'runtime_duplicates.technical_runtime', [])),
        );

        return [
            'schema_version' => 'atlas.documentation_reality.vocabulary_alignment.v1',
            'status' => $nameConflicts !== [] ? 'review' : 'ready',
            'name_conflict_count' => count($nameConflicts),
            // glossary diff (the conflicting names) + rename proposal (the action) — the real verb.
            'glossary_diff' => $nameConflicts,
            // Demoted secondary registry signal (near-tautological, never the verdict alone).
            'duplicate_source_ids' => array_values(array_diff_assoc($ids, array_unique($ids))),
            'duplicate_source_paths' => array_values(array_diff_assoc($paths, array_unique($paths))),
            'canonical_terms' => ['ADRS', 'ADRIB', 'ADR-BUM', 'ACRUI', 'AURC'],
        ];
    }

    /**
     * Turn audit naming-collision GROUPS into glossary-diff rows with a rename proposal.
     *
     * @return array<int,array<string,mixed>>
     */
    private function nameConflictRows(string $nameKind, mixed $groups): array
    {
        return array_values(array_map(static fn (array $group): array => [
            'name_kind' => $nameKind,
            'name_key' => (string) ($group['key'] ?? ''),
            'paths' => array_values(array_filter((array) ($group['paths'] ?? []))),
            'titles' => array_values(array_filter((array) ($group['titles'] ?? []))),
            'required_action' => 'rename_or_supersede_conflicting_canonical_name',
        ], array_values(array_filter((array) $groups, static fn (array $group): bool => count(array_filter((array) ($group['paths'] ?? []))) >= 2))));
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
     * Block #11 — Drift & Duplication Guard. The mother-doc verb is "detecta divergencia
     * entre doc, codigo, routes, commands, tests e Cartografia -> blockers de drift".
     *
     * The old body was tautologically empty: it only checked for duplicate source ids /
     * paths, but ids are the CANONICAL_DOCS array keys (unique by construction) and paths
     * are literal constants — so a duplicate could NEVER appear and the guard could NEVER
     * fire. This is now a REAL doc-vs-code drift detector that composes two live signals
     * and FIRES on real divergence. It is read-only; it never writes or mutates.
     *
     * PRIMARY signal 1 — OVER-CLAIM DRIFT: compose the AAEOS capability truth ledger
     * (AtlasAaeosImplementationTruthService::ledger). A capability whose doc declares a
     * higher implementation_state than the code index can prove (rank(claimed) >
     * rank(computed)) is real drift. We surface drift_count + the drifting
     * {owner_doc, capability_id, claimed, computed} rows. We DO NOT re-derive drift — the
     * ledger is the single source (B3 made `verified` require a green run, so it is honest).
     *
     * PRIMARY signal 2 — DOC-CLAIMED-FACT DRIFT: for each canonical source doc this system
     * governs, resolve every declared evidence_ref (symbol/command/route) against the code
     * index via AtlasAaeosImplementationEvidenceResolver. A doc claiming a symbol/command/
     * route that does NOT resolve is a claimed-but-absent drift — the doc asserts a code
     * fact reality cannot back. Each unresolved ref becomes a drift row.
     *
     * SECONDARY signal — duplicate ids/paths (the old check) is kept but demoted: it can
     * contribute `review`, never `ready`, and is no longer the only/primary signal.
     *
     * VERDICT: 'ready' ONLY when zero real drift AND no duplicates. Any real drift (either
     * primary signal) => 'drift_detected' with a populated drift list. Duplicates only =>
     * 'review'. Degrade-safe: if the code index is absent/empty the doc-vs-code verdict
     * cannot be trusted (every ref would falsely look unresolved / every claim over-claimed),
     * so the guard reports 'degraded' — never a false 'ready'.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    private function driftDuplicationEvaluation(array $sources, string $root): array
    {
        // SECONDARY signal first (cheap, never trusts the index): the old duplicate check.
        $duplicateIds = $this->duplicates(array_column($sources, 'id'));
        $duplicatePaths = $this->duplicates(array_column($sources, 'path'));
        $hasDuplicates = $duplicateIds !== [] || $duplicatePaths !== [];

        // DEGRADE-SAFE: the two PRIMARY signals both resolve refs against the code
        // intelligence index. A present-but-empty (or absent) index makes every ref look
        // unresolved and every partial/verified claim look over-claimed — so we must NOT
        // emit a corpus-wide "everything drifts" verdict, and equally must NOT emit a false
        // 'ready'. We report 'degraded' and still surface the duplicate (index-free) signal.
        if (! $this->codeIndexHealthy()) {
            return [
                'schema_version' => 'atlas.documentation_reality.drift_duplication_guard.v1',
                'status' => 'degraded',
                'degraded' => true,
                'degraded_reason' => 'code_intelligence_index_empty_or_absent_drift_verdict_withheld',
                'drift_count' => 0,
                'drifts' => [],
                'over_claim_drift_count' => 0,
                'claimed_fact_drift_count' => 0,
                'duplicate_source_ids' => $duplicateIds,
                'duplicate_source_paths' => $duplicatePaths,
                'drift_policy' => 'real_doc_vs_code_drift_blocks_ready_index_health_required_for_a_trustworthy_verdict',
                'writes' => false,
            ];
        }

        // PRIMARY signal 1 — over-claim drift, composed from the AAEOS truth ledger.
        $ledger = $this->aaeosTruthService()->ledger();
        $ledgerRows = (array) ($ledger['capabilities'] ?? []);
        $overClaimDrifts = [];
        foreach ($ledgerRows as $row) {
            if (($row['drift'] ?? false) !== true) {
                continue;
            }
            $overClaimDrifts[] = [
                'kind' => 'over_claim',
                'owner_doc' => (string) ($row['owner_doc'] ?? ''),
                'capability_id' => (string) ($row['capability_id'] ?? ''),
                'claimed' => (string) ($row['claimed_state'] ?? ''),
                'computed' => (string) ($row['computed_state'] ?? ''),
                'detail' => 'doc claims implementation_state the code index cannot prove (over-claim)',
            ];
        }

        // PRIMARY signal 2 — claimed-but-absent fact drift over the governed source docs.
        $claimedFactDrifts = $this->claimedFactDrifts($sources, $root);

        $drifts = array_merge($overClaimDrifts, $claimedFactDrifts);
        $driftCount = count($drifts);

        $status = match (true) {
            $driftCount > 0 => 'drift_detected',
            $hasDuplicates => 'review',
            default => 'ready',
        };

        return [
            'schema_version' => 'atlas.documentation_reality.drift_duplication_guard.v1',
            'status' => $status,
            'degraded' => false,
            // Real doc-vs-code drift — the load-bearing signal. drift_count is surfaced
            // (NOT a tautological empty): live it is the AAEOS ledger's real count (0 today
            // over the live corpus), and it FIRES the instant a real over-claim or a
            // claimed-but-absent fact appears.
            'drift_count' => $driftCount,
            'over_claim_drift_count' => count($overClaimDrifts),
            'claimed_fact_drift_count' => count($claimedFactDrifts),
            'drifts' => $drifts,
            'ledger_evaluated' => (int) data_get($ledger, 'summary.evaluated', 0),
            'ledger_drift_count' => (int) data_get($ledger, 'summary.drift_count', 0),
            // Secondary (demoted) duplicate signal — contributes 'review', never 'ready',
            // and is never the sole/primary signal.
            'duplicate_source_ids' => $duplicateIds,
            'duplicate_source_paths' => $duplicatePaths,
            'drift_policy' => 'real_doc_vs_code_drift_blocks_ready_duplicates_are_a_secondary_review_signal_not_auto_fix',
            'writes' => false,
        ];
    }

    /**
     * Signal 2 — for each canonical source doc this system governs, resolve every declared
     * evidence_ref of kind symbol/command/route against the code intelligence index. A ref
     * that does NOT resolve is a claimed-but-absent drift: the doc asserts a code fact the
     * index cannot back. Returns one drift row per unresolved ref. Tests/receipts are
     * intentionally excluded here — they carry their own green-run/existence semantics in
     * the AAEOS layer (signal 1) and an existence-only test match is not a "claimed fact"
     * in the same sense as a named symbol/command/route.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<int,array<string,mixed>>
     */
    private function claimedFactDrifts(array $sources, string $root): array
    {
        $resolver = $this->resolver();
        $checkedKinds = ['symbol', 'command', 'route'];
        $drifts = [];

        foreach ($sources as $source) {
            if (($source['exists'] ?? false) !== true) {
                continue; // missing source is its own blocker; not a claimed-fact drift.
            }
            $path = (string) ($source['path'] ?? '');
            $absolute = $this->absolutePath($root, $path);
            if (! File::exists($absolute)) {
                continue;
            }
            $parsed = $this->frontmatter->parse(File::get($absolute));
            $frontmatter = is_array($parsed['frontmatter'] ?? null) ? $parsed['frontmatter'] : [];
            $refs = $this->declaredEvidenceRefs($frontmatter['evidence_refs'] ?? null);

            foreach ($refs as $ref) {
                $kind = strtolower(trim($ref['kind']));
                if (! in_array($kind, $checkedKinds, true)) {
                    continue;
                }
                $resolution = $resolver->resolve($kind, $ref['ref']);
                if (($resolution['resolved'] ?? false) === true) {
                    continue;
                }
                $drifts[] = [
                    'kind' => 'claimed_fact_absent',
                    'owner_doc' => $path,
                    'capability_id' => (string) ($source['id'] ?? ''),
                    'ref_kind' => $kind,
                    'ref' => $ref['ref'],
                    'detail' => "doc declares {$kind} '{$ref['ref']}' which does not resolve in the code index (claimed-but-absent)",
                ];
            }
        }

        return $drifts;
    }

    /**
     * Normalize a doc's raw frontmatter evidence_refs into a {kind, ref} list. Accepts the
     * two authored shapes (a "kind: ref" string, or a {kind, ref} map), mirroring the AAEOS
     * truth service's own normalization so the two signals read the SAME declarations.
     *
     * @return array<int,array{kind:string, ref:string}>
     */
    private function declaredEvidenceRefs(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $refs = [];
        foreach ($raw as $entry) {
            if (is_array($entry)) {
                $kind = trim((string) ($entry['kind'] ?? ''));
                $ref = trim((string) ($entry['ref'] ?? ''));
            } elseif (is_string($entry) && str_contains($entry, ':')) {
                [$kind, $ref] = array_map('trim', explode(':', $entry, 2));
            } else {
                continue;
            }
            if ($kind !== '' && $ref !== '') {
                $refs[] = ['kind' => $kind, 'ref' => $ref];
            }
        }

        return $refs;
    }

    /**
     * The code intelligence index is "healthy enough to trust a doc-vs-code drift verdict"
     * when its symbol table holds >=1 active row. An ABSENT table is a non-production/test
     * context where no doc-vs-code claim can be evaluated at all, so the guard reports
     * degraded; a PRESENT-but-EMPTY table is the dangerous blind index (every ref would look
     * unresolved) and must also degrade — never a false 'ready'. Mirrors the repair
     * proposer's index-health gate, but treats an absent table as degraded too: this guard's
     * job is to detect divergence, and with no index there is nothing to compare against.
     */
    private function codeIndexHealthy(): bool
    {
        if (! Schema::hasTable('atlas_engineering_code_symbols')) {
            return false;
        }

        return AtlasEngineeringCodeSymbol::query()
            ->where('status', 'active')
            ->whereNull('archived_at')
            ->limit(1)
            ->exists();
    }

    private function aaeosTruthService(): AtlasAaeosImplementationTruthService
    {
        return $this->aaeosTruth ?? App::make(AtlasAaeosImplementationTruthService::class);
    }

    private function resolver(): AtlasAaeosImplementationEvidenceResolver
    {
        return $this->evidenceResolver ?? App::make(AtlasAaeosImplementationEvidenceResolver::class);
    }

    private function resolveAuthorityAudit(): EngineeringDocumentationAuthorityAuditService
    {
        return $this->authorityAudit ?? App::make(EngineeringDocumentationAuthorityAuditService::class);
    }

    /**
     * @return array<string,mixed>
     */
    private function legacyQuarantineEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.legacy_quarantine.v1',
            'status' => 'spec',
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
     * Block #27 (Semantic Deduplication Engine).
     *
     * FULL VERB: detect different docs that say the same thing or fight over the same owner /
     * responsibility -> a merge/supersede plan. The real signal is the corpus audit's
     * capability_overlap_clusters: each cluster (>=2 docs declaring the same capability, with the
     * declared graph-families already excluded) is a real semantic-duplication candidate. Cross-
     * owner clusters demand an owner decision; same-owner clusters get a link-primary suggestion.
     * We emit dedup_candidates {capability, paths, owners, risk, required_action} as the plan and
     * DERIVE 'review' when >=1 overlap cluster exists, 'ready' when none. The same-owner histogram
     * is kept as a DEMOTED secondary stat (it never inspected shared responsibility — the actual
     * dedup signal). Planting two non-family docs declaring the same capability flips ready ->
     * review; the histogram alone does not react (anti-proxy divergence).
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function semanticDeduplicationEvaluation(array $sources, array $authorityReport): array
    {
        // DEMOTED secondary stat: same-owner headcount (never inspects responsibility).
        $ownerGroups = collect($sources)->groupBy('owner')->map(static fn ($items): int => $items->count())->all();
        $maxSameOwner = $ownerGroups === [] ? 0 : max($ownerGroups);

        // LOAD-BEARING signal: shared-capability (same-responsibility) clusters across the corpus.
        $candidates = array_values(array_map(static fn (array $cluster): array => [
            'capability' => (string) ($cluster['capability'] ?? ''),
            'paths' => array_values(array_filter((array) ($cluster['paths'] ?? []))),
            'owners' => array_values(array_filter((array) ($cluster['owners'] ?? []))),
            'risk' => (string) ($cluster['risk'] ?? 'same_owner_overlap'),
            'required_action' => ($cluster['requires_decision'] ?? false) === true
                ? 'cross_owner_overlap_requires_owner_decision'
                : 'same_owner_overlap_link_primary_doc',
        ], array_values(array_filter(
            (array) data_get($authorityReport, 'capability_overlap_clusters', []),
            static fn (array $cluster): bool => count(array_filter((array) ($cluster['paths'] ?? []))) >= 2,
        ))));

        return [
            'schema_version' => 'atlas.documentation_reality.semantic_deduplication.v1',
            'status' => $candidates !== [] ? 'review' : 'ready',
            'dedup_candidate_count' => count($candidates),
            // The real merge/supersede plan.
            'dedup_candidates' => $candidates,
            // Demoted secondary owner histogram, surfaced but never the verdict alone.
            'owner_groups' => $ownerGroups,
            'max_same_owner_count' => $maxSameOwner,
            'decision' => 'shared_capability_overlap_is_review_not_blocker',
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
            'status' => 'spec',
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
        $snapshotHash = hash('sha256', json_encode(array_column($sources, 'content_hash'), JSON_THROW_ON_ERROR));
        // DERIVED (partial signal): a diff needs a prior baseline to compare against. No prior
        // snapshot store is wired, so there is honestly nothing to diff — that is 'review', never a
        // fabricated 'ready'. The instant a prior snapshot is loaded, this derives ready/review by
        // comparing the current fingerprint to it.
        $priorSnapshot = null;

        return [
            'schema_version' => 'atlas.documentation_reality.reality_diff.v1',
            'status' => $priorSnapshot === null ? 'review' : ($snapshotHash === $priorSnapshot ? 'ready' : 'review'),
            'snapshot_hash' => $snapshotHash,
            'prior_snapshot_present' => $priorSnapshot !== null,
            'diff_scope' => ['source_hash', 'status', 'owner', 'authority_tier', 'line_count'],
        ];
    }

    /**
     * Block #41 (Orphaned Decision Finder).
     *
     * FULL VERB: find decisions with no owner, no implementation path, no test or no evidence ->
     * an orphan queue. The real signal is the corpus audit's owner_gaps: per canonical doc, the
     * missing-of {owner, repo_paths (implementation path), evidence, summary}. We build the orphan
     * queue directly from those gaps over the WHOLE canonical corpus (the old proxy only saw the
     * 11 registered sources' owner=='unknown' / empty-path — one orphan dimension over a hand-
     * picked few, ignoring missing implementation-path/evidence entirely). DERIVED 'review' when
     * orphan_count>0, 'ready' when zero. Planting a canonical doc that omits owner+evidence flips
     * it to review and names that path with the missing legs; removing it returns to ready.
     *
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<string,mixed>  $authorityReport
     * @return array<string,mixed>
     */
    private function orphanedDecisionEvaluation(array $sources, array $authorityReport): array
    {
        $queue = array_values(array_map(static fn (array $gap): array => [
            'path' => (string) ($gap['path'] ?? ''),
            'id' => (string) ($gap['id'] ?? ''),
            'missing' => array_values(array_filter((array) ($gap['missing'] ?? []))),
        ], array_values(array_filter(
            (array) data_get($authorityReport, 'owner_gaps', []),
            static fn (array $gap): bool => array_filter((array) ($gap['missing'] ?? [])) !== [],
        ))));

        return [
            'schema_version' => 'atlas.documentation_reality.orphaned_decision_finder.v1',
            'status' => $queue !== [] ? 'review' : 'ready',
            'orphan_count' => count($queue),
            'orphan_queue' => $queue,
            'orphan_dimensions' => ['owner', 'repo_paths', 'evidence', 'summary'],
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
            // DERIVED (partial signal): entropy is healthy only while the average doc stays inside the
            // 520-line budget AND there is at least one identified owner. Either condition failing is a
            // real entropy/dispersion signal worth a review — not a hardcoded green.
            'status' => ($averageLines <= 520 && count($owners) > 0) ? 'ready' : 'review',
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
                // The honest structural cardinality is the COMPLETE auto-derived
                // structure (areas -> subsystems -> leaves from the live code index),
                // not the 23-node curated macro projection. Surface both so the report
                // reads the real ~737/~1430 and the curated entrypoint stays visible
                // for what it is.
                'node_count' => data_get($cartography, 'summary.complete_node_count')
                    ?? data_get($cartography, 'summary.node_count'),
                'edge_count' => data_get($cartography, 'summary.complete_edge_count')
                    ?? data_get($cartography, 'summary.edge_count'),
                'complete_node_count' => data_get($cartography, 'summary.complete_node_count'),
                'complete_edge_count' => data_get($cartography, 'summary.complete_edge_count'),
                'complete_structure_source' => data_get($cartography, 'summary.complete_structure_source'),
                'curated_macro_node_count' => data_get($cartography, 'summary.curated_node_count'),
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
        // DERIVED (partial signal): a question is only routable when owner targets exist to route to.
        // Empty owner targets means the router has nowhere to send a question — that is honestly
        // 'review', not a constant green.
        $routeTargets = array_values(array_unique(array_column($sources, 'owner')));

        return [
            'schema_version' => 'atlas.documentation_reality.canonical_question_router.v1',
            'status' => $routeTargets === [] ? 'review' : 'ready',
            'route_targets' => $routeTargets,
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
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
            'status' => 'spec',
            'example_types' => ['good_owner_doc', 'bad_duplicate_doc', 'good_cartography_node', 'bad_context_pack', 'safe_quarantine_plan'],
            'reuse_policy' => 'examples_are_training_ground_for_docs_cartography_and_provider_projection',
        ];
    }

    /**
     * The single classifier that owns the executes/partial/declared decision. Every roll-up
     * (readiness_level, isIntegratedRuntimeBlock, summary counts, integration_summary, acceptance,
     * score, claim_policy) derives from this — there is no literal execution label hand-written per
     * method, so a declared stub can never self-promote to 'executes'.
     */
    private function executionFor(?string $evaluationKey): string
    {
        if ($evaluationKey === null) {
            return 'declared';
        }

        if (in_array($evaluationKey, self::EXECUTING_EVALUATION_KEYS, true)) {
            return 'executes';
        }

        if (in_array($evaluationKey, self::PARTIAL_EVALUATION_KEYS, true)) {
            return 'partial';
        }

        return 'declared';
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

        $key = $map[$name] ?? null;
        if ($key === null || $this->executionFor($key) !== 'executes') {
            return false;
        }

        $evaluation = $evaluations[$key] ?? null;
        $status = is_array($evaluation) ? ($evaluation['status'] ?? null) : null;

        // 'spec' must NEVER enter the integrated set; a declared block short-circuits above, but
        // belt-and-suspenders: even an executes key whose status was somehow forced to 'spec' is
        // excluded here.
        return $status !== 'spec' && in_array($status, self::INTEGRATED_EVALUATION_STATUSES, true);
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
        $partial = array_values(array_filter($blocks, static fn (array $block): bool => $block['readiness_level'] === 'L3_read_only'));
        $declaredSpec = array_values(array_filter($blocks, static fn (array $block): bool => ($block['execution'] ?? null) === 'declared'));
        $missingEvaluationRefs = array_values(array_filter($blocks, static fn (array $block): bool => ($block['evaluation_ref'] ?? null) === null));
        $danglingEvaluationRefs = array_values(array_filter($blocks, static function (array $block) use ($evaluations): bool {
            $ref = $block['evaluation_ref'] ?? null;

            return is_string($ref) && ! array_key_exists($ref, $evaluations);
        }));

        // A block that claims integration without actually executing is the over-claim we are killing.
        // Honest integration_summary: nobody claims L4_integrated unless they execute, and every ref
        // resolves. Declared specs and partials are NOT integration claims, so they do not block.
        $integrationLiars = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['readiness_level'] ?? null) === 'L4_integrated' && ($block['execution'] ?? null) !== 'executes',
        ));

        return [
            'schema_version' => 'atlas.documentation_reality.integration_summary.v1',
            'status' => count($blocks) === 52 && $missingEvaluationRefs === [] && $danglingEvaluationRefs === [] && $integrationLiars === []
                ? 'ready'
                : 'blocked',
            'integrated_block_count' => count($integrated),
            'partial_runtime_block_count' => count($partial),
            'declared_spec_block_count' => count($declaredSpec),
            'expected_block_count' => 52,
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
     * @return array<string,mixed>
     */
    private function blockAcceptanceMatrix(array $blocks, array $evaluations): array
    {
        $items = array_map(function (array $block) use ($evaluations): array {
            $evaluationRef = $block['evaluation_ref'] ?? null;
            $evaluation = is_string($evaluationRef) ? ($evaluations[$evaluationRef] ?? null) : null;
            $commands = $this->acceptanceCommandsForBlock((string) $block['name']);
            $tests = $this->acceptanceTestsForBlock((string) $block['name']);
            $execution = $this->executionFor($evaluationRef);
            $status = is_array($evaluation) ? ($evaluation['status'] ?? null) : null;

            // DERIVED 4-way acceptance, never a literal. 'accepted' is reserved for an executes block
            // that truly integrated (real check passed) and carries command+test+evidence. A declared
            // spec is honestly 'declared'; a partial block is honestly 'partial_runtime'; an executes
            // block whose real check FAILED is the only genuinely 'incomplete' outcome.
            if ($execution === 'executes'
                && ($block['readiness_level'] ?? null) === 'L4_integrated'
                && in_array($status, self::INTEGRATED_EVALUATION_STATUSES, true)
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
                'acceptance_policy' => 'accepted_only_when_executes_block_integrates_declared_specs_are_declared_partials_are_partial_runtime',
            ];
        }, $blocks);

        $accepted = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'accepted'));
        $declared = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'declared'));
        $partialRuntime = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'partial_runtime'));
        $incomplete = array_values(array_filter($items, static fn (array $item): bool => $item['status'] === 'incomplete'));

        return [
            'schema_version' => 'atlas.documentation_reality.block_acceptance_matrix.v1',
            // Honest gate: zero GENUINELY-incomplete (failed-executes) blocks. Declared specs and
            // partials are honestly reported, not blockers — they do not hold the matrix back.
            'status' => count($items) === 52 && count($incomplete) === 0 ? 'ready' : 'blocked',
            'expected_block_count' => 52,
            'block_count' => count($items),
            'accepted_block_count' => count($accepted),
            'declared_block_count' => count($declared),
            'partial_runtime_block_count' => count($partialRuntime),
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

        // Honest top tier: 'excellent_integrated_runtime' requires that EVERY non-declared block
        // actually executes and integrates — i.e. there are zero partial blocks and zero failed
        // executes. Today 12 blocks are partial (real but narrow), so this tier is honestly NOT
        // reached; the score falls to 'excellent_specification_foundation' on a strong average,
        // backed by real source + spec coverage. We do NOT fudge the average to preserve the old tier.
        $nonDeclaredBlocks = array_values(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) !== 'declared',
        ));
        $everyNonDeclaredExecutes = $nonDeclaredBlocks !== [] && array_reduce(
            $nonDeclaredBlocks,
            static fn (bool $carry, array $block): bool => $carry
                && ($block['execution'] ?? null) === 'executes'
                && ($block['readiness_level'] ?? null) === 'L4_integrated',
            true,
        );

        return [
            'schema_version' => 'atlas.documentation_reality.score.v1',
            'status' => $average >= 99.0 && $everyNonDeclaredExecutes
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
     * @param  array<string,array<string,mixed>>  $evaluations
     * @return array<int,array<string,mixed>>
     */
    private function blockers(array $sources, array $blocks, array $blockAcceptanceMatrix, array $evaluations = []): array
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

        // REAL doc-vs-code drift blocks system readiness honestly. Only 'drift_detected'
        // (a real over-claim or claimed-but-absent fact) is a blocker; 'degraded' (index
        // unavailable, verdict withheld) is NOT — a missing index must not masquerade as
        // confirmed drift, and the guard already reports degraded transparently.
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
     * @return array<string,mixed>
     */
    private function summary(array $sources, array $blocks, array $blockers): array
    {
        // Honest three-way EXECUTION-TIER split, derived from the executes/partial/declared
        // classification. These three tier counts partition all 52 blocks and MUST sum to the block
        // count — anything else means a block landed in an impossible tier, which we surface loudly
        // rather than hide. The tier is independent of pass/fail: a block is 'executes' because it
        // computes its verb, even on a turn where its real check fails.
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

        // INTEGRATION is the honest subset of executes that actually PASSED its real check (L4). When
        // every executes block passes (the live corpus) this equals $executingCount; when an executes
        // block's real signal fails, it drops out of integration but stays in the executes tier — the
        // gap is a failed-executes (surfaced as 'incomplete' in the acceptance matrix), never hidden.
        $integratedCount = count(array_filter(
            $blocks,
            static fn (array $block): bool => ($block['execution'] ?? null) === 'executes' && ($block['readiness_level'] ?? null) === 'L4_integrated',
        ));

        // 'Honestly reported' = every block that tells the truth about itself: an executes block that
        // passes (so it is integrated), a partial block honestly marked partial, or a declared block
        // honestly marked a spec. The ONLY block that is NOT honestly reported is an executes block
        // whose real check FAILED yet which would still be expected to integrate (a failed-executes).
        $honestlyReportedCount = count(array_filter($blocks, function (array $block): bool {
            $execution = $block['execution'] ?? null;
            if ($execution === 'partial' || $execution === 'declared') {
                return true;
            }

            // executes block: honest only when it actually reached integration (its derived status passed).
            return ($block['readiness_level'] ?? null) === 'L4_integrated';
        }));

        $blockCount = count($blocks);
        if ($blockCount === 52 && $executingCount + $partialCount + $declaredSpecCount !== 52) {
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
            'source_present_count' => count(array_filter($sources, static fn (array $source): bool => $source['exists'] === true)),
            'block_count' => $blockCount,
            'expected_block_count' => 52,
            'block_with_upgrade_count' => count(array_filter($blocks, static fn (array $block): bool => ($block['upgrade'] ?? null) !== null)),
            'read_only_foundation_block_count' => $partialCount,
            // Honest headline: only executes-and-passing blocks are integrated runtime (=11 today).
            'integrated_runtime_block_count' => $integratedCount,
            'executing_block_count' => $executingCount,
            'partial_runtime_block_count' => $partialCount,
            'declared_spec_block_count' => $declaredSpecCount,
            // SMELL FIX: renamed from the old 'accepted_block_count' (=52), which could be misread
            // as "52 blocks working". This is the count of blocks that tell the TRUTH about
            // themselves (executes-and-passing OR honestly partial OR honestly declared), surfaced
            // ALONGSIDE the three-way split so it can never hide it. It is NOT a count of working
            // blocks — the working subset is integrated_runtime_block_count. (The real
            // runtime-acceptance count lives at block_acceptance_matrix.accepted_block_count, which
            // stays = the executes-and-passing subset and is deliberately NOT renamed.)
            'honestly_classified_block_count' => $honestlyReportedCount,
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
