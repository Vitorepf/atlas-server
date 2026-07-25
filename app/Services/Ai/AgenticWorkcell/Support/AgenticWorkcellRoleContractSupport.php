<?php

declare(strict_types=1);

namespace App\Services\Ai\AgenticWorkcell\Support;

use App\Services\Ai\EngineeringKernel\EngineeringRoleRoster;

/**
 * Pure role-contract helpers for AAWR workcell design.
 *
 * Extracted from AtlasAgenticWorkcellRuntimeService private pure residual:
 * depth profile by risk band, topology assignment map, role context scope,
 * output contract, tool boundary, task dependencies, expected artifacts, and
 * the full official role_roster envelope (membership fixed; depth varies).
 *
 * No I/O, no DI, no provider calls, no clock, no filesystem, no DB.
 */
final class AgenticWorkcellRoleContractSupport
{
    /** @var list<string> */
    public const FORBIDDEN_ACTIONS = [
        'spawn_provider_directly',
        'mutate_files_outside_ownership',
        'declare_completion_without_evidence',
    ];

    /** Roles that always receive independent_context on the roster. */
    /** @var list<string> */
    public const INDEPENDENT_CONTEXT_ROLES = [
        'qa_testing',
        'evidence_audit',
        'final_certification',
    ];

    private function __construct()
    {
    }

    public static function depthProfile(string $riskBand, string $role): string
    {
        if ($riskBand === 'R0') {
            return 'minimal_evidence';
        }
        if ($riskBand === 'R1') {
            return 'light_independent_review';
        }
        if ($riskBand === 'R2') {
            return 'standard_contract_integration';
        }
        if ($riskBand === 'R3') {
            return 'multi_verifier_regression_compatibility';
        }
        if ($riskBand === 'R4') {
            return in_array($role, ['appsec_privacy', 'performance_resilience', 'devops_sre', 'evidence_audit'], true)
                ? 'security_mutation_property_chaos_rollback'
                : 'deep_independent_regression';
        }

        return 'competing_candidates_different_family_disaster_drill';
    }

    /**
     * @return array<string,string>
     */
    public static function topologyAssignments(string $topology): array
    {
        $assignments = array_fill_keys(EngineeringRoleRoster::OFFICIAL_ROLES, 'supporting_review');
        $assignments['product_strategy'] = 'lead';
        $assignments['product_management'] = 'coordination';
        $assignments['architecture'] = $topology === 'forge_milestone_crew' ? 'lead_architecture' : 'design';
        $assignments['evidence_audit'] = 'independent_audit';
        $assignments['final_certification'] = 'final_certification';
        $assignments['qa_testing'] = 'independent_verification';

        return $assignments;
    }

    public static function roleContextScope(string $roleId): string
    {
        return match (true) {
            str_contains($roleId, 'verifier') || str_contains($roleId, 'critic') || str_contains($roleId, 'reviewer')
                || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification', 'outcome_analysis'], true) => 'read_only_evidence_and_outputs',
            str_contains($roleId, 'worker') || str_contains($roleId, 'builder') => 'owned_work_packet_only',
            str_contains($roleId, 'scout') || str_contains($roleId, 'cartographer') => 'retrieval_and_mapping_only',
            default => 'goal_and_coordination_context',
        };
    }

    /**
     * @return array<string,mixed>
     */
    public static function roleOutputContract(string $roleId): array
    {
        return [
            'must_return' => ['summary', 'evidence_refs', 'confidence', 'blockers', 'next_action'],
            'role_specific_artifact' => str_contains($roleId, 'verifier') || str_contains($roleId, 'critic')
                || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification'], true) ? 'verification_report' : 'work_product_or_findings',
            'forbidden_output' => ['unsupported_completion_claim', 'hidden_assumptions', 'raw_secret_or_credential'],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public static function roleToolBoundary(string $roleId, int $risk): array
    {
        $readOnly = str_contains($roleId, 'critic') || str_contains($roleId, 'auditor') || str_contains($roleId, 'reviewer') || str_contains($roleId, 'scout')
            || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification', 'outcome_analysis'], true);

        return [
            'read_only' => $readOnly,
            'writes_allowed' => ! $readOnly && $risk < 9,
            'requires_receipt' => true,
            'external_side_effects_allowed' => false,
        ];
    }

    /**
     * @return list<string>
     */
    public static function taskDependencies(string $roleId, int $index, string $leadTaskId): array
    {
        if ($index === 0) {
            return [];
        }
        if (str_contains($roleId, 'verifier') || str_contains($roleId, 'auditor') || str_contains($roleId, 'certifier') || str_contains($roleId, 'synthesizer') || str_contains($roleId, 'adjudicator') || str_contains($roleId, 'critic')
            || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification', 'outcome_analysis'], true)) {
            return [$leadTaskId];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public static function expectedArtifacts(string $roleId): array
    {
        return match (true) {
            str_contains($roleId, 'verifier') || in_array($roleId, ['qa_testing', 'evidence_audit', 'final_certification'], true) => ['verification_report', 'failed_or_passed_checks'],
            str_contains($roleId, 'critic') => ['risk_report', 'counterarguments'],
            str_contains($roleId, 'auditor') => ['evidence_manifest', 'missing_evidence'],
            str_contains($roleId, 'worker') || str_contains($roleId, 'builder') => ['work_product', 'changed_artifacts_or_plan'],
            default => ['findings', 'handoff_packet'],
        };
    }

    /**
     * Official 22-role roster envelope. Membership is fixed; depth/risk_band vary.
     *
     * @return list<array<string,mixed>>
     */
    public static function roleRoster(string $topology, string $domain, string $flowId, int $risk): array
    {
        $riskBand = AgenticWorkcellTopologyPolicySupport::riskBand($risk);
        $topologyAssignments = self::topologyAssignments($topology);

        return collect(EngineeringRoleRoster::OFFICIAL_ROLES)
            ->map(function (string $role, int $index) use ($domain, $flowId, $risk, $riskBand, $topologyAssignments): array {
                return [
                    'role_id' => $role,
                    'agent_index' => $index + 1,
                    'membership_source' => 'EngineeringRoleRoster::OFFICIAL_ROLES',
                    'risk_band' => $riskBand,
                    'depth' => self::depthProfile($riskBand, $role),
                    'topology_assignment' => $topologyAssignments[$role] ?? 'supporting_review',
                    'independent_context' => in_array($role, self::INDEPENDENT_CONTEXT_ROLES, true),
                    'domain' => $domain,
                    'flow_id' => $flowId,
                    'context_scope' => self::roleContextScope($role),
                    'output_contract' => self::roleOutputContract($role),
                    'tool_boundary' => self::roleToolBoundary($role, $risk),
                    'forbidden_actions' => self::FORBIDDEN_ACTIONS,
                ];
            })
            ->values()
            ->all();
    }
}
