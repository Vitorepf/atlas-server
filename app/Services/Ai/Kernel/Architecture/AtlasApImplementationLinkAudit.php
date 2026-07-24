<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApImplementationLinkAudit
{
    use ArchitecturePathHelper;

    private const SCHEMA_VERSION = 'atlas.ap_implementation_link_audit.v1';

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $docsApPath = null): array
    {
        $docsApPath ??= base_path('docs/ap');
        $files = glob(rtrim($docsApPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'AP-*.md') ?: [];
        sort($files);

        $entries = [];
        $missing = [];
        $withRelatedPaths = 0;

        foreach ($files as $file) {
            $relatedPaths = $this->relatedPaths($file);
            if ($relatedPaths === []) {
                continue;
            }

            $withRelatedPaths++;
            $entryMissing = [];
            foreach ($relatedPaths as $path) {
                $exists = file_exists(base_path($path));
                if (! $exists) {
                    $entryMissing[] = $path;
                    $missing[] = [
                        'ap_doc' => $this->relativePath($file),
                        'missing_path' => $path,
                    ];
                }
            }

            $entries[] = [
                'ap_doc' => $this->relativePath($file),
                'related_path_count' => count($relatedPaths),
                'missing_path_count' => count($entryMissing),
                'related_paths' => $relatedPaths,
                'missing_paths' => $entryMissing,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $missing === [] ? 'ok' : 'attention',
            'mode' => 'read_only_audit',
            'authority' => 'ap_related_paths_only_no_file_writes',
            'docs_ap_path' => $this->relativePath($docsApPath),
            'ap_with_related_paths_count' => $withRelatedPaths,
            'missing_path_count' => count($missing),
            'missing_paths' => $missing,
            'entries' => $entries,
            'guardrails' => [
                'writes_files' => false,
                'creates_missing_files' => false,
                'changes_ap_docs' => false,
                'changes_static_scanner' => false,
                'blocks_on_missing_related_paths' => true,
            ],
        ];
    }

    /**
     * @return array<int,string>
     */
    private function relatedPaths(string $file): array
    {
        $contents = file_get_contents($file);
        if (! is_string($contents) || ! str_starts_with($contents, "---\n")) {
            return [];
        }

        $end = strpos($contents, "\n---", 4);
        if ($end === false) {
            return [];
        }

        $frontmatter = substr($contents, 4, $end - 4);
        $lines = preg_split('/\R/', $frontmatter) ?: [];
        $paths = [];
        $inRelatedPaths = false;

        foreach ($lines as $line) {
            if (preg_match('/^[a-zA-Z0-9_-]+:/', $line) === 1 && ! str_starts_with(trim($line), 'related_paths:')) {
                $inRelatedPaths = false;
            }

            if (trim($line) === 'related_paths:') {
                $inRelatedPaths = true;

                continue;
            }

            if (! $inRelatedPaths) {
                continue;
            }

            if (preg_match('/^\s*-\s+(.+)$/', $line, $matches) !== 1) {
                continue;
            }

            $path = trim($matches[1], " \t\n\r\0\x0B\"'");
            if ($path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }
}
