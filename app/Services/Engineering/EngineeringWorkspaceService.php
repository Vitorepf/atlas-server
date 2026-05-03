<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class EngineeringWorkspaceService
{
    private const COMPOSE_FILES = [
        'compose.yaml',
        'compose.yml',
        'docker-compose.yaml',
        'docker-compose.yml',
    ];

    public function __construct(
        private readonly EngineeringDockerHarnessService $dockerHarness,
    ) {}

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    public function prepare(string $workspace, AtlasEngineeringRun $run, array $options = []): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $sandbox = $this->normalizedSandbox($options['sandbox'] ?? 'workspace');
        $base = [
            'mode' => $sandbox,
            'requested_mode' => $sandbox,
            'status' => 'ready',
            'original_workspace' => $workspace,
            'execution_workspace' => $workspace,
            'repo_root' => $this->repoRoot($workspace),
            'branch' => $this->run(['git', 'branch', '--show-current'], $workspace) ?: null,
            'head' => $this->run(['git', 'rev-parse', 'HEAD'], $workspace) ?: null,
            'dirty_files' => $this->dirtyFiles($workspace),
            'isolated' => false,
            'created_at' => now()->toJSON(),
        ];

        if ($sandbox === 'workspace') {
            return $base;
        }

        if ($sandbox === 'docker') {
            return $this->prepareDocker($base, $run, $options);
        }

        return $this->prepareWorktree($base, $run);
    }

    /**
     * @param  array<string,mixed>  $base
     * @return array<string,mixed>
     */
    private function prepareWorktree(array $base, AtlasEngineeringRun $run): array
    {
        if (! is_string($base['repo_root']) || $base['repo_root'] === '') {
            return array_merge($base, [
                'mode' => 'workspace',
                'status' => 'fallback',
                'fallback_reason' => 'workspace_is_not_a_git_repository',
            ]);
        }

        $targetRoot = storage_path('app/engineering-worktrees');
        $worktreePath = $targetRoot.'/run-'.$run->id;
        File::ensureDirectoryExists($targetRoot);

        if (File::exists($worktreePath)) {
            File::deleteDirectory($worktreePath);
        }

        $this->process(['git', 'worktree', 'prune'], (string) $base['repo_root'], 30);

        $head = is_string($base['head']) && $base['head'] !== '' ? $base['head'] : 'HEAD';
        $process = $this->process(['git', 'worktree', 'add', '--detach', $worktreePath, $head], (string) $base['repo_root'], 60);
        if ((int) $process['exit_code'] !== 0 || ! is_dir($worktreePath)) {
            return array_merge($base, [
                'mode' => 'workspace',
                'status' => 'fallback',
                'fallback_reason' => 'git_worktree_add_failed',
                'stderr_excerpt' => Str::limit((string) $process['stderr'], 1200),
            ]);
        }

        return array_merge($base, [
            'status' => 'ready',
            'execution_workspace' => realpath($worktreePath) ?: $worktreePath,
            'isolated' => true,
            'isolation_type' => 'git_worktree',
            'worktree_path_hash' => hash('sha256', $worktreePath),
            'dirty_files_included' => false,
        ]);
    }

    /**
     * @param  array<string,mixed>  $base
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function prepareDocker(array $base, AtlasEngineeringRun $run, array $options): array
    {
        $docker = $this->dockerProfile((string) $base['original_workspace'], $options);
        $worktree = $this->prepareWorktree(array_merge($base, ['mode' => 'worktree']), $run);
        $worktree['requested_mode'] = 'docker';
        $worktree['docker'] = $docker;

        if (! (bool) ($worktree['isolated'] ?? false)) {
            return array_merge($worktree, [
                'mode' => 'workspace',
                'status' => 'fallback',
                'fallback_reason' => $worktree['fallback_reason'] ?? 'docker_requires_git_worktree',
                'containerized_execution' => false,
            ]);
        }

        if (! (bool) ($docker['profile_found'] ?? false)) {
            return array_merge($worktree, [
                'mode' => 'worktree',
                'status' => 'fallback',
                'fallback_reason' => 'docker_profile_missing',
                'containerized_execution' => false,
            ]);
        }

        if (! (bool) ($docker['usable'] ?? false)) {
            return array_merge($worktree, [
                'mode' => 'worktree',
                'status' => 'fallback',
                'fallback_reason' => $docker['unusable_reason'] ?? 'docker_unavailable',
                'containerized_execution' => false,
            ]);
        }

        return array_merge($worktree, [
            'mode' => 'docker',
            'status' => 'ready',
            'isolation_type' => 'docker_worktree',
            'containerized_execution' => true,
            'fallback_reason' => null,
        ]);
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function release(array $plan, bool $keep = false): array
    {
        if (! (bool) ($plan['isolated'] ?? false)
            || ! in_array((string) ($plan['isolation_type'] ?? ''), ['git_worktree', 'docker_worktree'], true)
        ) {
            return [
                'status' => 'not_applicable',
                'released_at' => now()->toJSON(),
            ];
        }

        $workspace = (string) ($plan['execution_workspace'] ?? '');
        $repoRoot = (string) ($plan['repo_root'] ?? '');
        if ($workspace === '' || $repoRoot === '') {
            return [
                'status' => 'skipped',
                'reason' => 'missing_workspace_or_repo_root',
                'released_at' => now()->toJSON(),
            ];
        }

        if ($keep) {
            return [
                'status' => 'kept',
                'execution_workspace' => $workspace,
                'released_at' => now()->toJSON(),
            ];
        }

        $process = $this->process(['git', 'worktree', 'remove', '--force', $workspace], $repoRoot, 60);
        if ((int) $process['exit_code'] !== 0 && File::isDirectory($workspace)) {
            File::deleteDirectory($workspace);
        }

        return [
            'status' => File::isDirectory($workspace) ? 'failed' : 'released',
            'exit_code' => $process['exit_code'],
            'stderr_excerpt' => $process['stderr'] !== '' ? Str::limit((string) $process['stderr'], 1200) : null,
            'released_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function applyPatchToOriginal(array $plan, ?AtlasEngineeringPatchArtifact $patch): array
    {
        if (! (bool) ($plan['isolated'] ?? false)
            || ! in_array((string) ($plan['isolation_type'] ?? ''), ['git_worktree', 'docker_worktree'], true)
        ) {
            return [
                'status' => 'not_applicable',
                'reason' => 'workspace_not_isolated',
                'applied_at' => now()->toJSON(),
            ];
        }

        $originalWorkspace = (string) ($plan['original_workspace'] ?? '');
        $diffPath = (string) ($patch?->diff_path ?? '');
        if ($originalWorkspace === '' || $diffPath === '' || ! File::exists($diffPath)) {
            return [
                'status' => 'no_patch',
                'reason' => 'missing_diff_artifact',
                'applied_at' => now()->toJSON(),
            ];
        }

        $riskFlags = (array) ($patch?->risk_flags_json ?? []);
        if (in_array('possible_secret_in_diff', $riskFlags, true)) {
            return [
                'status' => 'blocked',
                'reason' => 'possible_secret_in_diff',
                'applied_at' => now()->toJSON(),
            ];
        }

        $dirtyOverlap = $this->dirtyOverlap($originalWorkspace, (array) ($patch?->changed_files_json ?? []));
        if ($dirtyOverlap !== []) {
            return [
                'status' => 'blocked',
                'reason' => 'dirty_state_overlap',
                'dirty_overlap' => $dirtyOverlap,
                'applied_at' => now()->toJSON(),
            ];
        }

        $check = $this->process(['git', 'apply', '--check', $diffPath], $originalWorkspace, 60);
        if ((int) $check['exit_code'] !== 0) {
            return [
                'status' => 'failed',
                'reason' => 'git_apply_check_failed',
                'stderr_excerpt' => Str::limit((string) $check['stderr'], 1200),
                'applied_at' => now()->toJSON(),
            ];
        }

        $apply = $this->process(['git', 'apply', $diffPath], $originalWorkspace, 60);

        return [
            'status' => ((int) $apply['exit_code'] === 0) ? 'applied' : 'failed',
            'reason' => ((int) $apply['exit_code'] === 0) ? null : 'git_apply_failed',
            'changed_files' => array_values((array) ($patch?->changed_files_json ?? [])),
            'exit_code' => $apply['exit_code'],
            'stderr_excerpt' => $apply['stderr'] !== '' ? Str::limit((string) $apply['stderr'], 1200) : null,
            'applied_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $options
     * @return array<string,mixed>
     */
    private function dockerProfile(string $workspace, array $options): array
    {
        $composeFiles = collect(self::COMPOSE_FILES)
            ->filter(fn (string $file): bool => File::exists($workspace.'/'.$file))
            ->values()
            ->all();
        $dockerfile = File::exists($workspace.'/Dockerfile') ? 'Dockerfile' : null;
        $devcontainer = File::exists($workspace.'/.devcontainer/devcontainer.json') ? '.devcontainer/devcontainer.json' : null;
        $dockerAvailable = $this->process(['docker', '--version'], $workspace, 5);
        $composeAvailable = (int) $dockerAvailable['exit_code'] === 0
            ? $this->process(['docker', 'compose', 'version'], $workspace, 5)
            : ['exit_code' => 1, 'stdout' => '', 'stderr' => 'docker unavailable'];
        $composeFile = $composeFiles[0] ?? null;
        $service = $this->dockerService($workspace, $composeFile, $options);
        $image = $this->nonEmptyString($options['docker_image'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.docker.default_image'))
            ?: 'atlas-harness-'.substr(hash('sha256', $workspace), 0, 12);
        $runtime = match (true) {
            $composeFile !== null && $service !== null => 'compose',
            $dockerfile !== null => 'dockerfile',
            default => 'none',
        };
        $profileFound = $composeFile !== null || $dockerfile !== null || $devcontainer !== null;
        $usable = match ($runtime) {
            'compose' => (int) $dockerAvailable['exit_code'] === 0 && (int) $composeAvailable['exit_code'] === 0,
            'dockerfile' => (int) $dockerAvailable['exit_code'] === 0,
            default => false,
        };

        $profile = [
            'profile_found' => $profileFound,
            'usable' => $usable,
            'runtime' => $runtime,
            'docker_available' => (int) $dockerAvailable['exit_code'] === 0,
            'compose_available' => (int) $composeAvailable['exit_code'] === 0,
            'compose_files' => $composeFiles,
            'selected_compose_file' => $composeFile,
            'dockerfile' => $dockerfile,
            'devcontainer' => $devcontainer,
            'service' => $service,
            'image' => $image,
            'container_workdir' => $this->nonEmptyString($options['docker_workdir'] ?? null)
                ?: $this->nonEmptyString(config('atlas.engineering.docker.workdir'))
                ?: '/workspace',
            'unusable_reason' => $this->dockerUnusableReason($profileFound, $runtime, $dockerAvailable, $composeAvailable, $service),
        ];

        return $this->dockerHarness->augmentProfile($workspace, $profile, $options);
    }

    /**
     * @param  array<string,mixed>  $options
     */
    private function dockerService(string $workspace, ?string $composeFile, array $options): ?string
    {
        $configured = $this->nonEmptyString($options['docker_service'] ?? null)
            ?: $this->nonEmptyString(config('atlas.engineering.docker.default_service'));
        if ($configured) {
            return $configured;
        }

        if ($composeFile === null) {
            return null;
        }

        $services = $this->process(['docker', 'compose', '-f', $workspace.'/'.$composeFile, 'config', '--services'], $workspace, 10);
        if ((int) $services['exit_code'] !== 0) {
            return null;
        }

        return collect(explode("\n", (string) $services['stdout']))
            ->map(fn (string $service): string => trim($service))
            ->filter()
            ->first();
    }

    /**
     * @param  array{exit_code:int,stdout:string,stderr:string}  $dockerAvailable
     * @param  array{exit_code:int,stdout:string,stderr:string}  $composeAvailable
     */
    private function dockerUnusableReason(
        bool $profileFound,
        string $runtime,
        array $dockerAvailable,
        array $composeAvailable,
        ?string $service,
    ): ?string {
        if (! $profileFound) {
            return 'docker_profile_missing';
        }
        if ((int) $dockerAvailable['exit_code'] !== 0) {
            return 'docker_binary_unavailable';
        }
        if ($runtime === 'compose' && $service === null) {
            return 'docker_compose_service_missing';
        }
        if ($runtime === 'compose' && (int) $composeAvailable['exit_code'] !== 0) {
            return 'docker_compose_unavailable';
        }
        if ($runtime === 'none') {
            return 'docker_runtime_unsupported';
        }

        return null;
    }

    /**
     * @return array<int,string>
     */
    private function dirtyFiles(string $workspace): array
    {
        $status = $this->run(['git', 'status', '--short'], $workspace);
        if ($status === '') {
            return [];
        }

        return collect(explode("\n", $status))
            ->map(fn (string $line): string => trim($line))
            ->filter()
            ->take(100)
            ->values()
            ->all();
    }

    /**
     * @param  array<int,string>  $changedFiles
     * @return array<int,string>
     */
    private function dirtyOverlap(string $workspace, array $changedFiles): array
    {
        $changed = collect($changedFiles)
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (string $file): string => trim($file))
            ->values();

        if ($changed->isEmpty()) {
            return [];
        }

        return collect($this->dirtyFiles($workspace))
            ->map(fn (string $line): string => $this->pathFromStatusLine($line))
            ->filter()
            ->intersect($changed)
            ->values()
            ->all();
    }

    private function pathFromStatusLine(string $line): string
    {
        $line = rtrim($line);
        if (preg_match('/^.{1,2}\s+(.+)$/', $line, $matches) === 1) {
            return trim((string) $matches[1]);
        }

        return trim($line);
    }

    private function repoRoot(string $workspace): ?string
    {
        $root = $this->run(['git', 'rev-parse', '--show-toplevel'], $workspace);

        return $root !== '' ? $root : null;
    }

    private function normalizedSandbox(mixed $value): string
    {
        $sandbox = $this->nonEmptyString($value) ?: 'workspace';

        return in_array($sandbox, ['workspace', 'worktree', 'docker'], true) ? $sandbox : 'workspace';
    }

    private function nonEmptyString(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    /**
     * @param  array<int,string>  $command
     */
    private function run(array $command, string $workspace, int $timeout = 10): string
    {
        $process = $this->process($command, $workspace, $timeout);

        return trim((string) $process['stdout']);
    }

    /**
     * @param  array<int,string>  $command
     * @return array{exit_code:int,stdout:string,stderr:string}
     */
    private function process(array $command, string $workspace, int $timeout): array
    {
        try {
            $process = new Process($command, $workspace, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout($timeout);
            $process->run();

            return [
                'exit_code' => $process->getExitCode() ?? 1,
                'stdout' => AtlasSecurity::redactString($process->getOutput()),
                'stderr' => AtlasSecurity::redactString($process->getErrorOutput()),
            ];
        } catch (\Throwable $exception) {
            return [
                'exit_code' => 1,
                'stdout' => '',
                'stderr' => AtlasSecurity::redactString($exception->getMessage()),
            ];
        }
    }
}
