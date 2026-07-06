<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Plans the human approval policy for a merge review packet. Pure
 * projection — never grants approval, never persists approval state,
 * never advances a slice pointer.
 *
 * APPROVAL MODES (in escalation order):
 *   autonomous_approval — low-risk clean-scope packets with fresh executable proof
 *   single              — medium risk or one blocking condition
 *   double              — high risk or two blocking conditions
 *   quorum              — critical risk or multiple unsafe conditions
 */
final class AgentMergeReviewHumanApprovalPlanner
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_human_approval_plan.v1';

    public const MODE = 'read_only_agent_merge_review_human_approval_plan';

    public const APPROVAL_MODES = ['autonomous_approval', 'single', 'double', 'quorum'];

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_human_approval_planner_does_not_grant_approval',
        'agent_merge_review_human_approval_planner_does_not_persist_state',
        'agent_merge_review_human_approval_planner_does_not_apply_patch',
        'agent_merge_review_human_approval_planner_does_not_advance_completion_claim',
        'agent_merge_review_human_approval_planner_does_not_write_ledger',
    ];

    public const DEFAULT_APPROVERS = [
        'low' => ['operator'],
        'medium' => ['operator', 'reviewer'],
        'high' => ['operator', 'reviewer', 'security'],
        'critical' => ['operator', 'reviewer', 'security', 'release_manager'],
    ];

    /** Patterns that require human approval regardless of risk band. */
    private const UNSAFE_PATH_PREFIXES = [
        'app/Console/',
        'app/Exceptions/',
        'app/Http/Controllers/',
        'bootstrap/',
        'config/',
        'database/migrations/',
        'database/seeders/',
        'routes/',
        'storage/',
        '.env',
    ];

    private const MIGRATION_PATTERN = '#database/migrations/#i';

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $scopeVerification
     * @param  array<string, mixed>  $riskScore
     * @param  array<string, mixed>  $options
     * @return array<string, mixed>
     */
    public function plan(array $packet, array $scopeVerification, array $riskScore, array $options = []): array
    {
        $band = (string) (data_get($riskScore, 'risk.overall_band') ?? 'low');
        $blockers = (array) (data_get($riskScore, 'risk.blockers') ?? []);

        // Detect conditions that require human approval.
        $humanApprovalReasons = $this->detectHumanApprovalConditions($packet, $scopeVerification, $options);

        $hasFreshExecutableProof = (bool) ($options['has_fresh_executable_proof'] ?? false);
        $hasScopeVerification = $scopeVerification['verification'] ?? null;
        $isCleanScope = is_array($hasScopeVerification)
            && (int) (data_get($scopeVerification, 'verification.forbidden_violation_count') ?? 0) === 0
            && (int) (data_get($scopeVerification, 'verification.cross_axis_violation_count') ?? 0) === 0
            && (int) (data_get($scopeVerification, 'verification.unsafe_path_violation_count') ?? 0) === 0;

        // Determine approval mode.
        $canUseAutonomous = $band === 'low'
            && $humanApprovalReasons === []
            && $hasFreshExecutableProof
            && $isCleanScope
            && count($blockers) === 0;

        if ($canUseAutonomous) {
            $mode = 'autonomous_approval';
            $approvers = ['operator'];
        } else {
            $mode = match ($band) {
                'critical' => 'quorum',
                'high' => 'double',
                'medium' => 'double',
                default => 'single',
            };
            $approvers = self::DEFAULT_APPROVERS[$band] ?? ['operator'];
        }

        $overrideApprovers = (array) ($options['required_approvers'] ?? []);
        if ($overrideApprovers !== [] && $mode !== 'autonomous_approval') {
            $approvers = array_values(array_unique(array_map(static fn ($a) => (string) $a, $overrideApprovers)));
        }

        $blockingConditions = [];
        foreach ($blockers as $blocker) {
            $blockingConditions[] = [
                'name' => (string) $blocker,
                'kind' => 'risk_blocker',
                'must_clear_before_approval' => true,
            ];
        }
        foreach ($humanApprovalReasons as $reason) {
            $blockingConditions[] = [
                'name' => $reason,
                'kind' => 'human_approval_required',
                'must_clear_before_approval' => true,
            ];
        }

        $verification = (array) ($scopeVerification['verification'] ?? []);
        // Fail-closed: absent or empty scope verification is never treated as verified-clean.
        if ($verification === []) {
            $blockingConditions[] = [
                'name' => 'scope_verification_missing',
                'kind' => 'scope_blocker',
                'must_clear_before_approval' => true,
            ];
        } else {
            if ((int) ($verification['forbidden_violation_count'] ?? 0) > 0) {
                $blockingConditions[] = [
                    'name' => 'forbidden_violation_present',
                    'kind' => 'scope_blocker',
                    'must_clear_before_approval' => true,
                ];
            }
            if ((int) ($verification['cross_axis_violation_count'] ?? 0) > 0) {
                $blockingConditions[] = [
                    'name' => 'cross_axis_violation_present',
                    'kind' => 'scope_blocker',
                    'must_clear_before_approval' => true,
                ];
            }
            if ((int) ($verification['unsafe_path_violation_count'] ?? 0) > 0) {
                $blockingConditions[] = [
                    'name' => 'unsafe_path_present',
                    'kind' => 'scope_blocker',
                    'must_clear_before_approval' => true,
                ];
            }
        }
        $blockingConditions = $this->dedupRows($blockingConditions, ['name']);

        $approvalAllowed = $band !== 'critical' && count($blockingConditions) === 0;
        $packetId = (string) (data_get($packet, 'packet.packet_id') ?? 'agent-merge-review-packet-unknown');

        $status = $canUseAutonomous ? 'autonomous_approval_ready' : ($approvalAllowed ? 'agent_merge_review_human_approval_plan_ready' : 'agent_merge_review_human_approval_blocked');

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'application_mode' => $mode,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'approval_granted' => false,
            'approval_persisted' => false,
            'plan' => [
                'packet_id' => $packetId,
                'risk_band' => $band,
                'approval_mode' => $mode,
                'required_approvers' => array_values(array_unique($approvers)),
                'required_approver_count' => count(array_unique($approvers)),
                'justification' => $this->justification($band, $blockingConditions, $verification, $mode, $humanApprovalReasons),
                'blocking_conditions' => $blockingConditions,
                'blocking_condition_count' => count($blockingConditions),
                'approval_eligible' => $approvalAllowed,
                'human_approval_reasons' => $humanApprovalReasons,
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['plan_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    /**
     * Detect conditions that require human approval.
     *
     * @param  array<string,mixed>  $packet
     * @param  array<string,mixed>  $scopeVerification
     * @param  array<string,mixed>  $options
     * @return list<string>
     */
    private function detectHumanApprovalConditions(array $packet, array $scopeVerification, array $options): array
    {
        $reasons = [];

        // Migration files require human review.
        $allowedFiles = (array) (data_get($packet, 'packet.allowed_files') ?? data_get($packet, 'allowed_files') ?? []);
        foreach ($allowedFiles as $file) {
            if ((bool) preg_match(self::MIGRATION_PATTERN, (string) $file)) {
                $reasons[] = 'migration_detected_in_allowed_files';
                break;
            }
        }

        // Destructive deletes.
        if (! empty($options['contains_destructive_delete'])) {
            $reasons[] = 'destructive_delete_detected';
        }

        // Unsafe path prefixes.
        $changes = (array) (data_get($packet, 'packet.changes') ?? data_get($packet, 'changes') ?? []);
        foreach ($changes as $change) {
            $path = (string) ($change['path'] ?? '');
            foreach (self::UNSAFE_PATH_PREFIXES as $prefix) {
                if (str_starts_with($path, $prefix)) {
                    $reasons[] = 'unsafe_path_prefix_in_changes:'.$prefix;
                    break 2;
                }
            }
        }

        // Stale evidence.
        if (! empty($options['stale_evidence'])) {
            $reasons[] = 'stale_evidence_detected';
        }

        return array_values(array_unique($reasons));
    }

    /**
     * @param  list<array<string, mixed>>  $blockingConditions
     * @param  array<string, mixed>  $verification
     * @param  list<string>  $humanApprovalReasons
     */
    private function justification(string $band, array $blockingConditions, array $verification, string $mode, array $humanApprovalReasons): string
    {
        if ($mode === 'autonomous_approval') {
            return 'autonomous_approval_low_risk_clean_scope_fresh_proof';
        }
        if (count($blockingConditions) > 0) {
            return 'human_approval_blocked_until_conditions_cleared';
        }
        if ($band === 'critical') {
            return 'critical_band_requires_quorum_even_without_blockers';
        }
        if ((int) ($verification['out_of_scope_count'] ?? 0) > 0) {
            return 'out_of_scope_paths_present_requires_extra_review';
        }

        return 'human_approval_plan_aligned_with_risk_band';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return list<array<string, mixed>>
     */
    private function dedupRows(array $rows, array $keys): array
    {
        $seen = [];
        $out = [];
        foreach ($rows as $row) {
            $signature = implode('|', array_map(static fn ($k) => (string) ($row[$k] ?? ''), $keys));
            if (isset($seen[$signature])) {
                continue;
            }
            $seen[$signature] = true;
            $out[] = $row;
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['plan_hash']);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
