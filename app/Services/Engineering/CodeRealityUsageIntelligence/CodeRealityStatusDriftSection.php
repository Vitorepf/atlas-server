<?php

declare(strict_types=1);

namespace App\Services\Engineering\CodeRealityUsageIntelligence;

use Illuminate\Support\Facades\File;

final class CodeRealityStatusDriftSection
{
    public function __construct(
        private readonly CodeRealityPrimitives $primitives,
    ) {}


    /**
     * @param  array<string,mixed>  $docs
     * @param  array<string,mixed>  $code
     * @return array<string,mixed>
     */
    public function statusDriftInventory(array $docs, array $code): array
    {
        $commandSignatures = $this->primitives->uniqueStrings(array_map(
            static fn (array $command): string => (string) ($command['signature'] ?? ''),
            (array) ($code['artisan_commands'] ?? [])
        ));
        $reviewItems = [];
        $statusCounts = [];
        $plannedOrFutureWithExistingCode = 0;
        $scaffoldLanguageWithExistingCode = 0;
        $plannedFutureBoundaryExplained = 0;

        foreach ((array) ($docs['canonical_active_items'] ?? []) as $doc) {
            $path = (string) ($doc['path'] ?? '');
            $docId = strtolower((string) ($doc['id'] ?: $doc['path_stem'] ?? md5($path)));
            $status = strtolower((string) ($doc['status'] ?? 'missing'));
            $statusCounts[$status] = ((int) ($statusCounts[$status] ?? 0)) + 1;
            $content = $this->primitives->readSmallFile(base_path($path));
            if ($content === null) {
                continue;
            }

            $evidence = $this->docImplementationEvidence($content, $commandSignatures);
            $bodyState = $this->docBodyImplementationLanguage($content);
            $evidenceScore = (int) $evidence['evidence_score'];
            $isPlannedLikeStatus = in_array($status, ['planned', 'future', 'scaffold'], true);
            $hasExistingCode = $evidenceScore >= 3;
            $hasScaffoldLanguage = $bodyState['has_scaffold_language'] === true;
            $hasImplementedLanguage = $bodyState['has_implemented_language'] === true;

            if ($this->allowsMixedStatusMatrixLanguage($docId) && ($hasScaffoldLanguage || $isPlannedLikeStatus)) {
                continue;
            }

            if ($isPlannedLikeStatus && $hasExistingCode) {
                $plannedOrFutureWithExistingCode++;
            }
            if ($hasScaffoldLanguage && $hasExistingCode) {
                $scaffoldLanguageWithExistingCode++;
            }

            $reason = null;
            $severity = 'review';
            $boundaryContract = $this->explicitBacklogStatusBoundaryContract($doc)
                ?? $this->explicitNoRuntimeStatusBoundaryContract($doc)
                ?? $this->explicitPartialRuntimeStatusBoundaryContract($doc)
                ?? $this->explicitGatedRuntimeStatusBoundaryContract($doc)
                ?? $this->explicitNorthStarStatusBoundaryContract($doc)
                ?? $this->explicitImplementationStateStatusBoundaryContract($doc)
                ?? $this->statusDriftBoundaryContract($docId);
            if ($isPlannedLikeStatus && $hasExistingCode) {
                if ($boundaryContract !== null) {
                    $plannedFutureBoundaryExplained++;
                    if (($boundaryContract['review_policy'] ?? null) === 'suppress_status_drift_review_item') {
                        continue;
                    }
                    $reason = 'planned_or_future_status_references_existing_dependency_boundary';
                    $severity = 'low';
                } else {
                    $reason = 'frontmatter_status_may_understate_existing_code';
                    $severity = $evidenceScore >= 8 ? 'high' : 'medium';
                }
            } elseif ($hasScaffoldLanguage && $hasExistingCode && $hasImplementedLanguage) {
                if (($boundaryContract['review_policy'] ?? null) === 'suppress_status_drift_review_item') {
                    continue;
                }
                $reason = 'body_mixes_scaffold_and_implemented_language_with_code_evidence';
                $severity = $evidenceScore >= 8 ? 'medium' : 'review';
            } elseif ($hasScaffoldLanguage && $evidenceScore >= 8) {
                if (($boundaryContract['review_policy'] ?? null) === 'suppress_status_drift_review_item') {
                    continue;
                }
                $reason = 'scaffold_language_has_strong_code_evidence';
                $severity = 'medium';
            }

            if ($reason === null) {
                continue;
            }

            $boundaryContract ??= $this->defaultStatusDriftBoundaryContract($status, $reason, $evidenceScore);

            $reviewItems[] = [
                'id' => 'status_drift:'.$docId,
                'path' => $path,
                'title' => (string) ($doc['title'] ?? ''),
                'owner' => (string) ($doc['owner'] ?? 'unknown'),
                'doc_area' => $this->statusDriftDocArea($path),
                'frontmatter_status' => $status,
                'severity' => $severity,
                'reason' => $reason,
                'boundary_contract' => $boundaryContract,
                'implementation_evidence' => $evidence,
                'body_language' => $bodyState,
                'required_decision' => 'confirm_planned|mark_partial|mark_implemented|split_future_work_from_implemented_runtime',
                'policy' => 'candidate_only_owner_doc_must_decide_before_status_change',
            ];
        }

        usort($reviewItems, static function (array $a, array $b): int {
            $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];

            return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                ?: ((int) data_get($b, 'implementation_evidence.evidence_score', 0)) <=> ((int) data_get($a, 'implementation_evidence.evidence_score', 0));
        });

