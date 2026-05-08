<?php

namespace App\Services\Ai\Kernel\Architecture;

final class AtlasApCreationDecisionContract
{
    private const SCHEMA_VERSION = 'atlas.ap_creation_decision_contract.v1';

    public function __construct(
        private readonly AtlasApDocumentationGovernanceRegistry $governance,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function decide(?string $docsApPath = null, ?int $requestedNumber = null, ?string $proposedSlug = null): array
    {
        $summary = $this->governance->summary($docsApPath);
        $nextSuggestedNumber = (int) $summary['next_suggested_number'];
        $violations = [];

        if ($summary['blockers'] !== []) {
            $violations[] = [
                'reason' => 'ap_documentation_governance_blocked',
                'source' => 'ap_documentation_governance_registry',
                'blocker_count' => $summary['blocker_count'],
            ];
        }

        if ($requestedNumber !== null && $requestedNumber !== $nextSuggestedNumber) {
            $violations[] = [
                'reason' => 'requested_ap_number_must_match_next_suggested_number',
                'requested_number' => $requestedNumber,
                'next_suggested_number' => $nextSuggestedNumber,
            ];
        }

        if ($proposedSlug !== null && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $proposedSlug) !== 1) {
            $violations[] = [
                'reason' => 'proposed_ap_slug_must_be_lowercase_kebab_case',
                'proposed_slug' => $proposedSlug,
            ];
        }

        $allowed = $violations === [];
        $selectedNumber = $requestedNumber ?? $nextSuggestedNumber;

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $allowed ? 'allowed' : 'blocked',
            'mode' => 'read_only_decision_contract',
            'authority' => 'ap_creation_decision_only_no_file_writes',
            'allowed' => $allowed,
            'requested_number' => $requestedNumber,
            'next_suggested_number' => $nextSuggestedNumber,
            'selected_number' => $selectedNumber,
            'proposed_slug' => $proposedSlug,
            'recommended_doc_path' => $this->recommendedDocPath($selectedNumber, $proposedSlug),
            'violations' => $violations,
            'governance' => [
                'schema_version' => $summary['schema_version'],
                'status' => $summary['status'],
                'blocker_count' => $summary['blocker_count'],
                'blockers' => $summary['blockers'],
            ],
            'next_action' => $allowed
                ? 'create_ap_doc_with_selected_number_and_declared_scope'
                : 'repair_governance_or_use_next_suggested_ap_number_before_creation',
            'guardrails' => [
                'writes_files' => false,
                'creates_ap_doc' => false,
                'renumbers_files' => false,
                'bypasses_governance_registry' => false,
                'requires_human_or_agent_to_write_doc_after_decision' => true,
            ],
        ];
    }

    private function recommendedDocPath(int $number, ?string $slug): ?string
    {
        if ($slug === null || preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', $slug) !== 1) {
            return null;
        }

        return sprintf('docs/ap/AP-%03d-%s.md', $number, $slug);
    }
}
