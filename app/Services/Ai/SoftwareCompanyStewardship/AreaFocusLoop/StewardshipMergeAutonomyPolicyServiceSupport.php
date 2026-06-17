<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

/**
 * AP-774 · Stewardship Merge Autonomy Policy — Support.
 *
 * Cohesive cluster of decision helpers extracted from
 * {@see StewardshipMergeAutonomyPolicyService::decide()} so the public surface
 * stays policy-shaped and the per-step decisions become individually
 * auditable. Behaviour is preserved verbatim — these helpers are pure
 * functions of their inputs, with no hidden state and no new branches.
 */
final class StewardshipMergeAutonomyPolicyServiceSupport
{
    /**
     * @param  array<string,mixed>  $classification
     * @param  array<string,mixed>  $validation
     * @param  list<string>  $changedFiles
     * @param  array<string,mixed>  $input
     * @return array{safe_kind:bool, validation_executed:bool, operator_safe_class:bool, factory_scoped_code_class:bool, bounded_packet_code_class:bool, injected_plan_slice_code_class:bool}
     */
    public function classifyAutoMergeAuthorizations(
        string $kind,
        array $classification,
        array $validation,
        array $changedFiles,
        int $branchOnly,
        array $input,
    ): array {
        $validationExecuted = ($validation['passed'] ?? false) === true
            && ($validation['run_validation'] ?? false) === true
            && ($validation['results'] ?? []) !== [];

        $codeOrMixedWithRange = $kind === 'code_or_mixed'
            && $branchOnly >= 1
            && $branchOnly <= 3;
        $allowCodeAutoMerge = (bool) ($input['allow_code_auto_merge'] ?? false);
        $hasCodeFiles = (int) ($classification['code_or_other_file_count'] ?? 0) > 0;

        return [
            'safe_kind' => in_array($kind, ['documentation_only', 'tests_only', 'docs_and_tests'], true),
            'validation_executed' => $validationExecuted,
            'operator_safe_class' => in_array($kind, ['bugfix', 'cleanup', 'test'], true)
                && $allowCodeAutoMerge
                && $hasCodeFiles
                && $validationExecuted,
            'factory_scoped_code_class' => $codeOrMixedWithRange
                && $allowCodeAutoMerge
                && $hasCodeFiles
                && $validationExecuted
                && $this->factoryScopedCodeChange($changedFiles),
            'bounded_packet_code_class' => $codeOrMixedWithRange
                && $allowCodeAutoMerge
                && $hasCodeFiles
                && $validationExecuted
                && (bool) ($input['bounded_packet_auto_merge'] ?? false)
                && (string) ($input['merge_target'] ?? '') === 'integration_lane'
                && (string) ($input['origin_type'] ?? '') === 'self_construction_admission_packet'
                && $this->boundedPacketCodeChange($changedFiles, (array) ($input['bounded_packet_allowed_files'] ?? [])),
            'injected_plan_slice_code_class' => $codeOrMixedWithRange
                && $allowCodeAutoMerge
                && $hasCodeFiles
                && $validationExecuted
                && (bool) ($input['injected_plan_slice_auto_merge'] ?? false)
                && $this->boundedPacketCodeChange($changedFiles, (array) ($input['injected_plan_slice_allowed_files'] ?? [])),
        ];
    }

    /**
     * @param  list<string>  $blockers
     * @param  array{safe_kind:bool, operator_safe_class:bool, factory_scoped_code_class:bool, bounded_packet_code_class:bool, injected_plan_slice_code_class:bool}  $classes
     * @param  array<string,mixed>  $validation
     * @param  list<string>  $changedFiles
     * @return list<string>
     */
    public function collectReasons(
        array $blockers,
        int $branchOnly,
        array $changedFiles,
        int $maxFiles,
        array $classes,
        array $validation,
        string $riskClass,
    ): array {
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
        if (! $classes['safe_kind'] && ! $classes['operator_safe_class'] && ! $classes['factory_scoped_code_class'] && ! $classes['bounded_packet_code_class'] && ! $classes['injected_plan_slice_code_class']) {
            $reasons[] = 'change_class_requires_operator_review';
        }
        if (($validation['passed'] ?? true) === false) {
            $reasons[] = 'validation_failed';
        }
        if (in_array($riskClass, ['p0_blocked', 'p1_high_risk'], true)) {
            $reasons[] = 'risk_class_blocks_auto_merge';
        }

        return $reasons;
    }

