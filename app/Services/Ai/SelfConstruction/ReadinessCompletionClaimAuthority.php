<?php

namespace App\Services\Ai\SelfConstruction;

final class ReadinessCompletionClaimAuthority
{
    /**
     * @param  list<string>  $failedCriteria
     * @return array<string, mixed>
     */
    public static function aliases(array $failedCriteria, string $currentRequiredOperatorArtifact): array
    {
        $missingCount = max(1, count($failedCriteria));
        $policy = [
            'schema_version' => 'atlas.self_construction.completion_claim_authority_aliases.v1',
            'mode' => 'read_only_completion_claim_authority_aliases',
            'status' => 'reject_external_completion_claim',
            'completion_authority' => 'atlas_self_construction_os_completion_audit',
            'required_completion_predicate' => 'completion_audit.status=complete AND completion_allowed=true AND failed_count=0',
            'external_agent_claim_accepted' => false,
            'external_agent_claim_can_mark_os_complete' => false,
            'external_agent_claim_can_override_audit' => false,
            'current_required_operator_artifact' => $currentRequiredOperatorArtifact,
            'current_failed_count' => $missingCount,
            'failed_criteria' => $failedCriteria,
        ];
        $policy['external_completion_claim_policy_hash'] = ReadinessHash::stable($policy);

        return [
            'completion_claim_authority' => $policy['completion_authority'],
            'completion_claim_required_completion_predicate' => $policy['required_completion_predicate'],
            'completion_claim_external_agent_claim_accepted' => false,
            'completion_claim_external_agent_claim_can_mark_os_complete' => false,
            'completion_claim_external_agent_claim_can_override_audit' => false,
            'external_completion_claim_policy_status' => $policy['status'],
            'external_completion_claim_policy_completion_authority' => $policy['completion_authority'],
            'external_completion_claim_policy_required_completion_predicate' => $policy['required_completion_predicate'],
            'external_completion_claim_policy_external_agent_claim_accepted' => false,
            'external_completion_claim_policy_external_agent_claim_can_mark_os_complete' => false,
            'external_completion_claim_policy_external_agent_claim_can_override_audit' => false,
            'external_completion_claim_policy_current_required_operator_artifact' => $currentRequiredOperatorArtifact,
            'external_completion_claim_policy_current_failed_count' => $missingCount,
            'external_completion_claim_policy_hash' => $policy['external_completion_claim_policy_hash'],
        ];
    }
}
