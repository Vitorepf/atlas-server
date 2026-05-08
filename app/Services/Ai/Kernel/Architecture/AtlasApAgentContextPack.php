<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApAgentContextPack
{
    private const SCHEMA_VERSION = 'atlas.ap_agent_context_pack.v1';

    public function __construct(
        private readonly AtlasApDocumentationManifest $manifest,
        private readonly AtlasApDependencyMap $dependencyMap,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function pack(int|string $ap, ?string $docsApPath = null): array
    {
        $manifest = $this->manifest->manifest($docsApPath);
        $dependencyMap = $this->dependencyMap->map($docsApPath);
        $entry = $this->findEntry((array) $manifest['entries'], $ap);

        if ($entry === null) {
            return [
                'schema_version' => self::SCHEMA_VERSION,
                'status' => 'blocked',
                'mode' => 'read_only_agent_context_pack',
                'authority' => 'ap_agent_context_pack_only_no_file_writes',
                'requested_ap' => $ap,
                'target_ap' => null,
                'reason' => 'requested_ap_not_found',
                'governance' => $this->governanceSummary($manifest),
                'next_action' => 'use_ap_manifest_or_creation_decision_before_editing',
                'guardrails' => $this->guardrails(),
            ];
        }

        $apId = (string) $entry['ap'];
        $dependencies = $dependencyMap['dependencies_by_ap'][$apId] ?? [];
        $dependents = $dependencyMap['dependents_by_ap'][$apId] ?? [];
        $edges = $this->edgesForAp((array) $dependencyMap['edges'], $apId);
        $missingReferences = $this->missingReferencesForAp((array) $dependencyMap['missing_references'], $apId);
        $status = ($manifest['governance']['status'] ?? null) === 'ok' && $missingReferences === []
            ? 'ready'
            : 'attention';

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => 'read_only_agent_context_pack',
            'authority' => 'ap_agent_context_pack_only_no_file_writes',
            'requested_ap' => $ap,
            'target_ap' => [
                'ap' => $entry['ap'],
                'number' => $entry['number'],
                'slug' => $entry['slug'],
                'title' => $entry['title'],
                'status' => $entry['status'],
                'owner' => $entry['owner'],
                'doc_path' => $entry['path'],
                'line_count' => $entry['line_count'],
                'line_limit' => $entry['line_limit'],
                'within_line_limit' => $entry['within_line_limit'],
                'related_paths' => $entry['related_paths'],
            ],
            'dependency_context' => [
                'dependencies' => $dependencies,
                'dependents' => $dependents,
                'edge_count' => count($edges),
                'edges' => $edges,
                'missing_reference_count' => $dependencyMap['missing_reference_count'],
                'target_missing_reference_count' => count($missingReferences),
                'target_missing_references' => $missingReferences,
            ],
            'governance' => $this->governanceSummary($manifest),
            'recommended_review_order' => $this->reviewOrder($dependencies, $apId, $dependents),
            'next_action' => $this->nextAction($status, $entry, $dependents),
            'guardrails' => $this->guardrails(),
        ];
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,mixed>|null
     */
    private function findEntry(array $entries, int|string $ap): ?array
    {
        $needle = $this->normalizeAp($ap);

        foreach ($entries as $entry) {
            if ($needle === (string) $entry['slug'] || $needle === (string) $entry['number'] || $needle === strtolower((string) $entry['ap'])) {
                return $entry;
            }
        }

        return null;
    }

    private function normalizeAp(int|string $ap): string
    {
        $value = strtolower(trim((string) $ap));

        if (preg_match('/^ap-(\d+)$/', $value, $matches) === 1) {
            return 'ap-'.(int) $matches[1];
        }

        return $value;
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<int,array<string,mixed>>
     */
    private function edgesForAp(array $edges, string $ap): array
    {
        return array_values(array_filter(
            $edges,
            fn (array $edge): bool => $edge['from_ap'] === $ap || $edge['to_ap'] === $ap,
        ));
    }

    /**
     * @param  array<int,array<string,mixed>>  $missingReferences
     * @return array<int,array<string,mixed>>
     */
    private function missingReferencesForAp(array $missingReferences, string $ap): array
    {
        return array_values(array_filter(
            $missingReferences,
            fn (array $reference): bool => $reference['from_ap'] === $ap || $reference['to_ap'] === $ap,
        ));
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @return array<string,mixed>
     */
    private function governanceSummary(array $manifest): array
    {
        return [
            'schema_version' => $manifest['governance']['schema_version'],
            'status' => $manifest['governance']['status'],
            'blocker_count' => $manifest['governance']['blocker_count'],
            'next_suggested_number' => $manifest['governance']['next_suggested_number'],
        ];
    }

    /**
     * @param  array<int,string>  $dependencies
     * @param  array<int,string>  $dependents
     * @return array<int,string>
     */
    private function reviewOrder(array $dependencies, string $apId, array $dependents): array
    {
        return array_values(array_unique([...$dependencies, $apId, ...$dependents]));
    }

    /**
     * @param  array<string,mixed>  $entry
     * @param  array<int,string>  $dependents
     */
    private function nextAction(string $status, array $entry, array $dependents): string
    {
        if ($status !== 'ready') {
            return 'repair_ap_governance_or_dependency_map_before_editing_target_ap';
        }

        if (($entry['within_line_limit'] ?? true) !== true) {
            return 'split_or_shrink_target_ap_before_adding_scope';
        }

        if ($dependents !== []) {
            return 'review_target_ap_and_dependents_before_editing';
        }

        return 'review_target_ap_then_edit_declared_related_paths_only';
    }

    /**
     * @return array<string,bool>
     */
    private function guardrails(): array
    {
        return [
            'writes_files' => false,
            'loads_full_doc_body' => false,
            'creates_ap_docs' => false,
            'infers_semantic_dependencies' => false,
            'requires_agent_to_review_target_doc_before_editing' => true,
        ];
    }
}
