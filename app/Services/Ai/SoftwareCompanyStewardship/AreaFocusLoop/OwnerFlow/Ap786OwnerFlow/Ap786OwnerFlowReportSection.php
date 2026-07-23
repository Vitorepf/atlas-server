<?php

declare(strict_types=1);

namespace App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlow;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusScalarNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\AreaFocusStringListNormalizer;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\OwnerFlow\Ap786OwnerFlowExecutor;

/**
 * AP-786 owner-flow REPORT / salvage section: blocked / preflight-skipped /
 * repair-short-circuit / execution-result reports, the owner-runtime blocker report,
 * validated-timeout salvage and small report primitives. Bodies moved verbatim from
 * Ap786OwnerFlowExecutor (GOD-DEBULK surgical split).
 */
final class Ap786OwnerFlowReportSection
{
    /**
     * A real scoped diff: at least one changed file, all within allowed scope.
     *
     * @param  list<string>  $changedFiles
     * @param  list<string>  $allowedFiles
     */
    public function diffTouchesAllowedScope(array $changedFiles, array $allowedFiles): bool
    {
        if ($changedFiles === []) {
            return false;
        }
        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Honest terminal report for a repair short-circuit (repeated-repair or
     * review-lock). It records evidence through AP-750 so the cycle is auditable
     * and surfaces the precise blocker the runner uses to advance.
     *
     * @param  list<array<string,mixed>>  $steps
     * @param  array<string,mixed>  $repairAttempt
     * @param  array<string,mixed>  $ownerResult
     * @return array<string,mixed>
     */
    public function repairShortCircuitReport(string $shortCircuit, string $owner, array $steps, array $repairAttempt, array $ownerResult): array
    {
        [$blocker, $reason] = $shortCircuit === Ap786OwnerFlowExecutor::STATUS_REPEATED_REPAIR_NO_PROGRESS
            ? [
                'owner_runtime_repeated_repair_no_progress',
                'The repair agent re-emitted a diff already tried this cycle ('.(string) ($repairAttempt['repeated_diff_hash'] ?? '').'); stopped before another provider call. Advance to a different finding or change scope.',
            ]
            : [
                'owner_runtime_review_locked',
                sprintf(
                    'Slice failed repair %d times (ceiling %d); review-locked so the loop advances to the next finding. Last error: %s.',
                    max(0, (int) ($repairAttempt['repair_failure_count'] ?? Ap786OwnerFlowExecutor::REPAIR_REVIEW_LOCK_THRESHOLD)),
                    Ap786OwnerFlowExecutor::REPAIR_REVIEW_LOCK_THRESHOLD,
                    (string) ($repairAttempt['last_error_summary'] ?? 'senior_loop_execution_not_passed'),
                ),
            ];

        return [
            'schema_version' => Ap786OwnerFlowExecutor::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => $shortCircuit,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'provider_invoked' => (bool) ($repairAttempt['provider_invoked_any_attempt'] ?? false)
                || AreaFocusScalarNormalizer::nonNegativeInt($repairAttempt['provider_calls_total'] ?? 0) > 0
                || (bool) ($ownerResult['provider_invoked'] ?? data_get($ownerResult, 'runtime_invocation.provider_invoked', false)),
            'provider_calls_total' => max(
                AreaFocusScalarNormalizer::nonNegativeInt($repairAttempt['provider_calls_total'] ?? 0),
                $this->ownerCliProviderCalls($ownerResult),
            ),
            'merge_allowed' => false,
            'reason' => $blocker,
            'owner_result' => $ownerResult,
            'steps' => $steps,
            'repair_attempt' => $repairAttempt,
            'blockers' => [$blocker],
            'blocker_details' => [[
                'blocker' => $blocker,
                'reason' => $reason,
            ]],
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * A provider process can time out after writing a valid scoped diff and
     * after the senior loop has already captured passing scope/verification
     * evidence. In that narrow case, keep the evidence honest but do not throw
     * away the completed patch solely because the provider failed to exit.
     *
     * @param  array<string,mixed>  $ownerResult
     * @param  list<string>  $allowedFiles
     * @param  list<string>  $changedFiles
     * @return array<string,mixed>
     */
    public function validatedTimeoutSalvage(string $owner, array $ownerResult, array $allowedFiles, array $changedFiles): array
    {
        $timedOut = (bool) data_get($ownerResult, 'runtime_invocation.command_result.timed_out', false);
        $providerErrors = AreaFocusStringListNormalizer::trimmedStrings(data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.provider_call.error_codes', []));
        $providerTimedOut = $timedOut || in_array('timeout', $providerErrors, true);

        $scopePassed = $this->evidenceGatePassed($ownerResult, 'scope_guard')
            || (string) data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.scope_guard_status', '') === 'passed';
        $verificationPassed = $this->evidenceGatePassed($ownerResult, 'verification')
            || (string) data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.verification_status', '') === 'passed';

        $changedWithinScope = $changedFiles !== [];
        foreach ($changedFiles as $file) {
            if (! in_array($file, $allowedFiles, true)) {
                $changedWithinScope = false;
                break;
            }
        }

        $salvaged = $owner === 'atlas_dev'
            && $providerTimedOut
            && $changedWithinScope
            && $scopePassed
            && $verificationPassed;

        return [
            'salvaged' => $salvaged,
            'reason' => $salvaged ? 'provider_timed_out_after_validated_scoped_diff' : '',
            'provider_timed_out' => $providerTimedOut,
            'scope_guard_passed' => $scopePassed,
            'verification_passed' => $verificationPassed,
            'changed_files_within_allowed_scope' => $changedWithinScope,
            'changed_file_count' => count($changedFiles),
        ];
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $salvage
     * @return array<string,mixed>
     */
    public function withValidatedTimeoutSalvage(array $ownerResult, array $salvage): array
    {
        $ownerResult['result_status'] = 'completed';
        $ownerResult['status'] = 'completed';
        $ownerResult['completion_state'] = 'passed';
        $ownerResult['summary'] = 'Atlas owner runtime produced a scoped diff with passing verification before the provider process timed out.';
        $ownerResult['validated_timeout_salvage'] = $salvage;
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', 'passed');
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_status', 'completed');
        data_set($ownerResult, 'runtime_invocation.command_result.owner_cli_blockers', []);
        data_set($ownerResult, 'runtime_invocation.senior_loop.run_summary.status', 'completed');
        data_set($ownerResult, 'runtime_invocation.senior_loop.run_summary.completion_state', 'passed');

        return $ownerResult;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     */
    public function evidenceGatePassed(array $ownerResult, string $gate): bool
    {
        foreach ((array) ($ownerResult['test_results'] ?? data_get($ownerResult, 'evidence_pack.test_results', [])) as $result) {
            if (! is_array($result)) {
                continue;
            }
            if ((string) ($result['gate'] ?? '') === $gate && (string) ($result['status'] ?? '') === 'passed') {
                return true;
            }
        }

        return false;
    }

    /**
     * Build an AP-765-compatible execution_result from the AP-759 owner_result
     * so AP-786 can emit Product Mode / Inbox evidence before any merge attempt.
     *
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $consumption
     * @param  array<string,mixed>  $finding
     * @param  list<string>  $command
     * @param  list<array<string,mixed>>  $steps
     * @param  list<string>  $blockers
     * @param  array<string,mixed>  $repairAttempt
     * @return array<string,mixed>
     */
    public function executionResult(
        array $ownerResult,
        array $consumption,
        array $finding,
        string $worktree,
        string $owner,
        array $command,
        string $ownerSandboxRunId,
        string $dispatchKind,
        bool $planOnly,
        array $steps,
        array $blockers,
        array $repairAttempt,
        array $validatedTimeoutSalvage = [],
    ): array {
        $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? []);
        $tests = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['tests'] ?? data_get($ownerResult, 'evidence_pack.tests', []));
        $testResults = is_array($ownerResult['test_results'] ?? null) ? $ownerResult['test_results'] : (array) data_get($ownerResult, 'evidence_pack.test_results', []);
        $completionState = (string) ($ownerResult['completion_state'] ?? data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_completion_state', ''));
        $status = (string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? 'partial');

        return [
            'schema_version' => 'atlas.software_company_stewardship.ap786_owner_flow_execution_result.v1',
            'execution_id' => (string) ($ownerResult['result_id'] ?? ''),
            'owner' => $owner,
            'result_status' => $status,
            'completion_state' => $completionState !== '' ? $completionState : ($status === 'completed' ? 'passed' : 'failed'),
            'summary' => (string) ($ownerResult['summary'] ?? data_get($ownerResult, 'evidence_pack.summary', 'Atlas owner runtime ran an allowlisted command inside the AP-756 sandbox via AP-759.')),
            // AP-765 inbox richness: carry the real finding identity so the inbox
            // shows WHAT was found / WHY it matters instead of generic boilerplate.
            'finding_title' => (string) ($finding['title'] ?? ''),
            'finding_kind' => (string) ($finding['kind'] ?? ''),
            'finding_why_it_matters' => (string) ($finding['why_it_matters'] ?? $finding['value_reason'] ?? ''),
            'finding_detail' => (string) ($finding['detail'] ?? ''),
            'finding_id' => (string) ($finding['finding_id'] ?? ''),
            'spec_id' => (string) data_get($finding, 'spec_seed.candidate_id', ''),
            'handoff_id' => 'AP-786:'.(string) ($consumption['consumption_id'] ?? ''),
            'sandbox_id' => (string) data_get($consumption, 'sandbox_binding.sandbox_id', ''),
            'branch_ref' => (string) data_get($consumption, 'sandbox_binding.branch_name', ''),
            'worktree_path' => $worktree,
            'changed_files' => $changedFiles,
            'tests' => $tests !== [] ? $tests : ['atlas:dev:senior-loop:run (AP-759 owner command)'],
            'validation_commands' => [implode(' ', array_map(static fn ($p): string => (string) $p, $command))],
            'test_results' => $testResults,
            'evidence_pack' => is_array($ownerResult['evidence_pack'] ?? null) ? $ownerResult['evidence_pack'] : [
                'summary' => 'AP-759 owner runtime command receipt.',
                'changed_files' => $changedFiles,
                'tests' => $tests,
            ],
            'risks' => AreaFocusStringListNormalizer::trimmedStrings($ownerResult['risks'] ?? []),
            'rollback' => (string) ($ownerResult['rollback'] ?? 'Discard the isolated AP-756 branch/worktree; no merge was performed.'),
            'runtime_execution_started' => true,
            'uses_full_owner_runtime_chain' => true,
            'provider_router_used' => false,
            'owner_sandbox_run_id' => $ownerSandboxRunId,
            'real_execution_bridge' => [
                'schema_version' => Ap786OwnerFlowExecutor::REAL_EXECUTION_BRIDGE_SCHEMA,
                'ap790_backlog_item' => Ap786OwnerFlowExecutor::AP790_BACKLOG_OWNER_RUNTIME_REAL_EXECUTION_BRIDGE,
                'owner_chain_ap_contracts' => $this->ownerChainApContracts($steps),
                'dispatch_kind' => $dispatchKind,
                'plan_only' => $planOnly,
                'repair_attempt' => $repairAttempt,
                'validated_timeout_salvage' => $validatedTimeoutSalvage,
                'blockers' => $blockers,
            ],
            'provider_invoked' => (bool) ($ownerResult['provider_invoked'] ?? false),
            'merge_performed' => false,
            'deploy_performed' => false,
            'external_push_performed' => false,
            'secret_access' => false,
            'destructive_change' => false,
        ];
    }

    /**
     * @param  list<array<string,mixed>>  $steps
     * @return list<string>
     */
    public function ownerChainApContracts(array $steps): array
    {
        $contracts = [];
        foreach ($steps as $step) {
            if (! is_array($step)) {
                continue;
            }
            $ap = trim((string) ($step['ap_contract'] ?? ''));
            if ($ap !== '' && ! in_array($ap, $contracts, true)) {
                $contracts[] = $ap;
            }
        }

        return $contracts;
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     * @param  array<string,mixed>  $repairAttempt
     * @return array{blockers:list<string>,details:list<array<string,string>>}
     */
    public function ownerRuntimeBlockerReport(array $ownerResult, bool $forgePlanned, bool $completed, array $repairAttempt = []): array
    {
        if ($completed) {
            return ['blockers' => [], 'details' => []];
        }
        if ($forgePlanned) {
            return [
                'blockers' => ['forge_runtime_dispatch_planned_only'],
                'details' => [[
                    'blocker' => 'forge_runtime_dispatch_planned_only',
                    'reason' => 'Forge runtime-dispatch produced a governed plan only; re-run with live Obra authority or switch owner to atlas_dev for executable patches.',
                ]],
            ];
        }

        $blockers = [];
        $details = [];
        $commandResult = is_array(data_get($ownerResult, 'runtime_invocation.command_result'))
            ? data_get($ownerResult, 'runtime_invocation.command_result')
            : [];
        $resultStatus = strtolower(trim((string) ($ownerResult['result_status'] ?? $ownerResult['status'] ?? '')));
        $completion = strtolower(trim((string) ($commandResult['owner_cli_completion_state'] ?? '')));
        $providerCalls = $this->ownerCliProviderCalls($ownerResult);
        $minimaxCodexReview = is_array($ownerResult['minimax_codex_review'] ?? null)
            ? $ownerResult['minimax_codex_review']
            : [];
        $providerProofCalls = $providerCalls
            + AreaFocusScalarNormalizer::nonNegativeInt($minimaxCodexReview['reviewed_provider_calls'] ?? 0)
            + AreaFocusScalarNormalizer::nonNegativeInt($minimaxCodexReview['review_provider_calls'] ?? 0);
        $changedFiles = AreaFocusStringListNormalizer::trimmedStrings($ownerResult['changed_files'] ?? []);
        $routingDecision = strtolower(trim((string) data_get(
            $ownerResult,
            'runtime_invocation.senior_loop.routing_decision',
            data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.routing_decision', ''),
        )));
        $debugReason = trim((string) data_get($ownerResult, 'runtime_invocation.senior_loop.debug_loop.reason', ''));
        $providerErrors = AreaFocusStringListNormalizer::trimmedStrings(data_get($ownerResult, 'runtime_invocation.senior_loop.run_summary.provider_call.error_codes', []));
        $commandTimedOut = (bool) ($commandResult['timed_out'] ?? false);

        if ($completion === 'no_patch_needed') {
            if ($providerProofCalls === 0 || $changedFiles === []) {
                $blockers[] = 'owner_runtime_no_patch_needed_without_proof';
                $details[] = [
                    'blocker' => 'owner_runtime_no_patch_needed_without_proof',
                    'reason' => 'Atlas Dev returned no_patch_needed without provider proof or sandbox diff; edit an allowed file or cite file:line plus passing focused test output.',
                ];
            } else {
                $blockers[] = 'owner_runtime_no_patch_needed';
                $details[] = [
                    'blocker' => 'owner_runtime_no_patch_needed',
                    'reason' => 'Provider claimed no_patch_needed despite execution; prove the exact acceptance criterion with file:line evidence and passing focused tests.',
                ];
            }
        }

        // Bug #2: file changes with zero provider calls = deterministic scaffold
        // without real execution. Surface the precise, honest blocker so the
        // cycle is never mistaken for a real implement attempt. It is NOT a
        // permanent blocker — the finding is retried on a later cycle.
        if ($providerProofCalls === 0 && $changedFiles !== [] && ! $commandTimedOut) {
            $blockers[] = 'owner_runtime_scaffold_without_provider';
            $details[] = [
                'blocker' => 'owner_runtime_scaffold_without_provider',
                'reason' => 'Owner runtime produced changed files with zero provider calls — deterministic scaffold, not a real execution. A cycle that intends to implement MUST invoke a real provider and produce a real patch/evidence; this scaffold is rejected (no merge) and the finding is retried.',
            ];
        }

        if ($commandTimedOut || in_array('timeout', $providerErrors, true)) {
            $blockers[] = 'owner_runtime_provider_timeout';
            $details[] = [
                'blocker' => 'owner_runtime_provider_timeout',
                'reason' => 'Owner provider invocation timed out before producing a mergeable diff; retry with a larger provider timeout or reroute through AtlasDecide failover.',
            ];
        }

        // Provider unavailable / rate-limited: the provider never produced a
        // verdict for environmental reasons. These are TRANSIENT (honest retry
        // window), never permanent quarantine — the finding is retried.
        if (in_array('unavailable', $providerErrors, true) || in_array('provider_unavailable', $providerErrors, true)) {
            $blockers[] = 'owner_runtime_provider_unavailable';
            $details[] = [
                'blocker' => 'owner_runtime_provider_unavailable',
                'reason' => 'Owner provider was unavailable; no real execution occurred. Transient — the finding is retried on a later cycle, never permanently quarantined.',
            ];
        }
        if (in_array('rate_limited', $providerErrors, true) || in_array('rate_limit', $providerErrors, true)) {
            $blockers[] = 'owner_runtime_provider_rate_limited';
            $details[] = [
                'blocker' => 'owner_runtime_provider_rate_limited',
                'reason' => 'Owner provider was rate-limited; no real execution occurred. Transient — the finding is retried on a later cycle, never permanently quarantined.',
            ];
        }

        if ($completion === 'failed' || (string) ($commandResult['owner_cli_status'] ?? '') === 'failed') {
            $details[] = [
                'blocker' => 'owner_runtime_senior_loop_failed',
                'reason' => $debugReason !== ''
                    ? $debugReason
                    : 'Senior loop failed verification or scope; inspect verification_receipt and scope_guard in the AP-759 stdout JSON.',
            ];
        }

        if ($resultStatus !== '' && $resultStatus !== 'completed' && $completion === '' && $blockers === []) {
            $blockers[] = 'owner_runtime_result_failed';
            $details[] = [
                'blocker' => 'owner_runtime_result_failed',
                'reason' => 'Owner runtime returned result_status='.$resultStatus.' without machine-readable owner_cli_blockers; AP-759 must expose completion_state/blockers or the candidate is quarantined instead of being retried blindly.',
            ];
        }

        foreach (AreaFocusStringListNormalizer::trimmedStrings($commandResult['owner_cli_blockers'] ?? []) as $blocker) {
            $mapped = match ($blocker) {
                'senior_loop_execution_not_passed' => 'owner_runtime_senior_loop_execution_not_passed',
                'routing_not_executable' => 'owner_runtime_routing_not_executable',
                'scope_violation' => 'owner_runtime_scope_violation',
                default => 'owner_runtime_'.$blocker,
            };
            $blockers[] = $mapped;
            $exitCode = $blocker === 'senior_loop_execution_not_passed'
                ? ($commandResult['exit_code'] ?? null)
                : null;
            $stderrExcerpt = $blocker === 'senior_loop_execution_not_passed'
                ? trim((string) ($commandResult['stderr_excerpt'] ?? ''))
                : '';
            $details[] = [
                'blocker' => $mapped,
                'reason' => match ($blocker) {
                    'senior_loop_execution_not_passed' => 'Senior loop did not reach passed scope_guard and verification; apply a minimal patch in allowed_files and rerun the focused worktree validation command.'
                        .($exitCode !== null ? ' (exit_code='.$exitCode.')' : '')
                        .($stderrExcerpt !== '' ? ' stderr: '.mb_substr($stderrExcerpt, 0, 500) : ''),
                    'routing_not_executable' => $routingDecision !== ''
                        ? 'Atlas Dev routing blocked execution (routing_decision='.$routingDecision.'); keep the task as a scoped repair with allowed_files and avoid forge-preview trigger phrases in the owner intent.'
                        : 'Atlas Dev routing blocked execution; keep the task as a scoped repair inside allowed_files only.',
                    'scope_violation' => 'Patch touched paths outside allowed_files; restrict edits to the declared allowed_files list.',
                    default => 'Owner CLI reported blocker '.$blocker.'; inspect AP-759 command_result stdout JSON.',
                },
            ];
        }

        if ($completion === 'scope_violation' && ! in_array('owner_runtime_scope_violation', $blockers, true)) {
            $blockers[] = 'owner_runtime_scope_violation';
            $details[] = [
                'blocker' => 'owner_runtime_scope_violation',
                'reason' => 'Completion state scope_violation indicates edits outside allowed_files; restrict changes to the declared scope.',
            ];
        }

        if (($repairAttempt['retried'] ?? false) === true) {
            $firstDiagnostics = AreaFocusStringListNormalizer::trimmedStrings($repairAttempt['first_diagnostics'] ?? []);
            $blockers[] = 'owner_runtime_senior_loop_repair_exhausted';
            $details[] = [
                'blocker' => 'owner_runtime_senior_loop_repair_exhausted',
                'reason' => sprintf(
                    'AP-786 retried senior-loop once after scoped diff (first_run=%s status=%s provider_calls=%d, repair_run=%s status=%s); first diagnostics: %s.',
                    (string) ($repairAttempt['first_owner_sandbox_run_id'] ?? ''),
                    (string) ($repairAttempt['first_result_status'] ?? ''),
                    AreaFocusScalarNormalizer::nonNegativeInt($repairAttempt['first_provider_calls'] ?? 0),
                    (string) ($repairAttempt['repair_owner_sandbox_run_id'] ?? ''),
                    (string) ($repairAttempt['repair_result_status'] ?? ''),
                    $firstDiagnostics !== [] ? implode('; ', $firstDiagnostics) : 'none',
                ),
            ];
        }

        $blockers = AreaFocusStringListNormalizer::uniqueStringValues($blockers !== [] ? $blockers : ['owner_runtime_result_not_completed']);
        if ($details === [] && $blockers !== []) {
            $details[] = [
                'blocker' => $blockers[0],
                'reason' => 'Owner runtime did not complete with mergeable evidence; review changed_files and test_results on the AP-759 owner_result.',
            ];
        }

        return ['blockers' => $blockers, 'details' => $details];
    }

    /**
     * @param  array<string,mixed>  $extra
     * @return array<string,mixed>
     */
    public function blocked(string $reason, string $owner, array $steps, array $extra = []): array
    {
        return [
            'schema_version' => Ap786OwnerFlowExecutor::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => Ap786OwnerFlowExecutor::STATUS_BLOCKED,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => $steps !== [],
            'provider_router_used' => false,
            'merge_allowed' => false,
            'reason' => $reason,
            'blockers' => [$reason],
            'steps' => $steps,
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ] + $extra;
    }

    /**
     * Honest terminal report for a zero-provider pre-flight skip. No provider was
     * invoked, no merge is allowed, and the cycle is flagged NOT token-spending so
     * the loop's merges/token-spending-cycles metric excludes it.
     *
     * @param  list<array<string,mixed>>  $steps
     * @param  array<string,mixed>  $preflightGate
     * @return array<string,mixed>
     */
    public function preflightSkipped(string $owner, array $steps, array $preflightGate): array
    {
        $blockers = AreaFocusStringListNormalizer::trimmedStrings($preflightGate['blockers'] ?? []);

        return [
            'schema_version' => Ap786OwnerFlowExecutor::REPORT_SCHEMA,
            'ap_contract' => 'AP-786',
            'status' => Ap786OwnerFlowExecutor::STATUS_PREFLIGHT_SKIPPED,
            'owner' => $owner,
            'uses_full_owner_runtime_chain' => false,
            'provider_router_used' => false,
            'provider_invoked' => false,
            // Metric: a pre-flight skip is a cheap, non-token-spending cycle. The
            // loop divides merges by token-spending cycles; this must be excluded.
            'token_spending_cycle' => false,
            'preflight_gate' => $preflightGate,
            'merge_allowed' => false,
            'reason' => $blockers[0] ?? 'preflight_not_admitted',
            'blockers' => $blockers,
            'steps' => $steps,
            'claim_policy' => $this->claimPolicy(),
            'generated_at' => gmdate('c'),
        ];
    }

    /**
     * @return array<string,mixed>
     */
    public function step(string $ap, string $name, string $status): array
    {
        return ['ap_contract' => $ap, 'step' => $name, 'status' => (string) $status];
    }

    /**
     * @param  array<string,mixed>  $ownerResult
     */
    public function ownerCliProviderCalls(array $ownerResult): int
    {
        return AreaFocusScalarNormalizer::nonNegativeInt(data_get($ownerResult, 'runtime_invocation.command_result.owner_cli_provider_calls', 0));
    }

    /**
     * @return array<string,bool|string>
     */
    public function claimPolicy(): array
    {
        return [
            'mode' => 'full_atlas_owner_runtime_flow',
            'provider_router_invoked' => false,
            'direct_provider_driver_used' => false,
            'owner_command_runs_only_via_ap759' => true,
            'merges' => false,
            'deploys' => false,
            'external_push' => false,
            'secret_access' => false,
            'operator_review_required' => true,
        ];
    }
}
