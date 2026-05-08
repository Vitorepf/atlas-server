<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApDocumentationGovernanceRegistry
{
    private const SCHEMA_VERSION = 'atlas.ap_documentation_governance_registry.v1';

    public function __construct(
        private readonly AtlasApNumberRegistryAudit $numberRegistry,
        private readonly AtlasApImplementationLinkAudit $implementationLinks,
        private readonly AtlasApLineLimitAudit $lineLimits,
        private readonly AtlasApFrontmatterAudit $frontmatter,
        private readonly AtlasApStatusTaxonomyAudit $statusTaxonomy,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function summary(?string $docsApPath = null): array
    {
        $numberAudit = $this->numberRegistry->audit($docsApPath);
        $linkAudit = $this->implementationLinks->audit($docsApPath);
        $lineLimitAudit = $this->lineLimits->audit($docsApPath);
        $frontmatterAudit = $this->frontmatter->audit($docsApPath);
        $statusTaxonomyAudit = $this->statusTaxonomy->audit($docsApPath);
        $blockers = $this->blockers($numberAudit, $linkAudit, $lineLimitAudit, $frontmatterAudit, $statusTaxonomyAudit);

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $blockers === [] ? 'ok' : 'attention',
            'mode' => 'read_only_registry',
            'authority' => 'ap_documentation_governance_only_no_file_writes',
            'docs_ap_path' => $numberAudit['docs_ap_path'] ?? 'docs/ap',
            'audit_count' => 5,
            'audits' => [
                'number_registry' => [
                    'schema_version' => $numberAudit['schema_version'],
                    'status' => $numberAudit['status'],
                    'ap_count' => $numberAudit['ap_count'],
                    'duplicate_number_count' => $numberAudit['duplicate_number_count'],
                    'duplicate_slug_count' => $numberAudit['duplicate_slug_count'],
                    'malformed_count' => $numberAudit['malformed_count'],
                    'next_suggested_number' => $numberAudit['next_suggested_number'],
                ],
                'implementation_links' => [
                    'schema_version' => $linkAudit['schema_version'],
                    'status' => $linkAudit['status'],
                    'ap_with_related_paths_count' => $linkAudit['ap_with_related_paths_count'],
                    'missing_path_count' => $linkAudit['missing_path_count'],
                ],
                'line_limits' => [
                    'schema_version' => $lineLimitAudit['schema_version'],
                    'status' => $lineLimitAudit['status'],
                    'ap_with_line_limit_count' => $lineLimitAudit['ap_with_line_limit_count'],
                    'oversized_count' => $lineLimitAudit['oversized_count'],
                ],
                'frontmatter' => [
                    'schema_version' => $frontmatterAudit['schema_version'],
                    'status' => $frontmatterAudit['status'],
                    'ap_with_frontmatter_count' => $frontmatterAudit['ap_with_frontmatter_count'],
                    'violation_count' => $frontmatterAudit['violation_count'],
                ],
                'status_taxonomy' => [
                    'schema_version' => $statusTaxonomyAudit['schema_version'],
                    'status' => $statusTaxonomyAudit['status'],
                    'declared_status_count' => $statusTaxonomyAudit['declared_status_count'],
                    'invalid_status_count' => $statusTaxonomyAudit['invalid_status_count'],
                ],
            ],
            'blocker_count' => count($blockers),
            'blockers' => $blockers,
            'next_action' => $blockers === []
                ? 'continue_ap_development_with_next_suggested_number'
                : 'repair_ap_documentation_governance_blockers_before_new_ap',
            'next_suggested_number' => $numberAudit['next_suggested_number'],
            'details' => [
                'number_registry' => $numberAudit,
                'implementation_links' => $linkAudit,
                'line_limits' => $lineLimitAudit,
                'frontmatter' => $frontmatterAudit,
                'status_taxonomy' => $statusTaxonomyAudit,
            ],
            'guardrails' => [
                'writes_files' => false,
                'renumbers_files' => false,
                'creates_missing_files' => false,
                'changes_static_scanner' => false,
                'safe_for_architecture_readiness_embedding' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $numberAudit
     * @param  array<string,mixed>  $linkAudit
     * @param  array<string,mixed>  $lineLimitAudit
     * @param  array<string,mixed>  $frontmatterAudit
     * @return array<int,array<string,mixed>>
     */
    private function blockers(array $numberAudit, array $linkAudit, array $lineLimitAudit, array $frontmatterAudit, array $statusTaxonomyAudit): array
    {
        $blockers = [];

        foreach ([
            'duplicate_number_count' => 'duplicate_ap_numbers',
            'duplicate_slug_count' => 'duplicate_ap_slugs',
            'malformed_count' => 'malformed_ap_filenames',
        ] as $key => $reason) {
            $count = (int) ($numberAudit[$key] ?? 0);
            if ($count > 0) {
                $blockers[] = [
                    'reason' => $reason,
                    'count' => $count,
                    'source_audit' => 'number_registry',
                ];
            }
        }

        $missingPathCount = (int) ($linkAudit['missing_path_count'] ?? 0);
        if ($missingPathCount > 0) {
            $blockers[] = [
                'reason' => 'missing_ap_related_paths',
                'count' => $missingPathCount,
                'source_audit' => 'implementation_links',
            ];
        }

        $oversizedCount = (int) ($lineLimitAudit['oversized_count'] ?? 0);
        if ($oversizedCount > 0) {
            $blockers[] = [
                'reason' => 'ap_line_limit_overflow',
                'count' => $oversizedCount,
                'source_audit' => 'line_limits',
            ];
        }

        $frontmatterViolationCount = (int) ($frontmatterAudit['violation_count'] ?? 0);
        if ($frontmatterViolationCount > 0) {
            $blockers[] = [
                'reason' => 'ap_frontmatter_shape_violation',
                'count' => $frontmatterViolationCount,
                'source_audit' => 'frontmatter',
            ];
        }

        $invalidStatusCount = (int) ($statusTaxonomyAudit['invalid_status_count'] ?? 0);
        if ($invalidStatusCount > 0) {
            $blockers[] = [
                'reason' => 'ap_status_taxonomy_violation',
                'count' => $invalidStatusCount,
                'source_audit' => 'status_taxonomy',
            ];
        }

        return $blockers;
    }
}
