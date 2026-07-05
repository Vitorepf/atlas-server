<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Builds a rollback plan for an execution workspace preview without
 * executing rollback operations.
 *
 * Rollback classification:
 *   safe_revert          — pure additive test/code changes (new files, test-only)
 *   behavior_parity     — behavior-changing service edits require parity checks
 *   manual_escalation   — destructive or missing-preview changes
 */
final class AgentControlPlaneRollbackPlanBuilder
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_control_plane_rollback_plan.v1';

    public const MODE = 'read_only_agent_control_plane_rollback_plan';

    public const ROLLBACK_CLASS_SAFE_REVERT = 'safe_revert';
    public const ROLLBACK_CLASS_BEHAVIOR_PARITY = 'behavior_parity';
    public const ROLLBACK_CLASS_MANUAL_ESCALATION = 'manual_escalation';

    /** @return array<string, mixed> */
    public function build(array $workspacePlan, array $diffPreview): array
    {
        $steps = [];
        $artifacts = (array) ($diffPreview['artifacts'] ?? []);

        // Missing preview → manual escalation for the entire plan.
        if ($artifacts === []) {
            $payload = [
                'schema_version' => self::SCHEMA_VERSION,
                'mode' => self::MODE,
                'status' => 'rollback_plan_empty',
                'workspace_plan_id' => (string) ($workspacePlan['workspace_plan_id'] ?? ''),
                'step_count' => 0,
                'steps' => [],
                'rollback_class' => self::ROLLBACK_CLASS_MANUAL_ESCALATION,
                'rollback_reason' => 'no_diff_preview_artifacts_provided',
                'behavior_parity_checks' => [],
                'automatic_rollback_allowed' => false,
                'real_file_write_allowed' => false,
                'patch_apply_allowed' => false,
                'dispatch_allowed' => false,
                'provider_call_allowed' => false,
                'token_spend_allowed' => false,
                'ledger_write_allowed' => false,
                'self_programming_allowed' => false,
            ];
            $payload['rollback_plan_hash'] = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $payload;
        }

        $planRollbackClass = self::ROLLBACK_CLASS_SAFE_REVERT;
        $behaviorParityChecks = [];

        foreach ($artifacts as $artifact) {
            $path = (string) ($artifact['path'] ?? '');
            if ($path === '') {
                continue;
            }

            $changeType = (string) ($artifact['change_type'] ?? 'modified');
            $isTestFile = str_starts_with($path, 'tests/') || str_ends_with($path, 'Test.php');
            $isNewFile = $changeType === 'added' || ($artifact['is_new'] ?? false);
            $isDestructive = $changeType === 'deleted' || ($artifact['is_destructive'] ?? false);

            // Classify this artifact's rollback class.
            $stepClass = match (true) {
                $isDestructive => self::ROLLBACK_CLASS_MANUAL_ESCALATION,
                $isTestFile && $isNewFile => self::ROLLBACK_CLASS_SAFE_REVERT,
                $isNewFile => self::ROLLBACK_CLASS_SAFE_REVERT,
                $isTestFile => self::ROLLBACK_CLASS_SAFE_REVERT,
                default => self::ROLLBACK_CLASS_BEHAVIOR_PARITY,
            };

            // Escalate the plan class if any step is more severe.
            if ($stepClass === self::ROLLBACK_CLASS_MANUAL_ESCALATION) {
                $planRollbackClass = self::ROLLBACK_CLASS_MANUAL_ESCALATION;
            } elseif ($stepClass === self::ROLLBACK_CLASS_BEHAVIOR_PARITY && $planRollbackClass === self::ROLLBACK_CLASS_SAFE_REVERT) {
                $planRollbackClass = self::ROLLBACK_CLASS_BEHAVIOR_PARITY;
            }

            $verificationCommand = $isTestFile
                ? 'php artisan test ' . escapeshellarg($path)
                : 'php artisan test --filter=' . basename($path, '.php');

            $steps[] = [
                'path' => $path,
                'rollback_action' => $stepClass === self::ROLLBACK_CLASS_MANUAL_ESCALATION
                    ? 'manual_escalation_required'
                    : 'restore_from_operator_reviewed_baseline',
                'rollback_class' => $stepClass,
                'verification_command' => $verificationCommand,
                'requires_human_approval' => $stepClass !== self::ROLLBACK_CLASS_SAFE_REVERT,
                'automatic_rollback_allowed' => $stepClass === self::ROLLBACK_CLASS_SAFE_REVERT,
            ];

            if ($stepClass === self::ROLLBACK_CLASS_BEHAVIOR_PARITY) {
                $behaviorParityChecks[] = [
                    'path' => $path,
                    'required_check' => 'behavior_parity_test_must_pass_before_release',
                    'verification_command' => $verificationCommand,
                ];
            }
        }

        $rollbackReason = match ($planRollbackClass) {
            self::ROLLBACK_CLASS_SAFE_REVERT => 'all_changes_are_additive_or_test_only',
            self::ROLLBACK_CLASS_BEHAVIOR_PARITY => 'behavior_changing_service_edits_require_parity_verification',
            self::ROLLBACK_CLASS_MANUAL_ESCALATION => 'destructive_or_missing_preview_changes_require_manual_escalation',
            default => 'unknown',
        };

        $payload = [
            'schema_version' => self::SCHEMA_VERSION,
            'mode' => self::MODE,
            'status' => 'rollback_plan_ready',
            'workspace_plan_id' => (string) ($workspacePlan['workspace_plan_id'] ?? ''),
            'step_count' => count($steps),
            'steps' => $steps,
            'rollback_class' => $planRollbackClass,
            'rollback_reason' => $rollbackReason,
            'behavior_parity_checks' => $behaviorParityChecks,
            'automatic_rollback_allowed' => $planRollbackClass === self::ROLLBACK_CLASS_SAFE_REVERT,
            'real_file_write_allowed' => false,
            'patch_apply_allowed' => false,
            'dispatch_allowed' => false,
            'provider_call_allowed' => false,
            'token_spend_allowed' => false,
            'ledger_write_allowed' => false,
            'self_programming_allowed' => false,
        ];
        $payload['rollback_plan_hash'] = hash('sha256', (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $payload;
    }
}
