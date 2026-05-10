<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AtlasExternalGraphHarnessService
{
    public function __construct(
        private readonly AtlasRuntimeLanguageBoundaryReportService $runtimeBoundary,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function contract(): array
    {
        return [
            'schema_version' => 'atlas.external_graph_harness.contract.v1',
            'status' => 'implemented_read_only_contract',
            'mode' => 'candidate_validation_no_runtime_no_writes',
            'authority' => 'code_intelligence_candidate_only',
            'owner_doc' => 'docs/engineering-knowledge-base/code-intelligence/external-graph-harness.md',
            'ap' => 'docs/ap/AP-684-graphify-external-graph-harness.md',
            'candidate_schema' => [
                'schema_version' => 'atlas.external_graph_candidate.v1',
                'required_top_level_fields' => [
                    'schema_version',
                    'source_tool',
                    'source_tool_version',
                    'source_archive_hash',
                    'scan_root',
                    'generated_at',
                    'nodes',
                    'edges',
                    'privacy_class',
                    'review_state',
                ],
                'node_required_fields' => ['id', 'label', 'kind', 'source_refs'],
                'edge_required_fields' => ['source', 'target', 'relation', 'confidence', 'source_refs'],
                'allowed_confidence' => $this->allowedConfidence(),
                'allowed_privacy_classes' => $this->allowedPrivacyClasses(),
                'allowed_review_states' => $this->allowedReviewStates(),
                'default_confidence' => 'AMBIGUOUS',
                'max_nodes' => 5000,
                'max_edges' => 20000,
            ],
            'allowed_scan_roots' => $this->allowedScanRoots(),
            'denylist_fragments' => $this->denylistFragments(),
            'guardrails' => [
                'network_fetching_enabled' => false,
                'provider_calls_enabled' => false,
                'writes_memory_registry' => false,
                'writes_context_builder' => false,
                'writes_constelacao' => false,
                'writes_policy_profile' => false,
                'changes_decide_routing' => false,
                'installs_graphify_hooks' => false,
                'reads_private_notes' => false,
            ],
            'review_only_constraints' => $this->reviewOnlyConstraints(),
            'promotion_requires' => [
                'schema_validation_passed',
                'privacy_gate_passed',
                'source_refs_for_each_promoted_node_or_edge',
                'Architecture Operations review',
                'Curator proposal',
                'human_review',
                'optional Atlas-native extractor AP before runtime adoption',
            ],
            'forbidden_shortcuts' => [
                'graph_json_to_memory',
                'graph_json_to_context_builder',
                'graph_json_to_constelacao',
                'graphify_private_workspace_scan',
                'external_graph_provider_call_without_AP',
                'external_graph_decide_signal_without_human_review',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $candidate
     * @return array<string,mixed>
     */
    public function validateCandidate(array $candidate): array
    {
        $errors = [];
        $warnings = [];

        foreach ($this->contract()['candidate_schema']['required_top_level_fields'] as $field) {
            if (! array_key_exists($field, $candidate)) {
                $errors[] = "missing_top_level_field:{$field}";
            }
        }

        if (($candidate['schema_version'] ?? null) !== 'atlas.external_graph_candidate.v1') {
            $errors[] = 'invalid_schema_version';
        }

        if (($candidate['source_tool'] ?? null) !== 'graphify') {
            $errors[] = 'source_tool_not_allowed';
        }

        $sourceToolVersion = (string) ($candidate['source_tool_version'] ?? '');
        if ($sourceToolVersion === '') {
            $errors[] = 'source_tool_version_required';
        }

        $sourceArchiveHash = (string) ($candidate['source_archive_hash'] ?? '');
        if (! preg_match('/\\A[a-f0-9]{64}\\z/', $sourceArchiveHash)) {
            $errors[] = 'source_archive_hash_must_be_sha256';
        }

        $generatedAt = (string) ($candidate['generated_at'] ?? '');
        if ($generatedAt === '' || strtotime($generatedAt) === false) {
            $errors[] = 'generated_at_must_be_timestamp';
        }

        $privacyClass = (string) ($candidate['privacy_class'] ?? '');
        if (! in_array($privacyClass, $this->allowedPrivacyClasses(), true)) {
            $errors[] = 'privacy_class_not_allowed';
        }

        $reviewState = (string) ($candidate['review_state'] ?? '');
        if (! in_array($reviewState, $this->allowedReviewStates(), true)) {
            $errors[] = 'review_state_not_allowed';
        }

        if ($reviewState !== 'candidate') {
            $errors[] = 'review_state_must_be_candidate_for_import';
        }

        if (array_key_exists('promotion_target', $candidate)) {
            $errors[] = 'promotion_target_not_allowed_before_review';
        }

        $this->validateForbiddenCandidateKeys($candidate, $errors);

        $nodes = $this->arrayList($candidate['nodes'] ?? null);
        $edges = $this->arrayList($candidate['edges'] ?? null);

        if ($nodes === null) {
            $errors[] = 'nodes_must_be_array';
            $nodes = [];
        }

        if ($edges === null) {
            $errors[] = 'edges_must_be_array';
            $edges = [];
        }

        if (count($nodes) > 5000) {
            $errors[] = 'node_count_exceeds_limit';
        }

        if (count($edges) > 20000) {
            $errors[] = 'edge_count_exceeds_limit';
        }

        $scanRoot = $this->normalizePath((string) ($candidate['scan_root'] ?? ''));
        if ($scanRoot === '' || ! $this->isAllowedPath($scanRoot)) {
            $errors[] = 'scan_root_not_allowed';
        }

        if ($this->isDeniedPath($scanRoot)) {
            $errors[] = 'scan_root_denied';
        }

        $nodeIds = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                $errors[] = "node_{$index}_must_be_object";

                continue;
            }

            foreach ($this->contract()['candidate_schema']['node_required_fields'] as $field) {
                if (! array_key_exists($field, $node)) {
                    $errors[] = "node_{$index}_missing_{$field}";
                }
            }

            $id = (string) ($node['id'] ?? '');
            if ($id !== '') {
                if (isset($nodeIds[$id])) {
                    $errors[] = "node_{$index}_duplicate_id";
                }

                $nodeIds[$id] = true;
            } else {
                $errors[] = "node_{$index}_id_required";
            }

            if (trim((string) ($node['label'] ?? '')) === '') {
                $errors[] = "node_{$index}_label_required";
            }

            if (trim((string) ($node['kind'] ?? '')) === '') {
                $errors[] = "node_{$index}_kind_required";
            }

            $this->validateSourceRefs($node['source_refs'] ?? null, "node_{$index}", $errors);
        }

        foreach ($edges as $index => $edge) {
            if (! is_array($edge)) {
                $errors[] = "edge_{$index}_must_be_object";

                continue;
            }

            foreach ($this->contract()['candidate_schema']['edge_required_fields'] as $field) {
                if (! array_key_exists($field, $edge)) {
                    $errors[] = "edge_{$index}_missing_{$field}";
                }
            }

            $source = (string) ($edge['source'] ?? '');
            $target = (string) ($edge['target'] ?? '');
            if ($source === '') {
                $errors[] = "edge_{$index}_source_required";
            }
            if ($target === '') {
                $errors[] = "edge_{$index}_target_required";
            }
            if ($source !== '' && ! isset($nodeIds[$source])) {
                $errors[] = "edge_{$index}_source_unknown";
            }
            if ($target !== '' && ! isset($nodeIds[$target])) {
                $errors[] = "edge_{$index}_target_unknown";
            }

            $confidence = (string) ($edge['confidence'] ?? 'AMBIGUOUS');
            if (! in_array($confidence, $this->allowedConfidence(), true)) {
                $errors[] = "edge_{$index}_invalid_confidence";
            }
            if ($confidence === 'INFERRED') {
                $warnings[] = "edge_{$index}_inferred_requires_human_review";
            }
            if ($confidence === 'AMBIGUOUS') {
                $warnings[] = "edge_{$index}_ambiguous_not_promotable";
            }

            if (trim((string) ($edge['relation'] ?? '')) === '') {
                $errors[] = "edge_{$index}_relation_required";
            }

            $this->validateSourceRefs($edge['source_refs'] ?? null, "edge_{$index}", $errors);
        }

        $candidateHash = hash('sha256', json_encode($this->canonicalize($candidate), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));

        return [
            'schema_version' => 'atlas.external_graph_candidate.validation.v1',
            'status' => $errors === [] ? 'accepted_read_only_candidate' : 'rejected',
            'mode' => 'validation_only_no_writes',
            'candidate_hash' => $candidateHash,
            'node_count' => count($nodes),
            'edge_count' => count($edges),
            'error_count' => count($errors),
            'warning_count' => count($warnings),
            'errors' => $errors,
            'warnings' => array_values(array_unique($warnings)),
            'guardrails' => $this->contract()['guardrails'],
            'review_only_constraints' => $this->reviewOnlyConstraints(),
            'review_packet' => $this->reviewPacket($errors, $warnings, $candidateHash, count($nodes), count($edges)),
            'promotion_allowed' => false,
            'promotion_state' => $errors === [] ? 'eligible_for_architecture_operations_review_only' : 'blocked_until_candidate_fixed',
        ];
    }

    /**
     * @param  array<string,mixed>|null  $candidate
     * @return array<string,mixed>
     */
    public function report(?array $candidate = null): array
    {
        $validation = $candidate === null ? null : $this->validateCandidate($candidate);

        return [
            'schema_version' => 'atlas.external_graph_harness.report.v1',
            'status' => $validation === null || $validation['status'] === 'accepted_read_only_candidate' ? 'ok' : 'blocked',
            'mode' => 'architecture_operations_read_only_report',
            'promotion_allowed' => false,
            'next_action' => $this->reportNextAction($validation),
            'contract' => $this->contract(),
            'candidate_validation' => $validation,
            'comparison_plan' => [
                'native_index' => 'EngineeringCodeIntelligenceService',
                'external_candidate' => 'atlas.external_graph_candidate.v1',
                'compare_dimensions' => [
                    'missing_source_refs',
                    'unexpected_bridge_nodes',
                    'weak_or_ambiguous_edges',
                    'doc_code_test_relation_gaps',
                    'native_extractor_improvement_candidates',
                ],
                'writes_enabled' => false,
                'promotion_allowed' => false,
                'review_only_constraints' => $this->reviewOnlyConstraints(),
            ],
            'review_packet_contract' => [
                'schema_version' => 'atlas.external_graph_review_packet.v1',
                'status' => 'proposal_only',
                'human_review_required' => true,
                'auto_promotion_allowed' => false,
                'allowed_outcomes' => [
                    'reject_candidate',
                    'request_candidate_fix',
                    'record_native_extractor_gap',
                    'draft_future_ap_for_native_graph_extractor',
                ],
                'forbidden_outcomes' => [
                    'promote_to_memory',
                    'promote_to_context_builder',
                    'promote_to_constelacao',
                    'enable_python_graph_rag_runtime',
                    'patch_decide_policy',
                ],
            ],
            'curator_proposal' => [
                'enabled' => false,
                'next_flow' => 'self_improvement.external_graph_review',
                'reason' => 'P0/P2 only publishes contract and validation. Proposal emission requires dedicated Curator slice.',
            ],
        ];
    }

    /**
     * @param  array<string,mixed>|null  $validation
     */
    private function reportNextAction(?array $validation): string
    {
        if ($validation === null) {
            return 'provide_external_graph_candidate_for_read_only_validation';
        }

        return ($validation['status'] ?? null) === 'accepted_read_only_candidate'
            ? 'review_external_graph_candidate_against_native_code_intelligence'
            : 'fix_external_graph_candidate_before_review';
    }

    /**
     * @param  array<int,string>  $errors
     * @param  array<int,string>  $warnings
     * @return array<string,mixed>
     */
    private function reviewPacket(array $errors, array $warnings, string $candidateHash, int $nodeCount, int $edgeCount): array
    {
        $accepted = $errors === [];

        return [
            'schema_version' => 'atlas.external_graph_review_packet.v1',
            'status' => $accepted ? 'ready_for_human_review' : 'blocked_until_candidate_fixed',
            'candidate_hash' => $candidateHash,
            'node_count' => $nodeCount,
            'edge_count' => $edgeCount,
            'human_review_required' => true,
            'curator_proposal_required' => true,
            'required_human_decision' => 'approve_or_reject_external_graph_candidate_for_native_extractor_improvement',
            'rollback_plan_required' => true,
            'policy_patch_review_required' => true,
            'auto_promotion_allowed' => false,
            'promotion_allowed' => false,
            'evidence_required' => [
                'external_graph_candidate.validation.accepted_read_only_candidate',
                'candidate_hash',
                'source_archive_hash',
                'source_refs_for_each_node_and_edge',
                'native_code_intelligence_comparison',
                'human_review_or_curator_proposal',
                'future_ap_before_runtime_promotion',
            ],
            'rollback_required' => [
                'discard_external_graph_candidate',
                'keep_memory_core_unchanged',
                'keep_context_builder_unchanged',
                'keep_constelacao_unchanged',
                'keep_graph_rag_runtime_disabled',
                'preserve_architecture_operations_review_only_report',
            ],
            'forbidden_until_review' => [
                'enable_python_graph_rag_runtime',
                'promote_graph_json_to_memory',
                'promote_graph_json_to_context_builder',
                'promote_graph_json_to_constelacao',
                'inject_external_graph_into_provider_prompt',
                'patch_decide_policy',
                'auto_apply_policy_patch',
                'surface_direct_external_graph_call',
            ],
            'review_scope' => [
                'compare_against_code_intelligence',
                'identify_missing_native_relations',
                'identify_weak_or_ambiguous_edges',
                'decide_whether_future_native_extractor_ap_is_worth_it',
            ],
            'blocked_runtime_targets' => [
                'memory_core',
                'context_builder',
                'constelacao',
                'atlas_decide',
                'provider_prompt',
                'python_graph_rag_runtime',
            ],
            'required_before_any_future_promotion' => [
                'human_review',
                'curator_proposal',
                'future_ap',
                'decision_receipt',
                'runtime_invocation_contract',
                'rollback_plan',
                'privacy_review',
                'slo_budget',
            ],
            'future_runtime_invocation_contract' => $this->futureGraphRuntimeInvocationContract(),
            'recommended_action' => $accepted
                ? 'review_external_graph_candidate_against_native_code_intelligence'
                : 'fix_external_graph_candidate_before_review',
            'failure_summary' => [
                'error_count' => count($errors),
                'warning_count' => count($warnings),
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function futureGraphRuntimeInvocationContract(): array
    {
        return [
            ...$this->runtimeBoundary->invocationContract(),
            'selected_runtime_family' => 'python_ai_data',
            'runtime_id' => 'external_graph_candidate_runtime',
            'capability_id' => 'code_intelligence.external_graph_candidate',
            'evidence_rule' => 'external_graph_candidates_may_only_return_review_packets_until_human_review_and_future_ap',
            'promotion_allowed_now' => false,
            'auto_enable_allowed_now' => false,
        ];
    }

    /**
     * @return array<int,mixed>|null
     */
    private function arrayList(mixed $value): ?array
    {
        return is_array($value) && array_is_list($value) ? $value : null;
    }

    /**
     * @param  array<int,string>  $errors
     */
    private function validateForbiddenCandidateKeys(mixed $value, array &$errors, string $prefix = ''): void
    {
        if (! is_array($value)) {
            return;
        }

        foreach ($value as $rawKey => $item) {
            $key = (string) $rawKey;
            $path = $prefix === '' ? $key : "{$prefix}.{$key}";

            if (in_array(Str::snake($key), $this->forbiddenCandidateKeys(), true)) {
                $errors[] = "forbidden_candidate_key:{$path}";
            }

            $this->validateForbiddenCandidateKeys($item, $errors, $path);
        }
    }

    /**
     * @param  array<int,string>  $errors
     */
    private function validateSourceRefs(mixed $sourceRefs, string $prefix, array &$errors): void
    {
        if (! is_array($sourceRefs) || ! array_is_list($sourceRefs) || $sourceRefs === []) {
            $errors[] = "{$prefix}_source_refs_required";

            return;
        }

        foreach ($sourceRefs as $refIndex => $ref) {
            if (! is_array($ref)) {
                $errors[] = "{$prefix}_source_ref_{$refIndex}_must_be_object";

                continue;
            }

            $path = $this->normalizePath((string) ($ref['path'] ?? ''));
            if ($path === '') {
                $errors[] = "{$prefix}_source_ref_{$refIndex}_missing_path";

                continue;
            }

            if (! $this->isAllowedPath($path)) {
                $errors[] = "{$prefix}_source_ref_{$refIndex}_path_not_allowed";
            }

            if ($this->isDeniedPath($path)) {
                $errors[] = "{$prefix}_source_ref_{$refIndex}_path_denied";
            }

            foreach (['line_start', 'line_end'] as $lineField) {
                if (array_key_exists($lineField, $ref) && (! is_int($ref[$lineField]) || $ref[$lineField] < 1)) {
                    $errors[] = "{$prefix}_source_ref_{$refIndex}_{$lineField}_must_be_positive_integer";
                }
            }

            if (
                isset($ref['line_start'], $ref['line_end'])
                && is_int($ref['line_start'])
                && is_int($ref['line_end'])
                && $ref['line_end'] < $ref['line_start']
            ) {
                $errors[] = "{$prefix}_source_ref_{$refIndex}_line_end_before_line_start";
            }
        }
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?: '';

        if ($path === '' || str_starts_with($path, '/')) {
            return '';
        }

        $segments = [];
        foreach (explode('/', ltrim($path, './')) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                return '';
            }

            $segments[] = $segment;
        }

        return implode('/', $segments);
    }

    private function isAllowedPath(string $path): bool
    {
        return Arr::first($this->allowedScanRoots(), fn (string $root): bool => $path === $root || Str::startsWith($path, $root.'/')) !== null;
    }

    private function isDeniedPath(string $path): bool
    {
        $lower = Str::lower($path);

        return Arr::first($this->denylistFragments(), fn (string $fragment): bool => str_contains($lower, $fragment)) !== null;
    }

    /**
     * @return array<int,string>
     */
    private function allowedScanRoots(): array
    {
        return [
            'docs/engineering-knowledge-base',
            'app/Services/Ai',
            'app/Services/Engineering',
            'tests/Feature',
            'tests/Unit',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function denylistFragments(): array
    {
        return [
            '.env',
            'secret',
            'secrets',
            'receipt',
            'receipts',
            'capture',
            'captures',
            'atlasvault',
            'obsidian',
            'personal',
            'private',
            'memory/private',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function forbiddenCandidateKeys(): array
    {
        return [
            'memory_write',
            'memory_payload',
            'context_builder_payload',
            'constelacao_star',
            'decide_signal',
            'provider_prompt',
            'provider_call',
            'policy_patch',
            'tool_call',
            'direct_tool_execution',
            'api_secret',
            'access_token',
            'private_notes',
            'raw_receipt',
        ];
    }

    /**
     * @return array<int,string>
     */
    private function allowedConfidence(): array
    {
        return ['EXTRACTED', 'INFERRED', 'AMBIGUOUS'];
    }

    /**
     * @return array<int,string>
     */
    private function allowedPrivacyClasses(): array
    {
        return ['engineering_internal'];
    }

    /**
     * @return array<int,string>
     */
    private function allowedReviewStates(): array
    {
        return ['candidate'];
    }

    /**
     * @return array<string,mixed>
     */
    private function reviewOnlyConstraints(): array
    {
        return [
            'runtime_promotion_allowed' => false,
            'memory_promotion_allowed' => false,
            'context_injection_allowed' => false,
            'constelacao_promotion_allowed' => false,
            'decide_signal_allowed' => false,
            'provider_prompt_injection_allowed' => false,
            'policy_patch_allowed' => false,
            'requires_human_review' => true,
            'requires_curator_proposal_slice' => true,
            'requires_ap_683_or_successor_for_graph_rag_promotion' => true,
            'allowed_use' => 'architecture_operations_read_only_candidate_review',
            'forbidden_uses' => [
                'memory_core_write',
                'context_builder_source',
                'constelacao_star_creation',
                'runtime_decision_source',
                'provider_prompt_injection',
                'policy_profile_patch',
            ],
        ];
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        $canonical = [];
        foreach ($value as $key => $item) {
            $canonical[$key] = $this->canonicalize($item);
        }

        if (! array_is_list($canonical)) {
            ksort($canonical);
        }

        return $canonical;
    }
}
