<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApFrontmatterAudit
{
    private const SCHEMA_VERSION = 'atlas.ap_frontmatter_audit.v1';

    /**
     * @return array<string,mixed>
     */
    public function audit(?string $docsApPath = null): array
    {
        $docsApPath ??= base_path('docs/ap');
        $files = glob(rtrim($docsApPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'AP-*.md') ?: [];
        sort($files);

        $entries = [];
        $violations = [];

        foreach ($files as $file) {
            $frontmatter = $this->frontmatter($file);
            if ($frontmatter === null) {
                continue;
            }

            $parsed = $this->parse($frontmatter);
            $entryViolations = [];

            foreach (['title', 'status'] as $requiredKey) {
                if (($parsed[$requiredKey] ?? '') === '') {
                    $entryViolations[] = 'missing_'.$requiredKey;
                }
            }

            if (
                array_key_exists('line_limit', $parsed)
                && (! preg_match('/^\d+$/', (string) $parsed['line_limit']) || (int) $parsed['line_limit'] < 1)
            ) {
                $entryViolations[] = 'line_limit_must_be_positive_integer';
            }

            foreach ($entryViolations as $violation) {
                $violations[] = [
                    'ap_doc' => $this->relativePath($file),
                    'violation' => $violation,
                ];
            }

            $entries[] = [
                'ap_doc' => $this->relativePath($file),
                'title' => $parsed['title'] ?? null,
                'status' => $parsed['status'] ?? null,
                'has_related_paths' => str_contains($frontmatter, "\nrelated_paths:"),
                'has_line_limit' => array_key_exists('line_limit', $parsed),
                'violation_count' => count($entryViolations),
                'violations' => $entryViolations,
            ];
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $violations === [] ? 'ok' : 'attention',
            'mode' => 'read_only_audit',
            'authority' => 'ap_frontmatter_shape_only_no_file_writes',
            'docs_ap_path' => $this->relativePath($docsApPath),
            'ap_with_frontmatter_count' => count($entries),
            'violation_count' => count($violations),
            'violations' => $violations,
            'entries' => $entries,
            'guardrails' => [
                'writes_files' => false,
                'normalizes_frontmatter' => false,
                'requires_frontmatter_for_all_aps' => false,
                'changes_static_scanner' => false,
                'blocks_on_malformed_declared_frontmatter' => true,
            ],
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
    private function parse(string $frontmatter): array
    {
        $parsed = [];
        $lines = preg_split('/\R/', $frontmatter) ?: [];

        foreach ($lines as $line) {
            if (preg_match('/^([a-zA-Z0-9_-]+):\s*(.*?)\s*$/', $line, $matches) !== 1) {
                continue;
            }

            $parsed[$matches[1]] = trim($matches[2], " \t\n\r\0\x0B\"'");
        }

        return $parsed;
    }

    private function relativePath(string $path): string
    {
        $base = rtrim(base_path(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
        $normalized = str_replace('\\', '/', $path);
        $normalizedBase = str_replace('\\', '/', $base);

        return str_starts_with($normalized, $normalizedBase)
            ? substr($normalized, strlen($normalizedBase))
            : $normalized;
    }
}
