<?php

namespace App\Http\Controllers;

use App\Models\AtlasEngineeringBenchmarkRun;
use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Models\AtlasEngineeringRun;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class EngineeringBenchmarkController extends Controller
{
    public function indexSuites(Request $request): JsonResponse
    {
        $status = $request->query('status');

        $suites = AtlasEngineeringBenchmarkSuite::query()
            ->when(is_string($status) && $status !== '', fn ($query) => $query->where('status', $status))
            ->with('latestBenchmarkRun')
            ->withCount(['cases', 'benchmarkRuns'])
            ->orderBy('slug')
            ->get()
            ->map(fn (AtlasEngineeringBenchmarkSuite $suite): array => [
                'id' => $suite->id,
                'slug' => $suite->slug,
                'name' => $suite->name,
                'description' => $suite->description,
                'status' => $suite->status,
                'cases_count' => $suite->cases_count,
                'benchmark_runs_count' => $suite->benchmark_runs_count,
                'corpus_manifest' => data_get($suite->metadata, 'corpus_manifest'),
                'corpus_health' => data_get($suite->metadata, 'corpus_health'),
                'rollout_policy' => data_get($suite->metadata, 'rollout_policy'),
                'rollout_calibration' => data_get($suite->metadata, 'rollout_calibration'),
                'latest_run' => $suite->latestBenchmarkRun ? [
                    'id' => $suite->latestBenchmarkRun->id,
                    'suite_id' => $suite->latestBenchmarkRun->suite_id,
                    'benchmark_key' => $suite->latestBenchmarkRun->benchmark_key,
                    'provider' => $suite->latestBenchmarkRun->provider,
                    'model' => $suite->latestBenchmarkRun->model,
                    'mode' => $suite->latestBenchmarkRun->mode,
                    'case_set_hash' => $suite->latestBenchmarkRun->case_set_hash,
                    'status' => $suite->latestBenchmarkRun->status,
                    'total_cases' => $suite->latestBenchmarkRun->total_cases,
                    'passed_cases' => $suite->latestBenchmarkRun->passed_cases,
                    'failed_cases' => $suite->latestBenchmarkRun->failed_cases,
                    'baseline_run_id' => $suite->latestBenchmarkRun->baseline_run_id,
                    'pass_rate' => $suite->latestBenchmarkRun->pass_rate,
                    'pass_rate_delta' => $suite->latestBenchmarkRun->pass_rate_delta,
                    'average_score' => $suite->latestBenchmarkRun->average_score,
                    'average_score_delta' => $suite->latestBenchmarkRun->average_score_delta,
                    'duration_ms' => $suite->latestBenchmarkRun->duration_ms,
                    'trend_status' => $suite->latestBenchmarkRun->trend_status,
                    'harness_version' => $suite->latestBenchmarkRun->harness_version,
                    'total_attempts' => $suite->latestBenchmarkRun->total_attempts,
                    'failed_control_count' => $suite->latestBenchmarkRun->failed_control_count,
                    'blocked_control_count' => $suite->latestBenchmarkRun->blocked_control_count,
                    'skipped_required_control_count' => $suite->latestBenchmarkRun->skipped_required_control_count,
                    'failed_test_count' => $suite->latestBenchmarkRun->failed_test_count,
                    'open_review_finding_count' => $suite->latestBenchmarkRun->open_review_finding_count,
                    'blocking_review_finding_count' => $suite->latestBenchmarkRun->blocking_review_finding_count,
                    'changed_files_count' => $suite->latestBenchmarkRun->changed_files_count,
                    'risk_flag_count' => $suite->latestBenchmarkRun->risk_flag_count,
                    'total_tokens' => $suite->latestBenchmarkRun->total_tokens,
                    'cost_microusd' => $suite->latestBenchmarkRun->cost_microusd,
                    'telemetry_coverage_count' => $suite->latestBenchmarkRun->telemetry_coverage_count,
                    'quality_metrics' => $suite->latestBenchmarkRun->quality_metrics_json,
                    'release_gate_status' => $suite->latestBenchmarkRun->release_gate_status,
                    'release_gate_profile' => $suite->latestBenchmarkRun->release_gate_profile,
                    'release_gate_policy' => $suite->latestBenchmarkRun->release_gate_policy_json,
                    'release_gate_failures' => $suite->latestBenchmarkRun->release_gate_failures_json,
                    'release_gate_warnings' => $suite->latestBenchmarkRun->release_gate_warnings_json,
                    'rollout_status' => $suite->latestBenchmarkRun->rollout_status,
                    'rollout_policy' => $suite->latestBenchmarkRun->rollout_policy_json,
                    'rollout_decision_at' => $suite->latestBenchmarkRun->rollout_decision_at?->toJSON(),
                    'outcome_status' => $suite->latestBenchmarkRun->outcome_status,
                    'outcome_score' => $suite->latestBenchmarkRun->outcome_score,
                    'outcome' => $suite->latestBenchmarkRun->outcome_json,
                    'outcome_recorded_at' => $suite->latestBenchmarkRun->outcome_recorded_at?->toJSON(),
                    'outcome_recorded_by' => $suite->latestBenchmarkRun->outcome_recorded_by,
                    'started_at' => $suite->latestBenchmarkRun->started_at?->toJSON(),
                    'finished_at' => $suite->latestBenchmarkRun->finished_at?->toJSON(),
                    'created_at' => $suite->latestBenchmarkRun->created_at?->toJSON(),
                ] : null,
                'created_at' => $suite->created_at?->toJSON(),
                'updated_at' => $suite->updated_at?->toJSON(),
            ])
            ->values()
            ->all();

        return response()->json(['suites' => $suites]);
    }

    public function storeSuite(Request $request, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:120'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'default_runner_options' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $suite = $benchmarks->createSuite($data);

        return response()->json($benchmarks->suitePayload($suite), 201);
    }

    public function ensureDefaultSuite(Request $request, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'slug' => ['nullable', 'string', 'max:120'],
            'name' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'default_runner_options' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ]);

        $suite = $benchmarks->ensureDefaultSuite($data);

        return response()->json($benchmarks->suitePayload($suite), $suite->wasRecentlyCreated ? 201 : 200);
    }

    public function showSuite(string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->suitePayload($this->resolveSuite($suite)));
    }

    public function showTrends(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'benchmark_key' => ['nullable', 'string', 'max:180'],
            'provider' => ['nullable', 'string', 'max:80'],
        ]);

        return response()->json($benchmarks->trendPayload($this->resolveSuite($suite), $data));
    }

    public function showFairClaudeReport(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
        ]);

        return response()->json($benchmarks->fairClaudeReportPayload($this->resolveSuite($suite), $data));
    }

    public function storeCase(
        Request $request,
        string $suite,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'task_id' => ['nullable', 'uuid'],
            'case_code' => ['nullable', 'string', 'max:120'],
            'title' => ['required', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'workspace' => ['nullable', 'string', 'max:1000'],
            'persist_workspace_path' => ['nullable', 'boolean'],
            'task_contract' => ['nullable', 'array'],
            'runner_options' => ['nullable', 'array'],
            'expected_decision' => ['nullable', Rule::in(['resolved', 'partial', 'blocked', 'unsafe', 'unresolved'])],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'corpus_tier' => ['nullable', Rule::in(['smoke', 'release', 'full_regression', 'quarantine'])],
            'domain_slug' => ['nullable', 'string', 'max:80'],
            'risk_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'curation_status' => ['nullable', Rule::in(['candidate', 'curated', 'quarantined', 'retired'])],
            'curation_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'metadata' => ['nullable', 'array'],
        ]);

        $case = $benchmarks->registerCase($this->resolveSuite($suite), $data);

        return response()->json([
            'case' => $benchmarks->casePayload($case),
        ], 201);
    }

    public function promoteRunCase(
        Request $request,
        string $suite,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'run_id' => ['required', 'uuid'],
            'case_code' => ['nullable', 'string', 'max:120'],
            'title' => ['nullable', 'string', 'max:180'],
            'description' => ['nullable', 'string', 'max:12000'],
            'workspace' => ['nullable', 'string', 'max:1000'],
            'persist_workspace_path' => ['nullable', 'boolean'],
            'task_contract' => ['nullable', 'array'],
            'runner_options' => ['nullable', 'array'],
            'expected_decision' => ['nullable', Rule::in(['resolved', 'partial', 'blocked', 'unsafe', 'unresolved'])],
            'min_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'corpus_tier' => ['nullable', Rule::in(['smoke', 'release', 'full_regression', 'quarantine'])],
            'domain_slug' => ['nullable', 'string', 'max:80'],
            'risk_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'curation_status' => ['nullable', Rule::in(['candidate', 'curated', 'quarantined', 'retired'])],
            'curation_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'status' => ['nullable', Rule::in(['active', 'draft', 'archived'])],
            'metadata' => ['nullable', 'array'],
        ]);

        $run = AtlasEngineeringRun::query()->findOrFail($data['run_id']);
        $case = $benchmarks->promoteRunToCase($run, $this->resolveSuite($suite), $data);

        return response()->json([
            'case' => $benchmarks->casePayload($case),
        ], $case->wasRecentlyCreated ? 201 : 200);
    }

    public function runSuite(
        Request $request,
        string $suite,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'workspace' => ['nullable', 'string', 'max:1000'],
            'provider' => ['nullable', 'string', 'max:80'],
            'model' => ['nullable', 'string', 'max:120'],
            'model_policy' => ['nullable', Rule::in(['fixed', 'off', 'auto', 'balanced', 'best_quality', 'best-quality', 'fastest', 'cheapest'])],
            'fair_mode' => ['nullable', 'boolean'],
            'claude_only' => ['nullable', 'boolean'],
            'single_provider' => ['nullable', 'boolean'],
            'no_decide' => ['nullable', 'boolean'],
            'fallback_disabled' => ['nullable', 'boolean'],
            'require_pass_without_human' => ['nullable', 'boolean'],
            'claude_code_baseline' => ['nullable', Rule::in(['off', 'plan', 'run', true, false, 1, 0, '1', '0', 'true', 'false'])],
            'claude_code_baseline_mode' => ['nullable', Rule::in(['off', 'plan', 'run'])],
            'claude_code_baseline_model' => ['nullable', 'string', 'max:120'],
            'claude_code_baseline_binary' => ['nullable', 'string', 'max:200'],
            'claude_code_baseline_workspace' => ['nullable', 'string', 'max:1000'],
            'claude_code_baseline_timeout' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'claude_code_baseline_validation_timeout' => ['nullable', 'integer', 'min:1', 'max:1800'],
            'baseline_runner' => ['nullable', Rule::in(['off', 'plan', 'run'])],
            'baseline_model' => ['nullable', 'string', 'max:120'],
            'baseline_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:3600'],
            'baseline_validation_timeout_seconds' => ['nullable', 'integer', 'min:1', 'max:1800'],
            'permission' => ['nullable', Rule::in(['auto', 'read', 'write', 'danger'])],
            'sandbox' => ['nullable', Rule::in(['workspace', 'worktree', 'docker'])],
            'docker_service' => ['nullable', 'string', 'max:120'],
            'docker_image' => ['nullable', 'string', 'max:180'],
            'docker_workdir' => ['nullable', 'string', 'max:200'],
            'docker_cache' => ['nullable', Rule::in(['auto', 'off'])],
            'docker_network' => ['nullable', Rule::in(['profile', 'none', 'bridge'])],
            'docker_healthcheck_services' => ['nullable', 'array'],
            'docker_healthcheck_services.*' => ['string', 'max:120'],
            'docker_healthcheck_timeout' => ['nullable', 'integer', 'min:1', 'max:600'],
            'docker_artifact_paths' => ['nullable', 'array'],
            'docker_artifact_paths.*' => ['string', 'max:300'],
            'docker_artifact_max_files' => ['nullable', 'integer', 'min:1', 'max:1000'],
            'docker_artifact_max_bytes' => ['nullable', 'integer', 'min:1', 'max:104857600'],
            'provider_runtime' => ['nullable', Rule::in(['host', 'docker', 'auto'])],
            'provider_docker_compose_file' => ['nullable', 'string', 'max:1000'],
            'provider_docker_service' => ['nullable', 'string', 'max:120'],
            'provider_docker_app_dir' => ['nullable', 'string', 'max:200'],
            'provider_docker_workspace_dir' => ['nullable', 'string', 'max:200'],
            'max_attempts' => ['nullable', 'integer', 'min:1', 'max:10'],
            'test_command' => ['nullable', 'string', 'max:500'],
            'visual_e2e' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_scan' => ['nullable', Rule::in(['auto', 'off', 'required'])],
            'quality_profile' => ['nullable', Rule::in(['auto', 'fast', 'standard', 'release', 'deep'])],
            'quality_changed_only' => ['nullable', 'boolean'],
            'harness_policy' => ['nullable', Rule::in(['auto', 'off', 'strict'])],
            'control_profile' => ['nullable', 'string', 'max:120'],
            'complete' => ['nullable', 'boolean'],
            'auto_test' => ['nullable', 'boolean'],
            'critical' => ['nullable', 'boolean'],
            'dry_run' => ['nullable', 'boolean'],
            'no_provider' => ['nullable', 'boolean'],
            'keep_workspace' => ['nullable', 'boolean'],
            'apply_isolated_patch' => ['nullable', 'boolean'],
            'release_gate_profile' => ['nullable', Rule::in(['off', 'advisory', 'smoke', 'release', 'strict'])],
            'release_gate_policy' => ['nullable', 'array'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
            'corpus_tier' => ['nullable', Rule::in(['smoke', 'release', 'full_regression', 'quarantine'])],
            'domain_slug' => ['nullable', 'string', 'max:80'],
            'risk_profile' => ['nullable', Rule::in(['low', 'medium', 'high', 'critical'])],
            'curation_status' => ['nullable', Rule::in(['candidate', 'curated', 'quarantined', 'retired'])],
            'case_codes' => ['nullable', 'array'],
            'case_codes.*' => ['string', 'max:120'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:80'],
            'runner_options' => ['nullable', 'array'],
        ]);

        $run = $benchmarks->runSuite($this->resolveSuite($suite), $data);

        return response()->json($benchmarks->runPayload($run), 201);
    }

    public function showRun(AtlasEngineeringBenchmarkRun $benchmarkRun, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->runPayload($benchmarkRun));
    }

    public function replayManifest(AtlasEngineeringBenchmarkRun $benchmarkRun, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        return response()->json($benchmarks->replayManifestPayload($benchmarkRun));
    }

    public function refreshCorpus(string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $resolved = $this->resolveSuite($suite);
        $manifest = $benchmarks->refreshCorpusManifest($resolved);

        return response()->json([
            ...$benchmarks->suitePayload($resolved->refresh()),
            'corpus_manifest' => $manifest,
        ]);
    }

    public function calibrateSuite(Request $request, string $suite, EngineeringBenchmarkService $benchmarks): JsonResponse
    {
        $data = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:500'],
        ]);
        $resolved = $this->resolveSuite($suite);
        $calibration = $benchmarks->calibrateSuite($resolved, $data);

        return response()->json([
            ...$benchmarks->suitePayload($resolved->refresh()),
            'rollout_calibration' => $calibration,
        ]);
    }

    public function recordOutcome(
        Request $request,
        AtlasEngineeringBenchmarkRun $benchmarkRun,
        EngineeringBenchmarkService $benchmarks,
    ): JsonResponse {
        $data = $request->validate([
            'status' => ['nullable', Rule::in(['pending', 'healthy', 'accepted', 'degraded', 'incident', 'rolled_back'])],
            'outcome_status' => ['nullable', Rule::in(['pending', 'healthy', 'accepted', 'degraded', 'incident', 'rolled_back'])],
            'score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'outcome_score' => ['nullable', 'integer', 'min:0', 'max:100'],
            'summary' => ['nullable', 'string', 'max:12000'],
            'notes' => ['nullable', 'string', 'max:12000'],
            'signals' => ['nullable', 'array'],
            'rollback_reason' => ['nullable', 'string', 'max:12000'],
            'incident_ref' => ['nullable', 'string', 'max:500'],
            'recorded_by' => ['nullable', 'string', 'max:120'],
        ]);

        $run = $benchmarks->recordOutcome($benchmarkRun, $data);

        return response()->json($benchmarks->runPayload($run));
    }

    private function resolveSuite(string $suite): AtlasEngineeringBenchmarkSuite
    {
        return AtlasEngineeringBenchmarkSuite::query()
            ->where('id', $suite)
            ->orWhere('slug', $suite)
            ->firstOrFail();
    }
}
