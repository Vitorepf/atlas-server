<?php

declare(strict_types=1);

namespace App\Services\Engineering\DocumentationReality;

use App\Services\Engineering\EngineeringStringListNormalizer;

/**
 * Documentation-Reality · self-contained read-model computations.
 *
 * Extracted VERBATIM from AtlasDocumentationRealitySystemService (GOD-DEBULK split) so the
 * 2,894-LOC façade drops under the size ceiling. This section holds the block evaluators and
 * derived scoring that compute PURELY from data the façade already gathered (the source
 * registry, the block catalog, the sibling-evaluation array) — none of them touch an injected
 * service, a class constant, or the container, so they move with zero rewiring and no
 * dependencies. The façade delegates to them via $this->evaluationsSection->...().
 *
 * What deliberately STAYS in the façade (not here):
 *   - authorityKernelEvaluation / driftDuplicationEvaluation / canonicalQuestionRouterEvaluation
 *     (entangled with injected services + lazy resolvers + reflection-pinned by a flip test),
 *   - documentationSloEvaluation / ownerEscalationEvaluation (reflection-pinned to the service
 *     class by AtlasDocumentationRealityRollupBlockFlipTest),
 *   - acruiOperationalRealityEvaluation (needs the injected code-reality service),
 *   - every orchestrator/helper (evaluations(), computeReport(), the classifiers, etc.).
 *
 * Bodies are byte-identical to the originals; the only change is visibility (private -> public)
 * on the methods the façade calls across the object boundary. The intra-section helpers
 * (contradictionRows / nameConflictRows / planeScore) keep their original private visibility.
 */
final class DocumentationRealityEvaluationsSection
{
    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    public function sourceFreshnessEvaluation(array $sources): array
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
    public function evidenceSufficiencyEvaluation(array $catalog, array $upgradeMap): array
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
    public function contradictionResolverEvaluation(array $sources, array $authorityReport): array
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
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    public function lifecycleEvaluation(array $sources): array
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
    public function documentationOperatingSystemEvaluation(array $sources): array
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
    public function knowledgeGovernanceEvaluation(array $sources, array $authorityReport): array
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
    public function vocabularyAlignmentEvaluation(array $sources, array $authorityReport): array
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
     * @param  array<int,array<string,mixed>>  $sources
     * @return array<string,mixed>
     */
    public function documentationBudgetEvaluation(array $sources): array
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
    public function aiContextProjectionEvaluation(array $sources): array
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
    public function retrievalAuditTrailEvaluation(array $sources): array
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
    public function documentationCompressionTiersEvaluation(array $sources): array
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
    public function providerMisreadDefenseEvaluation(): array
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
    public function privacyRedactionEvaluation(array $sources): array
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
    public function accessPolicyEvaluation(array $sources): array
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
     * @return array<string,mixed>
     */
    public function legacyQuarantineEvaluation(): array
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
    public function evidenceRuntimeProofBridgeEvaluation(array $sources): array
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
    public function semanticDeduplicationEvaluation(array $sources, array $authorityReport): array
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
    public function autoSplitPlannerEvaluation(array $sources): array
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
    public function obsoleteKnowledgeSimulatorEvaluation(): array
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
    public function realityDiffEvaluation(array $sources): array
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
    public function orphanedDecisionEvaluation(array $sources, array $authorityReport): array
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
    public function documentationEntropyEvaluation(array $sources): array
    {
        $owners = EngineeringStringListNormalizer::uniqueStringCasts(array_column($sources, 'owner'));
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
    public function aurcVisualRealityEvaluation(array $sources, ?array $cartography): array
    {
        $ids = array_column($sources, 'id');
        $required = ['aurc', 'cartography_os', 'system_graph'];
        $missing = array_values(array_diff($required, $ids));

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
    public function humanModalContractEvaluation(): array
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
    public function semanticZoomContractEvaluation(): array
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
    public function visualGrammarEvaluation(): array
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
    public function crossOrganizationBoundaryEvaluation(array $sources): array
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
    public function humanCorrectionLoopEvaluation(): array
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
    public function visualCompletenessEvaluation(): array
    {
        // DECLARED SPEC (boot-cycle blocked): the full verb audits the live cartography map for
        // coverage gaps, but map('universe') is built BY the ADRS report(), so report() cannot
        // consume it without recursion (the cartography is null here by design). It stays an honest
        // declared spec until a non-circular structure feed exists — never promoted to a hollow
        // 'blocked' that would diverge executes from integrated.
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
    public function multiAgentHandoffProjectionEvaluation(): array
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
    public function realityChangeJournalEvaluation(): array
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
    public function humanAttentionHeatmapEvaluation(): array
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
    public function cartographyTaskSimulatorEvaluation(): array
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
    public function documentationWorkingSetCacheEvaluation(): array
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
    public function crossModalConsistencyEvaluation(): array
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
    public function contextPackRegressionEvaluation(): array
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
    public function cartographyCognitiveLoadEvaluation(): array
    {
        // DECLARED SPEC (boot-cycle blocked): the full verb scores the live cartography visual_scene
        // cognitive budget, but that scene is built BY this report() via map('universe'), so it is
        // unavailable here without recursion. Honest declared spec until a non-circular feed exists.
        return [
            'schema_version' => 'atlas.documentation_reality.cartography_cognitive_load.v1',
            'status' => 'spec',
            'metrics' => ['visible_node_count', 'edge_density', 'label_noise', 'modal_dependency', 'zoom_depth_to_answer'],
            'goal' => 'map_explains_macro_flow_visually_before_text',
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function documentationAdoptionEvaluation(): array
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
    public function surfaceCoverageEvaluation(): array
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
    public function learningToDocPromotionEvaluation(): array
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
    public function syntheticReaderEvaluation(): array
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
    public function canonicalExampleCorpusEvaluation(): array
    {
        return [
            'schema_version' => 'atlas.documentation_reality.example_corpus.v1',
            'status' => 'spec',
            'example_types' => ['good_owner_doc', 'bad_duplicate_doc', 'good_cartography_node', 'bad_context_pack', 'safe_quarantine_plan'],
            'reuse_policy' => 'examples_are_training_ground_for_docs_cartography_and_provider_projection',
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $sources
     * @param  array<int,array<string,mixed>>  $blocks
     * @param  array<string,array<string,mixed>>  $planes
     * @return array<string,mixed>
     */
    public function documentationRealityScore(array $sources, array $blocks, array $planes): array
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
     * @param  array<int,array<string,mixed>>  $blocks
     */
    public function nextUpgradeTarget(array $blocks): ?array
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
     * @param  array<string,array<string,mixed>>  $planes
     */
    private function planeScore(array $planes, string $plane): float
    {
        return (float) ($planes[$plane]['average_readiness_score'] ?? 0.0);
    }
}
