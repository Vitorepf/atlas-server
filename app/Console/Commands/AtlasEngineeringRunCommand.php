<?php

namespace App\Console\Commands;

use App\Models\AtlasTask;
use App\Services\Engineering\EngineeringHarnessRunnerService;
use Illuminate\Console\Command;

class AtlasEngineeringRunCommand extends Command
{
    protected $signature = 'atlas:engineering:run
        {--task-id= : Atlas task id to execute}
        {--workspace= : Workspace path. Defaults to current directory}
        {--provider= : Force provider passed through to atlas:cli:dev}
        {--model= : Force model alias/id passed through to atlas:cli:dev}
        {--model-policy=fixed : fixed, auto, balanced, best-quality, fastest or cheapest automatic model selection}
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
        {--max-attempts=1 : Maximum attempts for the underlying dev workflow}
        {--test-command= : Explicit validation command}
        {--visual-e2e=auto : auto, off or required visual/E2E test discovery}
        {--quality-scan=off : off, auto or required Atlas quality/security scan sensor}
        {--quality-profile=auto : auto, fast, standard, release or deep profile for quality scan}
        {--quality-changed-only : Prefer changed files for quality scan tools that support explicit targets}
        {--harness-policy=auto : auto, off or strict harnessability autonomy policy}
        {--control-profile= : Force harness template/profile}
        {--complete : Allow multi-attempt provider repair loop}
        {--auto-test : Run applicable computational sensors/test matrix}
        {--critical : Mark provider execution as critical}
        {--dry-run : Prepare and score without provider execution}
        {--no-provider : Skip provider execution but still capture current diff and sensors}
        {--keep-workspace : Keep isolated execution workspace after the run for debugging}
        {--no-apply-isolated-patch : Do not apply a resolved worktree patch back to the original workspace}
        {--json : Print machine-readable JSON}';

    protected $description = 'Run the Atlas Engineering Harness Runner for a task with controls, patch artifacts, tests and score.';

    public function handle(EngineeringHarnessRunnerService $runner): int
    {
        $taskId = is_string($this->option('task-id')) ? trim($this->option('task-id')) : '';
        if ($taskId === '') {
            $this->error('--task-id e obrigatorio.');

            return self::FAILURE;
        }

        $task = AtlasTask::query()->with(['project', 'projectStep'])->find($taskId);
        if (! $task) {
            $this->error("Task nao encontrada: {$taskId}");

            return self::FAILURE;
        }

        $payload = $runner->run($task, [
            'workspace' => $this->workspace(),
            'provider' => is_string($this->option('provider')) ? $this->option('provider') : null,
            'model' => is_string($this->option('model')) ? $this->option('model') : null,
            'model_policy' => is_string($this->option('model-policy')) ? $this->option('model-policy') : 'fixed',
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
            'max_attempts' => (int) $this->option('max-attempts'),
            'test_command' => is_string($this->option('test-command')) ? $this->option('test-command') : null,
            'visual_e2e' => is_string($this->option('visual-e2e')) ? $this->option('visual-e2e') : 'auto',
            'quality_scan' => is_string($this->option('quality-scan')) ? $this->option('quality-scan') : 'off',
            'quality_profile' => is_string($this->option('quality-profile')) ? $this->option('quality-profile') : 'auto',
            'quality_changed_only' => (bool) $this->option('quality-changed-only'),
            'harness_policy' => is_string($this->option('harness-policy')) ? $this->option('harness-policy') : 'auto',
            'control_profile' => is_string($this->option('control-profile')) ? $this->option('control-profile') : null,
            'complete' => (bool) $this->option('complete'),
            'auto_test' => (bool) $this->option('auto-test'),
            'critical' => (bool) $this->option('critical'),
            'dry_run' => (bool) $this->option('dry-run'),
            'no_provider' => (bool) $this->option('no-provider'),
            'keep_workspace' => (bool) $this->option('keep-workspace'),
            'apply_isolated_patch' => ! (bool) $this->option('no-apply-isolated-patch'),
        ]);

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return $this->successExit($payload);
        }

        $this->render($payload);

        return $this->successExit($payload);
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function render(array $payload): void
    {
        $run = (array) ($payload['run'] ?? []);
        $score = (array) ($payload['score'] ?? []);

        $this->newLine();
        $this->components->twoColumnDetail('<fg=bright-blue;options=bold>Atlas Engineering Runner</>', (string) ($run['decision'] ?? 'unknown'));
        $this->components->twoColumnDetail('Run', (string) ($run['id'] ?? '-'));
        $this->components->twoColumnDetail('Status', (string) ($run['status'] ?? '-'));
        $this->components->twoColumnDetail('Score', (string) ($run['score'] ?? '-'));
        $this->components->twoColumnDetail('Context', (string) ($run['context_pack_hash'] ?? '-'));
        $this->components->twoColumnDetail('Harnessability', (string) data_get($payload, 'harnessability.score', '-'));

        $this->newLine();
        $this->line('<fg=bright-blue;options=bold>Controles</>');
        $this->table(
            ['control', 'status', 'summary'],
            collect((array) ($run['control_results'] ?? []))
                ->map(fn (array $result): array => [
                    $result['control_slug'] ?? '-',
                    $result['status'] ?? '-',
                    $result['summary'] ?? '-',
                ])
                ->all(),
        );

        $reasons = (array) ($score['blocking_reasons'] ?? []);
        if ($reasons !== []) {
            $this->newLine();
            $this->line('<fg=yellow>Razoes de bloqueio/atencao</>');
            foreach ($reasons as $reason) {
                $this->line('  - '.(string) $reason);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $payload
     */
    private function successExit(array $payload): int
    {
        $decision = (string) data_get($payload, 'run.decision', 'unresolved');

        return in_array($decision, ['resolved', 'partial'], true) ? self::SUCCESS : self::FAILURE;
    }

    private function workspace(): string
    {
        $workspace = (string) ($this->option('workspace') ?: getcwd() ?: base_path());
        $resolved = realpath($workspace);

        return $resolved && is_dir($resolved) ? $resolved : $workspace;
    }
}