        return [
            'summary' => [
                'canonical_doc_count' => count((array) ($docs['canonical_active_items'] ?? [])),
                'status_counts' => $statusCounts,
                'review_count' => count($reviewItems),
                'owner_group_count' => count($this->statusDriftGroups($reviewItems, 'owner')),
                'doc_area_group_count' => count($this->statusDriftGroups($reviewItems, 'doc_area')),
                'planned_or_future_with_existing_code_count' => $plannedOrFutureWithExistingCode,
                'planned_future_boundary_explained_count' => $plannedFutureBoundaryExplained,
                'scaffold_language_with_existing_code_count' => $scaffoldLanguageWithExistingCode,
                'policy' => 'review_count_is_drift_pressure_not_automatic_status_mutation',
            ],
            'owner_groups' => $this->statusDriftGroups($reviewItems, 'owner'),
            'doc_area_groups' => $this->statusDriftGroups($reviewItems, 'doc_area'),
            'review_items' => $reviewItems,
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $items
     * @return array<int,array<string,mixed>>
     */
    private function statusDriftGroups(array $items, string $key): array
    {
        $groups = [];
        foreach ($items as $item) {
            $value = (string) ($item[$key] ?? 'unknown');
            $groups[$value][] = $item;
        }

        $rank = ['high' => 0, 'medium' => 1, 'review' => 2, 'low' => 3];
        $result = [];
        foreach ($groups as $value => $groupItems) {
            usort($groupItems, static function (array $a, array $b) use ($rank): int {
                return ($rank[$a['severity']] ?? 9) <=> ($rank[$b['severity']] ?? 9)
                    ?: ((int) data_get($b, 'implementation_evidence.evidence_score', 0)) <=> ((int) data_get($a, 'implementation_evidence.evidence_score', 0));
            });

            $severityCounts = [];
            foreach ($groupItems as $item) {
                $severity = (string) ($item['severity'] ?? 'review');
                $severityCounts[$severity] = ((int) ($severityCounts[$severity] ?? 0)) + 1;
            }

            $result[] = [
                'value' => $value,
                'count' => count($groupItems),
                'top_severity' => (string) ($groupItems[0]['severity'] ?? 'review'),
                'severity_counts' => $severityCounts,
                'samples' => array_slice(array_map(static fn (array $item): array => [
                    'id' => (string) ($item['id'] ?? ''),
                    'path' => (string) ($item['path'] ?? ''),
                    'frontmatter_status' => (string) ($item['frontmatter_status'] ?? ''),
                    'severity' => (string) ($item['severity'] ?? ''),
                    'reason' => (string) ($item['reason'] ?? ''),
                    'evidence_score' => (int) data_get($item, 'implementation_evidence.evidence_score', 0),
                ], $groupItems), 0, 8),
                'policy' => $key === 'owner'
                    ? 'owner_should_triage_highest_evidence_docs_first'
                    : 'area_group_is_cleanup_batch_not_authority_boundary',
            ];
        }

        usort($result, static function (array $a, array $b) use ($rank): int {
            return ($rank[$a['top_severity']] ?? 9) <=> ($rank[$b['top_severity']] ?? 9)
                ?: ((int) $b['count']) <=> ((int) $a['count']);
        });

        return $result;
    }

    private function statusDriftDocArea(string $path): string
    {
        if (str_contains($path, '/self-construction/')) {
            return 'self-construction';
        }
        if (str_contains($path, '/domains/')) {
            return 'domains';
        }
        if (str_contains($path, '/architecture-audit/')) {
            return 'architecture-audit';
        }
        if (str_contains($path, '/vault/')) {
            return 'vault';
        }
        if (str_contains($path, '/system-graph/')) {
            return 'system-graph';
        }
        if (str_contains($path, '/research-self-improvement/')) {
            return 'research-self-improvement';
        }

        return 'root';
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitBacklogStatusBoundaryContract(array $doc): ?array
    {
        $authorityClass = strtolower((string) ($doc['authority_class'] ?? ''));
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if ($authorityClass !== 'backlog') {
            return null;
        }
        if (! str_starts_with($implementationState, 'backlog_only')
            && ! (str_contains($implementationState, 'backlog') && str_contains($implementationState, 'no_runtime'))) {
            return null;
        }

        return [
            'classification' => 'planned_backlog_doc_with_existing_code_refs',
            'authority_class' => $authorityClass,
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'backlog_docs_may_cite_target_code_tests_and_commands_as_slice_inputs_without_being_runtime_proof',
            'existing_runtime_refs_are' => 'planned_slice_targets_or_execution_inputs_not_doc_runtime_completion',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_mark_backlog_doc_implemented_from_target_refs_or_ready_rows',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitNoRuntimeStatusBoundaryContract(array $doc): ?array
    {
        $authorityClass = strtolower((string) ($doc['authority_class'] ?? ''));
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_contains($implementationState, 'no_runtime')) {
            return null;
        }
        if (! in_array($authorityClass, ['proposal', 'runbook', 'planner', 'proposer'], true)) {
            return null;
        }

        return [
            'classification' => 'explicit_no_runtime_doc_with_existing_code_refs',
            'authority_class' => $authorityClass,
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'proposal_runbook_planner_and_proposer_docs_may_cite_existing_code_as_analysis_dependencies_or_targets_without_claiming_runtime_delivery',
            'existing_runtime_refs_are' => 'analysis_dependencies_or_remediation_targets_not_proof_that_the_doc_runtime_is_implemented',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_mark_no_runtime_proposal_runbook_planner_or_proposer_implemented_from_dependency_refs',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitPartialRuntimeStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_starts_with($implementationState, 'partial_runtime_with_future')) {
            return null;
        }

        return [
            'classification' => 'partial_runtime_doc_with_future_scope',
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'the_doc_owns_a_live_partial_runtime_or_read_model_while_explicitly_preserving_future_scope',
            'existing_runtime_refs_are' => 'implemented_slice_evidence_not_full_completion_or_permission_to_skip_remaining_gates',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_treat_partial_runtime_docs_as_future_only_or_complete_runtime',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitGatedRuntimeStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_contains($implementationState, 'shadow_gated')
            && ! str_contains($implementationState, 'fail_closed')) {
            return null;
        }

        return [
            'classification' => str_contains($implementationState, 'fail_closed')
                ? 'experimental_fail_closed_runtime_with_blocked_promotion_scope'
                : 'active_shadow_gated_runtime_with_blocked_promotion_scope',
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'runtime_exists_but_promotion_or_topology_changes_remain_operator_gated',
            'existing_runtime_refs_are' => 'shadow_runtime_evidence_not_permission_for_automatic_activation_or_external_claims',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_treat_shadow_gated_runtime_as_auto_promoted_or_unimplemented',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitNorthStarStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if (! str_contains($implementationState, 'north_star_no_runtime')) {
            return null;
        }

        return [
            'classification' => 'north_star_doc_with_existing_governance_refs',
            'implementation_state' => $implementationState,
            'current_runtime_boundary' => 'north_star_docs_may_cite_existing_governance_commands_and_owner_docs_without_claiming_runtime_delivery',
            'existing_runtime_refs_are' => 'sequencing_dependencies_or_governance_gates_not_proof_that_the_north_star_runtime_exists',
            'review_policy' => 'suppress_status_drift_review_item',
            'forbidden' => 'do_not_mark_north_star_docs_implemented_from_docs_health_architecture_or_owner_doc_refs',
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function explicitImplementationStateStatusBoundaryContract(array $doc): ?array
    {
        $implementationState = strtolower((string) ($doc['implementation_state'] ?? ''));
        if ($implementationState === '') {
            return null;
        }

        if ($this->primitives->containsAny($implementationState, [
            'no_code_yet',
            'not_current_runtime',
            'not_runtime_complete',
            'not_runtime_promoted',
            'not_promoted',
            'paper_only',
            'spec_only',
            'no_runtime_change',
        ])) {
            return [
                'classification' => 'explicit_not_promoted_or_no_runtime_implementation_state',
                'implementation_state' => $implementationState,
                'current_runtime_boundary' => 'the_doc_frontmatter_explicitly_says_refs_are_future_targets_plans_or_unpromoted_boundaries_not_current_runtime_completion',
                'existing_runtime_refs_are' => 'dependencies_targets_or_forbidden_scope_not_proof_that_this_doc_runtime_is_promoted',
                'review_policy' => 'suppress_status_drift_review_item',
                'forbidden' => 'do_not_promote_docs_from_existing_refs_when_implementation_state_says_not_promoted_or_no_current_runtime',
            ];
        }

        if ($this->primitives->containsAny($implementationState, [
            'runtime_surface_',
            'runtime_available',
            'implemented_',
            'read_only_',
            'scorecard_ready',
            'partial_php_baseline',
            'shadow_available',
            '_present',
            '_ready',
            'staging_only',
        ])) {
            return [
                'classification' => 'explicit_runtime_slice_implementation_state',
                'implementation_state' => $implementationState,
                'current_runtime_boundary' => 'the_doc_frontmatter_declares_a_specific_live_or_read_only_runtime_slice_while_body_language_may_still_name_future_or_unfinished_scope',
                'existing_runtime_refs_are' => 'slice_evidence_not_full_completion_or_permission_to_ignore_remaining_gates',
                'review_policy' => 'suppress_status_drift_review_item',
                'forbidden' => 'do_not_treat_explicit_runtime_slice_docs_as_either_future_only_or_complete_runtime',
            ];
        }

        return null;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function statusDriftBoundaryContract(string $docId): ?array
    {
        return match ($docId) {
            'atlas-forge-rivals-intelligence-ledger-v1' => [
                'classification' => 'planned_next_layer_over_existing_provider_performance_ledger',
                'current_runtime_boundary' => 'Provider Performance Ledger and Decide signal projection exist; Intelligence Ledger v1 remains proposed next patamar until historical segmented ledger tests and two real batteries exist.',
                'existing_runtime_refs_are' => 'dependencies_not_proof_that_this_planned_layer_is_implemented',
                'forbidden' => 'do_not_treat_existing_forge_rivals_ledger_services_as_intelligence_ledger_v1_complete',
            ],
            'atlas-cartographic-knowledge-os' => [
                'classification' => 'future_visual_os_over_existing_vault_graph_readers',
                'current_runtime_boundary' => 'Vault graph readers and Universal Reality Cartography are existing inputs/surfaces; Cartographic Knowledge OS remains future target for visual semantic zoom OS.',
                'existing_runtime_refs_are' => 'predecessor_or_input_runtime_not_future_visual_os_completion',
                'forbidden' => 'do_not_claim_cartographic_knowledge_os_active_because_vault_graph_reader_exists',
            ],
            'atlas-vox-v4-contextual-operator-plan' => [
                'classification' => 'paper_only_v4_plan_with_forbidden_runtime_boundary',
                'current_runtime_boundary' => 'Voice/Vox paths are cited as boundary and forbidden scope; no Vox V4 runtime is authorized before V3 gate and ADR 0004.',
                'existing_runtime_refs_are' => 'boundary_and_do_not_touch_refs_not_implementation_evidence',
                'forbidden' => 'do_not_create_vox_v4_runtime_or_touch_voice_runtime_from_this_plan',
            ],
            default => null,
        };
    }

    private function allowsMixedStatusMatrixLanguage(string $docId): bool
    {
        return in_array($docId, [
            'atlas-execution-doctrine-runtime-matrix',
            'atlas-duplication-reality-governance',
            'atlas-canonical-cleanup-inventory',
            'atlas-runtime-spine-completion-audit',
            'atlas-ai-research-self-improvement-runtime',
            'atlas-pre-benchmark-readiness-audit',
            'atlas-code-reality-usage-intelligence',
            'atlas-documentation-reality-system',
            'atlas-documentation-enforcement-runtime',
            'atlas-quality-preserving-efficiency-system',
            'atlas-persistent-context-runtime',
            'atlas-execution-memory-outcome-runtime',
            'atlas-kernel-mission-foundation',
            'atlas-ai-follow-through-loop',
            'atlas-ai-router-runtime-enterprise-upgrade',
            'atlas-aiworker-kernel-integration-adr',
            'atlas-autonomous-control-plane',
            'atlas-ai-programming-enterprise-implementation-plan',
            'atlas-programming-superiority-contracts',
            'atlas-programming-forge-flow',
            'atlas-long-horizon-intelligence-layer',
            'atlas-self-construction-agent-dispatch-planner-runtime-v1',
            'atlas-forge-live-execution-e2e-v1',
            'atlas-universal-reality-cartography',
        ], true);
    }

    /**
     * @return array<string,mixed>
     */
    private function defaultStatusDriftBoundaryContract(string $frontmatterStatus, string $reason, int $evidenceScore): array
    {
        return [
            'classification' => 'status_language_review_over_existing_evidence',
            'frontmatter_status' => $frontmatterStatus,
            'reason' => $reason,
            'evidence_score' => $evidenceScore,
            'current_runtime_boundary' => 'code_test_command_references_are_review_pressure_not_automatic_status_truth',
            'existing_runtime_refs_are' => 'candidate_evidence_requiring_owner_doc_reachability_tests_and_operator_decision',
            'required_owner_decision' => 'confirm_planned|mark_partial|mark_implemented|split_future_work_from_implemented_runtime',
            'safe_next_commands' => [
                'php artisan atlas:code-reality reachability --target="<runtime-or-doc-target>" --json',
                'php artisan atlas:code-reality status-drift-audit --json',
                'php artisan atlas:documentation:enforce --task="<task>" --feature="<feature>" --strict --json',
            ],
            'forbidden' => 'do_not_auto_change_frontmatter_or_claim_doc_wrong_from_heuristic_evidence',
        ];
    }

    /**
     * @param  array<int,string>  $commandSignatures
     * @return array<string,mixed>
     */
    private function docImplementationEvidence(string $content, array $commandSignatures): array
    {
        preg_match_all('/\b(?:app|routes|config|database|tests)\/[A-Za-z0-9_\/.\-]+/', $content, $pathMatches);
        $pathRefs = $this->primitives->uniqueStrings($pathMatches[0] ?? []);
        $existingPathRefs = array_values(array_filter(
            $pathRefs,
            static fn (string $path): bool => File::exists(base_path($path))
        ));
        preg_match_all('/php artisan\s+([a-z0-9:._-]+)/i', $content, $commandMatches);
        $commandRefs = $this->primitives->uniqueStrings($commandMatches[1] ?? []);
        $existingCommandRefs = array_values(array_intersect($commandRefs, $commandSignatures));
        $testRefs = array_values(array_filter(
            $existingPathRefs,
            static fn (string $path): bool => str_starts_with($path, 'tests/')
        ));
        $runtimeRefs = array_values(array_filter(
            $existingPathRefs,
            static fn (string $path): bool => str_starts_with($path, 'app/') || str_starts_with($path, 'routes/') || str_starts_with($path, 'database/')
        ));

        return [
            'evidence_score' => count($runtimeRefs) + count($testRefs) + count($existingCommandRefs),
            'existing_path_ref_count' => count($existingPathRefs),
            'runtime_ref_count' => count($runtimeRefs),
            'test_ref_count' => count($testRefs),
            'existing_command_ref_count' => count($existingCommandRefs),
            'runtime_ref_samples' => array_slice($runtimeRefs, 0, 8),
            'test_ref_samples' => array_slice($testRefs, 0, 8),
            'command_ref_samples' => array_slice($existingCommandRefs, 0, 8),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function docBodyImplementationLanguage(string $content): array
    {
        $lower = strtolower($content);
        $scaffoldTerms = ['scaffold', 'planned', 'future', 'not implemented', 'nao implementado', 'falta runtime', 'falta implementacao', 'missing implementation', 'missing runtime'];
        $implementedTerms = ['implemented_ready', 'implemented_partial', 'active_runtime', 'codigo/teste', 'tests existem', 'rotas', 'comando', 'runtime'];

        return [
            'has_scaffold_language' => $this->containsStatusLanguage($lower, $scaffoldTerms),
            'has_implemented_language' => $this->primitives->containsAny($lower, $implementedTerms),
            'policy' => 'language_is_heuristic_confirm_with_owner_doc_and_reachability',
        ];
    }

    /**
     * @param  array<int,string>  $terms
     */
    private function containsStatusLanguage(string $haystack, array $terms): bool
    {
        foreach ($terms as $term) {
            $quoted = preg_quote($term, '/');
            $pattern = '/(?<![a-z0-9_-])'.$quoted.'(?![a-z0-9_-])/';

            if (preg_match($pattern, $haystack) === 1) {
                return true;
            }
        }

        return false;
    }
}
