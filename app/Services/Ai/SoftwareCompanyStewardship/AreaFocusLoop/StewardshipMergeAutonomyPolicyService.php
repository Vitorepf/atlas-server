<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AP-774 · Stewardship Merge Autonomy Policy.
 *
 * Central, deterministic policy for deciding when the stewardship loop may
 * auto-merge. It does not inspect git or perform merges; AP-769 owns that. This
 * service only turns branch evidence into an auditable autonomy decision.
 */
final class StewardshipMergeAutonomyPolicyService
{
    public const DECISION_SCHEMA = 'atlas.software_company_stewardship.merge_autonomy_policy.v1';

    public const DEFAULT_AREA_ID = 'agentic_engineering_os';

    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $validation
     * @param  list<string>  $changedFiles
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function decide(array $classification, array $validation, array $changedFiles, int $branchOnly, array $blockers, array $input): array
    {
        $areaId = $this->slug((string) ($input['area_id'] ?? self::DEFAULT_AREA_ID));
        $kind = (string) ($classification['kind'] ?? '');
        $maxFiles = max(1, (int) ($input['max_auto_merge_files'] ?? 5));
        $riskClass = $this->riskClass($kind, $changedFiles, $branchOnly, $blockers);
        $safeKind = in_array($kind, ['documentation_only', 'tests_only', 'docs_and_tests'], true);
        $operatorSafeClass = in_array($kind, ['bugfix', 'cleanup'], true)
            && (bool) ($input['allow_code_auto_merge'] ?? false)
            && ($validation['passed'] ?? false) === true;

        $reasons = [];
        if ($blockers !== []) {
            $reasons[] = 'branch_blockers_present';
        }
        if ($branchOnly < 1) {
            $reasons[] = 'no_branch_commit_to_merge';
        }
        if (count($changedFiles) > $maxFiles) {
            $reasons[] = 'changed_file_count_exceeds_policy';
        }
        if (! $safeKind && ! $operatorSafeClass) {
            $reasons[] = 'change_class_requires_operator_review';
        }
        if (($validation['passed'] ?? true) === false) {
            $reasons[] = 'validation_failed';
        }
        if (in_array($riskClass, ['p0_blocked', 'p1_high_risk'], true)) {
            $reasons[] = 'risk_class_blocks_auto_merge';
        }

        $eligible = $reasons === [];

        return [
            'schema_version' => self::DECISION_SCHEMA,
            'ap_contract' => 'AP-774',
            'status' => $eligible ? 'auto_merge_allowed' : 'operator_review_required',
            'area_id' => $areaId,
            'eligible' => $eligible,
            'class' => $kind,
            'risk_class' => $riskClass,
            'safe_kind_without_operator' => $safeKind,
            'code_auto_merge_authorized' => $operatorSafeClass,
            'max_auto_merge_files' => $maxFiles,
            'changed_file_count' => count($changedFiles),
            'branch_commit_count' => $branchOnly,
            'reasons' => array_values(array_unique($reasons)),
            'merge_mode' => 'ff_only',
            'rollback_plan' => $this->rollbackPlan($eligible),
            'validation_required_for_code_auto_merge' => ! $safeKind,
            'operator_controls' => [
                'allow_code_auto_merge_flag_required' => in_array($kind, ['bugfix', 'cleanup'], true),
                'validation_green_required_for_code' => in_array($kind, ['bugfix', 'cleanup', 'code_or_mixed'], true),
                'human_review_required_for_code_or_mixed' => $kind === 'code_or_mixed',
            ],
            'irreversible_actions' => ['none_before_execute_merge'],
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $blockers
     */
    private function riskClass(string $kind, array $changedFiles, int $branchOnly, array $blockers): string
    {
        if ($blockers !== []) {
            return 'p0_blocked';
        }
        if ($branchOnly > 5 || count($changedFiles) > 12) {
            return 'p1_high_risk';
        }
        if (in_array($kind, ['code_or_mixed', 'bugfix', 'cleanup'], true)) {
            return 'p2_code_review_boundary';
        }

        return 'p3_low_risk_docs_tests';
    }

    private function rollbackPlan(bool $eligible): string
    {
        if (! $eligible) {
            return 'No merge permitted; rollback is to keep the branch isolated, repair/rebase/release it, or reject it through operator review.';
        }

        return 'Fast-forward only. If accepted and later reverted, use a normal revert commit on the base branch; never reset, rebase, force-push or silently discard branch history.';
    }

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }
}
