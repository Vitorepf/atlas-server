<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApStatusTaxonomyAudit
{
    private const SCHEMA_VERSION = 'atlas.ap_status_taxonomy_audit.v1';

    /**
     * @var array<int,string>
     */
    private const ALLOWED_STATUSES = [
        'draft',
        'planned',
        'implemented',
        'deprecated',
        'foundation-audit-implemented',
        'foundation-contract-implemented',
        'foundation-contract-planned',
        'foundation-read-model-implemented',
        'foundation-registry-implemented',
        'implemented-dedicated-curator-flow',
        'implemented-direct-surfaces',
        'implemented-filter-surface',
        'implemented_partial',
        'implemented_ready',
        'implemented-self-improvement-review',
        'proposed',
        'active',
    ];

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
            $status = $this->declaredStatus($file);
            if ($status === null || $status === '') {
                continue;
            }

            $valid = in_array($status, self::ALLOWED_STATUSES, true);
            $entry = [
                'ap_doc' => $this->relativePath($file),
                'status' => $status,
                'valid' => $valid,
            ];
            $entries[] = $entry;

            if (! $valid) {
                $violations[] = [
                    ...$entry,
                    'violation' => 'status_not_in_ap_taxonomy',
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $violations === [] ? 'ok' : 'attention',
            'mode' => 'read_only_audit',
            'authority' => 'ap_status_taxonomy_only_no_doc_writes',
            'docs_ap_path' => $this->relativePath($docsApPath),
            'allowed_statuses' => self::ALLOWED_STATUSES,
            'declared_status_count' => count($entries),
            'invalid_status_count' => count($violations),
            'status_counts' => $this->statusCounts($entries),
            'violations' => $violations,
            'entries' => $entries,
            'guardrails' => [
                'writes_files' => false,
                'normalizes_statuses' => false,
                'requires_frontmatter_for_all_aps' => false,
                'changes_static_scanner' => false,
                'blocks_on_unknown_declared_status' => true,
            ],
        ];
    }

    private function declaredStatus(string $file): ?string
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
        if (preg_match('/^status:\s*(.*?)\s*$/m', $frontmatter, $matches) !== 1) {
            return null;
        }

        return trim($matches[1], " \t\n\r\0\x0B\"'");
    }

    /**
     * @param  array<int,array<string,mixed>>  $entries
     * @return array<string,int>
     */
    private function statusCounts(array $entries): array
    {
        $counts = [];
        foreach ($entries as $entry) {
            $status = (string) $entry['status'];
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }
        ksort($counts);

        return $counts;
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
