<?php

namespace App\Console\Commands;

use App\Models\AtlasEngineeringBenchmarkSuite;
use App\Services\Ai\FairClaudePolicy;
use App\Services\Engineering\EngineeringBenchmarkService;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use App\Support\YesNo;
use App\Console\Concerns\EmitsCanonicalJson;

class AtlasEngineeringBenchmarkCommand extends Command
{
    use EmitsCanonicalJson;

    protected $signature = 'atlas:engineering:benchmark
        {--suite= : Suite slug or id}
        {--workspace= : Workspace path for cases without a persisted workspace}
        {--case=* : Restrict execution to one or more case codes}
        {--tag=* : Restrict execution to cases carrying these tags}
        {--limit= : Maximum number of cases to run}
        {--tier= : Restrict execution to a corpus tier}
        {--domain= : Restrict execution to a corpus domain}
        {--risk= : Restrict execution to a risk profile}
        {--curation-status= : Restrict execution to a curation status}
        {--provider= : Force provider passed through to atlas:cli:dev}
        {--model= : Benchmark model label used for baseline comparisons}
        {--model-policy=fixed : fixed, auto, balanced, best-quality, fastest or cheapest model selection}
        {--claude-only : Fair Claude benchmark mode: force claude_cli + Claude Opus and require pass_without_human}
        {--single-provider : Fair Claude benchmark mode: forbid provider switching}
        {--no-decide : Fair Claude benchmark mode: disable Atlas Decide}
        {--fallback-disabled : Fair Claude benchmark mode: fail instead of falling back to another provider/model}
        {--allow-unverified-fair-pass : Legacy non-fair escape hatch. Rejected when any Fair Claude flag is active}
        {--claude-code-baseline=off : off, plan or run Claude Code CLI baseline arm}
        {--claude-code-baseline-model=opus : Claude Code baseline model; opus resolves to the configured Claude premium model}
        {--claude-code-baseline-binary= : Claude Code CLI binary override}
        {--claude-code-baseline-workspace= : Separate workspace for claude-code-baseline=run}
        {--claude-code-baseline-timeout=900 : Seconds to wait for Claude Code baseline run}
        {--claude-code-baseline-validation-timeout=300 : Seconds to wait for Claude Code baseline deterministic validation}
        {--case-timeout= : Maximum wall-clock seconds budgeted per benchmark case}
        {--permission=auto : auto, read, write or danger}
        {--sandbox=workspace : workspace, worktree or docker}
        {--docker-service= : Docker Compose service used for sandbox=docker}
        {--docker-image= : Docker image tag used for Dockerfile-based sandbox=docker}
        {--docker-workdir=/workspace : Container working directory for sandbox=docker}
        {--docker-cache=auto : auto or off for dependency cache mounts in sandbox=docker}
        {--docker-network=profile : profile, none or bridge network policy for sandbox=docker}
        {--docker-healthcheck-service=* : Docker Compose dependency service to start and wait before tests}
        {--docker-healthcheck-timeout=45 : Seconds to wait for Docker dependency healthchecks}
        {--docker-artifact-path=* : Relative artifact path to export after containerized tests}
        {--docker-artifact-max-files=100 : Max files to export from Docker test artifacts}
        {--docker-artifact-max-bytes=10485760 : Max bytes to export from Docker test artifacts}
        {--provider-runtime=host : host, docker or auto for atlas:cli:dev execution}
        {--provider-docker-compose-file= : Atlas Docker Compose file used when provider-runtime=docker}
        {--provider-docker-service= : Atlas Compose service used when provider-runtime=docker}
        {--provider-docker-app-dir=/app : Atlas app directory inside provider runtime container}
        {--provider-docker-workspace-dir=/workspace : Workspace mount path inside provider runtime container}
        {--provider-timeout= : Seconds to wait for Atlas provider execution before aborting}
        {--test-timeout= : Seconds to wait for each deterministic test command before aborting}
        {--max-attempts=1 : Maximum attempts for the underlying dev workflow}
        {--test-command= : Explicit validation command}
        {--visual-e2e=auto : auto, off or required visual/E2E test discovery}
        {--quality-scan=auto : off, auto or required Atlas quality/security scan sensor}
        {--quality-profile=auto : auto, fast, standard, release or deep profile for quality scan}
        {--quality-changed-only : Prefer changed files for quality scan tools that support explicit targets}
        {--control-profile= : Force harness template/profile}
        {--complete : Allow multi-attempt provider repair loop}
        {--auto-test : Run applicable computational sensors/test matrix}
        {--critical : Mark provider execution as critical}
        {--dry-run : Prepare and score without provider execution}
        {--no-provider : Skip provider execution but still capture current diff and sensors}
        {--keep-workspace : Keep isolated execution workspace after the run for debugging}
        {--no-apply-isolated-patch : Do not apply a resolved worktree patch back to the original workspace}
        {--gate-profile=release : Release gate profile: release, smoke, strict, advisory or off}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run an Atlas Engineering benchmark suite and persist case-level runner quality results.';

