<?php

namespace App\Services\Engineering\Benchmark;

use App\Models\AtlasEngineeringBenchmarkCase;
use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasTask;
use App\Services\Ai\FairClaudePolicy;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use App\Services\Engineering\EngineeringClaudeCodeBaselineRunnerService;
use App\Services\Engineering\EngineeringWorkspaceService;
use App\Services\Engineering\EngineeringReleaseGateAlertService;

class BenchmarkRunExecutionSection
{
    /**
     * @var array<int,string>
     */
    private const RUNNER_OPTION_KEYS = [
        'workspace',
        'provider',
        'model',
        'model_policy',
        'permission',
        'sandbox',
        'docker_service',
        'docker_image',
        'docker_workdir',
        'docker_cache',
        'docker_network',
        'docker_healthcheck_services',
        'docker_healthcheck_timeout',
        'docker_artifact_paths',
        'docker_artifact_max_files',
        'docker_artifact_max_bytes',
        'provider_runtime',
        'provider_docker_compose_file',
        'provider_docker_service',
        'provider_docker_app_dir',
        'provider_docker_workspace_dir',
        'provider_timeout_seconds',
        'case_timeout_seconds',
        'test_timeout_seconds',
        'max_attempts',
        'test_command',
        'visual_e2e',
        'quality_scan',
        'quality_profile',
        'quality_changed_only',
        'harness_policy',
        'control_profile',
        'complete',
        'auto_test',
        'critical',
        'dry_run',
        'no_provider',
        'keep_workspace',
        'apply_isolated_patch',
        'release_gate_profile',
        'release_gate_policy',
        'fair_mode',
        'claude_only',
        'single_provider',
        'no_decide',
        'fallback_disabled',
        'require_pass_without_human',
        'claude_code_baseline',
        'claude_code_baseline_mode',
        'claude_code_baseline_model',
        'claude_code_baseline_binary',
        'claude_code_baseline_timeout',
        'claude_code_baseline_workspace',
        'claude_code_baseline_validation_timeout',
        'baseline_runner',
        'baseline_model',
        'baseline_timeout_seconds',
        'baseline_validation_timeout_seconds',
        'rivals_battery_mode',
        'rivals_battery_plan_hash',
        'operator_plan_reviewed',
        'operator_cost_acknowledged',
        'rivals_battery_plan',
    ];

    private const DEFAULT_RELEASE_GATE_PROFILE = 'release';

    public function __construct(
        private readonly BenchmarkPrimitives $primitives,
        private readonly BenchmarkRunResultsSection $runResults,
        private readonly BenchmarkSuiteDataSection $suiteData,
        private readonly EngineeringHarnessRunnerService $runner,
        private readonly EngineeringWorkspaceService $workspaces,
        private readonly EngineeringClaudeCodeBaselineRunnerService $claudeCodeBaseline,
        private readonly EngineeringReleaseGateAlertService $releaseGateAlerts,
    ) {}

    public function selectedCases(AtlasEngineeringBenchmarkSuite $suite, array $options): Collection
    {
        $cases = $suite->cases
            ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $case->status === 'active')
            ->sortBy('case_code')
            ->values();

