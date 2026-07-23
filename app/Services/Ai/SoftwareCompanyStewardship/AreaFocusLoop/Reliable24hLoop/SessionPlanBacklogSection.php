<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hLoop;

use App\Services\Ai\Mission\MissionCanonicalHash;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusAppendOnlyJsonlRecorder;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusBranchSandboxMaterializerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusJsonlReader;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusPathNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusSlugNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusUtcClock;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AutonomousEvolutionSessionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\FinalDeliveryQualityGateService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopCycleFailureTaxonomyService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPostCycleAuditorService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopPreflightCycleFirewallService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\BuildPlanDecomposerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\OwnerFlowPlanSliceCycleExecutor;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanCompletionTrackerService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceDecompositionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\PlanExecution\PlanSliceSelectionService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\ZeroProviderPreflightGate;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\Reliable24hStewardshipRecoveryContract;
use App\Services\Ai\Support\JsonFileStore;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * Session invocation + plan-backlog runtime family. Extracted VERBATIM from Reliable24hLoopRunnerService by the GOD-DEBULK split; composed back into the facade as a trait so every $this->/self:: reference and cross-family call resolves byte-identically. Constants and properties stay on the facade.
 */
trait SessionPlanBacklogSection
{
//__GODDEBULK_SPAN_START__

    // ------------------------------------------------------------------
    // Session invocation (wraps AP-786, never reimplements it)
    // ------------------------------------------------------------------

    /**
     * @param  array<string,mixed>  $input
     * @param  array<string,bool>  $seenFindingKeys
     * @param  array<string,string>  $seenFindingOutcomes
     * @return array<string,mixed>
     */
    private function invokeSession(array $input, string $areaId, string $focus, bool $execute, array $seenFindingKeys, array $seenFindingOutcomes, array $blockedAttemptsByFinding = []): array
    {
        $reviewLocked = $this->sessionReviewLockedKeys($seenFindingKeys, $seenFindingOutcomes);
        // Cap blocked-finding retries: once a finding has blocked too many times
        // this run, review-lock it so the loop picks a different finding instead
        // of re-implementing the same one (the source of duplicate branches).
        $reviewLocked += $this->blockedAttemptReviewLocks($blockedAttemptsByFinding);
        $terminalLocked = $this->sessionTerminalLockedKeys($seenFindingKeys, $seenFindingOutcomes);

        $planBacklogReport = $this->invokePlanBacklogSession(
            $input,
            $areaId,
            $focus,
            $execute,
            $reviewLocked + $terminalLocked,
        );
        if ($planBacklogReport !== null) {
            return $planBacklogReport;
        }

        $sessionInput = [
            'area_id' => $areaId,
            'focus' => $focus,
            'cycles' => 1,
            'provider' => (string) ($input['provider'] ?? 'cursor_cli'),
            'model' => (string) ($input['model'] ?? ''),
            'scope_profile' => (string) ($input['scope_profile'] ?? 'factory_max'),
            'repo_root' => (string) ($input['repo_root'] ?? ''),
            'actor' => (string) ($input['actor'] ?? 'operator'),
            'execute' => $execute,
            'auto_merge' => (bool) ($input['auto_merge'] ?? false),
            'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
            'allow_direct_provider_driver' => (bool) ($input['allow_direct_provider_driver'] ?? false),
            'continue_on_blocked' => (bool) ($input['continue_on_blocked'] ?? false),
            'multi_agent_workcell' => (bool) ($input['multi_agent_workcell'] ?? false),
            'pull_main' => (bool) ($input['pull_main'] ?? false),
            'record' => (bool) ($input['record'] ?? false),
            'max_findings' => (int) ($input['max_findings'] ?? 200),
            'max_auto_merge_files' => (int) ($input['max_auto_merge_files'] ?? 5),
            'ap790_kill_switch_path' => $this->killSwitchPath($areaId, $focus),
            'validation_commands' => AreaFocusStringListNormalizer::coercedStringValues($input['validation_commands'] ?? []),
            'session_review_locked' => $reviewLocked,
            'session_terminal_locked' => $terminalLocked,
        ];
        foreach ([
            'forge_obra', 'obra_id', 'forge_live_topology', 'forge_live_decision',
            'forge_dispatch_mode', 'forge_role', 'forge_provider_authorization',
            'forge_budget_approved', 'forge_tickets', 'forge_agents',
        ] as $key) {
            if (array_key_exists($key, $input)) {
                $sessionInput[$key] = $input[$key];
            }
        }
        if (isset($input['forge_inputs']) && is_array($input['forge_inputs'])) {
            $sessionInput['forge_inputs'] = $input['forge_inputs'];
        }
        if (is_array($input['injected_finding'] ?? null) && $input['injected_finding'] !== []) {
            $sessionInput['injected_finding'] = $input['injected_finding'];
        }

        $runner = $this->sessionRunner ?? fn (array $in): array => $this->session->run($in);

        try {
            $report = $runner($sessionInput);

            return is_array($report) ? $report : [];
        } catch (Throwable $e) {
            return [
                'status' => 'blocked',
                'cycles' => [[
                    'final_status' => 'blocked',
                    'blockers' => ['session_invocation_failed'],
                    'selected_finding' => [],
                    'error' => $e->getMessage(),
                ]],
            ];
        }
    }

