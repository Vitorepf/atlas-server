<?php

namespace App\Services\Ai\Kernel\Architecture;

use Illuminate\Support\Arr;
use Illuminate\Support\Str;

final class AtlasExternalGraphHarnessService
{
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
                $nodeIds[$id] = true;
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
            'promotion_state' => $errors === [] ? 'eligible_for_architecture_operations_review' : 'blocked_until_candidate_fixed',
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
            ],
            'curator_proposal' => [
                'enabled' => false,
                'next_flow' => 'self_improvement.external_graph_review',
                'reason' => 'P0/P2 only publishes contract and validation. Proposal emission requires dedicated Curator slice.',
            ],
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
        }
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $path = preg_replace('#/+#', '/', $path) ?: '';

        if ($path === '' || str_contains($path, '../') || str_starts_with($path, '/')) {
            return '';
        }

        return ltrim($path, './');
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
    private function allowedConfidence(): array
    {
        return ['EXTRACTED', 'INFERRED', 'AMBIGUOUS'];
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
