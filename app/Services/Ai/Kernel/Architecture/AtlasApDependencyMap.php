<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApDependencyMap
{
    private const SCHEMA_VERSION = 'atlas.ap_dependency_map.v1';

    public function __construct(
        private readonly AtlasApDocumentationManifest $manifest,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function map(?string $docsApPath = null): array
    {
        $manifest = $this->manifest->manifest($docsApPath);
        $docsApPath ??= base_path('docs/ap');
        $entries = (array) $manifest['entries'];
        $knownByNumber = [];

        foreach ($entries as $entry) {
            $knownByNumber[(int) $entry['number']] = $entry;
        }

        $edges = [];
        $missingRefs = [];

        foreach ($entries as $entry) {
            $absolutePath = $this->absolutePath((string) $entry['path'], $docsApPath);
            $contents = file_get_contents($absolutePath);
            if (! is_string($contents)) {
                continue;
            }

            $refs = $this->references($contents);
            foreach ($refs as $number => $sources) {
                if ($number === (int) $entry['number']) {
                    continue;
                }

                if (! isset($knownByNumber[$number])) {
                    $missingRefs[] = [
                        'from_ap' => $entry['ap'],
                        'from_doc' => $entry['path'],
                        'to_ap' => 'AP-'.$number,
                        'sources' => array_keys($sources),
                    ];

                    continue;
                }

                $to = $knownByNumber[$number];
                $edges[] = [
                    'from_ap' => $entry['ap'],
                    'from_number' => $entry['number'],
                    'from_slug' => $entry['slug'],
                    'from_doc' => $entry['path'],
                    'to_ap' => $to['ap'],
                    'to_number' => $to['number'],
                    'to_slug' => $to['slug'],
                    'to_doc' => $to['path'],
                    'sources' => array_keys($sources),
                ];
            }
        }

        $edges = $this->uniqueEdges($edges);
        $missingRefs = $this->uniqueMissingRefs($missingRefs);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $manifest['governance']['status'] === 'ok' && $missingRefs === [] ? 'ok' : 'attention',
            'mode' => 'read_only_dependency_map',
            'authority' => 'ap_dependency_map_only_no_file_writes',
            'ap_count' => count($entries),
            'edge_count' => count($edges),
            'missing_reference_count' => count($missingRefs),
            'edges' => $edges,
            'missing_references' => $missingRefs,
            'dependents_by_ap' => $this->dependentsByAp($edges),
            'dependencies_by_ap' => $this->dependenciesByAp($edges),
            'governance' => [
                'schema_version' => $manifest['governance']['schema_version'],
                'status' => $manifest['governance']['status'],
                'blocker_count' => $manifest['governance']['blocker_count'],
                'next_suggested_number' => $manifest['governance']['next_suggested_number'],
            ],
            'next_action' => $this->nextAction($manifest, $missingRefs),
            'guardrails' => [
                'writes_files' => false,
                'normalizes_docs' => false,
                'infers_semantic_dependencies' => false,
                'requires_explicit_ap_references' => true,
                'safe_for_agent_context_loading' => true,
            ],
        ];
    }

    /**
     * @return array<int,array<string,bool>>
     */
    private function references(string $contents): array
    {
        $refs = [];

        foreach ($this->dependsOnReferences($contents) as $number) {
            $refs[$number]['depends_on'] = true;
        }

        if (preg_match_all('/\bAP-(\d{1,4})\b/', $contents, $matches) > 0) {
            foreach ($matches[1] as $rawNumber) {
                $refs[(int) $rawNumber]['body_reference'] = true;
            }
        }

        return $refs;
    }

    /**
     * @return array<int,int>
     */
    private function dependsOnReferences(string $contents): array
    {
        if (! str_starts_with($contents, "---\n")) {
            return [];
        }

        $end = strpos($contents, "\n---", 4);
        if ($end === false) {
            return [];
        }

        $frontmatter = substr($contents, 4, $end - 4);
        $numbers = [];
        $inDependsOn = false;

        foreach (preg_split('/\R/', $frontmatter) ?: [] as $line) {
            if (preg_match('/^[a-zA-Z0-9_-]+:/', $line) === 1 && ! str_starts_with(trim($line), 'depends_on:')) {
                $inDependsOn = false;
            }

            if (trim($line) === 'depends_on:') {
                $inDependsOn = true;

                continue;
            }

            if ($inDependsOn && preg_match('/^\s*-\s+AP-(\d{1,4})\s*$/', $line, $matches) === 1) {
                $numbers[] = (int) $matches[1];
            }
        }

        return array_values(array_unique($numbers));
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<int,array<string,mixed>>
     */
    private function uniqueEdges(array $edges): array
    {
        $unique = [];
        foreach ($edges as $edge) {
            $key = $edge['from_ap'].'>'.$edge['to_ap'];
            $unique[$key] ??= $edge;
            $unique[$key]['sources'] = array_values(array_unique(array_merge($unique[$key]['sources'], $edge['sources'])));
        }

        $edges = array_values($unique);
        usort($edges, fn (array $a, array $b): int => [$a['from_number'], $a['to_number']] <=> [$b['from_number'], $b['to_number']]);

        return $edges;
    }

    /**
     * @param  array<int,array<string,mixed>>  $missingRefs
     * @return array<int,array<string,mixed>>
     */
    private function uniqueMissingRefs(array $missingRefs): array
    {
        $unique = [];
        foreach ($missingRefs as $ref) {
            $key = $ref['from_ap'].'>'.$ref['to_ap'];
            $unique[$key] ??= $ref;
            $unique[$key]['sources'] = array_values(array_unique(array_merge($unique[$key]['sources'], $ref['sources'])));
        }

        return array_values($unique);
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,array<int,string>>
     */
    private function dependentsByAp(array $edges): array
    {
        $dependents = [];
        foreach ($edges as $edge) {
            $dependents[$edge['to_ap']][] = $edge['from_ap'];
        }

        return $this->sortedGraphBuckets($dependents);
    }

    /**
     * @param  array<int,array<string,mixed>>  $edges
     * @return array<string,array<int,string>>
     */
    private function dependenciesByAp(array $edges): array
    {
        $dependencies = [];
        foreach ($edges as $edge) {
            $dependencies[$edge['from_ap']][] = $edge['to_ap'];
        }

        return $this->sortedGraphBuckets($dependencies);
    }

    /**
     * @param  array<string,array<int,string>>  $buckets
     * @return array<string,array<int,string>>
     */
    private function sortedGraphBuckets(array $buckets): array
    {
        ksort($buckets);
        foreach ($buckets as $key => $values) {
            $buckets[$key] = array_values(array_unique($values));
            sort($buckets[$key]);
        }

        return $buckets;
    }

    private function nextAction(array $manifest, array $missingRefs): string
    {
        if (($manifest['governance']['status'] ?? null) !== 'ok') {
            return 'repair_ap_governance_before_using_dependency_map';
        }

        if ($missingRefs !== []) {
            return 'review_missing_ap_references_before_relying_on_dependency_map';
        }

        return 'use_dependency_map_before_editing_referenced_aps';
    }

    private function absolutePath(string $relativePath, string $docsApPath): string
    {
        if (str_starts_with($relativePath, '/')) {
            return $relativePath;
        }

        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';
        $normalized = str_replace('\\', '/', $relativePath);
        if (str_starts_with($normalized, 'docs/ap/')) {
            return $base.$normalized;
        }

        return rtrim($docsApPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.basename($relativePath);
    }
}
