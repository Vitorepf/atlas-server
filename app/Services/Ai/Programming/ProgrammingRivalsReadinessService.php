<?php

namespace App\Services\Ai\Programming;

use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasToolRun;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Throwable;

class ProgrammingRivalsReadinessService
{
    /**
     * @var array<string,array<string,mixed>>
     */
    private static array $localBenchmarkRuntimeCache = [];

    public function __construct(
        private readonly ProgrammingRetrievalBenchmarkService $retrievalBenchmark,
        private readonly ProgrammingTestImpactBenchmarkService $testImpactBenchmark,
        private readonly ProgrammingPatchVerifierBenchmarkService $patchVerifierBenchmark,
        private readonly ProgrammingRepairLoopBenchmarkService $repairLoopBenchmark,
        private readonly EngineeringBenchmarkService $engineeringBenchmarks,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function report(string $workspace, bool $refreshLocalBenchmarks = false): array
    {
        $localBenchmarkPacket = $this->localBenchmarks($workspace, $refreshLocalBenchmarks);
        $localBenchmarks = $localBenchmarkPacket['benchmarks'];
        $localPassed = collect($localBenchmarks)->every(fn (array $benchmark): bool => $benchmark['status'] === 'passed');
        $fairClaude = $this->fairClaudeState();
        $comparableCases = (int) data_get($fairClaude, 'report.readiness.comparable_count', 0);
        $realBatteryAttempted = (int) data_get($fairClaude, 'report.scope.paired_run_count', 0) > 0
            || (int) data_get($fairClaude, 'report.scope.fair_run_count', 0) > 0;
        $invalidCaseCount = (int) data_get($fairClaude, 'report.paired_scorecard.invalid_case_count', 0);
        $realBatteryInvalid = $realBatteryAttempted && $invalidCaseCount > 0 && $comparableCases === 0;
        $invalidBatteryRequiresTriage = $realBatteryInvalid
            && (bool) data_get($fairClaude, 'report.result_integrity.triage_required_before_rerun', true);
        $readyForExternalBattery = $localPassed;
        $claimReady = $readyForExternalBattery
            && $comparableCases > 0
            && (bool) data_get($fairClaude, 'report.readiness.ready_for_claim', false);
        $integrityAssurance = $this->integrityAssurance($localPassed, $claimReady, $fairClaude);
        $resultIntegrityDiagnostics = $this->resultIntegrityDiagnostics($claimReady, $fairClaude);
        $fairClaudeResultIntegrity = $this->fairClaudeResultIntegrity($fairClaude);
        $latestRealBatteryEvidence = $this->latestRealBatteryEvidence($fairClaude, $realBatteryAttempted, $realBatteryInvalid);
        $currentWorkspacePreflight = $this->currentWorkspacePreflight($workspace);
        $currentLocalRecheckEvidence = $this->currentLocalRecheckEvidence($workspace);
        $invalidBatteryTriagePacket = $this->invalidBatteryTriagePacket(
            $latestRealBatteryEvidence,
            $realBatteryInvalid,
            $invalidBatteryRequiresTriage,
            $localPassed,
            $currentWorkspacePreflight,
            $currentLocalRecheckEvidence,
        );

        return [
            'schema_version' => 'atlas.programming.rivals_readiness.v1',
            'status' => $claimReady
                ? 'claim_ready'
                : ($readyForExternalBattery ? ($realBatteryInvalid ? 'external_battery_invalid' : 'external_battery_required') : 'local_benchmarks_failed'),
            'generated_at' => now()->toJSON(),
            'workspace_hash' => hash('sha256', $workspace),
            'summary' => [
                'local_programming_foundation_ready' => $localPassed,
                'external_provider_battery_executed' => $realBatteryAttempted,
                'real_provider_battery_attempted' => $realBatteryAttempted,
                'comparable_case_count' => $comparableCases,
                'valid_comparable_cases_generated' => $comparableCases > 0,
                'invalid_case_count' => $invalidCaseCount,
                'real_battery_invalid' => $realBatteryInvalid,
                'invalid_battery_requires_triage_before_rerun' => $invalidBatteryRequiresTriage,
                'claim_ready' => $claimReady,
                'synthetic_scores_allowed' => false,
            ],
            'local_benchmarks' => $localBenchmarks,
            'local_benchmark_cache' => $localBenchmarkPacket['cache'],
            'fair_claude_rivals' => $fairClaude,
            'integrity_assurance' => $integrityAssurance,
            'result_integrity_diagnostics' => $resultIntegrityDiagnostics,
            'fair_claude_result_integrity' => $fairClaudeResultIntegrity,
            'latest_real_battery_evidence' => $latestRealBatteryEvidence,
            'current_local_recheck_evidence' => $currentLocalRecheckEvidence,
            'invalid_battery_triage_packet' => $invalidBatteryTriagePacket,
            'current_workspace_preflight' => $currentWorkspacePreflight,
            'operator_execution_packet' => $this->operatorExecutionPacket($localPassed, $claimReady, $fairClaude, $workspace, $currentWorkspacePreflight),
            'rivals_programming_contract' => [
                'schema_version' => 'atlas.programming.rivals_contract.v1',
                'goal' => 'Measure Atlas programming quality against external coding agents with protocol-valid paired cases.',
                'what_local_benchmarks_prove' => [
                    'agentic_rag_retrieves_expected_programming_context',
                    'test_impact_selects_expected_tests',
                    'patch_verifier_blocks_ungrounded_patches',
                    'repair_loop_plans_repair_or_human_review_with_receipts',
                ],
                'what_real_rivals_must_prove' => [
                    'Atlas arm and rival arm solve the same case from equivalent initial state',
                    'Provider/model locks are explicit and audited',
                    'No synthetic score is accepted as provider result',
                    'Pass without human requires deterministic gates and zero human intervention',
                    'Report includes case comparisons, replay manifest and export verification',
                    'External variables are recorded but cannot become score unless the protocol marks the case comparable',
                ],
                'minimum_claim_gate' => [
                    'local_benchmarks_passed' => $localPassed,
                    'real_comparable_cases_required' => true,
                    'current_real_comparable_cases' => $comparableCases,
                    'claim_ready' => $claimReady,
                    'integrity_status' => $integrityAssurance['status'],
                    'score_diagnostic_status' => $resultIntegrityDiagnostics['status'],
                    'fair_claude_result_integrity_status' => $fairClaudeResultIntegrity['status'] ?? 'unknown',
                    'claim_winner_admitted' => (bool) ($fairClaudeResultIntegrity['claim_winner_admitted'] ?? false),
                    'triage_status' => $invalidBatteryTriagePacket['status'],
                ],
            ],
            'commands' => [
                'local_retrieval' => 'php artisan atlas:programming:retrieval-benchmark --json',
                'local_test_impact' => 'php artisan atlas:programming:test-impact-benchmark --json',
                'local_patch_verifier' => 'php artisan atlas:programming:patch-verifier-benchmark --json',
                'local_repair_loop' => 'php artisan atlas:programming:repair-loop-benchmark --json',
                'real_rivals_runbook' => 'php artisan atlas:engineering:benchmark:rivals runbook --suite=atlas-fair-claude-v1 --workspace=<clean-atlas-workspace> --claude-code-baseline-workspace=<separate-clean-baseline-workspace> --json',
                'real_rivals_quick' => 'php artisan atlas:engineering:benchmark:rivals run --quick --suite=atlas-fair-claude-v1 --workspace=<clean-atlas-workspace> --claude-code-baseline-workspace=<separate-clean-baseline-workspace> --confirm-runbook-reviewed --confirm-provider-cost --json',
                'real_rivals_medium' => 'php artisan atlas:engineering:benchmark:rivals run --medium --suite=atlas-fair-claude-v1 --workspace=<clean-atlas-workspace> --claude-code-baseline-workspace=<separate-clean-baseline-workspace> --confirm-runbook-reviewed --confirm-provider-cost --json',
                'real_rivals_report' => 'php artisan atlas:engineering:benchmark:rivals report --suite=atlas-fair-claude-v1 --json',
                'real_rivals_export_verify' => 'php artisan atlas:engineering:benchmark:rivals verify --suite=atlas-fair-claude-v1 --output-dir=atlas-rivals-report --json',
            ],
            'safety' => [
                'readiness_command_dispatches_provider' => false,
                'real_provider_execution_requires_explicit_cost_confirmation' => true,
                'real_provider_execution_requires_runbook_review' => true,
                'external_cost_possible_only_for_real_rivals_run_commands' => true,
            ],
        ];
    }

    /**
     * @return array{benchmarks:array<string,array<string,mixed>>,cache:array<string,mixed>}
     */
    private function localBenchmarks(string $workspace, bool $refresh): array
    {
        $cacheKey = hash('sha256', $workspace);
        if (! $refresh && isset(self::$localBenchmarkRuntimeCache[$cacheKey])) {
            return [
                'benchmarks' => self::$localBenchmarkRuntimeCache[$cacheKey],
                'cache' => [
                    'schema_version' => 'atlas.programming.local_benchmark_runtime_cache.v1',
                    'scope' => 'process_memory_only',
                    'hit' => true,
                    'refresh_requested' => false,
                    'persistent_cache' => false,
                    'provider_state_cached' => false,
                ],
            ];
        }

        $retrieval = $this->retrievalBenchmark->run($workspace, $refresh);
        $testImpact = $this->testImpactBenchmark->run();
        $patchVerifier = $this->patchVerifierBenchmark->run();
        $repairLoop = $this->repairLoopBenchmark->run();

        self::$localBenchmarkRuntimeCache[$cacheKey] = [
            'retrieval' => $this->benchmarkSummary($retrieval),
            'test_impact' => $this->benchmarkSummary($testImpact),
            'patch_verifier' => $this->benchmarkSummary($patchVerifier),
            'repair_loop' => $this->benchmarkSummary($repairLoop),
        ];

        return [
            'benchmarks' => self::$localBenchmarkRuntimeCache[$cacheKey],
            'cache' => [
                'schema_version' => 'atlas.programming.local_benchmark_runtime_cache.v1',
                'scope' => 'process_memory_only',
                'hit' => false,
                'refresh_requested' => $refresh,
                'persistent_cache' => false,
                'provider_state_cached' => false,
            ],
        ];
    }

    /**
     * @param  array<string,mixed>  $fairClaude
     * @return array<string,mixed>
     */
    private function integrityAssurance(bool $localPassed, bool $claimReady, array $fairClaude): array
    {
        $readiness = (array) data_get($fairClaude, 'report.readiness', []);
        $scorecard = (array) data_get($fairClaude, 'report.paired_scorecard', []);
        $battery = (array) data_get($fairClaude, 'report.battery_execution_contract', []);
        $scope = (array) data_get($fairClaude, 'report.scope', []);
        $comparableCases = (int) ($readiness['comparable_count'] ?? 0);
        $minimumComparableCases = (int) ($readiness['minimum_comparable_case_count'] ?? 0);
        $blockingReasons = array_values(array_unique(array_filter(array_merge(
            $localPassed ? [] : ['local_programming_benchmarks_not_passed'],
            (array) ($readiness['blocking_reasons'] ?? []),
            (array) ($fairClaude['blocking_reasons'] ?? []),
            ((int) ($scorecard['invalid_case_count'] ?? 0) > 0) ? ['real_battery_has_protocol_invalid_cases'] : [],
            $claimReady ? [] : ['claim_blocked_until_protocol_valid_real_comparable_cases']
        ))));

        return [
            'schema_version' => 'atlas.programming.rivals_integrity_assurance.v1',
            'status' => $claimReady ? 'claim_ready' : 'claim_blocked',
            'ab_test_validity_model' => [
                'same_case_snapshot_required' => true,
                'equivalent_initial_state_required' => true,
                'same_acceptance_gates_required' => true,
                'no_provider_specific_case_filtering' => true,
                'human_intervention_policy_equalized' => true,
                'synthetic_scores_allowed' => false,
            ],
            'external_variable_controls' => [
                'provider_and_model_must_be_locked_per_arm' => true,
                'environment_snapshot_must_be_recorded' => true,
                'replay_manifest_must_verify_artifacts' => true,
                'cost_and_latency_are_metrics_not_quality_labels' => true,
                'non_evaluated_variables_cannot_decide_winner' => true,
            ],
            'quality_scope_policy' => [
                'schema_version' => 'atlas.programming.rivals_quality_scope_policy.v1',
                'same_quality_scope_required_for_both_arms' => true,
                'changed_only_quality_scan_required_for_patch_cases' => true,
                'repo_wide_debt_outside_case_scope_cannot_decide_winner' => true,
                'repo_wide_scan_allowed_as_diagnostic_only' => true,
                'clean_worktree_required_before_provider_dispatch' => true,
                'current_valid_local_recheck_commands' => [
                    'quality_changed_only' => 'php artisan atlas:engineering:quality-scan --workspace=<clean-atlas-workspace> --profile=auto --changed-only --timeout=300 --json',
                    'visual_smoke' => 'php artisan atlas:engineering:visual-smoke --workspace=<clean-atlas-workspace> --artifact-dir=atlas-visual-report --timeout=45 --baseline=observe --screenshot-driver=auto --json',
                ],
            ],
            'score_admission_gate' => [
                'local_benchmarks_passed' => $localPassed,
                'real_provider_dispatch_required_for_claim' => true,
                'current_comparable_case_count' => $comparableCases,
                'minimum_comparable_case_count' => $minimumComparableCases,
                'protocol_validity_rate' => (float) ($scorecard['protocol_validity_rate'] ?? 0),
                'final_gate_pass_rate' => (float) ($scorecard['final_gate_pass_rate'] ?? 0),
                'pass_without_human_rate' => (float) ($scorecard['pass_without_human_rate'] ?? 0),
                'replay_verified' => (bool) data_get($battery, 'current_state.replay_verified', false),
                'export_verification_required' => true,
                'claim_ready' => $claimReady,
            ],
            'observed_state' => [
                'paired_run_count' => (int) ($scope['paired_run_count'] ?? 0),
                'fair_run_count' => (int) ($scope['fair_run_count'] ?? 0),
                'case_comparison_count' => (int) ($scope['case_comparison_count'] ?? 0),
                'invalid_case_count' => (int) ($scorecard['invalid_case_count'] ?? 0),
                'provider_violation_count' => (int) ($scorecard['provider_violation_count'] ?? 0),
                'fallback_violation_count' => (int) ($scorecard['fallback_violation_count'] ?? 0),
            ],
            'blocking_reasons' => $blockingReasons,
        ];
    }

    /**
     * @param  array<string,mixed>  $fairClaude
     * @return array<string,mixed>
     */
    private function resultIntegrityDiagnostics(bool $claimReady, array $fairClaude): array
    {
        $caseComparisons = collect((array) data_get($fairClaude, 'report.case_comparisons', []))
            ->filter(fn (mixed $case): bool => is_array($case))
            ->values();
        $invalidCases = $caseComparisons
            ->filter(fn (array $case): bool => ! (bool) ($case['comparable'] ?? false))
            ->values();
        $reasonCounts = $invalidCases
            ->flatMap(fn (array $case): array => (array) ($case['blocking_reasons'] ?? []))
            ->filter(fn (mixed $reason): bool => is_string($reason) && $reason !== '')
            ->countBy()
            ->sortDesc();

        return [
            'schema_version' => 'atlas.programming.rivals_result_integrity_diagnostics.v1',
            'status' => $claimReady
                ? 'score_claim_ready'
                : ($caseComparisons->isEmpty() ? 'no_real_case_comparisons' : 'no_valid_comparable_score'),
            'operator_answer' => $claimReady
                ? 'Current Rivals score is backed by comparable real provider cases.'
                : 'Current data must not be rendered as Atlas lost or won; invalid and inconclusive cases are excluded from win/loss math.',
            'score_admission_policy' => [
                'invalid_cases_count_as_losses' => false,
                'inconclusive_cases_count_as_losses' => false,
                'only_comparable_cases_enter_win_loss_math' => true,
                'case_level_blocking_reasons_required' => true,
            ],
            'observed_counts' => [
                'case_comparison_count' => $caseComparisons->count(),
                'comparable_count' => (int) data_get($fairClaude, 'report.readiness.comparable_count', 0),
                'invalid_or_inconclusive_count' => $invalidCases->count(),
                'atlas_win_count' => (int) data_get($fairClaude, 'report.readiness.atlas_win_count', 0),
                'rival_win_count' => (int) data_get($fairClaude, 'report.readiness.claude_code_baseline_win_count', 0),
                'tie_count' => (int) data_get($fairClaude, 'report.readiness.tie_count', 0),
            ],
            'case_status_counts' => $caseComparisons
                ->countBy(fn (array $case): string => (string) ($case['comparison_status'] ?? 'unknown'))
                ->all(),
            'blocking_reason_counts' => $reasonCounts->all(),
            'first_invalid_cases' => $invalidCases
                ->take(5)
                ->map(fn (array $case): array => [
                    'case_code' => $case['case_code'] ?? null,
                    'title' => $case['title'] ?? null,
                    'comparison_status' => $case['comparison_status'] ?? 'unknown',
                    'blocking_reasons' => $case['blocking_reasons'] ?? [],
                    'plain_explanation' => $this->plainComparisonExplanation($case),
                ])
                ->values()
                ->all(),
        ];
    }

    /**
     * @param  array<string,mixed>  $fairClaude
     * @return array<string,mixed>
     */
    private function fairClaudeResultIntegrity(array $fairClaude): array
    {
        $integrity = (array) data_get($fairClaude, 'report.result_integrity', []);
        if ($integrity === []) {
            return [
                'schema_version' => 'atlas.programming.fair_claude_result_integrity_projection.v1',
                'status' => 'missing',
                'score_admitted' => false,
                'claim_winner_admitted' => false,
                'winner_for_claim' => null,
                'provisional_leader' => null,
                'ui_contract' => [
                    'must_not_render_winner' => true,
                    'must_not_render_invalid_cases_as_losses' => true,
                ],
                'projection_source' => 'fair_claude_report_missing_result_integrity',
            ];
        }

        return [
            'schema_version' => 'atlas.programming.fair_claude_result_integrity_projection.v1',
            'source_schema_version' => $integrity['schema_version'] ?? null,
            'status' => $integrity['status'] ?? 'unknown',
            'operator_headline' => $integrity['operator_headline'] ?? null,
            'score_admitted' => (bool) ($integrity['score_admitted'] ?? false),
            'claim_winner_admitted' => (bool) ($integrity['claim_winner_admitted'] ?? false),
            'winner_for_claim' => $integrity['winner_for_claim'] ?? null,
            'provisional_leader' => $integrity['provisional_leader'] ?? null,
            'policy' => $integrity['policy'] ?? [],
            'counts' => $integrity['counts'] ?? [],
            'ui_contract' => $integrity['ui_contract'] ?? [],
            'projection_source' => 'fair_claude_report_result_integrity',
        ];
    }

    /**
     * @param  array<string,mixed>  $case
     */
    private function plainComparisonExplanation(array $case): string
    {
        $status = (string) ($case['comparison_status'] ?? 'unknown');
        $reasons = array_values(array_filter((array) ($case['blocking_reasons'] ?? []), 'is_string'));

        if ($status === 'atlas_protocol_invalid') {
            return 'Atlas arm did not prove a valid fair protocol, so this case is excluded from the score instead of counted as a loss.';
        }
        if ($status === 'baseline_planned' || in_array('baseline_not_verified_pass', $reasons, true)) {
            return 'Rival baseline was not verified as a completed deterministic pass, so the case is not comparable.';
        }
        if ($status === 'comparable') {
            return 'Case is comparable and may enter win/loss math.';
        }

        return 'Case is not comparable under the current Rivals protocol and must be treated as diagnostic evidence, not as a win/loss result.';
    }

    /**
     * @param  array<string,mixed>  $report
     * @return array<string,mixed>
     */
    private function benchmarkSummary(array $report): array
    {
        return [
            'schema_version' => $report['schema_version'] ?? null,
            'status' => $report['status'] ?? 'unknown',
            'benchmark_id' => $report['benchmark_id'] ?? null,
            'golden_set' => $report['golden_set'] ?? [],
            'metrics' => $report['metrics'] ?? [],
            'promotion_gate' => $report['promotion_gate'] ?? [],
            'runtime_cache' => $report['runtime_cache'] ?? null,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function fairClaudeState(): array
    {
        try {
            $suite = AtlasEngineeringBenchmarkSuite::query()
                ->where('slug', 'atlas-fair-claude-v1')
                ->first();

            if (! $suite) {
                return [
                    'status' => 'suite_not_prepared',
                    'suite_slug' => 'atlas-fair-claude-v1',
                    'report' => null,
                    'blocking_reasons' => ['fair_claude_suite_not_prepared'],
                ];
            }

            $report = $this->engineeringBenchmarks->fairClaudeReportPayload($suite, [
                'limit' => 20,
            ]);

            return [
                'status' => (string) data_get($report, 'readiness.status', 'unknown'),
                'suite_slug' => 'atlas-fair-claude-v1',
                'report' => [
                    'readiness' => $report['readiness'] ?? [],
                    'executive_summary' => $report['executive_summary'] ?? [],
                    'result_integrity' => $report['result_integrity'] ?? [],
                    'paired_scorecard' => $report['paired_scorecard'] ?? [],
                    'battery_execution_contract' => $report['battery_execution_contract'] ?? [],
                    'scope' => $report['scope'] ?? [],
                    'case_comparisons' => array_slice((array) ($report['case_comparisons'] ?? []), 0, 5),
                    'runs' => array_slice((array) ($report['runs'] ?? []), 0, 5),
                    'replay_manifest' => $report['replay_manifest'] ?? [],
                ],
                'blocking_reasons' => (array) data_get($report, 'readiness.blocking_reasons', []),
            ];
        } catch (Throwable $exception) {
            return [
                'status' => 'unavailable',
                'suite_slug' => 'atlas-fair-claude-v1',
                'report' => null,
                'blocking_reasons' => ['fair_claude_report_unavailable'],
                'error' => $exception->getMessage(),
            ];
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function operatorExecutionPacket(bool $localPassed, bool $claimReady, array $fairClaude, string $workspace, array $currentWorkspacePreflight): array
    {
        $battery = (array) data_get($fairClaude, 'report.battery_execution_contract', []);
        $current = (array) data_get($battery, 'current_state', []);
        $scorecard = (array) data_get($fairClaude, 'report.paired_scorecard', []);
        $corpusPrepared = (bool) ($current['corpus_prepared'] ?? false);
        $invalidCaseCount = (int) ($scorecard['invalid_case_count'] ?? 0);
        $realBatteryAttempted = (int) data_get($fairClaude, 'report.scope.paired_run_count', 0) > 0
            || (int) data_get($fairClaude, 'report.scope.fair_run_count', 0) > 0;
        $invalidBatteryRequiresTriage = $realBatteryAttempted
            && $invalidCaseCount > 0
            && ! $claimReady
            && (bool) data_get($fairClaude, 'report.result_integrity.triage_required_before_rerun', true);
        $blockedByInvalidAttempt = $invalidBatteryRequiresTriage;
        $currentWorkspaceReady = (bool) data_get($currentWorkspacePreflight, 'ready_for_provider_battery', false);
        $readyToRequestOperator = $localPassed && ! $claimReady && $corpusPrepared && ! $blockedByInvalidAttempt && $currentWorkspaceReady;
        $providerWorkspace = (bool) data_get($currentWorkspacePreflight, 'ready_for_provider_battery', false)
            ? $workspace
            : '<clean-atlas-workspace>';

        return [
            'schema_version' => 'atlas.programming.rivals_operator_execution_packet.v1',
            'status' => $claimReady
                ? 'not_required_claim_ready'
                : ($blockedByInvalidAttempt
                    ? 'blocked_until_invalid_battery_triaged'
                    : ($readyToRequestOperator ? 'ready_for_operator_approval' : ($currentWorkspaceReady ? 'blocked_before_operator' : 'blocked_until_clean_worktree'))),
            'workspace_hash' => hash('sha256', $workspace),
            'provider_dispatches_now' => false,
            'operator_approval_required' => ! $claimReady,
            'external_cost_possible' => ! $claimReady,
            'runbook_review_required' => ! $claimReady,
            'rerun_provider_battery_allowed_now' => $readyToRequestOperator,
            'recommended_first_run' => [
                'preset' => 'quick',
                'purpose' => $blockedByInvalidAttempt
                    ? 'blocked: triage the invalid Atlas protocol result before spending provider budget again.'
                    : 'validate real paired execution at the lowest useful provider cost before medium/full battery.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals run --quick --suite=atlas-fair-claude-v1 --workspace='.$providerWorkspace.' --claude-code-baseline-workspace=<separate-clean-baseline-workspace> --confirm-runbook-reviewed --confirm-provider-cost --json',
                'workspace_placeholder_used' => $providerWorkspace !== $workspace,
            ],
            'recommended_diagnostic' => [
                'purpose' => 'inspect the last real Rivals run without dispatching providers.',
                'command' => 'php artisan atlas:engineering:benchmark:rivals report --suite=atlas-fair-claude-v1 --json',
            ],
            'required_preflight' => [
                'runbook_status_must_be_ready' => true,
                'atlas_workspace_must_be_clean_git_worktree' => true,
                'baseline_workspace_must_be_separate_clean_git_worktree' => true,
                'provider_execution_blocks_on_dirty_workspace_even_after_cost_confirmation' => true,
            ],
            'after_run_commands' => [
                'report' => 'php artisan atlas:engineering:benchmark:rivals report --suite=atlas-fair-claude-v1 --json',
                'export' => 'php artisan atlas:engineering:benchmark:rivals report --suite=atlas-fair-claude-v1 --output-dir=atlas-rivals-report --json',
                'verify_export' => 'php artisan atlas:engineering:benchmark:rivals verify --suite=atlas-fair-claude-v1 --output-dir=atlas-rivals-report --json',
                'completion_audit' => 'php artisan atlas:programming:completion-audit --workspace='.$workspace.' --json',
            ],
            'preflight' => [
                'local_benchmarks_passed' => $localPassed,
                'corpus_prepared' => $corpusPrepared,
                'release_corpus_case_count' => (int) ($current['release_corpus_case_count'] ?? 0),
                'minimum_release_corpus_case_count' => (int) ($current['minimum_release_corpus_case_count'] ?? 0),
                'current_comparable_case_count' => (int) ($current['comparable_case_count'] ?? 0),
                'invalid_case_count' => $invalidCaseCount,
                'real_battery_attempted' => $realBatteryAttempted,
                'current_workspace_ready_for_provider_battery' => $currentWorkspaceReady,
                'ready_for_claim' => $claimReady,
            ],
            'blocking_reasons' => array_values(array_filter([
                $localPassed ? null : 'local_programming_benchmarks_not_passed',
                $corpusPrepared ? null : 'fair_claude_corpus_not_prepared',
                data_get($currentWorkspacePreflight, 'ready_for_provider_battery') ? null : 'current_workspace_not_provider_battery_ready',
                $blockedByInvalidAttempt ? 'triage_invalid_real_battery_before_rerun' : null,
                $claimReady || $blockedByInvalidAttempt ? null : 'operator_must_approve_provider_cost_before_real_battery',
            ])),
        ];
    }

    /**
     * @param  array<string,mixed>  $fairClaude
     * @return array<string,mixed>
     */
    private function latestRealBatteryEvidence(array $fairClaude, bool $realBatteryAttempted, bool $realBatteryInvalid): array
    {
        $latestRun = (array) data_get($fairClaude, 'report.runs.0', []);
        $latestCase = (array) data_get($fairClaude, 'report.case_comparisons.0', []);
        $finalPacket = (array) data_get($latestRun, 'replay_manifest.final_packet', []);
        $comparableCount = (int) data_get($fairClaude, 'report.readiness.comparable_count', 0);
        $claimReady = (bool) data_get($fairClaude, 'report.readiness.ready_for_claim', false);

        return [
            'schema_version' => 'atlas.programming.latest_real_battery_evidence.v1',
            'attempted' => $realBatteryAttempted,
            'status' => $claimReady
                ? 'valid_comparable_battery'
                : ($realBatteryInvalid ? 'invalid_battery_no_comparable_score' : ($realBatteryAttempted ? 'attempted_but_not_claim_ready' : 'not_attempted')),
            'does_not_prove_atlas_loss' => ! $claimReady && $comparableCount === 0,
            'does_not_prove_rival_win' => ! $claimReady && $comparableCount === 0,
            'counts' => [
                'paired_run_count' => (int) data_get($fairClaude, 'report.scope.paired_run_count', 0),
                'fair_run_count' => (int) data_get($fairClaude, 'report.scope.fair_run_count', 0),
                'case_comparison_count' => (int) data_get($fairClaude, 'report.scope.case_comparison_count', 0),
                'comparable_case_count' => $comparableCount,
                'invalid_case_count' => (int) data_get($fairClaude, 'report.paired_scorecard.invalid_case_count', 0),
                'inconclusive_count' => (int) data_get($fairClaude, 'report.paired_scorecard.inconclusive_count', 0),
            ],
            'run_id' => $latestRun['id'] ?? ($latestCase['benchmark_run_id'] ?? null),
            'case_code' => $latestCase['case_code'] ?? null,
            'comparison_status' => $latestCase['comparison_status'] ?? null,
            'comparable' => (bool) ($latestCase['comparable'] ?? false),
            'winner' => $latestCase['winner'] ?? null,
            'plain_explanation' => $latestCase === [] ? null : $this->plainComparisonExplanation($latestCase),
            'blocking_reasons' => $latestCase['blocking_reasons'] ?? [],
            'atlas' => [
                'verified' => (bool) data_get($latestCase, 'atlas.verified', false),
                'passed' => (bool) data_get($latestCase, 'atlas.passed', false),
                'score' => data_get($latestCase, 'atlas.score'),
                'duration_ms' => data_get($latestCase, 'atlas.duration_ms'),
            ],
            'baseline' => [
                'verified' => (bool) data_get($latestCase, 'claude_code_baseline.verified', false),
                'passed' => (bool) data_get($latestCase, 'claude_code_baseline.passed', false),
                'score' => data_get($latestCase, 'claude_code_baseline.score'),
                'duration_ms' => data_get($latestCase, 'claude_code_baseline.duration_ms'),
            ],
            'failure_summary' => $latestCase['failure_summary'] ?? null,
            'final_packet' => [
                'status' => $finalPacket['status'] ?? null,
                'protocol_valid' => $finalPacket['protocol_valid'] ?? null,
                'files_changed_count' => $finalPacket['files_changed_count'] ?? null,
                'failed_test_count' => data_get($finalPacket, 'tests.failed_test_count'),
                'failed_tests' => data_get($finalPacket, 'tests.failed_tests', []),
                'release_gate_status' => data_get($finalPacket, 'gates.release_gate_status'),
                'failed_control_count' => data_get($finalPacket, 'gates.failed_control_count'),
                'release_gate_failures' => data_get($finalPacket, 'gates.release_gate_failures', []),
                'risk_flags' => data_get($finalPacket, 'risks.risk_flags', []),
                'decision_reasons' => $finalPacket['decision_reasons'] ?? [],
            ],
            'artifact_integrity' => data_get($latestRun, 'replay_manifest.artifact.integrity', []),
            'next_action' => $realBatteryInvalid
                ? 'Do not spend provider budget on another battery until protocol invalid cases, failed gates and workspace scope are triaged.'
                : ($realBatteryAttempted ? 'Review report and verify export bundle.' : 'Run paid provider battery only after operator approval.'),
        ];
    }

    /**
     * @param  array<string,mixed>  $latestEvidence
     * @return array<string,mixed>
     */
    private function invalidBatteryTriagePacket(
        array $latestEvidence,
        bool $realBatteryInvalid,
        bool $invalidBatteryRequiresTriage,
        bool $localPassed,
        array $currentWorkspacePreflight,
        array $currentLocalRecheckEvidence,
    ): array {
        $failedTestCount = (int) data_get($latestEvidence, 'final_packet.failed_test_count', 0);
        $failedControlCount = (int) data_get($latestEvidence, 'final_packet.failed_control_count', 0);
        $riskFlags = array_values((array) data_get($latestEvidence, 'final_packet.risk_flags', []));
        $releaseGateFailures = array_values((array) data_get($latestEvidence, 'final_packet.release_gate_failures', []));
        $decisionReasons = array_values((array) data_get($latestEvidence, 'final_packet.decision_reasons', []));
        $artifactIntegrity = (array) data_get($latestEvidence, 'artifact_integrity', []);
        $artifactIntegrityOk = $artifactIntegrity === [] || (
            (bool) ($artifactIntegrity['checked'] ?? false)
            && (bool) ($artifactIntegrity['exists'] ?? false)
            && (bool) ($artifactIntegrity['hash_matches'] ?? false)
        );
        $historicalFailureStatus = $invalidBatteryRequiresTriage ? 'blocked' : 'quarantined_diagnostic';
        $currentWorkspaceReady = (bool) data_get($currentWorkspacePreflight, 'ready_for_provider_battery', false);
        $currentLocalRechecksPassed = (bool) data_get($currentLocalRecheckEvidence, 'all_known_rechecks_passed', false);
        $spendMoreProviderTokensNow = ! $invalidBatteryRequiresTriage
            && $localPassed
            && $currentLocalRechecksPassed
            && $currentWorkspaceReady;

        return [
            'schema_version' => 'atlas.programming.invalid_battery_triage_packet.v1',
            'status' => $invalidBatteryRequiresTriage
                ? 'triage_required_before_rerun'
                : ($realBatteryInvalid ? 'triaged_quarantined_pending_fresh_battery' : 'not_required'),
            'rerun_provider_battery_allowed_now' => $spendMoreProviderTokensNow,
            'historical_failure_policy' => [
                'schema_version' => 'atlas.programming.rivals_historical_failure_policy.v1',
                'historical_failed_gates_are_diagnostic' => true,
                'historical_failed_gates_do_not_authorize_new_provider_spend' => true,
                'current_preconditions_must_be_green_before_rerun' => true,
                'clean_worktree_required_even_if_local_rechecks_pass' => true,
                'triaged_invalid_batteries_do_not_enter_score_or_block_forever' => true,
            ],
            'provider_budget_policy' => [
                'spend_more_provider_tokens_now' => $spendMoreProviderTokensNow,
                'reason' => $this->providerBudgetPolicyReason(
                    $invalidBatteryRequiresTriage,
                    $localPassed,
                    $currentLocalRechecksPassed,
                    $currentWorkspaceReady,
                ),
            ],
            'root_cause_summary' => [
                'protocol_valid' => (bool) data_get($latestEvidence, 'final_packet.protocol_valid', false),
                'final_packet_status' => data_get($latestEvidence, 'final_packet.status'),
                'comparison_status' => data_get($latestEvidence, 'comparison_status'),
                'failed_test_count' => $failedTestCount,
                'failed_control_count' => $failedControlCount,
                'release_gate_status' => data_get($latestEvidence, 'final_packet.release_gate_status'),
                'risk_flag_count' => count($riskFlags),
                'artifact_integrity_ok' => $artifactIntegrityOk,
            ],
            'current_rerun_preconditions' => [
                'schema_version' => 'atlas.programming.current_rivals_rerun_preconditions.v1',
                'local_programming_benchmarks_passed' => $localPassed,
                'current_local_rechecks' => $currentLocalRecheckEvidence,
                'current_workspace_ready_for_provider_battery' => $currentWorkspaceReady,
                'current_workspace_status' => data_get($currentWorkspacePreflight, 'status'),
                'current_workspace_blocking_reasons' => data_get($currentWorkspacePreflight, 'blocking_reasons', []),
                'provider_dispatch_allowed_now' => false,
                'why_provider_dispatch_is_blocked' => array_values(array_filter([
                    $invalidBatteryRequiresTriage ? 'historical_real_battery_invalid_requires_triage' : null,
                    $localPassed ? null : 'local_programming_benchmarks_not_passed',
                    $currentLocalRechecksPassed ? null : 'current_local_rechecks_not_passed',
                    $currentWorkspaceReady ? null : 'current_workspace_not_provider_battery_ready',
                ])),
                'diagnostic_commands_without_provider_spend' => [
                    'programming_readiness' => 'php artisan atlas:programming:rivals-readiness --json',
                    'completion_audit' => 'php artisan atlas:programming:completion-audit --json',
                    'quality_changed_only' => 'php artisan atlas:engineering:quality-scan --workspace=<clean-atlas-workspace> --profile=auto --changed-only --timeout=300 --json',
                    'visual_smoke' => 'php artisan atlas:engineering:visual-smoke --workspace=<clean-atlas-workspace> --artifact-dir=atlas-visual-report --timeout=45 --baseline=observe --screenshot-driver=auto --json',
                    'fair_claude_report' => 'php artisan atlas:engineering:benchmark:rivals report --suite=atlas-fair-claude-v1 --json',
                ],
            ],
            'failed_tests' => array_slice((array) data_get($latestEvidence, 'final_packet.failed_tests', []), 0, 10),
            'release_gate_failures' => $releaseGateFailures,
            'risk_flags' => $riskFlags,
            'decision_reasons' => $decisionReasons,
            'artifact_integrity' => $artifactIntegrity,
            'triage_checklist' => [
                [
                    'id' => 'fix_failed_tests',
                    'status' => $failedTestCount === 0 ? 'passed' : $historicalFailureStatus,
                    'evidence' => 'final_packet.tests.failed_test_count',
                    'scope' => $invalidBatteryRequiresTriage ? 'current_rerun_blocker' : 'historical_quarantined_diagnostic',
                ],
                [
                    'id' => 'clear_release_gate_failures',
                    'status' => $failedControlCount === 0 && $releaseGateFailures === [] ? 'passed' : $historicalFailureStatus,
                    'evidence' => 'final_packet.gates',
                    'scope' => $invalidBatteryRequiresTriage ? 'current_rerun_blocker' : 'historical_quarantined_diagnostic',
                ],
                [
                    'id' => 'clear_risk_flags',
                    'status' => $riskFlags === [] ? 'passed' : $historicalFailureStatus,
                    'evidence' => 'final_packet.risks.risk_flags',
                    'scope' => $invalidBatteryRequiresTriage ? 'current_rerun_blocker' : 'historical_quarantined_diagnostic',
                ],
                [
                    'id' => 'verify_replay_artifact_integrity',
                    'status' => $artifactIntegrityOk ? 'passed' : $historicalFailureStatus,
                    'evidence' => 'replay_manifest.artifact.integrity',
                    'scope' => $invalidBatteryRequiresTriage ? 'current_rerun_blocker' : 'historical_quarantined_diagnostic',
                ],
                [
                    'id' => 'produce_protocol_valid_final_packet',
                    'status' => (bool) data_get($latestEvidence, 'final_packet.protocol_valid', false) ? 'passed' : $historicalFailureStatus,
                    'evidence' => 'final_packet.protocol_valid',
                    'scope' => $invalidBatteryRequiresTriage ? 'current_rerun_blocker' : 'historical_quarantined_diagnostic',
                ],
            ],
            'next_action' => $realBatteryInvalid
                ? ($invalidBatteryRequiresTriage
                    ? 'Fix the local Atlas protocol failure and rerun local deterministic gates before authorizing another paid Rivals battery.'
                    : 'Historical invalid battery is quarantined; use a clean Atlas workspace and separate clean baseline workspace before requesting a fresh paid battery.')
                : 'No invalid-battery triage is required.',
        ];
    }

    private function providerBudgetPolicyReason(
        bool $invalidBatteryRequiresTriage,
        bool $localPassed,
        bool $currentLocalRechecksPassed,
        bool $currentWorkspaceReady,
    ): string {
        if ($invalidBatteryRequiresTriage) {
            return 'The latest real battery has zero comparable cases and invalid Atlas protocol evidence.';
        }

        $reasons = [];

        if (! $localPassed) {
            $reasons[] = 'local programming benchmarks are not green';
        }

        if (! $currentLocalRechecksPassed) {
            $reasons[] = 'current local deterministic rechecks are not fully green';
        }

        if (! $currentWorkspaceReady) {
            $reasons[] = 'current workspace is not clean and auditable for a provider battery';
        }

        if ($reasons !== []) {
            return 'Do not spend provider tokens: '.implode('; ', $reasons).'.';
        }

        return 'Current no-provider preconditions are green; provider spend still requires explicit operator approval and clean separate baseline workspace.';
    }

    /**
     * @return array<string,mixed>
     */
    private function currentLocalRecheckEvidence(string $workspace): array
    {
        $quality = $this->latestQualityScanEvidence($workspace);
        $visual = $this->visualSmokeEvidence($workspace);
        $checks = collect([$quality, $visual]);
        $knownChecks = $checks->filter(fn (array $check): bool => ($check['status'] ?? 'unknown') !== 'unknown');
        $allRequiredChecksKnown = $knownChecks->count() === $checks->count();
        $allKnownRechecksPassed = $allRequiredChecksKnown
            && $knownChecks->every(fn (array $check): bool => ($check['status'] ?? null) === 'passed');

        return [
            'schema_version' => 'atlas.programming.current_local_recheck_evidence.v1',
            'purpose' => 'Separate current no-provider local deterministic rechecks from historical invalid Rivals battery failures.',
            'status' => $allKnownRechecksPassed ? 'passed' : ($knownChecks->isEmpty() ? 'unknown' : 'incomplete'),
            'provider_dispatches' => false,
            'quality_changed_only' => $quality,
            'visual_smoke' => $visual,
            'all_required_rechecks_known' => $allRequiredChecksKnown,
            'all_known_rechecks_passed' => $allKnownRechecksPassed,
            'historical_rivals_failures_still_authoritative_for_external_claim' => true,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function latestQualityScanEvidence(string $workspace): array
    {
        $root = storage_path('app/engineering-quality-scans');
        $dirs = is_dir($root) ? glob($root.'/*', GLOB_ONLYDIR) : false;
        $workspaceHash = hash('sha256', $workspace);

        if (! is_array($dirs) || $dirs === []) {
            return $this->latestQualityScanToolRuntimeEvidence($workspaceHash) ?? [
                'status' => 'unknown',
                'reason' => 'quality_scan_artifact_not_found',
                'changed_only' => null,
            ];
        }

        rsort($dirs, SORT_STRING);
        $selectedDir = null;
        $payload = null;

        foreach ($dirs as $dir) {
            $candidate = $this->jsonFile($dir.'/scan.json');

            if ($candidate !== null && ($candidate['workspace_hash'] ?? null) === $workspaceHash) {
                $selectedDir = $dir;
                $payload = $candidate;
                break;
            }
        }

        if ($payload === null) {
            return $this->latestQualityScanToolRuntimeEvidence($workspaceHash) ?? [
                'status' => 'unknown',
                'reason' => 'quality_scan_artifact_for_workspace_not_found',
                'workspace_hash' => $workspaceHash,
                'changed_only' => null,
            ];
        }

        $selectedDir ??= $dirs[0];

        return [
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'changed_only' => (bool) ($payload['changed_only'] ?? false),
            'workspace_hash' => $payload['workspace_hash'] ?? null,
            'artifact_root_hash' => $payload['artifact_root_hash'] ?? hash('sha256', $selectedDir),
            'artifact_dir_name' => basename($selectedDir),
            'finding_count' => (int) data_get($payload, 'summary.finding_count', 0),
            'blocking_finding_count' => (int) data_get($payload, 'summary.blocking_finding_count', 0),
            'paid_tool_required' => (bool) ($payload['paid_tool_required'] ?? false),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function latestQualityScanToolRuntimeEvidence(string $workspaceHash): ?array
    {
        if (! Schema::hasTable('atlas_tool_runs') || ! Schema::hasColumn('atlas_tool_runs', 'workspace_hash')) {
            return null;
        }

        $runs = AtlasToolRun::query()
            ->where('surface', 'engineering_quality_scan')
            ->where('workspace_hash', $workspaceHash)
            ->orderByDesc('created_at')
            ->limit(200)
            ->get()
            ->filter(fn (AtlasToolRun $run): bool => (bool) data_get($run->metadata_json, 'changed_only', false));

        if ($runs->isEmpty()) {
            return null;
        }

        $groups = $runs
            ->groupBy(fn (AtlasToolRun $run): string => (string) (data_get($run->metadata_json, 'scan_artifact_root_hash') ?: 'unknown'))
            ->sortByDesc(fn ($group) => $group->max('created_at'));
        $latest = $groups->first();

        if (! $latest) {
            return null;
        }

        $failedCount = $latest
            ->filter(fn (AtlasToolRun $run): bool => in_array($run->status, ['failed', 'timeout'], true))
            ->count();
        $passedCount = $latest->where('status', 'passed')->count();

        return [
            'status' => $failedCount === 0 ? 'passed' : 'failed',
            'source' => 'tool_runtime_evidence',
            'changed_only' => true,
            'workspace_hash' => $workspaceHash,
            'artifact_root_hash' => (string) ($latest->first()?->metadata_json['scan_artifact_root_hash'] ?? 'unknown'),
            'tool_run_count' => $latest->count(),
            'passed_count' => $passedCount,
            'failed_count' => $failedCount,
            'paid_tool_required' => false,
            'artifact_manifest_available' => false,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function visualSmokeEvidence(string $workspace): array
    {
        $path = rtrim($workspace, DIRECTORY_SEPARATOR).'/atlas-visual-report/manifest.json';
        $payload = $this->jsonFile($path);

        if ($payload === null) {
            return [
                'status' => 'unknown',
                'reason' => 'visual_smoke_manifest_not_found',
            ];
        }

        return [
            'status' => (string) ($payload['status'] ?? 'unknown'),
            'workspace_hash' => $payload['workspace_hash'] ?? null,
            'artifact_dir' => $payload['artifact_dir'] ?? null,
            'route_failed_count' => (int) data_get($payload, 'strict_failure_summary.route_failed_count', 0),
            'screenshot_required_failed_count' => (int) data_get($payload, 'strict_failure_summary.screenshot_required_failed_count', 0),
            'screenshot_status' => data_get($payload, 'screenshot.status'),
            'screenshot_driver_status' => data_get($payload, 'screenshot_driver.status'),
        ];
    }

    /**
     * @return array<string,mixed>|null
     */
    private function jsonFile(string $path): ?array
    {
        if (! is_file($path)) {
            return null;
        }

        $contents = file_get_contents($path);
        if (! is_string($contents) || trim($contents) === '') {
            return null;
        }

        $payload = json_decode($contents, true);

        return is_array($payload) ? $payload : null;
    }

    /**
     * @return array<string,mixed>
     */
    private function currentWorkspacePreflight(string $workspace): array
    {
        $git = $this->gitWorkspaceState($workspace);
        $ready = (bool) ($git['is_git'] ?? false) && (bool) ($git['clean'] ?? false);

        return [
            'schema_version' => 'atlas.programming.current_workspace_provider_preflight.v1',
            'workspace_hash' => hash('sha256', $workspace),
            'ready_for_provider_battery' => $ready,
            'status' => $ready ? 'ready' : 'blocked',
            'blocking_reasons' => array_values(array_filter([
                ($git['is_git'] ?? false) ? null : 'workspace_is_not_git_worktree',
                ($git['is_git'] ?? false) && ! ($git['clean'] ?? false) ? 'workspace_dirty' : null,
            ])),
            'dirty_count' => (int) ($git['dirty_count'] ?? 0),
            'dirty_files_sample' => (array) ($git['dirty_files_sample'] ?? []),
            'dirty_files_truncated' => (bool) ($git['dirty_files_truncated'] ?? false),
            'git' => $git,
            'operator_guidance' => [
                'use_current_dirty_workspace_for_provider_battery' => false,
                'create_clean_atlas_worktree' => 'git worktree add <clean-atlas-workspace> HEAD',
                'create_clean_baseline_worktree' => 'git worktree add <separate-clean-baseline-workspace> HEAD',
                'verify_clean_atlas_worktree' => 'git -C <clean-atlas-workspace> status --short',
                'verify_clean_baseline_worktree' => 'git -C <separate-clean-baseline-workspace> status --short',
            ],
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function gitWorkspaceState(string $workspace): array
    {
        if (! is_dir($workspace)) {
            return [
                'is_git' => false,
                'clean' => false,
                'status' => 'workspace_missing',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
            ];
        }

        $inside = new Process(['git', 'rev-parse', '--is-inside-work-tree'], $workspace);
        $inside->setTimeout(5);
        $inside->run();

        if (! $inside->isSuccessful() || trim($inside->getOutput()) !== 'true') {
            return [
                'is_git' => false,
                'clean' => false,
                'status' => 'not_git_workspace',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
            ];
        }

        $status = new Process(['git', 'status', '--porcelain'], $workspace);
        $status->setTimeout(10);
        $status->run();

        if (! $status->isSuccessful()) {
            return [
                'is_git' => true,
                'clean' => false,
                'status' => 'git_status_unavailable',
                'dirty_count' => 0,
                'dirty_files_sample' => [],
            ];
        }

        $dirtyFiles = collect(explode("\n", trim($status->getOutput())))
            ->filter(fn (string $line): bool => trim($line) !== '')
            ->map(function (string $line): string {
                $path = preg_replace('/^..\s*/', '', $line);

                return trim(is_string($path) && $path !== '' ? $path : $line);
            })
            ->values();

        return [
            'is_git' => true,
            'clean' => $dirtyFiles->isEmpty(),
            'status' => $dirtyFiles->isEmpty() ? 'clean' : 'dirty',
            'dirty_count' => $dirtyFiles->count(),
            'dirty_files_sample' => $dirtyFiles->take(20)->all(),
            'dirty_files_truncated' => $dirtyFiles->count() > 20,
        ];
    }
}
