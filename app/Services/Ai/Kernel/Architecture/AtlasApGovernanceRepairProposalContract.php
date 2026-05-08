<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApGovernanceRepairProposalContract
{
    private const SCHEMA_VERSION = 'atlas.ap_governance_repair_proposal_contract.v1';

    public function __construct(
        private readonly AtlasApDocumentationGovernanceRegistry $governance,
        private readonly AtlasApDependencyMap $dependencies,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function proposals(?string $docsApPath = null): array
    {
        $governance = $this->governance->summary($docsApPath);
        $dependencies = $this->dependencies->map($docsApPath);
        $proposals = [
            ...$this->governanceProposals($governance),
            ...$this->missingReferenceProposals($dependencies),
        ];

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $proposals === [] ? 'ok' : 'attention',
            'mode' => 'proposal_only',
            'authority' => 'ap_governance_repair_proposals_only_no_file_writes',
            'docs_ap_path' => $governance['docs_ap_path'] ?? ($dependencies['docs_ap_path'] ?? 'docs/ap'),
            'proposal_count' => count($proposals),
            'proposals' => $proposals,
            'source_reports' => [
                'governance' => [
                    'schema_version' => $governance['schema_version'],
                    'status' => $governance['status'],
                    'blocker_count' => $governance['blocker_count'],
                    'next_action' => $governance['next_action'],
                ],
                'dependency_map' => [
                    'schema_version' => $dependencies['schema_version'],
                    'status' => $dependencies['status'],
                    'missing_reference_count' => $dependencies['missing_reference_count'],
                    'next_action' => $dependencies['next_action'],
                ],
            ],
            'next_action' => $proposals === []
                ? 'continue_ap_development_with_documentation_governance_clean'
                : 'review_repair_proposals_before_creating_or_editing_ap_docs',
            'guardrails' => [
                'writes_files' => false,
                'applies_repairs' => false,
                'creates_ap_docs' => false,
                'removes_ap_references' => false,
                'renumbers_files' => false,
                'requires_human_review' => true,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $governance
     * @return array<int,array<string,mixed>>
     */
    private function governanceProposals(array $governance): array
    {
        $proposals = [];
        foreach ((array) ($governance['blockers'] ?? []) as $blocker) {
            $reason = (string) ($blocker['reason'] ?? 'unknown_ap_governance_blocker');
            $sourceAudit = (string) ($blocker['source_audit'] ?? 'unknown');
            $sourceRefs = $this->sourceRefsForGovernanceBlocker($reason, $governance);

            $proposals[] = [
                'proposal_id' => $this->proposalId($reason, $sourceRefs),
                'type' => 'ap_documentation_governance_repair',
                'reason' => $reason,
                'severity' => 'blocking',
                'source_audit' => $sourceAudit,
                'count' => (int) ($blocker['count'] ?? count($sourceRefs)),
                'recommendation' => $this->recommendationForGovernanceReason($reason),
                'source_refs' => $sourceRefs,
            ];
        }

        return $proposals;
    }

    /**
     * @param  array<string,mixed>  $dependencies
     * @return array<int,array<string,mixed>>
     */
    private function missingReferenceProposals(array $dependencies): array
    {
        $proposals = [];
        foreach ((array) ($dependencies['missing_references'] ?? []) as $reference) {
            $sourceRefs = [[
                'from_ap' => $reference['from_ap'] ?? null,
                'from_doc' => $reference['from_doc'] ?? null,
                'to_ap' => $reference['to_ap'] ?? null,
                'sources' => array_values((array) ($reference['sources'] ?? [])),
            ]];

            $proposals[] = [
                'proposal_id' => $this->proposalId('repair_missing_ap_reference', $sourceRefs),
                'type' => 'ap_dependency_reference_repair',
                'reason' => 'repair_missing_ap_reference',
                'severity' => 'blocking',
                'recommendation' => 'human_review_should_create_missing_ap_or_remove_stale_reference',
                'source_refs' => $sourceRefs,
            ];
        }

        return $proposals;
    }

    /**
     * @param  array<string,mixed>  $governance
     * @return array<int,array<string,mixed>>
     */
    private function sourceRefsForGovernanceBlocker(string $reason, array $governance): array
    {
        $details = (array) ($governance['details'] ?? []);

        return match ($reason) {
            'duplicate_ap_numbers' => (array) data_get($details, 'number_registry.duplicate_numbers', []),
            'duplicate_ap_slugs' => (array) data_get($details, 'number_registry.duplicate_slugs', []),
            'malformed_ap_filenames' => (array) data_get($details, 'number_registry.malformed_files', []),
            'missing_ap_related_paths' => (array) data_get($details, 'implementation_links.missing_paths', []),
            'ap_line_limit_overflow' => (array) data_get($details, 'line_limits.oversized_docs', []),
            'ap_frontmatter_shape_violation' => (array) data_get($details, 'frontmatter.violations', []),
            'ap_status_taxonomy_violation' => (array) data_get($details, 'status_taxonomy.violations', []),
            default => [],
        };
    }

    private function recommendationForGovernanceReason(string $reason): string
    {
        return match ($reason) {
            'duplicate_ap_numbers' => 'human_review_should_choose_single_canonical_ap_number_and_renumber_or_merge_duplicate',
            'duplicate_ap_slugs' => 'human_review_should_choose_single_canonical_slug_and_rename_or_merge_duplicate',
            'malformed_ap_filenames' => 'human_review_should_rename_ap_file_to_AP_number_slug_md',
            'missing_ap_related_paths' => 'human_review_should_restore_related_path_or_remove_stale_related_path',
            'ap_line_limit_overflow' => 'human_review_should_split_or_compress_doc_before_more_work',
            'ap_frontmatter_shape_violation' => 'human_review_should_complete_declared_frontmatter_fields',
            'ap_status_taxonomy_violation' => 'human_review_should_replace_status_with_allowed_ap_taxonomy_value',
            default => 'human_review_should_repair_ap_documentation_governance_blocker',
        };
    }

    /**
     * @param  array<int,array<string,mixed>>  $sourceRefs
     */
    private function proposalId(string $reason, array $sourceRefs): string
    {
        return 'ap_repair_'.substr(sha1($reason.'|'.json_encode($sourceRefs, JSON_THROW_ON_ERROR)), 0, 16);
    }
}