    public function handle(EngineeringBenchmarkService $benchmarks): int
    {
        if ((bool) $this->option('allow-unverified-fair-pass') && $this->fairModeRequested()) {
            return $this->fairModeViolation('Fair Claude benchmark mode cannot allow unverified pass_without_human.');
        }

        $suiteRef = is_string($this->option('suite')) ? trim($this->option('suite')) : '';
        if ($suiteRef === '') {
            $this->error('--suite e obrigatorio.');

            return self::FAILURE;
        }

        $suiteQuery = AtlasEngineeringBenchmarkSuite::query()
            ->where('slug', $suiteRef);
        if (Str::isUuid($suiteRef)) {
            $suiteQuery->orWhere('id', $suiteRef);
        }
        $suite = $suiteQuery->first();
        if (! $suite) {
            $this->error("Benchmark suite nao encontrada: {$suiteRef}");

            return self::FAILURE;
        }

        try {
            $benchmarkRun = $benchmarks->runSuite($suite, [
                'workspace' => $this->workspace(),
                'case_codes' => (array) $this->option('case'),
                'tags' => (array) $this->option('tag'),
                'limit' => $this->option('limit') ? (int) $this->option('limit') : null,
                'corpus_tier' => is_string($this->option('tier')) ? $this->option('tier') : null,
                'domain_slug' => is_string($this->option('domain')) ? $this->option('domain') : null,
                'risk_profile' => is_string($this->option('risk')) ? $this->option('risk') : null,
                'curation_status' => is_string($this->option('curation-status')) ? $this->option('curation-status') : null,
                'provider' => is_string($this->option('provider')) ? $this->option('provider') : null,
                'model' => is_string($this->option('model')) ? $this->option('model') : null,
                'model_policy' => is_string($this->option('model-policy')) ? $this->option('model-policy') : 'fixed',
                'claude_only' => (bool) $this->option('claude-only'),
                'single_provider' => (bool) $this->option('single-provider'),
                'no_decide' => (bool) $this->option('no-decide'),
                'fallback_disabled' => (bool) $this->option('fallback-disabled'),
                'require_pass_without_human' => ! (bool) $this->option('allow-unverified-fair-pass'),
                'claude_code_baseline' => is_string($this->option('claude-code-baseline')) ? $this->option('claude-code-baseline') : 'off',
                'claude_code_baseline_model' => is_string($this->option('claude-code-baseline-model')) ? $this->option('claude-code-baseline-model') : 'opus',
                'claude_code_baseline_binary' => is_string($this->option('claude-code-baseline-binary')) ? $this->option('claude-code-baseline-binary') : null,
                'claude_code_baseline_workspace' => is_string($this->option('claude-code-baseline-workspace')) ? $this->option('claude-code-baseline-workspace') : null,
                'claude_code_baseline_timeout' => (int) $this->option('claude-code-baseline-timeout'),
                'claude_code_baseline_validation_timeout' => (int) $this->option('claude-code-baseline-validation-timeout'),
                'case_timeout_seconds' => $this->option('case-timeout') ? (int) $this->option('case-timeout') : null,
                'permission' => (string) $this->option('permission'),
                'sandbox' => (string) $this->option('sandbox'),
                'docker_service' => is_string($this->option('docker-service')) ? $this->option('docker-service') : null,
                'docker_image' => is_string($this->option('docker-image')) ? $this->option('docker-image') : null,
                'docker_workdir' => is_string($this->option('docker-workdir')) ? $this->option('docker-workdir') : null,
                'docker_cache' => is_string($this->option('docker-cache')) ? $this->option('docker-cache') : 'auto',
                'docker_network' => is_string($this->option('docker-network')) ? $this->option('docker-network') : 'profile',
                'docker_healthcheck_services' => (array) $this->option('docker-healthcheck-service'),
                'docker_healthcheck_timeout' => (int) $this->option('docker-healthcheck-timeout'),
                'docker_artifact_paths' => (array) $this->option('docker-artifact-path'),
                'docker_artifact_max_files' => (int) $this->option('docker-artifact-max-files'),
                'docker_artifact_max_bytes' => (int) $this->option('docker-artifact-max-bytes'),
                'provider_runtime' => is_string($this->option('provider-runtime')) ? $this->option('provider-runtime') : 'host',
                'provider_docker_compose_file' => is_string($this->option('provider-docker-compose-file')) ? $this->option('provider-docker-compose-file') : null,
                'provider_docker_service' => is_string($this->option('provider-docker-service')) ? $this->option('provider-docker-service') : null,
                'provider_docker_app_dir' => is_string($this->option('provider-docker-app-dir')) ? $this->option('provider-docker-app-dir') : null,
                'provider_docker_workspace_dir' => is_string($this->option('provider-docker-workspace-dir')) ? $this->option('provider-docker-workspace-dir') : null,
                'provider_timeout_seconds' => $this->option('provider-timeout') ? (int) $this->option('provider-timeout') : null,
                'test_timeout_seconds' => $this->option('test-timeout') ? (int) $this->option('test-timeout') : null,
                'max_attempts' => (int) $this->option('max-attempts'),
                'test_command' => is_string($this->option('test-command')) ? $this->option('test-command') : null,
                'visual_e2e' => is_string($this->option('visual-e2e')) ? $this->option('visual-e2e') : 'auto',
                'quality_scan' => is_string($this->option('quality-scan')) ? $this->option('quality-scan') : 'auto',
                'quality_profile' => is_string($this->option('quality-profile')) ? $this->option('quality-profile') : 'auto',
                'quality_changed_only' => (bool) $this->option('quality-changed-only'),
                'control_profile' => is_string($this->option('control-profile')) ? $this->option('control-profile') : null,
                'complete' => (bool) $this->option('complete'),
                'auto_test' => (bool) $this->option('auto-test'),
                'critical' => (bool) $this->option('critical'),
                'dry_run' => (bool) $this->option('dry-run'),
                'no_provider' => (bool) $this->option('no-provider'),
                'keep_workspace' => (bool) $this->option('keep-workspace'),
                'apply_isolated_patch' => ! (bool) $this->option('no-apply-isolated-patch'),
                'release_gate_profile' => is_string($this->option('gate-profile')) ? $this->option('gate-profile') : 'release',
            ]);
        } catch (InvalidArgumentException $exception) {
            if (str_starts_with($exception->getMessage(), FairClaudePolicy::ERROR_CODE.':')) {
                return $this->fairModeViolation($exception->getMessage());
            }
            throw $exception;
        }
        $payload = $benchmarks->runPayload($benchmarkRun);

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return $benchmarkRun->status === 'passed' ? self::SUCCESS : self::FAILURE;
        }

