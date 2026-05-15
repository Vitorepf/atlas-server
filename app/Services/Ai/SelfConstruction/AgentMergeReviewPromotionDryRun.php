<?php

namespace App\Services\Ai\SelfConstruction;

/**
 * Plans a dry-run promotion of a merge review packet. Outputs the steps
 * that *would* run, the expected effects, the rollback steps and the
 * post-merge certification gates. Pure projection — never executes
 * a step, never applies a patch, never persists promotion state.
 */
final class AgentMergeReviewPromotionDryRun
{
    public const SCHEMA_VERSION = 'atlas.self_construction.agent_merge_review_promotion_dry_run.v1';

    public const MODE = 'read_only_agent_merge_review_promotion_dry_run';

    public const NON_EXECUTION_GUARANTEES = [
        'agent_merge_review_promotion_dry_run_does_not_apply_patch',
        'agent_merge_review_promotion_dry_run_does_not_modify_real_files',
        'agent_merge_review_promotion_dry_run_does_not_persist_promotion',
        'agent_merge_review_promotion_dry_run_does_not_advance_completion_claim',
        'agent_merge_review_promotion_dry_run_does_not_dispatch_agent',
        'agent_merge_review_promotion_dry_run_does_not_write_ledger',
    ];

    /**
     * @param  array<string, mixed>  $packet
     * @param  array<string, mixed>  $scopeVerification
     * @param  array<string, mixed>  $riskScore
     * @param  array<string, mixed>  $approvalPlan
     * @return array<string, mixed>
     */
    public function plan(array $packet, array $scopeVerification, array $riskScore, array $approvalPlan): array
    {
        $files = (array) data_get($packet, 'packet.files', []);
        $fileStats = (array) data_get($packet, 'packet.file_stats', []);
        $band = (string) (data_get($riskScore, 'risk.overall_band') ?? 'low');
        $blockers = (array) (data_get($riskScore, 'risk.blockers') ?? []);
        $approvalEligible = (bool) (data_get($approvalPlan, 'plan.approval_eligible') ?? false);

        $steps = [];
        $steps[] = $this->step('verify_baseline_certification', 'Re-run baseline certification before staging anything.', 'no');
        $steps[] = $this->step('stage_change_set_in_isolated_worktree', 'Stage the synthetic change set in an isolated worktree, never the real tree.', 'no');
        foreach ($files as $file) {
            $steps[] = $this->step(
                'simulate_change:'.((string) ($file['change_kind'] ?? 'modified')),
                'Simulate '.((string) ($file['change_kind'] ?? 'modified')).' on '.((string) ($file['path'] ?? 'unknown-path')).' inside the worktree projection.',
                'no',
                ['path' => (string) ($file['path'] ?? 'unknown-path'), 'change_kind' => (string) ($file['change_kind'] ?? 'modified')]
            );
        }
        $steps[] = $this->step('regenerate_evidence_bundle', 'Regenerate the evidence bundle from staged simulation.', 'no');
        $steps[] = $this->step('verify_scope_post_simulation', 'Verify scope and unsafe-path lists again after simulation.', 'no');
        $steps[] = $this->step('require_human_approval', 'Require human approval per plan; planner does not grant.', 'no');
        $steps[] = $this->step('promotion_gate_certification', 'Run promotion gate certification status; it must return read-only.', 'no');
        $steps[] = $this->step('skip_real_apply', 'Skip the real apply step — promotion is dry-run only.', 'no');

        $expectedEffects = [
            ['effect' => 'no_real_file_change', 'observed_change_kind' => 'projection_only'],
            ['effect' => 'no_ledger_entry', 'observed_change_kind' => 'projection_only'],
            ['effect' => 'no_provider_dispatch', 'observed_change_kind' => 'projection_only'],
            ['effect' => 'no_completion_claim_advance', 'observed_change_kind' => 'projection_only'],
            ['effect' => 'human_approval_pending', 'observed_change_kind' => 'projection_only'],
        ];

        $rollbackSteps = [];
        foreach ($files as $file) {
            $kind = (string) ($file['change_kind'] ?? 'modified');
            $rollbackSteps[] = $this->step(
                'rollback_simulation:'.$kind,
                'Reverse the simulated '.$kind.' on '.((string) ($file['path'] ?? 'unknown-path')),
                'no',
                ['path' => (string) ($file['path'] ?? 'unknown-path'), 'change_kind' => $kind]
            );
        }
        $rollbackSteps[] = $this->step('discard_isolated_worktree', 'Discard the isolated worktree once dry-run finishes.', 'no');

        $postMergeGates = [
            'chain_integrity_status_must_stay_available',
            'deterministic_replay_status_must_stay_available',
            'docs_health_must_not_regress',
            'architecture_validate_must_not_regress',
            'macro_sprint_promotion_gate_remains_blocked_until_runtime',
        ];

        $promotionAllowed = false; // hard-law: always false during dry-run
        $criticalBlockers = $band === 'critical' || count($blockers) > 0 || ! $approvalEligible;
        $status = $criticalBlockers
            ? 'agent_merge_review_promotion_dry_run_blocked'
            : 'agent_merge_review_promotion_dry_run_planned';

        $envelope = [
            'schema_version' => self::SCHEMA_VERSION,
            'status' => $status,
            'mode' => self::MODE,
            'promotion_allowed' => $promotionAllowed,
            'apply_patch_allowed' => false,
            'real_file_write_allowed' => false,
            'completion_claim_allowed' => false,
            'dispatch_allowed' => false,
            'ledger_write_allowed' => false,
            'plan' => [
                'packet_id' => (string) (data_get($packet, 'packet.packet_id') ?? 'agent-merge-review-packet-unknown'),
                'file_count' => (int) ($fileStats['file_count'] ?? count($files)),
                'risk_band' => $band,
                'risk_blockers' => array_values(array_unique(array_map(static fn ($b) => (string) $b, $blockers))),
                'approval_eligible' => $approvalEligible,
                'critical_blockers_present' => $criticalBlockers,
                'steps' => $steps,
                'step_count' => count($steps),
                'expected_effects' => $expectedEffects,
                'rollback_steps' => $rollbackSteps,
                'rollback_step_count' => count($rollbackSteps),
                'post_merge_gates' => $postMergeGates,
                'post_merge_gate_count' => count($postMergeGates),
            ],
            'non_execution_guarantees' => self::NON_EXECUTION_GUARANTEES,
        ];

        $envelope['dry_run_hash'] = $this->hashEnvelope($envelope);

        return $envelope;
    }

    /**
     * @param  array<string, mixed>  $extra
     * @return array<string, mixed>
     */
    private function step(string $name, string $description, string $sideEffect, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            'description' => $description,
            'has_side_effect' => $sideEffect !== 'no',
            'side_effect_kind' => $sideEffect,
        ], $extra);
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function hashEnvelope(array $envelope): string
    {
        $copy = $envelope;
        unset($copy['dry_run_hash']);

        return hash('sha256', (string) json_encode($copy, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
