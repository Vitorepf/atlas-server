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
        $operatorSafeClass = in_array($kind, ['bugfix', 'cleanup', 'test'], true)
            && (bool) ($input['allow_code_auto_merge'] ?? false)
            && (int) ($classification['code_or_other_file_count'] ?? 0) > 0
            && ($validation['passed'] ?? false) === true;
        $factoryScopedCodeClass = $kind === 'code_or_mixed'
            && (bool) ($input['allow_code_auto_merge'] ?? false)
            && (int) ($classification['code_or_other_file_count'] ?? 0) > 0
            && ($validation['passed'] ?? false) === true
            && $branchOnly >= 1 && $branchOnly <= 3
            && $this->factoryScopedCodeChange($changedFiles);
        $boundedPacketCodeClass = $kind === 'code_or_mixed'
            && (bool) ($input['allow_code_auto_merge'] ?? false)
            && (bool) ($input['bounded_packet_auto_merge'] ?? false)
            && (string) ($input['merge_target'] ?? '') === 'integration_lane'
            && (string) ($input['origin_type'] ?? '') === 'self_construction_admission_packet'
            && (int) ($classification['code_or_other_file_count'] ?? 0) > 0
            && ($validation['passed'] ?? false) === true
            && $branchOnly >= 1 && $branchOnly <= 3
            && $this->boundedPacketCodeChange($changedFiles, (array) ($input['bounded_packet_allowed_files'] ?? []));
        // Narrow Pilar 1 plan-execution exception: an OPERATOR-AUTHORIZED injected
        // build-plan slice may auto-merge a bounded code diff to main, mirroring the
        // factory-scoped exception but for an injected slice that edits files OUTSIDE
        // the AreaFocusLoop boundary. It is gated by the SAME safety conditions as the
        // bounded-packet exception -- allow_code_auto_merge + green validation + a 1..3
        // commit branch + every changed file explicitly inside the slice's declared
        // allowed_files -- PLUS the explicit injected-plan authorization flag the session
        // only sets when the finding carries auto_execution_allowed=true AND
        // operator_review_required=false. It NEVER weakens the upstream provider-proof,
        // scaffold/final-delivery, evidence, isolation or workcell gates; those all run
        // before the merge governor is ever reached and remain authoritative.
        $injectedPlanSliceCodeClass = $kind === 'code_or_mixed'
            && (bool) ($input['allow_code_auto_merge'] ?? false)
            && (bool) ($input['injected_plan_slice_auto_merge'] ?? false)
            && (int) ($classification['code_or_other_file_count'] ?? 0) > 0
            && ($validation['passed'] ?? false) === true
            && $branchOnly >= 1 && $branchOnly <= 3
            && $this->boundedPacketCodeChange($changedFiles, (array) ($input['injected_plan_slice_allowed_files'] ?? []));

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
        if (! $safeKind && ! $operatorSafeClass && ! $factoryScopedCodeClass && ! $boundedPacketCodeClass && ! $injectedPlanSliceCodeClass) {
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
            'code_auto_merge_authorized' => $operatorSafeClass || $factoryScopedCodeClass || $boundedPacketCodeClass || $injectedPlanSliceCodeClass,
            'factory_scoped_code_auto_merge_authorized' => $factoryScopedCodeClass,
            'bounded_packet_code_auto_merge_authorized' => $boundedPacketCodeClass,
            'injected_plan_slice_code_auto_merge_authorized' => $injectedPlanSliceCodeClass,
            'max_auto_merge_files' => $maxFiles,
            'changed_file_count' => count($changedFiles),
            'branch_commit_count' => $branchOnly,
            'reasons' => array_values(array_unique($reasons)),
            'merge_mode' => 'ff_only',
            'rollback_plan' => $this->rollbackPlan($eligible),
            'validation_required_for_code_auto_merge' => ! $safeKind,
            'operator_controls' => [
                'allow_code_auto_merge_flag_required' => in_array($kind, ['bugfix', 'cleanup'], true)
                    || ($kind === 'test' && (int) ($classification['code_or_other_file_count'] ?? 0) > 0),
                'validation_green_required_for_code' => in_array($kind, ['bugfix', 'cleanup', 'code_or_mixed'], true)
                    || ($kind === 'test' && (int) ($classification['code_or_other_file_count'] ?? 0) > 0),
                'human_review_required_for_code_or_mixed' => $kind === 'code_or_mixed'
                    && ! $factoryScopedCodeClass
                    && ! $boundedPacketCodeClass
                    && ! $injectedPlanSliceCodeClass,
            ],
            'irreversible_actions' => ['none_before_execute_merge'],
        ];
    }

    /**
     * Step 2 of 3: entry point for the per-class changed-file ceiling signal.
     * Empty input returns the step-1 default contract; non-empty transformation
     * wiring lands in step 3.
     *
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    public function evaluatePerClassChangedFileCeilingSignal(array $input = []): array
    {
        if ($input === []) {
            return APerClassChangedFileCeilingSignalContract::defaults()->toArray();
        }

        if (
            (isset($input['changed_files']) && ! is_array($input['changed_files']))
            || (isset($input['bounded_packet_allowed_files']) && ! is_array($input['bounded_packet_allowed_files']))
        ) {
            return APerClassChangedFileCeilingSignalContract::defaults()->toArray();
        }

        return APerClassChangedFileCeilingSignalContract::fromArray($input)->toArray();
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
        if (in_array($kind, ['code_or_mixed', 'bugfix', 'cleanup', 'test'], true)) {
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

    /**
     * Narrow AP-774 exception for the stewardship loop itself: a single-commit
     * code+test patch may auto-merge only when every changed path stays inside
     * the AreaFocusLoop runtime/test boundary. Broad Atlas code remains human
     * review only.
     *
     * @param  list<string>  $changedFiles
     */
    private function factoryScopedCodeChange(array $changedFiles): bool
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
     * Narrow AP-806/AP-810 lane exception: a bounded Self-Construction packet may
     * auto-advance only on the integration lane, only after green validation, and
     * only when every policy-relevant changed path is explicitly inside the
     * packet's own allowed_files. Broad cross-system code remains review-only.
     *
     * @param  list<string>  $changedFiles
     * @param  array<int|string,mixed>  $allowedFiles
     */
    private function boundedPacketCodeChange(array $changedFiles, array $allowedFiles): bool
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

    private function slug(string $value): string
    {
        $slug = strtolower(preg_replace('/[^a-zA-Z0-9_-]+/', '_', trim($value)) ?: '');

        return trim($slug, '_') ?: self::DEFAULT_AREA_ID;
    }
}
