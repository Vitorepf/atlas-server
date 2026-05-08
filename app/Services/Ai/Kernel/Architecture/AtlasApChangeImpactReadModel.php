<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApChangeImpactReadModel
{
    private const SCHEMA_VERSION = 'atlas.ap_change_impact_read_model.v1';

    public function __construct(
        private readonly AtlasApDocumentationManifest $manifest,
    ) {}

    /**
     * @param  array<int,string>  $changedPaths
     * @return array<string,mixed>
     */
    public function report(array $changedPaths, ?string $docsApPath = null): array
    {
        $manifest = $this->manifest->manifest($docsApPath);
        $normalizedChangedPaths = $this->normalizedChangedPaths($changedPaths);
        $entries = (array) $manifest['entries'];
        $impacted = [];
        $covered = [];
        $docsChanged = [];

        foreach ($normalizedChangedPaths as $changedPath) {
            $docEntry = $this->docEntryForChangedPath($changedPath, $entries);
            if ($docEntry !== null) {
                $docsChanged[] = [
                    'changed_path' => $changedPath,
                    'ap' => $docEntry['ap'],
                    'number' => $docEntry['number'],
                    'slug' => $docEntry['slug'],
                    'doc_path' => $docEntry['path'],
                    'match_type' => 'ap_doc_changed',
                ];
                $this->appendImpact($impacted, $docEntry, $changedPath, 'ap_doc_changed');
                $covered[] = $changedPath;

                continue;
            }

            foreach ($entries as $entry) {
                $matchType = $this->matchType($changedPath, (array) $entry['related_paths']);
                if ($matchType === null) {
                    continue;
                }

                $this->appendImpact($impacted, $entry, $changedPath, $matchType);
                $covered[] = $changedPath;
            }
        }

        $covered = array_values(array_unique($covered));
        $uncovered = array_values(array_diff($normalizedChangedPaths, $covered));
        $impacted = array_values($impacted);
        usort($impacted, fn (array $a, array $b): int => [$a['number'], $a['slug']] <=> [$b['number'], $b['slug']]);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => ($manifest['governance']['status'] ?? null) === 'ok' ? 'ok' : 'attention',
            'mode' => 'read_only_change_impact',
            'authority' => 'ap_change_impact_only_no_file_writes',
            'changed_path_count' => count($normalizedChangedPaths),
            'covered_changed_path_count' => count($covered),
            'uncovered_changed_path_count' => count($uncovered),
            'impacted_ap_count' => count($impacted),
            'docs_changed_count' => count($docsChanged),
            'changed_paths' => $normalizedChangedPaths,
            'covered_changed_paths' => $covered,
            'uncovered_changed_paths' => $uncovered,
            'impacted_aps' => $impacted,
            'docs_changed' => $docsChanged,
            'governance' => [
                'schema_version' => $manifest['governance']['schema_version'],
                'status' => $manifest['governance']['status'],
                'blocker_count' => $manifest['governance']['blocker_count'],
                'next_suggested_number' => $manifest['governance']['next_suggested_number'],
            ],
            'next_action' => $this->nextAction($manifest, $uncovered, $impacted),
            'guardrails' => [
                'writes_files' => false,
                'reads_git_status' => false,
                'creates_ap_docs' => false,
                'edits_related_paths' => false,
                'requires_human_or_agent_to_update_docs_for_uncovered_paths' => true,
            ],
        ];
    }

    /**
     * @param  array<int,string>  $changedPaths
     * @return array<int,string>
     */
    private function normalizedChangedPaths(array $changedPaths): array
    {
        $paths = [];
        foreach ($changedPaths as $path) {
            $normalized = $this->normalizePath($path);
            if ($normalized !== '') {
                $paths[] = $normalized;
            }
        }

        $paths = array_values(array_unique($paths));
        sort($paths);

        return $paths;
    }

    private function normalizePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        $base = rtrim(str_replace('\\', '/', base_path()), '/').'/';

        if (str_starts_with($path, $base)) {
            $path = substr($path, strlen($base));
        }

        return str_starts_with($path, './') ? substr($path, 2) : $path;
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,mixed>|null
     */
    private function docEntryForChangedPath(string $changedPath, array $entries): ?array
    {
        foreach ($entries as $entry) {
            if ($changedPath === $entry['path']) {
                return $entry;
            }
        }

        return null;
    }

    /**
     * @param  array<int,string>  $relatedPaths
     */
    private function matchType(string $changedPath, array $relatedPaths): ?string
    {
        foreach ($relatedPaths as $relatedPath) {
            $relatedPath = $this->normalizePath($relatedPath);
            if ($relatedPath === '') {
                continue;
            }

            if ($changedPath === $relatedPath) {
                return 'related_path_exact';
            }

            if (str_ends_with($relatedPath, '/') && str_starts_with($changedPath, $relatedPath)) {
                return 'related_directory_prefix';
            }
        }

        return null;
    }

    /**
     * @param  array<string,array<string,mixed>>  $impacted
     * @param  array<string,mixed>  $entry
     */
    private function appendImpact(array &$impacted, array $entry, string $changedPath, string $matchType): void
    {
        $key = (string) $entry['path'];
        $impacted[$key] ??= [
            'ap' => $entry['ap'],
            'number' => $entry['number'],
            'slug' => $entry['slug'],
            'title' => $entry['title'],
            'status' => $entry['status'],
            'owner' => $entry['owner'],
            'doc_path' => $entry['path'],
            'matched_changed_paths' => [],
        ];

        $impacted[$key]['matched_changed_paths'][] = [
            'path' => $changedPath,
            'match_type' => $matchType,
        ];
    }

    /**
     * @param  array<string,mixed>  $manifest
     * @param  array<int,string>  $uncovered
     * @param  array<int,array<string,mixed>>  $impacted
     */
    private function nextAction(array $manifest, array $uncovered, array $impacted): string
    {
        if (($manifest['governance']['status'] ?? null) !== 'ok') {
            return 'repair_ap_governance_before_reviewing_change_impact';
        }

        if ($uncovered !== []) {
            return 'review_uncovered_changed_paths_then_update_existing_ap_or_create_new_ap_via_ap191_ap192';
        }

        if ($impacted !== []) {
            return 'review_impacted_ap_docs_before_finishing_change';
        }

        return 'no_ap_documentation_impact_detected';
    }
}