        $caseCodes = collect((array) ($options['case_codes'] ?? $options['cases'] ?? []))
            ->filter(fn (mixed $code): bool => is_scalar($code) && trim((string) $code) !== '')
            ->map(fn (mixed $code): string => $this->primitives->caseCode($code))
            ->values();
        if ($caseCodes->isNotEmpty()) {
            $cases = $cases
                ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $caseCodes->contains($case->case_code))
                ->values();
        }

        $tags = collect((array) ($options['tags'] ?? []))
            ->filter(fn (mixed $tag): bool => is_scalar($tag) && trim((string) $tag) !== '')
            ->map(fn (mixed $tag): string => trim((string) $tag))
            ->values();
        if ($tags->isNotEmpty()) {
            $cases = $cases
                ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => $tags->intersect($case->tags_json ?? [])->isNotEmpty())
                ->values();
        }

        foreach ([
            'corpus_tier' => $options['corpus_tier'] ?? $options['tier'] ?? null,
            'domain_slug' => $options['domain_slug'] ?? $options['domain'] ?? null,
            'risk_profile' => $options['risk_profile'] ?? $options['risk'] ?? null,
            'curation_status' => $options['curation_status'] ?? null,
        ] as $field => $value) {
            if (! $this->primitives->nonEmptyString($value)) {
                continue;
            }

            $normalized = $this->primitives->caseCode($value);
            $cases = $cases
                ->filter(fn (AtlasEngineeringBenchmarkCase $case): bool => (string) $case->{$field} === $normalized)
                ->values();
        }

        $limit = (int) ($options['limit'] ?? 0);
        if ($limit > 0) {
            $cases = $cases->take($limit)->values();
        }

        return $cases;
    }

    public function taskForCase(AtlasEngineeringBenchmarkCase $case): AtlasTask
    {
        if ($case->task_id) {
            return AtlasTask::query()->findOrFail($case->task_id);
        }

        $contract = $this->primitives->arrayValue($case->task_contract_json ?? []);
        if ($contract === []) {
            throw new InvalidArgumentException('Benchmark case sem task_id precisa de task_contract.');
        }

        return AtlasTask::query()->create([
            'title' => $this->primitives->nonEmptyString($contract['goal'] ?? null) ?: $case->title,
            'description' => $case->description ?: $this->primitives->nonEmptyString($contract['context'][0] ?? null),
            'status' => 'open',
            'priority' => 'normal',
            'domain' => 'atlas',
            'estimated_minutes' => 60,
            'metadata' => [
                'engineering_contract' => $contract,
                'benchmark_generated' => true,
                'benchmark_case_id' => $case->id,
                'benchmark_suite_id' => $case->suite_id,
            ],
        ]);
    }

    public function caseDeadline(float $startedAt, array $runnerOptions): ?float
    {
        $timeout = $this->primitives->nullableInt($runnerOptions['case_timeout_seconds'] ?? null);
        if ($timeout === null || $timeout <= 0) {
            return null;
        }

        return $startedAt + $timeout;
    }

    public function assertCaseDeadline(?float $deadline, string $phase): void
    {
        if ($deadline === null || microtime(true) < $deadline) {
            return;
        }

        throw new \RuntimeException("Benchmark case timeout exceeded before {$phase}.");
    }

    public function capCaseTimeouts(array $runnerOptions, ?float $deadline): array
    {
        if ($deadline === null) {
            return $runnerOptions;
        }

        $remaining = (int) floor($deadline - microtime(true));
        if ($remaining <= 0) {
            return $runnerOptions;
        }

        foreach ([
            'provider_timeout_seconds',
            'claude_code_baseline_timeout',
            'claude_code_baseline_validation_timeout',
            'baseline_timeout_seconds',
            'baseline_validation_timeout_seconds',
        ] as $key) {
            $value = $this->primitives->nullableInt($runnerOptions[$key] ?? null);
            if ($value === null || $value <= 0 || $value > $remaining) {
                $runnerOptions[$key] = max(1, $remaining);
            }
        }

        return $runnerOptions;
    }

    public function runnerOptionsForCase(AtlasEngineeringBenchmarkCase $case, array $overrides): array
    {
        $case->loadMissing('suite');

        $suiteOptions = $this->primitives->arrayValue($case->suite?->default_runner_options_json ?? []);
        $caseOptions = $this->primitives->arrayValue($case->runner_options_json ?? []);
        $runOverrides = $this->runnerOverrides($overrides);

        return $this->withReleaseQualityScanDefaults(
            $this->withCaseValidationDefaults(
                $case,
                array_replace_recursive($suiteOptions, $caseOptions, $runOverrides),
                $runOverrides,
            ),
        );
    }

    public function withCaseValidationDefaults(AtlasEngineeringBenchmarkCase $case, array $options, array $runOverrides): array
    {
        if ($this->primitives->nonEmptyString($options['test_command'] ?? null) !== null) {
            return $options;
        }

        if (array_key_exists('test_command', $runOverrides)) {
            return $options;
        }

        $testCommands = (array) data_get($case->task_contract_json ?? [], 'test_commands', []);
        foreach ($testCommands as $command) {
            $command = $this->primitives->nonEmptyString($command);
            if ($command !== null) {
                $options['test_command'] = $command;
                $options['test_command_source'] = 'case_task_contract';

                return $options;
            }
        }

        return $options;
    }

    public function runnerOverrides(array $options): array
    {
        $nested = array_filter(
            $this->primitives->arrayValue($options['runner_options'] ?? []),
            fn (mixed $value): bool => $value !== null,
        );
        $flat = array_filter(
            Arr::only($options, self::RUNNER_OPTION_KEYS),
            fn (mixed $value): bool => $value !== null,
        );

        return array_replace_recursive($nested, $flat);
    }

    public function withReleaseQualityScanDefaults(array $options): array
    {
        $options = $this->withFairClaudeDefaults($options);
        $profile = $this->primitives->nonEmptyString($options['release_gate_profile'] ?? null) ?: self::DEFAULT_RELEASE_GATE_PROFILE;
        if (! in_array($profile, ['release', 'strict'], true)) {
            return $options;
        }

        if ($this->primitives->nonEmptyString($options['quality_scan'] ?? null) === null) {
            $options['quality_scan'] = 'auto';
        }

        if ($this->primitives->nonEmptyString($options['quality_profile'] ?? null) === null) {
            $options['quality_profile'] = $profile === 'strict' ? 'release' : 'standard';
        }

        if (! array_key_exists('quality_changed_only', $options)) {
            $options['quality_changed_only'] = true;
        }

        return $options;
    }

    public function withFairClaudeDefaults(array $options): array
    {
        $claudeOnly = (bool) ($options['claude_only'] ?? false);
        $fairMode = (bool) ($options['fair_mode'] ?? false)
            || $claudeOnly
            || (bool) ($options['single_provider'] ?? false)
            || (bool) ($options['no_decide'] ?? false)
            || (bool) ($options['fallback_disabled'] ?? false);
        if (! $fairMode) {
            return $options;
        }

        $explicitProvider = $this->primitives->nonEmptyString($options['provider'] ?? null);
        if ($explicitProvider !== null && $explicitProvider !== FairClaudePolicy::PROVIDER_LOCK) {
            throw new InvalidArgumentException(
                FairClaudePolicy::ERROR_CODE
                .': Fair Claude benchmark requires provider '
                .FairClaudePolicy::PROVIDER_LOCK
                .', got '.$explicitProvider.'.'
            );
        }

        $explicitModel = $this->primitives->nonEmptyString($options['model'] ?? null);
        if ($explicitModel !== null && ! $this->runResults->fairClaudeModelLocked($explicitModel)) {
            throw new InvalidArgumentException(
                FairClaudePolicy::ERROR_CODE
                .': Fair Claude benchmark requires Claude Opus model, got '.$explicitModel.'.'
            );
        }

        $explicitModelPolicy = $this->primitives->nonEmptyString($options['model_policy'] ?? null);
        if ($explicitModelPolicy !== null && strtolower($explicitModelPolicy) !== 'fixed') {
            throw new InvalidArgumentException(
                FairClaudePolicy::ERROR_CODE
                .': Fair Claude benchmark requires model_policy=fixed, got '.$explicitModelPolicy.'.'
            );
        }

        $options['fair_mode'] = true;
        if ($claudeOnly) {
            $options['claude_only'] = true;
        }
        $options['single_provider'] = true;
        $options['no_decide'] = true;
        $options['fallback_disabled'] = true;
        $options['require_pass_without_human'] = true;
        $options['provider'] = FairClaudePolicy::PROVIDER_LOCK;
        $options['model'] = $explicitModel ?? FairClaudePolicy::MODEL_LOCK;
        $options['model_policy'] = 'fixed';

        return $options;
    }

    public function preparePairedBaselineWorkspace(AtlasEngineeringBenchmarkCase $case, array $runnerOptions): array
    {
        if (! $this->baselineRunRequested($runnerOptions) || $this->hasExplicitBaselineWorkspace($runnerOptions)) {
            return [
                'runner_options' => $runnerOptions,
                'baseline_plan' => null,
                'artifact' => null,
            ];
        }

        $workspace = $this->primitives->workspaceFrom($runnerOptions['workspace'] ?? null);
        if ($workspace === null) {
            throw new InvalidArgumentException('Claude Code baseline auto worktree requires workspace.');
        }

        $plan = $this->workspaces->preparePairedWorktree(
            $workspace,
            'claude-code-baseline-'.$case->case_code,
        );

        $artifact = [
            'schema_version' => 1,
            'mode' => 'paired_git_worktree',
            'auto_prepared' => true,
            'claude_code_baseline' => $this->compactWorkspacePlan($plan),
        ];

        if ((string) ($plan['status'] ?? '') !== 'ready' || ! (bool) ($plan['isolated'] ?? false)) {
            throw new InvalidArgumentException('Claude Code baseline auto worktree failed: '.(string) ($plan['failure_reason'] ?? $plan['fallback_reason'] ?? 'unknown'));
        }

        $runnerOptions['claude_code_baseline_workspace'] = (string) $plan['execution_workspace'];
        $runnerOptions['claude_code_baseline_workspace_auto_prepared'] = true;
        $runnerOptions['claude_code_baseline_workspace_isolation_type'] = 'git_worktree';

        return [
            'runner_options' => $runnerOptions,
            'baseline_plan' => $plan,
            'artifact' => $artifact,
        ];
    }

    public function baselineRunRequested(array $runnerOptions): bool
    {
        $raw = $runnerOptions['claude_code_baseline'] ?? $runnerOptions['baseline_runner'] ?? $runnerOptions['claude_code_baseline_mode'] ?? 'off';
        if ($raw === true) {
            return false;
        }

        return strtolower(trim((string) $raw)) === 'run';
    }

    public function hasExplicitBaselineWorkspace(array $runnerOptions): bool
    {
        return isset($runnerOptions['claude_code_baseline_workspace'])
            && is_string($runnerOptions['claude_code_baseline_workspace'])
            && trim($runnerOptions['claude_code_baseline_workspace']) !== '';
    }

    public function releasePairedBaselineWorkspace(array $plan, array $runnerOptions): array
    {
        return $this->workspaces->release($plan, (bool) ($runnerOptions['keep_workspace'] ?? false));
    }

    public function compactWorkspacePlan(array $plan): array
    {
        $original = $this->primitives->nonEmptyString($plan['original_workspace'] ?? null);
        $execution = $this->primitives->nonEmptyString($plan['execution_workspace'] ?? null);
        $repoRoot = $this->primitives->nonEmptyString($plan['repo_root'] ?? null);
        $dirtyFiles = (array) ($plan['dirty_files'] ?? []);

        return [
            'status' => $plan['status'] ?? null,
            'mode' => $plan['mode'] ?? null,
            'pair_label' => $plan['pair_label'] ?? null,
            'original_workspace_hash' => $original ? hash('sha256', $original) : null,
            'execution_workspace_hash' => $execution ? hash('sha256', $execution) : null,
            'repo_root_hash' => $repoRoot ? hash('sha256', $repoRoot) : null,
            'branch' => $plan['branch'] ?? null,
            'head' => $plan['head'] ?? null,
            'isolated' => (bool) ($plan['isolated'] ?? false),
            'isolation_type' => $plan['isolation_type'] ?? null,
            'worktree_path_hash' => $plan['worktree_path_hash'] ?? null,
            'dirty_files_count' => count($dirtyFiles),
            'dirty_files_hash' => hash('sha256', json_encode(array_values($dirtyFiles), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)),
            'dirty_files_included' => (bool) ($plan['dirty_files_included'] ?? false),
            'failure_reason' => $plan['failure_reason'] ?? $plan['fallback_reason'] ?? null,
            'created_at' => $plan['created_at'] ?? null,
        ];
    }

    public function expectationFor(AtlasEngineeringBenchmarkCase $case): array
    {
        return [
            'expected_decision' => $case->expected_decision ?: 'resolved',
            'min_score' => (int) ($case->min_score ?? 85),
            'task_id' => $case->task_id,
            'case_code' => $case->case_code,
            'tags' => $case->tags_json ?? [],
        ];
    }

    public function finalizeRun(AtlasEngineeringBenchmarkRun $run): AtlasEngineeringBenchmarkRun
    {
        $results = $run->results()->get();
        $total = $results->count();
        $passed = $results->where('passed', true)->count();
        $failed = max(0, $total - $passed);
        $scores = $results
            ->pluck('score')
            ->filter(fn (mixed $score): bool => is_numeric($score))
            ->map(fn (mixed $score): int => (int) $score);
        $quality = $this->suiteData->qualityMetricsFor($run);
        $caseStatus = $total === 0 ? 'empty' : ($failed === 0 ? 'passed' : 'failed');
        $passRate = $total > 0 ? round(($passed / $total) * 100, 2) : null;
        $averageScore = $scores->isNotEmpty() ? round($scores->avg(), 2) : null;
        $finishedAt = now();
        $durationMs = $run->started_at
            ? max(0, ($finishedAt->getTimestamp() - $run->started_at->getTimestamp()) * 1000)
            : 0;
        $baseline = $this->suiteData->baselineFor($run);
        $trend = $this->suiteData->trendFor(
            status: $caseStatus,
            passRate: $passRate,
            averageScore: $averageScore,
            quality: $quality,
            baseline: $baseline,
        );
        $releaseGate = $this->suiteData->releaseGateFor($run, $caseStatus, $passRate, $averageScore, $quality, $trend);
        $status = $this->suiteData->statusAfterReleaseGate($caseStatus, $releaseGate);
        $rollout = $this->suiteData->rolloutFor($run, $status, $releaseGate);
        $claudeCodeBaselineSummary = $this->runResults->claudeCodeBaselineSummary($results);
        $pairedScorecardSummary = $this->runResults->pairedScorecardSummary($results, (int) ($quality['cost_microusd'] ?? 0));
        $replayManifest = $this->runResults->persistReplayManifestArtifact($run, $this->runResults->replayManifestSummary(
            run: $run,
            results: $results,
            status: $status,
            quality: $quality,
            releaseGate: $releaseGate,
        ));

        $run->forceFill([
            'status' => $status,
            'total_cases' => $total,
            'passed_cases' => $passed,
            'failed_cases' => $failed,
            'baseline_run_id' => $baseline?->id,
            'pass_rate' => $passRate,
            'pass_rate_delta' => $trend['pass_rate_delta'],
            'average_score' => $averageScore,
            'average_score_delta' => $trend['average_score_delta'],
            'duration_ms' => $durationMs,
            'trend_status' => $trend['trend_status'],
            'harness_version' => $quality['harness_version'],
            'total_attempts' => $quality['total_attempts'],
            'failed_control_count' => $quality['failed_control_count'],
            'blocked_control_count' => $quality['blocked_control_count'],
            'skipped_required_control_count' => $quality['skipped_required_control_count'],
            'failed_test_count' => $quality['failed_test_count'],
            'open_review_finding_count' => $quality['open_review_finding_count'],
            'blocking_review_finding_count' => $quality['blocking_review_finding_count'],
            'changed_files_count' => $quality['changed_files_count'],
            'risk_flag_count' => $quality['risk_flag_count'],
            'total_tokens' => $quality['total_tokens'],
            'cost_microusd' => $quality['cost_microusd'],
            'telemetry_coverage_count' => $quality['telemetry_coverage_count'],
            'quality_metrics_json' => $quality,
            'release_gate_status' => $releaseGate['status'],
            'release_gate_profile' => $releaseGate['profile'],
            'release_gate_policy_json' => $releaseGate['policy'],
            'release_gate_failures_json' => $releaseGate['failures'],
            'release_gate_warnings_json' => $releaseGate['warnings'],
            'rollout_status' => $rollout['status'],
            'rollout_policy_json' => $rollout['policy'],
            'rollout_decision_at' => $finishedAt,
            'summary_json' => [
                'case_status' => $caseStatus,
                'decisions' => $results->groupBy('decision')->map->count()->all(),
                'failures' => $results
                    ->where('passed', false)
                    ->pluck('failure_summary')
                    ->filter()
                    ->values()
                    ->take(10)
                    ->all(),
                'baseline' => $trend['baseline'],
                'claude_code_baseline' => $claudeCodeBaselineSummary,
                'paired_scorecard' => $pairedScorecardSummary,
                'replay_manifest' => $replayManifest,
                'quality_metrics' => $quality,
                'release_gate' => $releaseGate,
                'rollout' => $rollout,
            ],
            'finished_at' => $finishedAt,
        ])->save();

        $run = $run->refresh();
        $this->releaseGateAlerts->emitIfNeeded($run);

        return $run;
    }

    public function benchmarkRunSummary(AtlasEngineeringBenchmarkRun $run): array
    {
        $summary = $this->runResults->safeReplayManifestSummaryForApi(
            $this->runResults->withReplayManifestArtifactVerification($this->primitives->arrayValue($run->summary_json ?? [])),
        );

        return [
            'id' => $run->id,
            'suite_id' => $run->suite_id,
            'benchmark_key' => $run->benchmark_key,
            'provider' => $run->provider,
            'model' => $run->model,
            'mode' => $run->mode,
            'case_set_hash' => $run->case_set_hash,
            'status' => $run->status,
            'total_cases' => $run->total_cases,
            'passed_cases' => $run->passed_cases,
            'failed_cases' => $run->failed_cases,
            'baseline_run_id' => $run->baseline_run_id,
            'pass_rate' => $run->pass_rate,
            'pass_rate_delta' => $run->pass_rate_delta,
            'average_score' => $run->average_score,
            'average_score_delta' => $run->average_score_delta,
            'duration_ms' => $run->duration_ms,
            'trend_status' => $run->trend_status,
            'harness_version' => $run->harness_version,
            'total_attempts' => $run->total_attempts,
            'failed_control_count' => $run->failed_control_count,
            'blocked_control_count' => $run->blocked_control_count,
            'skipped_required_control_count' => $run->skipped_required_control_count,
            'failed_test_count' => $run->failed_test_count,
            'open_review_finding_count' => $run->open_review_finding_count,
            'blocking_review_finding_count' => $run->blocking_review_finding_count,
            'changed_files_count' => $run->changed_files_count,
            'risk_flag_count' => $run->risk_flag_count,
            'total_tokens' => $run->total_tokens,
            'cost_microusd' => $run->cost_microusd,
            'telemetry_coverage_count' => $run->telemetry_coverage_count,
            'quality_metrics' => $run->quality_metrics_json,
            'release_gate_status' => $run->release_gate_status,
            'release_gate_profile' => $run->release_gate_profile,
            'release_gate_policy' => $run->release_gate_policy_json,
            'release_gate_failures' => $run->release_gate_failures_json,
            'release_gate_warnings' => $run->release_gate_warnings_json,
            'rollout_status' => $run->rollout_status,
            'rollout_policy' => $run->rollout_policy_json,
            'rollout_decision_at' => $run->rollout_decision_at?->toJSON(),
            'outcome_status' => $run->outcome_status,
            'outcome_score' => $run->outcome_score,
            'outcome' => $run->outcome_json,
            'outcome_recorded_at' => $run->outcome_recorded_at?->toJSON(),
            'outcome_recorded_by' => $run->outcome_recorded_by,
            'summary' => $summary,
            'started_at' => $run->started_at?->toJSON(),
            'finished_at' => $run->finished_at?->toJSON(),
            'created_at' => $run->created_at?->toJSON(),
        ];
    }
}