        $this->render($payload);

        return $benchmarkRun->status === 'passed' ? self::SUCCESS : self::FAILURE;
    }

    private function fairModeRequested(): bool
    {
        return (bool) $this->option('claude-only')
            || (bool) $this->option('single-provider')
            || (bool) $this->option('no-decide')
            || (bool) $this->option('fallback-disabled');
    }

    private function fairModeViolation(string $message): int
    {
        $payload = [
            'ok' => false,
            'error' => 'fair_mode_violation',
            'message' => $message,
            'details' => [
                'allow_unverified_fair_pass' => (bool) $this->option('allow-unverified-fair-pass'),
                'claude_only' => (bool) $this->option('claude-only'),
                'single_provider' => (bool) $this->option('single-provider'),
                'no_decide' => (bool) $this->option('no-decide'),
                'fallback_disabled' => (bool) $this->option('fallback-disabled'),
            ],
        ];

        if ((bool) $this->option('json')) {
            $this->line($this->encode($payload));

            return self::FAILURE;
        }

        $this->error($message);

        return self::FAILURE;
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $run = (array) ($payload['benchmark_run'] ?? []);
        $suite = (array) ($payload['suite'] ?? []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering Benchmark</>', (string) ($run['status'] ?? 'unknown'));
        $this->components->twoColumnDetail('Suite', (string) ($suite['slug'] ?? $run['suite_id'] ?? '-'));
        $this->components->twoColumnDetail('Run', (string) ($run['id'] ?? '-'));
        $this->components->twoColumnDetail('Provider', (string) ($run['provider'] ?? '-'));
        $this->components->twoColumnDetail('Model', (string) ($run['model'] ?? '-'));
        $this->components->twoColumnDetail('Trend', (string) ($run['trend_status'] ?? '-'));
        $this->components->twoColumnDetail('Release gate', (string) ($run['release_gate_status'] ?? '-'));
        $this->components->twoColumnDetail('Gate profile', (string) ($run['release_gate_profile'] ?? '-'));
        $this->components->twoColumnDetail('Rollout', (string) ($run['rollout_status'] ?? '-'));
        $this->components->twoColumnDetail('Outcome', (string) ($run['outcome_status'] ?? 'pending'));
        $this->components->twoColumnDetail('Pass rate', (string) ($run['pass_rate'] ?? '0'));
        $this->components->twoColumnDetail('Pass rate delta', (string) ($run['pass_rate_delta'] ?? '-'));
        $this->components->twoColumnDetail('Average score', (string) ($run['average_score'] ?? '-'));
        $this->components->twoColumnDetail('Score delta', (string) ($run['average_score_delta'] ?? '-'));
        $this->components->twoColumnDetail('Failed controls', (string) (($run['failed_control_count'] ?? 0) + ($run['blocked_control_count'] ?? 0)));
        $this->components->twoColumnDetail('Failed tests', (string) ($run['failed_test_count'] ?? 0));
        $this->components->twoColumnDetail('Blocking findings', (string) ($run['blocking_review_finding_count'] ?? 0));
        $this->components->twoColumnDetail('Attempts', (string) ($run['total_attempts'] ?? 0));
        $this->components->twoColumnDetail('Tokens', (string) ($run['total_tokens'] ?? '-'));
        $this->components->twoColumnDetail('Cost microusd', (string) ($run['cost_microusd'] ?? '-'));

        foreach ((array) ($run['release_gate_failures'] ?? []) as $failure) {
            if (is_scalar($failure) && trim((string) $failure) !== '') {
                $this->warn('Gate: '.trim((string) $failure));
            }
        }

        $this->newLine();
        $this->table(
            ['case', 'passed', 'decision', 'score', 'failure'],
            collect((array) ($payload['results'] ?? []))
                ->map(fn (array $result): array => [
                    $result['case_code'] ?? $result['case_id'] ?? '-',
                    YesNo::format($result['passed'] ?? false),
                    $result['decision'] ?? '-',
                    $result['score'] ?? '-',
                    $result['failure_summary'] ?? '',
                ])
                ->all(),
        );
    }

    private function workspace(): ?string
    {
        $workspace = $this->option('workspace');
        if (! is_string($workspace) || trim($workspace) === '') {
            return null;
        }

        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
