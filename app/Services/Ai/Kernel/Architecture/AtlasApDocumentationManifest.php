<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApDocumentationManifest
{
    use ArchitecturePathHelper;

    private const SCHEMA_VERSION = 'atlas.ap_documentation_manifest.v1';

    public function __construct(
        private readonly AtlasApDocumentationGovernanceRegistry $governance,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function manifest(?string $docsApPath = null): array
    {
        $docsApPath ??= base_path('docs/ap');
        $files = glob(rtrim($docsApPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'AP-*.md') ?: [];
        sort($files);

        $entries = [];
        foreach ($files as $file) {
            $entry = $this->entry($file);
            if ($entry !== null) {
                $entries[] = $entry;
            }
        }

        usort($entries, fn (array $a, array $b): int => [$a['number'], $a['slug']] <=> [$b['number'], $b['slug']]);

        $governance = $this->governance->summary($docsApPath);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $governance['status'] === 'ok' ? 'ok' : 'attention',
            'mode' => 'read_only_manifest',
            'authority' => 'ap_documentation_manifest_only_no_file_writes',
            'docs_ap_path' => $this->relativePath($docsApPath),
            'ap_count' => count($entries),
            'status_counts' => $this->counts($entries, 'status'),
            'owner_counts' => $this->counts($entries, 'owner'),
            'docs_with_frontmatter_count' => count(array_filter($entries, fn (array $entry): bool => $entry['has_frontmatter'] === true)),
            'docs_with_related_paths_count' => count(array_filter($entries, fn (array $entry): bool => $entry['related_path_count'] > 0)),
            'docs_with_line_limit_count' => count(array_filter($entries, fn (array $entry): bool => $entry['line_limit'] !== null)),
            'entries' => $entries,
            'governance' => [
                'schema_version' => $governance['schema_version'],
                'status' => $governance['status'],
                'blocker_count' => $governance['blocker_count'],
                'blockers' => $governance['blockers'],
                'next_suggested_number' => $governance['next_suggested_number'],
            ],
            'next_action' => $governance['status'] === 'ok'
                ? 'use_manifest_to_pick_existing_ap_or_create_next_ap_via_ap191_ap192'
                : 'repair_ap_governance_before_using_manifest_for_new_work',
            'guardrails' => [
                'writes_files' => false,
                'normalizes_docs' => false,
                'creates_ap_docs' => false,
                'replaces_governance_registry' => false,
                'safe_for_agent_context_loading' => true,
            ],
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function entry(string $file): ?array
    {
        $basename = basename($file);
        if (preg_match('/^AP-(\d+)-([a-z0-9][a-z0-9-]*)\.md$/', $basename, $matches) !== 1) {
            return null;
        }

        $frontmatter = $this->frontmatter($file);
        $parsed = $frontmatter === null ? [] : $this->parseFrontmatter($frontmatter);
        $relatedPaths = $this->relatedPaths($frontmatter ?? '');
        $lineCount = $this->lineCount($file);
        $lineLimit = isset($parsed['line_limit']) && preg_match('/^\d+$/', $parsed['line_limit']) === 1
            ? max(1, (int) $parsed['line_limit'])
            : null;

        return [
            'ap' => 'AP-'.(int) $matches[1],
            'number' => (int) $matches[1],
            'slug' => $matches[2],
            'path' => $this->relativePath($file),
            'filename' => $basename,
            'title' => $parsed['title'] ?? $this->headingTitle($file),
            'status' => $parsed['status'] ?? 'undocumented_status',
            'owner' => $parsed['owner'] ?? 'undocumented_owner',
            'has_frontmatter' => $frontmatter !== null,
            'line_count' => $lineCount,
            'line_limit' => $lineLimit,
            'within_line_limit' => $lineLimit === null || $lineCount <= $lineLimit,
            'related_path_count' => count($relatedPaths),
            'related_paths' => $relatedPaths,
        ];
    }

    private function frontmatter(string $file): ?string
    {
        $contents = file_get_contents($file);
        if (! is_string($contents) || ! str_starts_with($contents, "---\n")) {
            return null;
        }

        $end = strpos($contents, "\n---", 4);
        if ($end === false) {
            return '';
        }

        return substr($contents, 4, $end - 4);
    }

    /**
     * @return array<string,string>
     */
    private function parseFrontmatter(string $frontmatter): array
    {
        $parsed = [];
        foreach (preg_split('/\R/', $frontmatter) ?: [] as $line) {
            if (preg_match('/^([a-zA-Z0-9_-]+):\s*(.*?)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $parsed[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
        }

        return $parsed;
    }

    /**
     * @return array<int,string>
     */
    private function relatedPaths(string $frontmatter): array
    {
        $paths = [];
        $inRelatedPaths = false;

        foreach (preg_split('/\R/', $frontmatter) ?: [] as $line) {
            if (preg_match('/^[a-zA-Z0-9_-]+:/', $line) === 1 && ! str_starts_with(trim($line), 'related_paths:')) {
                $inRelatedPaths = false;
            }

            if (trim($line) === 'related_paths:') {
                $inRelatedPaths = true;

                continue;
            }

            if ($inRelatedPaths && preg_match('/^\s*-\s+(.+)$/', $line, $matches) === 1) {
                $paths[] = trim($matches[1], " \t\n\r\0\x0B\"'");
            }
        }

        return array_values(array_unique(array_filter($paths)));
    }

    private function headingTitle(string $file): ?string
    {
        foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            if (str_starts_with($line, '# ')) {
                return substr($line, 2);
            }
        }

        return null;
    }

    private function lineCount(string $file): int
    {
        $contents = file_get_contents($file);
        if (! is_string($contents) || $contents === '') {
            return 0;
        }

        return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,int>
     */
    private function counts(array $entries, string $key): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            $value = (string) ($entry[$key] ?? 'unknown');
            $counts[$value] = ($counts[$value] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
    }
}