    /**
     * AP-790 plan-backlog bridge: when a factory_max AAEOS run is active, consume the
     * curated plan-execution backlog docs one atomic slice at a time before falling
     * back to broad native selection. This keeps the 24h runner on high-probability,
     * operator-authored AAEOS slices while preserving the same AP-786 owner flow for
     * provider/sandbox/merge.
     *
     * @param  array<string,mixed>  $input
     * @param  array<string,bool>  $skipFindingKeys
     * @return array<string,mixed>|null
     */
    private function invokePlanBacklogSession(array $input, string $areaId, string $focus, bool $execute, array $skipFindingKeys = []): ?array
    {
        $docs = $this->planBacklogDocs($input, $areaId, $focus);
        if ($docs === []) {
            return null;
        }

        $decomposer = new BuildPlanDecomposerService;
        $tracker = new PlanCompletionTrackerService;
        $selector = new PlanSliceSelectionService;
        $decomposition = new PlanSliceDecompositionService;
        $executor = new OwnerFlowPlanSliceCycleExecutor($this->session, $execute);
        $repoRoot = $this->repoRootFromInput($input);

        $blockedDocs = [];
        $completeDocs = [];
        $orderedDocGate = $this->shouldEnforceOrderedPlanBacklogDocs($input, $areaId, $focus, $docs);
        if ($orderedDocGate) {
            $orderBlockers = $this->planBacklogOrderPreflightBlockers($docs);
            if ($orderBlockers !== []) {
                return $this->planBacklogNoReadySession($docs, $orderBlockers, $completeDocs);
            }
        }

        foreach ($docs as $index => $doc) {
            $docPath = $this->absoluteRepoPath($repoRoot, $doc);
            if (! is_file($docPath)) {
                $blockedDocs[] = 'plan_doc_missing:'.$doc;
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            try {
                $plan = $decomposer->decompose([
                    'doc_path' => $docPath,
                    'scope_profile' => (string) ($input['scope_profile'] ?? 'factory_max'),
                ]);
            } catch (Throwable $e) {
                $blockedDocs[] = 'plan_doc_decomposition_exception:'.$doc.':'.substr($e->getMessage(), 0, 120);
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            $planId = (string) ($plan['plan_id'] ?? '');
            if ($planId === '' || (string) ($plan['decomposition_status'] ?? '') === 'blocked') {
                $blockedDocs[] = 'plan_doc_decomposition_blocked:'.$doc.':'.implode(',', AreaFocusStringListNormalizer::coercedStringValues($plan['blockers'] ?? []));
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            $rollupBefore = $tracker->rollup($planId, $areaId, $plan);
            $reconciliation = $this->planBacklogProviderProofReconciliationSession(
                tracker: $tracker,
                decomposition: $decomposition,
                areaId: $areaId,
                repoRoot: $repoRoot,
                doc: $doc,
                docIndex: $index + 1,
                docCount: count($docs),
                planId: $planId,
                plan: $plan,
                rollupBefore: $rollupBefore,
                orderedDocGate: $orderedDocGate && $this->isOrderedPlanBacklogDoc($doc),
                execute: $execute,
            );
            if ($reconciliation !== null) {
                return $reconciliation;
            }
            $supervisedExisting = $this->planBacklogSupervisedExistingDeliverySession(
                tracker: $tracker,
                decomposition: $decomposition,
                areaId: $areaId,
                repoRoot: $repoRoot,
                doc: $doc,
                docIndex: $index + 1,
                docCount: count($docs),
                planId: $planId,
                plan: $plan,
                rollupBefore: $rollupBefore,
                orderedDocGate: $orderedDocGate && $this->isOrderedPlanBacklogDoc($doc),
                execute: $execute,
            );
            if ($supervisedExisting !== null) {
                return $supervisedExisting;
            }

            $rehabilitatedSkipFindingKeys = $this->planBacklogRehabilitatedSkipFindingKeys($skipFindingKeys, $rollupBefore);
            $effectiveSkipFindingKeys = $this->planBacklogEffectiveSkipFindingKeys($skipFindingKeys, $rollupBefore);
            $selection = $selector->selectNext($plan, $rollupBefore, $effectiveSkipFindingKeys);
            $kind = (string) ($selection['kind'] ?? '');
            if ($kind === PlanSliceSelectionService::KIND_PLAN_COMPLETE) {
                $completeDocs[] = $doc;

                continue;
            }
            if ($kind !== PlanSliceSelectionService::KIND_SLICE_READY) {
                $blockedDocs[] = 'plan_doc_no_ready_slice:'.$doc.':'.(string) ($selection['reason'] ?? 'unknown');
                if ($orderedDocGate && $this->isOrderedPlanBacklogDoc($doc)) {
                    $blockedDocs[] = 'plan_ordered_doc_not_complete:'.$doc;

                    return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
                }

                continue;
            }

            $selectedSlice = is_array($selection['slice'] ?? null) ? $selection['slice'] : [];
            $slice = $decomposition->resolveExecutableSlice($selectedSlice);
            $cycle = $executor->executeSlice($slice, $this->planBacklogContext($input, $areaId, $focus));
            if (! is_array($cycle)) {
                $cycle = [];
            }
            if (! isset($cycle['selected_finding']) || ! is_array($cycle['selected_finding'])) {
                $sliceId = (string) ($selection['slice_id'] ?? $slice['slice_id'] ?? '');
                $cycle['selected_finding'] = [
                    'finding_id' => $sliceId,
                    'title' => (string) ($slice['title'] ?? $slice['delivery'] ?? $sliceId),
                ];
            }

            try {
                $rollupAfter = $tracker->recordCycle([
                    'decomposed_plan' => $plan,
                    'area_id' => $areaId,
                    'cycle' => $cycle,
                ]);
            } catch (Throwable $e) {
                $rollupAfter = $rollupBefore;
                $cycle['final_status'] = 'blocked';
                $cycle['blockers'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                    AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []),
                    ['plan_backlog_tracker_record_failed'],
                );
                $cycle['tracker_error_excerpt'] = substr($e->getMessage(), 0, 160);
            }

            $planBacklog = [
                'schema_version' => self::PLAN_BACKLOG_BRIDGE_SCHEMA,
                'mode' => 'ap790_auto_plan_backlog',
                'doc_path' => $doc,
                'doc_index' => $index + 1,
                'doc_count' => count($docs),
                'plan_id' => $planId,
                'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
                'decomposition_status' => (string) ($plan['decomposition_status'] ?? ''),
                'selection_kind' => $kind,
                'selection_reason' => (string) ($selection['reason'] ?? ''),
                'slice_id' => (string) ($selection['slice_id'] ?? ''),
                'finding_id' => (string) ($selection['finding_id'] ?? ''),
                'allowed_files' => AreaFocusStringListNormalizer::coercedStringValues($slice['allowed_files'] ?? []),
                'total_slices' => (int) ($rollupBefore['total_slices'] ?? count((array) ($plan['slices'] ?? []))),
                'delivered_before' => (int) ($rollupBefore['delivered_count'] ?? 0),
                'delivered_after' => (int) ($rollupAfter['delivered_count'] ?? 0),
                'completion_pct_after' => (float) ($rollupAfter['completion_pct'] ?? 0.0),
                'tracker_blockers_after' => AreaFocusStringListNormalizer::coercedStringValues($rollupAfter['blockers'] ?? []),
                'selection_skip_count' => count($effectiveSkipFindingKeys),
                'selection_skip_original_count' => count($skipFindingKeys),
                'selection_skip_rehabilitated_count' => max(0, count($skipFindingKeys) - count($effectiveSkipFindingKeys)),
                'selection_skip_rehabilitated_keys' => $rehabilitatedSkipFindingKeys,
                'ordered_doc_gate' => $orderedDocGate && $this->isOrderedPlanBacklogDoc($doc),
            ];
            $cycle['plan_backlog'] = $planBacklog;

            return [
                'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
                'status' => (string) ($cycle['final_status'] ?? '') === 'blocked' ? 'blocked' : 'completed',
                'cycles' => [$cycle],
                'plan_backlog' => $planBacklog,
            ];
        }

        return $this->planBacklogNoReadySession($docs, $blockedDocs, $completeDocs);
    }

    /**
     * Some legacy AP-790 plan-slice rows already have honest provider+merge proof
     * but were recorded before acceptance validation was captured. Reconcile those
     * rows before selecting another slice so the ordered backlog cannot skip a
     * completed predecessor or re-spend provider on the same work.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $rollupBefore
     * @return array<string,mixed>|null
     */
    private function planBacklogProviderProofReconciliationSession(
        PlanCompletionTrackerService $tracker,
        PlanSliceDecompositionService $decomposition,
        string $areaId,
        string $repoRoot,
        string $doc,
        int $docIndex,
        int $docCount,
        string $planId,
        array $plan,
        array $rollupBefore,
        bool $orderedDocGate,
        bool $execute,
    ): ?array {
        if (! $execute) {
            return null;
        }

        $sliceId = $this->planBacklogProviderProofReconciliationSliceId($plan, $rollupBefore);
        if ($sliceId === null) {
            return null;
        }

        $selectedSlice = $this->planBacklogSliceById($plan, $sliceId);
        if ($selectedSlice === []) {
            return null;
        }

        $slice = $decomposition->resolveExecutableSlice($selectedSlice);
        $validation = $this->runPlanBacklogReconciliationValidation($slice, $repoRoot);
        $rollupAfter = $rollupBefore;
        if (($validation['passed'] ?? null) === true) {
            $rollupAfter = $tracker->recordProviderProofReconciliation([
                'decomposed_plan' => $plan,
                'area_id' => $areaId,
                'slice_id' => $sliceId,
                'validation' => $validation,
            ]);
        }

        $warnings = AreaFocusStringListNormalizer::coercedStringValues($rollupAfter['warnings'] ?? []);
        $sliceState = is_array($rollupAfter['slice_states'][$sliceId] ?? null)
            ? $rollupAfter['slice_states'][$sliceId]
            : [];
        $delivered = (string) ($sliceState['state'] ?? '') === PlanCompletionTrackerService::SLICE_STATE_DELIVERED
            && ($sliceState['acceptance_met'] ?? null) === true
            && ($sliceState['provider_proof'] ?? null) === true;

        $blockers = [];
        if (($validation['passed'] ?? null) !== true) {
            $blockers[] = 'provider_proof_reconciliation_validation_failed:'.$sliceId;
            $blockers = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                $blockers,
                AreaFocusStringListNormalizer::coercedStringValues($validation['blockers'] ?? []),
            );
        } elseif (! $delivered) {
            $blockers[] = 'provider_proof_reconciliation_not_delivered:'.$sliceId;
            $blockers = AreaFocusStringListNormalizer::uniqueMergedStringValues($blockers, $warnings);
        }

        $cycleId = 'plan_backlog_reconcile_'.$sliceId.'_'.substr(MissionCanonicalHash::sha256([
            'plan_id' => $planId,
            'slice_id' => $sliceId,
            'validation' => $validation,
            'warnings' => $warnings,
        ]), 0, 12);
        $planBacklog = [
            'schema_version' => self::PLAN_BACKLOG_BRIDGE_SCHEMA,
            'mode' => 'ap790_auto_plan_backlog',
            'doc_path' => $doc,
            'doc_index' => $docIndex,
            'doc_count' => $docCount,
            'plan_id' => $planId,
            'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
            'decomposition_status' => (string) ($plan['decomposition_status'] ?? ''),
            'selection_kind' => 'provider_proof_reconciliation',
            'selection_reason' => PlanCompletionTrackerService::BLOCKER_PROVIDER_PROOF_RECONCILIATION_REQUIRED,
            'slice_id' => $sliceId,
            'finding_id' => $sliceId,
            'allowed_files' => AreaFocusStringListNormalizer::coercedStringValues($slice['allowed_files'] ?? []),
            'total_slices' => (int) ($rollupBefore['total_slices'] ?? count((array) ($plan['slices'] ?? []))),
            'delivered_before' => (int) ($rollupBefore['delivered_count'] ?? 0),
            'delivered_after' => (int) ($rollupAfter['delivered_count'] ?? 0),
            'completion_pct_after' => (float) ($rollupAfter['completion_pct'] ?? 0.0),
            'tracker_blockers_after' => AreaFocusStringListNormalizer::coercedStringValues($rollupAfter['blockers'] ?? []),
            'tracker_warnings_after' => $warnings,
            'provider_invoked' => false,
            'merge_performed' => false,
            'ordered_doc_gate' => $orderedDocGate,
        ];
        $cycle = [
            'cycle_id' => $cycleId,
            'final_status' => $delivered ? 'completed_real_no_merge_with_evidence' : 'blocked',
            'selected_finding' => [
                'finding_id' => $sliceId,
                'title' => (string) ($slice['title'] ?? $slice['objective'] ?? $slice['delivery'] ?? $sliceId),
            ],
            'merge_performed' => false,
            'provider_invoked' => false,
            'provider_state' => [
                'invoked' => false,
                'reason' => 'provider_proof_reconciliation_pre_spend',
            ],
            'judge_decision' => $delivered ? 'accept_reconciled_provider_proof' : 'block_reconciliation',
            'validation' => $validation,
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($validation['evidence_refs'] ?? []),
            'blockers' => $blockers,
            'plan_backlog' => $planBacklog,
        ];

        return [
            'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
            'status' => $delivered ? 'completed' : 'blocked',
            'cycles' => [$cycle],
            'plan_backlog' => $planBacklog,
        ];
    }

    /**
     * Truthfully consume supervisor-salvaged existing code without pretending it
     * was a provider-backed autonomous delivery. This path only applies to slices
     * already rehabilitated by the tracker, whose allowed files are present,
     * tracked in HEAD, clean, and whose focused validation passes.
     *
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $rollupBefore
     * @return array<string,mixed>|null
     */
    private function planBacklogSupervisedExistingDeliverySession(
        PlanCompletionTrackerService $tracker,
        PlanSliceDecompositionService $decomposition,
        string $areaId,
        string $repoRoot,
        string $doc,
        int $docIndex,
        int $docCount,
        string $planId,
        array $plan,
        array $rollupBefore,
        bool $orderedDocGate,
        bool $execute,
    ): ?array {
        if (! $execute) {
            return null;
        }

        $sliceId = $this->planBacklogSupervisedExistingDeliverySliceId($plan, $rollupBefore);
        if ($sliceId === null) {
            return null;
        }

        $selectedSlice = $this->planBacklogSliceById($plan, $sliceId);
        if ($selectedSlice === []) {
            return null;
        }

        $slice = $decomposition->resolveExecutableSlice($selectedSlice);
        $existing = $this->planBacklogTrackedExistingDelivery($slice, $repoRoot);
        if (($existing['eligible'] ?? false) !== true) {
            return null;
        }

        $validation = $this->runPlanBacklogReconciliationValidation($slice, $repoRoot);
        $rollupAfter = $rollupBefore;
        if (($validation['passed'] ?? null) === true) {
            $validation['evidence_refs'] = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($validation['evidence_refs'] ?? []),
                ['existing_delivery_commit:'.(string) ($existing['commit_hash'] ?? '')],
            );
            $rollupAfter = $tracker->recordSupervisedExistingDelivery([
                'decomposed_plan' => $plan,
                'area_id' => $areaId,
                'slice_id' => $sliceId,
                'validation' => $validation,
                'commit_hash' => (string) ($existing['commit_hash'] ?? ''),
                'evidence_refs' => ['supervised_existing_delivery:'.$sliceId],
            ]);
        }

        $warnings = AreaFocusStringListNormalizer::coercedStringValues($rollupAfter['warnings'] ?? []);
        $sliceState = is_array($rollupAfter['slice_states'][$sliceId] ?? null)
            ? $rollupAfter['slice_states'][$sliceId]
            : [];
        $delivered = (string) ($sliceState['state'] ?? '') === PlanCompletionTrackerService::SLICE_STATE_DELIVERED
            && ($sliceState['acceptance_met'] ?? null) === true
            && (string) ($sliceState['acceptance_basis'] ?? '') === PlanCompletionTrackerService::ACCEPTANCE_BASIS_SUPERVISED_EXISTING_DELIVERY;

        $blockers = [];
        if (($validation['passed'] ?? null) !== true) {
            $blockers[] = 'supervised_existing_delivery_validation_failed:'.$sliceId;
            $blockers = AreaFocusStringListNormalizer::uniqueMergedStringValues(
                $blockers,
                AreaFocusStringListNormalizer::coercedStringValues($validation['blockers'] ?? []),
            );
        } elseif (! $delivered) {
            $blockers[] = 'supervised_existing_delivery_not_recorded:'.$sliceId;
            $blockers = AreaFocusStringListNormalizer::uniqueMergedStringValues($blockers, $warnings);
        }

        $cycleId = 'plan_backlog_supervised_existing_'.$sliceId.'_'.substr(MissionCanonicalHash::sha256([
            'plan_id' => $planId,
            'slice_id' => $sliceId,
            'commit_hash' => (string) ($existing['commit_hash'] ?? ''),
            'validation' => $validation,
        ]), 0, 12);
        $planBacklog = [
            'schema_version' => self::PLAN_BACKLOG_BRIDGE_SCHEMA,
            'mode' => 'ap790_auto_plan_backlog',
            'doc_path' => $doc,
            'doc_index' => $docIndex,
            'doc_count' => $docCount,
            'plan_id' => $planId,
            'plan_hash' => (string) ($plan['plan_hash'] ?? ''),
            'decomposition_status' => (string) ($plan['decomposition_status'] ?? ''),
            'selection_kind' => 'supervised_existing_delivery',
            'selection_reason' => 'rehabilitated_existing_code_validation_passed',
            'slice_id' => $sliceId,
            'finding_id' => $sliceId,
            'allowed_files' => AreaFocusStringListNormalizer::coercedStringValues($slice['allowed_files'] ?? []),
            'total_slices' => (int) ($rollupBefore['total_slices'] ?? count((array) ($plan['slices'] ?? []))),
            'delivered_before' => (int) ($rollupBefore['delivered_count'] ?? 0),
            'delivered_after' => (int) ($rollupAfter['delivered_count'] ?? 0),
            'completion_pct_after' => (float) ($rollupAfter['completion_pct'] ?? 0.0),
            'tracker_blockers_after' => AreaFocusStringListNormalizer::coercedStringValues($rollupAfter['blockers'] ?? []),
            'tracker_warnings_after' => $warnings,
            'provider_invoked' => false,
            'provider_proof' => false,
            'merge_performed' => false,
            'delivery_authority' => 'supervisor_existing_delivery',
            'commit_hash' => (string) ($existing['commit_hash'] ?? ''),
            'ordered_doc_gate' => $orderedDocGate,
        ];
        $cycle = [
            'cycle_id' => $cycleId,
            'final_status' => $delivered ? 'completed_supervised_existing_delivery_with_evidence' : 'blocked',
            'selected_finding' => [
                'finding_id' => $sliceId,
                'title' => (string) ($slice['title'] ?? $slice['objective'] ?? $slice['delivery'] ?? $sliceId),
            ],
            'merge_performed' => false,
            'provider_invoked' => false,
            'provider_state' => [
                'invoked' => false,
                'reason' => 'supervised_existing_delivery_pre_spend',
            ],
            'judge_decision' => $delivered ? 'accept_supervised_existing_delivery' : 'block_supervised_existing_delivery',
            'validation' => $validation,
            'evidence_refs' => AreaFocusStringListNormalizer::coercedStringValues($validation['evidence_refs'] ?? []),
            'blockers' => $blockers,
            'plan_backlog' => $planBacklog,
        ];

        return [
            'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
            'status' => $delivered ? 'completed' : 'blocked',
            'cycles' => [$cycle],
            'plan_backlog' => $planBacklog,
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $rollup
     */
    private function planBacklogProviderProofReconciliationSliceId(array $plan, array $rollup): ?string
    {
        $prefix = PlanCompletionTrackerService::BLOCKER_PROVIDER_PROOF_RECONCILIATION_REQUIRED.':';
        $required = [];
        foreach (AreaFocusStringListNormalizer::coercedStringValues($rollup['blockers'] ?? []) as $blocker) {
            if (str_starts_with($blocker, $prefix)) {
                $sliceId = trim(substr($blocker, strlen($prefix)));
                if ($sliceId !== '') {
                    $required[$sliceId] = true;
                }
            }
        }
        if ($required === []) {
            return null;
        }

        foreach ((array) ($plan['slices'] ?? []) as $slice) {
            if (! is_array($slice)) {
                continue;
            }
            $sliceId = (string) ($slice['slice_id'] ?? '');
            if ($sliceId !== '' && isset($required[$sliceId])) {
                return $sliceId;
            }
        }

        return (string) array_key_first($required);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @param  array<string,mixed>  $rollup
     */
    private function planBacklogSupervisedExistingDeliverySliceId(array $plan, array $rollup): ?string
    {
        $prefixes = [
            PlanCompletionTrackerService::BLOCKER_EXECUTABLE_CONTRACT_FALSE_POSITIVE_REHABILITATED.':',
            PlanCompletionTrackerService::BLOCKER_LEGACY_PRE_PROVIDER_ATTEMPTS_REHABILITATED.':',
            PlanCompletionTrackerService::BLOCKER_RETRYABLE_BLOCKED_SLICE.':',
            PlanCompletionTrackerService::BLOCKER_SLICE_STUCK.':',
        ];
        $eligible = [];
        foreach (AreaFocusStringListNormalizer::coercedStringValues($rollup['blockers'] ?? []) as $blocker) {
            foreach ($prefixes as $prefix) {
                if (str_starts_with($blocker, $prefix)) {
                    $sliceId = trim(substr($blocker, strlen($prefix)));
                    if ($sliceId !== '') {
                        $eligible[$sliceId] = true;
                    }
                }
            }
        }
        if ($eligible === []) {
            return null;
        }

        foreach ((array) ($plan['slices'] ?? []) as $slice) {
            if (! is_array($slice)) {
                continue;
            }
            $sliceId = (string) ($slice['slice_id'] ?? '');
            if ($sliceId !== '' && isset($eligible[$sliceId])) {
                return $sliceId;
            }
        }

        return (string) array_key_first($eligible);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function planBacklogSliceById(array $plan, string $sliceId): array
    {
        foreach ((array) ($plan['slices'] ?? []) as $slice) {
            if (is_array($slice) && (string) ($slice['slice_id'] ?? '') === $sliceId) {
                return $slice;
            }
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return array{eligible:bool,commit_hash?:string,blockers:list<string>}
     */
    private function planBacklogTrackedExistingDelivery(array $slice, string $repoRoot): array
    {
        $allowedFiles = AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($slice['allowed_files'] ?? []);
        if ($allowedFiles === []) {
            return ['eligible' => false, 'blockers' => ['supervised_existing_delivery_allowed_files_missing']];
        }

        foreach ($allowedFiles as $path) {
            if (! is_file($this->absoluteRepoPath($repoRoot, $path))) {
                return ['eligible' => false, 'blockers' => ['supervised_existing_delivery_file_missing:'.$path]];
            }
            if (! $this->gitCommandSuccessful($repoRoot, ['git', 'ls-files', '--error-unmatch', $path])) {
                return ['eligible' => false, 'blockers' => ['supervised_existing_delivery_file_not_tracked:'.$path]];
            }
        }

        if (! $this->gitCommandSuccessful($repoRoot, array_merge(['git', 'diff', '--quiet', '--'], $allowedFiles))) {
            return ['eligible' => false, 'blockers' => ['supervised_existing_delivery_worktree_diff_present']];
        }
        if (! $this->gitCommandSuccessful($repoRoot, array_merge(['git', 'diff', '--cached', '--quiet', '--'], $allowedFiles))) {
            return ['eligible' => false, 'blockers' => ['supervised_existing_delivery_index_diff_present']];
        }

        $commit = $this->gitCommandOutput($repoRoot, array_merge(['git', 'log', '-n', '1', '--format=%H', '--'], $allowedFiles));
        if ($commit === '') {
            return ['eligible' => false, 'blockers' => ['supervised_existing_delivery_commit_missing']];
        }

        return [
            'eligible' => true,
            'commit_hash' => $commit,
            'blockers' => [],
        ];
    }

    /**
     * @param  list<string>  $command
     */
    private function gitCommandSuccessful(string $repoRoot, array $command): bool
    {
        try {
            $process = new Process($command, $repoRoot);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $command
     */
    private function gitCommandOutput(string $repoRoot, array $command): string
    {
        try {
            $process = new Process($command, $repoRoot);
            $process->setTimeout(15);
            $process->run();

            return $process->isSuccessful() ? trim($process->getOutput()) : '';
        } catch (Throwable) {
            return '';
        }
    }

    /**
     * @return array{ok:bool,reason?:string,current_branch?:string,current_head?:string,main_head?:string}
     */
    private function loopRunnerControllerBaseState(string $repoRoot): array
    {
        $current = $this->gitCommandOutput($repoRoot, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        if (! str_starts_with($current, 'atlas/loop-runner/')) {
            return ['ok' => true, 'current_branch' => $current];
        }

        $currentHead = $this->gitCommandOutput($repoRoot, ['git', 'rev-parse', 'HEAD']);
        $mainHead = $this->gitCommandOutput($repoRoot, ['git', 'rev-parse', 'main']);
        if ($currentHead === '' || $mainHead === '') {
            return [
                'ok' => false,
                'reason' => 'loop_runner_base_ref_unresolvable',
                'current_branch' => $current,
                'current_head' => $currentHead,
                'main_head' => $mainHead,
            ];
        }
        if ($currentHead !== $mainHead) {
            return [
                'ok' => false,
                'reason' => 'loop_runner_branch_not_promoted_to_main',
                'current_branch' => $current,
                'current_head' => $currentHead,
                'main_head' => $mainHead,
            ];
        }

        return [
            'ok' => true,
            'current_branch' => $current,
            'current_head' => $currentHead,
            'main_head' => $mainHead,
        ];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return array<string,mixed>
     */
    private function runPlanBacklogReconciliationValidation(array $slice, string $repoRoot): array
    {
        $commands = $this->planBacklogValidationCommands($slice);
        if ($commands === []) {
            return [
                'passed' => false,
                'commands' => [],
                'results' => [],
                'evidence_refs' => [],
                'blockers' => ['provider_proof_reconciliation_validation_command_missing'],
            ];
        }

        $results = [];
        $blockers = [];
        foreach ($commands as $command) {
            if (! $this->planBacklogReconciliationCommandAllowed($command)) {
                $results[] = [
                    'command' => $command,
                    'exit_code' => null,
                    'passed' => false,
                    'output_excerpt' => '',
                ];
                $blockers[] = 'provider_proof_reconciliation_unsafe_validation_command';
                break;
            }

            $started = microtime(true);
            try {
                $process = Process::fromShellCommandline($command, $repoRoot);
                $process->setTimeout(300);
                $process->run();
                $output = trim($process->getOutput()."\n".$process->getErrorOutput());
                $results[] = [
                    'command' => $command,
                    'exit_code' => $process->getExitCode(),
                    'passed' => $process->isSuccessful(),
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'output_excerpt' => substr($output, 0, 1200),
                ];
                if (! $process->isSuccessful()) {
                    $blockers[] = 'provider_proof_reconciliation_validation_command_failed';
                    break;
                }
            } catch (Throwable $e) {
                $results[] = [
                    'command' => $command,
                    'exit_code' => null,
                    'passed' => false,
                    'duration_ms' => (int) round((microtime(true) - $started) * 1000),
                    'output_excerpt' => substr($e->getMessage(), 0, 1200),
                ];
                $blockers[] = 'provider_proof_reconciliation_validation_exception';
                break;
            }
        }

        $passed = $blockers === [] && count($results) === count($commands);
        $evidenceRefs = $passed
            ? ['validation_passed:'.substr(MissionCanonicalHash::sha256([$commands, $results]), 0, 16)]
            : [];

        return [
            'passed' => $passed,
            'commands' => $commands,
            'results' => $results,
            'evidence_refs' => $evidenceRefs,
            'blockers' => AreaFocusStringListNormalizer::uniqueStringValues($blockers),
        ];
    }

    /**
     * @param  array<string,mixed>  $slice
     * @return list<string>
     */
    private function planBacklogValidationCommands(array $slice): array
    {
        $commands = AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($slice['validation_commands'] ?? []);
        foreach ((array) ($slice['executable_slices'] ?? []) as $executableSlice) {
            if (is_array($executableSlice)) {
                $commands = array_merge($commands, AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($executableSlice['validation_commands'] ?? []));
            }
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($commands);
    }

    private function planBacklogReconciliationCommandAllowed(string $command): bool
    {
        $command = trim($command);
        if ($command === 'git diff --check') {
            return true;
        }
        if (preg_match('/^php -l (?:app|tests)\/[A-Za-z0-9_\/.-]+\.php$/', $command) === 1) {
            return true;
        }
        if (preg_match('/^php artisan test (?:tests\/[A-Za-z0-9_\/.-]+\.php|--filter=?[A-Za-z0-9_\\\\:.-]+)(?: --stop-on-failure)?$/', $command) === 1) {
            return true;
        }
        if (preg_match('/^\.\/vendor\/bin\/phpunit --configuration=phpunit\.xml (?:tests\/[A-Za-z0-9_\/.-]+\.php|--filter=?[A-Za-z0-9_\\\\:.-]+)(?: --stop-on-failure)?$/', $command) === 1) {
            return true;
        }

        return false;
    }

    /**
     * When the plan completion tracker projects a slice as retryable/re-admitted,
     * the AP-790 seen/blocked-attempt skip set must not keep starving it. Future
     * policy-versioned failures still count toward the stuck threshold; this only
     * prevents the durable seen set from turning "retryable" into "never again".
     *
     * @param  array<string,bool>|list<string>  $skipFindingKeys
     * @return array<string,bool>|list<string>
     */
    private function planBacklogEffectiveSkipFindingKeys(array $skipFindingKeys, array $rollup): array
    {
        $rehabilitatedKeys = array_fill_keys($this->planBacklogRehabilitatedSkipFindingKeys($skipFindingKeys, $rollup), true);
        if ($rehabilitatedKeys === []) {
            return $skipFindingKeys;
        }

        $effective = $skipFindingKeys;
        foreach ($effective as $key => $value) {
            if (is_int($key) && is_string($value) && isset($rehabilitatedKeys[$value])) {
                unset($effective[$key]);
            } elseif (is_string($key) && isset($rehabilitatedKeys[$key])) {
                unset($effective[$key]);
            }
        }

        return $effective;
    }

    /**
     * @param  array<string,bool>|list<string>  $skipFindingKeys
     * @return list<string>
     */
    private function planBacklogRehabilitatedSkipFindingKeys(array $skipFindingKeys, array $rollup): array
    {
        $rehabilitated = $this->planBacklogRehabilitatedSliceIds($rollup);
        if ($rehabilitated === []) {
            return [];
        }

        $keys = [];
        foreach ($skipFindingKeys as $key => $value) {
            if (is_int($key) && is_string($value) && isset($rehabilitated[$value])) {
                $keys[$value] = true;
            } elseif (is_string($key) && $value && isset($rehabilitated[$key])) {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * @return array<string,bool>
     */
    private function planBacklogRehabilitatedSliceIds(array $rollup): array
    {
        $ids = [];
        foreach ((array) ($rollup['blockers'] ?? []) as $blocker) {
            $blocker = $this->str($blocker);
            foreach ([
                PlanCompletionTrackerService::BLOCKER_LEGACY_PRE_PROVIDER_ATTEMPTS_REHABILITATED,
                PlanCompletionTrackerService::BLOCKER_EXECUTABLE_CONTRACT_FALSE_POSITIVE_REHABILITATED,
                PlanCompletionTrackerService::BLOCKER_RETRYABLE_BLOCKED_SLICE,
            ] as $base) {
                $prefix = $base.':';
                if (str_starts_with($blocker, $prefix)) {
                    $sliceId = substr($blocker, strlen($prefix));
                    if ($sliceId !== '') {
                        $ids[$sliceId] = true;
                    }
                }
            }
        }

        foreach ((array) ($rollup['slice_states'] ?? []) as $sliceId => $row) {
            if (! is_array($row)) {
                continue;
            }
            if (((int) ($row['ignored_legacy_pre_provider_attempt_count'] ?? 0) > 0
                || (int) ($row['ignored_executable_contract_false_positive_attempt_count'] ?? 0) > 0)
                && (string) ($row['state'] ?? '') !== PlanCompletionTrackerService::SLICE_STATE_DELIVERED) {
                $ids[(string) $sliceId] = true;
            }
            if ((string) ($row['state'] ?? '') === PlanCompletionTrackerService::SLICE_STATE_IN_PROGRESS
                && (int) ($row['consecutive_non_delivered'] ?? 0) > 0
                && (int) ($row['consecutive_non_delivered'] ?? 0) < self::PLAN_BACKLOG_STUCK_THRESHOLD) {
                $ids[(string) $sliceId] = true;
            }
        }

        return $ids;
    }

    /**
     * A legacy pre-provider/no-proof slice can be intentionally re-admitted even
     * when AP-790's durable seen set still contains its key. This exception is
     * deliberately narrow: it only applies when the plan backlog bridge records
     * that the selected finding was one of the rehabilitated skipped keys.
     *
     * @param  array<string,mixed>  $cycle
     */
    private function cycleReplaysRehabilitatedPlanSlice(array $cycle, string $findingKey): bool
    {
        $planBacklog = is_array($cycle['plan_backlog'] ?? null) ? $cycle['plan_backlog'] : [];
        foreach (AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($planBacklog['selection_skip_rehabilitated_keys'] ?? []) as $key) {
            if ($key === $findingKey) {
                return true;
            }
        }

        return false;
    }

    /**
     * Provider-proof reconciliation intentionally revisits a previously seen slice
     * without invoking a provider: it appends the missing validation/evidence proof
     * for work that already merged. Treating that as a duplicate would mark a real
     * pre-spend cleanup as `repeated_finding` and strand ordered block 1 again.
     *
     * @param  array<string,mixed>  $cycle
     */
    private function cycleReconcilesProviderProofPlanSlice(array $cycle, string $findingKey): bool
    {
        $planBacklog = is_array($cycle['plan_backlog'] ?? null) ? $cycle['plan_backlog'] : [];

        return (string) ($planBacklog['selection_kind'] ?? '') === 'provider_proof_reconciliation'
            && in_array($findingKey, AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues([
                $planBacklog['slice_id'] ?? '',
                $planBacklog['finding_id'] ?? '',
                data_get($cycle, 'selected_finding.finding_id', ''),
            ]), true)
            && (bool) ($cycle['merge_performed'] ?? false) === false
            && (bool) ($cycle['provider_invoked'] ?? false) === false
            && (string) ($cycle['final_status'] ?? '') !== 'blocked'
            && AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []) === [];
    }

    /**
     * @param  array<string,mixed>  $cycle
     */
    private function cycleRecordsSupervisedExistingDelivery(array $cycle, string $findingKey): bool
    {
        $planBacklog = is_array($cycle['plan_backlog'] ?? null) ? $cycle['plan_backlog'] : [];

        return (string) ($planBacklog['selection_kind'] ?? '') === 'supervised_existing_delivery'
            && in_array($findingKey, AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues([
                $planBacklog['slice_id'] ?? '',
                $planBacklog['finding_id'] ?? '',
                data_get($cycle, 'selected_finding.finding_id', ''),
            ]), true)
            && (bool) ($cycle['merge_performed'] ?? false) === false
            && (bool) ($cycle['provider_invoked'] ?? false) === false
            && (string) ($cycle['final_status'] ?? '') !== 'blocked'
            && AreaFocusStringListNormalizer::coercedStringValues($cycle['blockers'] ?? []) === [];
    }

    /**
     * @param  array<string,mixed>  $input
     * @return list<string>
     */
    private function planBacklogDocs(array $input, string $areaId, string $focus): array
    {
        $explicit = AreaFocusStringListNormalizer::coercedTrimmedUniqueStringOrNumberValues($input['plan_backlog_docs'] ?? []);
        if ($explicit !== []) {
            return $explicit;
        }

        if ((bool) ($input['auto_plan_backlog'] ?? false) !== true) {
            return [];
        }
        if ($areaId !== 'agentic_engineering_os' || $focus !== 'dev_forge') {
            return [];
        }
        if ((string) ($input['scope_profile'] ?? 'factory_max') !== 'factory_max') {
            return [];
        }

        $repoRoot = $this->repoRootFromInput($input);
        $fromIndex = $this->planBacklogDocsFromIndex($repoRoot);

        return $fromIndex !== [] ? $fromIndex : self::DEFAULT_AAEOS_PLAN_BACKLOG_DOCS;
    }

    /**
     * @param  array<string,mixed>  $input
     * @param  list<string>  $docs
     */
    private function shouldEnforceOrderedPlanBacklogDocs(array $input, string $areaId, string $focus, array $docs): bool
    {
        if ($areaId !== 'agentic_engineering_os' || $focus !== 'dev_forge') {
            return false;
        }
        if ((string) ($input['scope_profile'] ?? 'factory_max') !== 'factory_max') {
            return false;
        }

        foreach ($docs as $doc) {
            if ($this->isOrderedPlanBacklogDoc($doc)) {
                return true;
            }
        }

        return false;
    }

    private function isOrderedPlanBacklogDoc(string $doc): bool
    {
        return in_array($doc, self::DEFAULT_AAEOS_PLAN_BACKLOG_DOCS, true);
    }

    /**
     * @param  list<string>  $docs
     * @return list<string>
     */
    private function planBacklogOrderPreflightBlockers(array $docs): array
    {
        $canonical = self::DEFAULT_AAEOS_PLAN_BACKLOG_DOCS;
        $positions = array_flip($canonical);
        $seen = [];
        $maxIndex = -1;
        $lastIndex = -1;

        foreach ($docs as $doc) {
            if (! isset($positions[$doc])) {
                continue;
            }

            if (isset($seen[$doc])) {
                return ['plan_order_duplicate_doc:'.$doc];
            }

            $index = (int) $positions[$doc];
            if ($index <= $lastIndex) {
                return ['plan_order_docs_out_of_order:'.$doc];
            }

            $seen[$doc] = true;
            $maxIndex = max($maxIndex, $index);
            $lastIndex = $index;
        }

        if ($maxIndex < 0) {
            return [];
        }

        for ($index = 0; $index <= $maxIndex; $index++) {
            $doc = $canonical[$index];
            if (! isset($seen[$doc])) {
                return ['plan_order_predecessor_doc_not_in_selection:'.$doc.':before:'.$canonical[$maxIndex]];
            }
        }

        return [];
    }

    /**
     * @return list<string>
     */
    private function planBacklogDocsFromIndex(string $repoRoot): array
    {
        $path = $this->absoluteRepoPath($repoRoot, self::AAEOS_PLAN_BACKLOG_INDEX);
        if (! is_file($path)) {
            return [];
        }
        $markdown = (string) file_get_contents($path);
        if ($markdown === '') {
            return [];
        }
        $count = preg_match_all('/`(atlas-aaeos-[^`]+\.md)`/', $markdown, $matches);
        if ($count === false || $count < 1) {
            return [];
        }

        $docs = [];
        foreach (array_values($matches[1]) as $file) {
            if (str_contains($file, 'index') || ! str_contains($file, 'backlog')) {
                continue;
            }
            $docs[] = 'docs/engineering-knowledge-base/'.$file;
        }

        return AreaFocusStringListNormalizer::uniqueStringValues($docs);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array<string,mixed>
     */
    private function planBacklogContext(array $input, string $areaId, string $focus): array
    {
        $context = [
            'area_id' => $areaId,
            'focus' => $focus,
            'scope_profile' => (string) ($input['scope_profile'] ?? 'factory_max'),
            'repo_root' => (string) ($input['repo_root'] ?? ''),
            'provider' => (string) ($input['provider'] ?? ''),
            'model' => (string) ($input['model'] ?? ''),
            'provider_explicit' => (bool) ($input['provider_explicit'] ?? false),
            'model_explicit' => (bool) ($input['model_explicit'] ?? false),
            'auto_merge' => (bool) ($input['auto_merge'] ?? false),
            'allow_code_auto_merge' => (bool) ($input['allow_code_auto_merge'] ?? false),
            'record' => (bool) ($input['record'] ?? false),
            'multi_agent_workcell' => (bool) ($input['multi_agent_workcell'] ?? false),
            'pull_main' => (bool) ($input['pull_main'] ?? false),
            'allow_direct_provider_driver' => (bool) ($input['allow_direct_provider_driver'] ?? false),
            'validation_commands' => AreaFocusStringListNormalizer::coercedStringValues($input['validation_commands'] ?? []),
            'cycle_index' => 0,
        ];

        return array_filter($context, static fn (mixed $value): bool => $value !== '');
    }

    /**
     * @param  list<string>  $docs
     * @param  list<string>  $blockedDocs
     * @param  list<string>  $completeDocs
     * @return array<string,mixed>
     */
    private function planBacklogNoReadySession(array $docs, array $blockedDocs, array $completeDocs): array
    {
        $allComplete = count($docs) > 0 && count($completeDocs) === count($docs);
        $blockers = $allComplete
            ? ['backlog_exhausted', 'plan_backlog_all_docs_complete']
            : AreaFocusStringListNormalizer::uniqueMergedStringValues(['plan_backlog_no_ready_slice'], $blockedDocs);

        $cycle = [
            'cycle_id' => 'plan_backlog_no_ready_'.substr(MissionCanonicalHash::sha256([$docs, $blockedDocs, $completeDocs]), 0, 16),
            'final_status' => 'blocked',
            'selected_finding' => [
                'finding_id' => $allComplete ? 'plan_backlog_exhausted' : 'plan_backlog_blocked',
                'title' => $allComplete ? 'AAEOS plan backlog exhausted' : 'AAEOS plan backlog has no ready slice',
            ],
            'blockers' => $blockers,
            'merge_performed' => false,
            'provider_invoked' => false,
            'plan_backlog' => [
                'schema_version' => self::PLAN_BACKLOG_BRIDGE_SCHEMA,
                'mode' => 'ap790_auto_plan_backlog',
                'selection_kind' => $allComplete ? 'plan_complete' : 'blocked',
                'doc_count' => count($docs),
                'complete_docs' => $completeDocs,
                'blocked_docs' => $blockedDocs,
            ],
        ];

        return [
            'schema_version' => AutonomousEvolutionSessionService::REPORT_SCHEMA,
            'status' => 'blocked',
            'cycles' => [$cycle],
            'plan_backlog' => $cycle['plan_backlog'],
        ];
    }

//__GODDEBULK_SPAN_END__
}