    /**
     * @param  array{safe_kind:bool, operator_safe_class:bool, factory_scoped_code_class:bool, bounded_packet_code_class:bool, injected_plan_slice_code_class:bool}  $classes
     * @param  list<string>  $reasons
     * @param  array<string,mixed>  $classification
     * @return array<string,mixed>
     */
    public function buildDecisionPayload(
        string $areaId,
        string $kind,
        bool $eligible,
        int $maxFiles,
        int $changedFileCount,
        int $branchOnly,
        array $classes,
        string $riskClass,
        array $reasons,
        array $classification,
    ): array {
        return [
            'schema_version' => StewardshipMergeAutonomyPolicyService::DECISION_SCHEMA,
            'ap_contract' => 'AP-774',
            'status' => $eligible ? 'auto_merge_allowed' : 'operator_review_required',
            'area_id' => $areaId,
            'eligible' => $eligible,
            'class' => $kind,
            'risk_class' => $riskClass,
            'safe_kind_without_operator' => $classes['safe_kind'],
            'code_auto_merge_authorized' => $classes['operator_safe_class'] || $classes['factory_scoped_code_class'] || $classes['bounded_packet_code_class'] || $classes['injected_plan_slice_code_class'],
            'factory_scoped_code_auto_merge_authorized' => $classes['factory_scoped_code_class'],
            'bounded_packet_code_auto_merge_authorized' => $classes['bounded_packet_code_class'],
            'injected_plan_slice_code_auto_merge_authorized' => $classes['injected_plan_slice_code_class'],
            'max_auto_merge_files' => $maxFiles,
            'changed_file_count' => $changedFileCount,
            'branch_commit_count' => $branchOnly,
            'reasons' => AreaFocusStringListNormalizer::uniqueStringValues($reasons),
            'merge_mode' => 'ff_only',
            'rollback_plan' => $this->rollbackPlan($eligible),
            'validation_required_for_code_auto_merge' => ! $classes['safe_kind'],
            'operator_controls' => $this->buildOperatorControls($kind, $classes, $classification),
            'irreversible_actions' => ['none_before_execute_merge'],
        ];
    }

    /**
     * @param  array<string,mixed>  $classification
     * @param  array{operator_safe_class:bool, factory_scoped_code_class:bool, bounded_packet_code_class:bool, injected_plan_slice_code_class:bool}  $classes
     * @return array{allow_code_auto_merge_flag_required:bool, validation_green_required_for_code:bool, human_review_required_for_code_or_mixed:bool}
     */
    public function buildOperatorControls(string $kind, array $classes, array $classification): array
    {
        return [
            'allow_code_auto_merge_flag_required' => in_array($kind, ['bugfix', 'cleanup'], true)
                || ($kind === 'test' && (int) ($classification['code_or_other_file_count'] ?? 0) > 0),
            'validation_green_required_for_code' => in_array($kind, ['bugfix', 'cleanup', 'code_or_mixed'], true)
                || ($kind === 'test' && (int) ($classification['code_or_other_file_count'] ?? 0) > 0),
            'human_review_required_for_code_or_mixed' => $kind === 'code_or_mixed'
                && ! $classes['factory_scoped_code_class']
                && ! $classes['bounded_packet_code_class']
                && ! $classes['injected_plan_slice_code_class'],
        ];
    }

    /**
     * @param  list<string>  $changedFiles
     * @param  list<string>  $blockers
     */
    public function riskClass(string $kind, array $changedFiles, int $branchOnly, array $blockers): string
    {
        if ($blockers !== []) {
            return 'p0_blocked';
        }
        if ($branchOnly > 5 || count($changedFiles) > 12) {
            return 'p1_high_risk';
        }
        if (in_array($kind, ['code_or_mixed', 'bugfix', 'cleanup', 'test'], true)) {
            return 'p2_code_review_boundary';
        }

        return 'p3_low_risk_docs_tests';
    }

    /**
     * Narrow AP-774 exception for the stewardship loop itself: a single-commit
     * code+test patch may auto-merge only when every changed path stays inside
     * the AreaFocusLoop runtime/test boundary. Broad Atlas code remains human
     * review only.
     *
     * @param  list<string>  $changedFiles
     */
    public function factoryScopedCodeChange(array $changedFiles): bool
    {
        if ($changedFiles === []) {
            return false;
        }

        foreach ($changedFiles as $file) {
            if (! is_string($file) || $file === '') {
                return false;
            }
            if (str_starts_with($file, 'app/Services/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }
            if (str_starts_with($file, 'tests/Unit/Ai/SoftwareCompanyStewardship/AreaFocusLoop/')) {
                continue;
            }

            return false;
        }

        return true;
    }

    /**
     * Narrow AP-806/AP-810 lane exception: a bounded Self-Construction packet
     * (or an operator-authorized injected build-plan slice) may auto-advance
     * only on the integration lane, only after green validation, and only when
     * every policy-relevant changed path is explicitly inside the packet's own
     * allowed_files. Broad cross-system code remains review-only.
     *
     * @param  list<string>  $changedFiles
     * @param  array<int|string,mixed>  $allowedFiles
     */
    public function boundedPacketCodeChange(array $changedFiles, array $allowedFiles): bool
    {
        if ($changedFiles === [] || $allowedFiles === []) {
            return false;
        }

        $allowed = [];
        foreach ($allowedFiles as $file) {
            if (! is_string($file) || trim($file) === '') {
                continue;
            }
            $allowed[trim($file)] = true;
        }
        if ($allowed === []) {
            return false;
        }

        foreach ($changedFiles as $file) {
            if (! is_string($file) || $file === '' || ! isset($allowed[$file])) {
                return false;
            }
        }

        return true;
    }

    private function rollbackPlan(bool $eligible): string
    {
        if (! $eligible) {
            return 'No merge permitted; rollback is to keep the branch isolated, repair/rebase/release it, or reject it through operator review.';
        }

        return 'Fast-forward only. If accepted and later reverted, use a normal revert commit on the base branch; never reset, rebase, force-push or silently discard branch history.';
    }
}
