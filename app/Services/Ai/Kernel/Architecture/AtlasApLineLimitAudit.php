<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApLineLimitAudit
{
    use ArchitecturePathHelper;

    private const SCHEMA_VERSION = 'atlas.ap_line_limit_audit.v1';

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $docsApPath = null): array
    {
        $docsApPath ??= base_path('docs/ap');
        $files = glob(rtrim($docsApPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'AP-*.md') ?: [];
        sort($files);

        $entries = [];
        $oversized = [];

        foreach ($files as $file) {
            $limit = $this->lineLimit($file);
            if ($limit === null) {
                continue;
            }

            $lineCount = $this->lineCount($file);
            $entry = [
                'ap_doc' => $this->relativePath($file),
                'line_count' => $lineCount,
                'line_limit' => $limit,
                'within_limit' => $lineCount <= $limit,
            ];
            $entries[] = $entry;

            if ($lineCount > $limit) {
                $oversized[] = [
                    ...$entry,
                    'excess_lines' => $lineCount - $limit,
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $oversized === [] ? 'ok' : 'attention',
            'mode' => 'read_only_audit',
            'authority' => 'ap_line_limit_only_no_file_writes',
            'docs_ap_path' => $this->relativePath($docsApPath),
            'ap_with_line_limit_count' => count($entries),
            'oversized_count' => count($oversized),
            'oversized_docs' => $oversized,
            'entries' => $entries,
            'guardrails' => [
                'writes_files' => false,
                'truncates_docs' => false,
                'changes_ap_docs' => false,
                'changes_static_scanner' => false,
                'blocks_on_declared_line_limit_overflow' => true,
            ],
        ];
    }

    private function lineLimit(string $file): ?int
    {
        $contents = file_get_contents($file);
        if (! is_string($contents) || ! str_starts_with($contents, "---\n")) {
            return null;
        }

        $end = strpos($contents, "\n---", 4);
        if ($end === false) {
            return null;
        }

        $frontmatter = substr($contents, 4, $end - 4);
        if (preg_match('/^line_limit:\s*(\d+)\s*$/m', $frontmatter, $matches) !== 1) {
            return null;
        }

        return max(1, (int) $matches[1]);
    }

    private function lineCount(string $file): int
    {
        $contents = file_get_contents($file);
        if (! is_string($contents) || $contents === '') {
            return 0;
        }

        return substr_count($contents, "\n") + (str_ends_with($contents, "\n") ? 0 : 1);
    }
}
