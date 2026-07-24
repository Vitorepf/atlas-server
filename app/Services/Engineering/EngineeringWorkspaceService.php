<?php

namespace App\Services\Engineering;

use App\Models\AtlasEngineeringPatchArtifact;
use App\Models\AtlasEngineeringRun;
use App\Services\Ai\Support\AiValueNormalizer;
use App\Services\AtlasCode\AtlasCodeWorkspaceProfileService;
use App\Support\AtlasCloneDir;
use App\Support\AtlasSecurity;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
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
        private readonly AtlasCodeWorkspaceProfileService $workspaceProfiles,
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

        $bootstrap = $this->bootstrapWorktreeArtifacts($worktreePath, (string) $base['original_workspace']);

        // Register only after bootstrap: AWIS inventory/brain readiness must
        // observe the complete isolated workspace, not its pre-bootstrap tree.
        $parentProfile = $this->workspaceProfiles->findContainingPath((string) $base['original_workspace']);
        if (Schema::hasTable('atlas_workspace_profiles')) {
            $this->workspaceProfiles->upsertPersistedProfile([
                // Preserve the indexed workspace identity so the generated
                // worktree can reuse its code/test graph without reindexing.
                'slug' => (string) ($parentProfile['slug'] ?? 'engineering-run-'.$run->id),
                'name' => (string) ($parentProfile['name'] ?? 'Engineering run '.$run->id),
                'kind' => 'isolated',
                'workspace_path' => $worktreePath,
                'repo_root' => $worktreePath,
                'production_status' => (string) ($parentProfile['production_status'] ?? 'development'),
                'docs_status' => (string) ($parentProfile['docs_status'] ?? 'unknown'),
                'default_risk' => (string) ($parentProfile['default_risk'] ?? 'medium'),
                'test_commands' => (array) ($parentProfile['test_commands'] ?? []),
                'critical_areas' => (array) ($parentProfile['critical_areas'] ?? []),
                'source' => 'engineering_workspace_service',
                'status' => 'active',
            ]);
        }

        return array_merge($base, [
            'status' => 'ready',
            'execution_workspace' => realpath($worktreePath) ?: $worktreePath,
            'isolated' => true,
            'isolation_type' => 'git_worktree',
            'worktree_path_hash' => hash('sha256', $worktreePath),
            'dirty_files_included' => false,
            'bootstrapped_artifacts' => $bootstrap,
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    public function preparePairedWorktree(string $workspace, string $label, ?string $head = null): array
    {
        $workspace = realpath($workspace) ?: $workspace;
        $base = [
            'mode' => 'worktree',
            'requested_mode' => 'worktree',
            'status' => 'ready',
            'original_workspace' => $workspace,
            'execution_workspace' => $workspace,
            'repo_root' => $this->repoRoot($workspace),
            'branch' => $this->run(['git', 'branch', '--show-current'], $workspace) ?: null,
            'head' => $this->run(['git', 'rev-parse', 'HEAD'], $workspace) ?: null,
            'dirty_files' => $this->dirtyFiles($workspace),
            'isolated' => false,
            'pair_label' => $this->worktreeLabel($label),
            'created_at' => now()->toJSON(),
        ];

        if (! is_string($base['repo_root']) || $base['repo_root'] === '') {
            return array_merge($base, [
                'mode' => 'workspace',
                'status' => 'failed',
                'failure_reason' => 'workspace_is_not_a_git_repository',
            ]);
        }

        $targetRoot = storage_path('app/engineering-worktrees');
        File::ensureDirectoryExists($targetRoot);

        $selectedHead = AiValueNormalizer::trimmedScalarStringOrNull($head) ?: (is_string($base['head']) && $base['head'] !== '' ? $base['head'] : 'HEAD');
        $worktreePath = $targetRoot.'/'.$base['pair_label'].'-'.substr(hash('sha256', implode('|', [
            $workspace,
            $selectedHead,
            Str::uuid()->toString(),
        ])), 0, 16);

        $this->process(['git', 'worktree', 'prune'], (string) $base['repo_root'], 30);

        $process = $this->process(['git', 'worktree', 'add', '--detach', $worktreePath, $selectedHead], (string) $base['repo_root'], 60);
        if ((int) $process['exit_code'] !== 0 || ! is_dir($worktreePath)) {
            return array_merge($base, [
                'mode' => 'workspace',
                'status' => 'failed',
                'failure_reason' => 'git_worktree_add_failed',
                'stderr_excerpt' => Str::limit((string) $process['stderr'], 1200),
            ]);
        }

        $bootstrap = $this->bootstrapWorktreeArtifacts($worktreePath, (string) $base['original_workspace']);

        return array_merge($base, [
            'status' => 'ready',
            'execution_workspace' => realpath($worktreePath) ?: $worktreePath,
            'head' => $selectedHead,
            'isolated' => true,
            'isolation_type' => 'git_worktree',
            'worktree_path_hash' => hash('sha256', $worktreePath),
            'dirty_files_included' => false,
            'bootstrapped_artifacts' => $bootstrap,
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
                'execution_workspace_hash' => hash('sha256', $workspace),
                'released_at' => now()->toJSON(),
            ];
        }

        $process = $this->process(['git', 'worktree', 'remove', '--force', $workspace], $repoRoot, 60);
        if ((int) $process['exit_code'] !== 0 && File::isDirectory($workspace)) {
            File::deleteDirectory($workspace);
        }

        $stderr = (string) ($process['stderr'] ?? '');

        return [
            'status' => File::isDirectory($workspace) ? 'failed' : 'released',
            'exit_code' => $process['exit_code'],
            'execution_workspace_hash' => hash('sha256', $workspace),
            'stderr_excerpt' => $stderr !== '' ? Str::limit(AtlasSecurity::redactString($stderr), 1200) : null,
            'released_at' => now()->toJSON(),
        ];
    }

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    public function applyPatchToOriginal(array $plan, ?AtlasEngineeringPatchArtifact $patch): array
    {
        $originalWorkspace = (string) ($plan['original_workspace'] ?? '');
        $changedFiles = array_values((array) ($patch?->changed_files_json ?? []));
        $scopeSafety = $this->scopeSafetyProfile($originalWorkspace, $changedFiles);

        if (! (bool) ($plan['isolated'] ?? false)
            || ! in_array((string) ($plan['isolation_type'] ?? ''), ['git_worktree', 'docker_worktree'], true)
        ) {
            return [
                'status' => 'not_applicable',
                'reason' => 'workspace_not_isolated',
                'scope_safety' => $scopeSafety,
                'applied_at' => now()->toJSON(),
            ];
        }

        $diffPath = (string) ($patch?->diff_path ?? '');
        if ($originalWorkspace === '' || $diffPath === '' || ! File::exists($diffPath)) {
            return [
                'status' => 'no_patch',
                'reason' => 'missing_diff_artifact',
                'scope_safety' => $scopeSafety,
                'applied_at' => now()->toJSON(),
            ];
        }

        $riskFlags = (array) ($patch?->risk_flags_json ?? []);
        if (in_array('possible_secret_in_diff', $riskFlags, true)) {
            return [
                'status' => 'blocked',
                'reason' => 'possible_secret_in_diff',
                'scope_safety' => $scopeSafety,
                'applied_at' => now()->toJSON(),
            ];
        }

        if (! $scopeSafety['safe']) {
            return [
                'status' => 'blocked',
                'reason' => 'dirty_state_overlap',
                'dirty_overlap' => $scopeSafety['dirty_overlap'],
                'untracked_overlap' => $scopeSafety['untracked_overlap'],
                'scope_safety' => $scopeSafety,
                'applied_at' => now()->toJSON(),
            ];
        }

        $check = $this->process(['git', 'apply', '--check', $diffPath], $originalWorkspace, 60);
        if ((int) $check['exit_code'] !== 0) {
            return [
                'status' => 'failed',
                'reason' => 'git_apply_check_failed',
                'stderr_excerpt' => Str::limit((string) $check['stderr'], 1200),
                'scope_safety' => $scopeSafety,
                'applied_at' => now()->toJSON(),
            ];
        }

        $apply = $this->process(['git', 'apply', $diffPath], $originalWorkspace, 60);

        return [
            'status' => ((int) $apply['exit_code'] === 0) ? 'applied' : 'failed',
            'reason' => ((int) $apply['exit_code'] === 0) ? null : 'git_apply_failed',
            'changed_files' => $changedFiles,
            'exit_code' => $apply['exit_code'],
            'stderr_excerpt' => $apply['stderr'] !== '' ? Str::limit((string) $apply['stderr'], 1200) : null,
            'scope_safety' => $scopeSafety,
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
        $image = AiValueNormalizer::trimmedScalarStringOrNull($options['docker_image'] ?? null)
            ?: AiValueNormalizer::trimmedScalarStringOrNull(config('atlas.engineering.docker.default_image'))
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
            'container_workdir' => AiValueNormalizer::trimmedScalarStringOrNull($options['docker_workdir'] ?? null)
                ?: AiValueNormalizer::trimmedScalarStringOrNull(config('atlas.engineering.docker.workdir'))
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
        $configured = AiValueNormalizer::trimmedScalarStringOrNull($options['docker_service'] ?? null)
            ?: AiValueNormalizer::trimmedScalarStringOrNull(config('atlas.engineering.docker.default_service'));
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
     * @return array{
     *     safe: bool,
     *     status: string,
     *     dirty_files: array<int,string>,
     *     untracked_files: array<int,string>,
     *     dirty_overlap: array<int,string>,
     *     untracked_overlap: array<int,string>,
     *     modified_overlap: array<int,string>,
     * }
     */
    private function scopeSafetyProfile(string $workspace, array $changedFiles): array
    {
        $changed = collect($changedFiles)
            ->filter(fn (mixed $file): bool => is_string($file) && trim($file) !== '')
            ->map(fn (string $file): string => trim($file))
            ->unique()
            ->values();

        $statusEntries = collect($workspace === '' ? [] : $this->dirtyFiles($workspace))
            ->map(fn (string $line): array => $this->statusEntry($line))
            ->filter(fn (array $entry): bool => $entry['file'] !== '');

        $allDirtyFiles = $statusEntries->pluck('file')->unique()->values();
        $untrackedFiles = $statusEntries
            ->filter(fn (array $entry): bool => $this->isUntrackedCode((string) $entry['code']))
            ->pluck('file')
            ->unique()
            ->values();

        if ($changed->isEmpty()) {
            return [
                'safe' => true,
                'status' => 'no_changed_files',
                'dirty_files' => $allDirtyFiles->all(),
                'untracked_files' => $untrackedFiles->all(),
                'dirty_overlap' => [],
                'untracked_overlap' => [],
                'modified_overlap' => [],
            ];
        }

        $dirtyOverlap = $allDirtyFiles->intersect($changed)->values();
        $untrackedOverlap = $untrackedFiles->intersect($changed)->values();
        $modifiedOverlap = $dirtyOverlap->diff($untrackedOverlap)->values();

        return [
            'safe' => $dirtyOverlap->isEmpty(),
            'status' => $dirtyOverlap->isEmpty() ? 'clean' : 'dirty_overlap',
            'dirty_files' => $allDirtyFiles->all(),
            'untracked_files' => $untrackedFiles->all(),
            'dirty_overlap' => $dirtyOverlap->all(),
            'untracked_overlap' => $untrackedOverlap->all(),
            'modified_overlap' => $modifiedOverlap->all(),
        ];
    }

    private function isUntrackedCode(string $code): bool
    {
        return str_contains($code, '?');
    }

    /**
     * @return array{code:string,file:string}
     */
    private function statusEntry(string $line): array
    {
        $line = rtrim($line);
        if (preg_match('/^(.{1,2})\s+(.+)$/', $line, $matches) === 1) {
            return [
                'code' => trim((string) $matches[1]),
                'file' => trim((string) $matches[2]),
            ];
        }

        return ['code' => '', 'file' => trim($line)];
    }

    private function repoRoot(string $workspace): ?string
    {
        $root = $this->run(['git', 'rev-parse', '--show-toplevel'], $workspace);

        return $root !== '' ? $root : null;
    }

    private function normalizedSandbox(mixed $value): string
    {
        $sandbox = AiValueNormalizer::trimmedScalarStringOrNull($value) ?: 'workspace';

        return in_array($sandbox, ['workspace', 'worktree', 'docker'], true) ? $sandbox : 'workspace';
    }

    private function worktreeLabel(string $label): string
    {
        $label = Str::slug($label);

        return $label !== '' ? $label : 'paired-worktree';
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

    /**
     * @return array{symlinks:array<int,string>,directories:array<int,string>,skipped:array<int,string>}
     *
     * Why: a fresh `git worktree add` only contains tracked files. Build artifacts
     * (vendor/, node_modules/, .env, bootstrap/cache, storage/*) are gitignored and
     * absent, so any deterministic test_command that requires Composer or Laravel
     * runtime fails immediately. Atlas Rivals baseline arm exec'd `php artisan test`
     * inside such a worktree and died with `vendor/autoload.php: No such file or directory`
     * in 78ms, blocking every paired baseline measurement. We symlink read-only build
     * artifacts from the original workspace and create writable Laravel skeleton dirs
     * so the worktree is runnable without a slow `composer install`.
     */
    private function bootstrapWorktreeArtifacts(string $worktreePath, string $originalWorkspace): array
    {
        $artifacts = [
            'symlinks' => [],
            'directories' => [],
            'skipped' => [],
        ];

        if ($originalWorkspace === '' || ! is_dir($originalWorkspace) || ! is_dir($worktreePath)) {
            $artifacts['skipped'][] = 'invalid_workspace_pair';

            return $artifacts;
        }

        $symlinkCandidates = ['vendor', 'node_modules', '.env', '.env.testing'];
        foreach ($symlinkCandidates as $artifact) {
            $source = $originalWorkspace.DIRECTORY_SEPARATOR.$artifact;
            $target = $worktreePath.DIRECTORY_SEPARATOR.$artifact;

            if (! file_exists($source) && ! is_link($source)) {
                $artifacts['skipped'][] = $artifact.':source_missing';

                continue;
            }

            // A provider workspace may itself be a disposable clone whose
            // ignored runtime artifacts are symlinks to the canonical checkout.
            // Resolve the directory before the isolated vendor copy: `cp -R`
            // otherwise preserves the source symlink on some hosts and leaves
            // the worktree without vendor/autoload.php.
            if ($artifact === 'vendor' && is_link($source)) {
                $resolved = realpath($source);
                if (is_string($resolved) && $resolved !== '') {
                    $source = $resolved;
                }
            }
            if (file_exists($target) || is_link($target)) {
                $artifacts['skipped'][] = $artifact.':already_present';

                continue;
            }

            // P6 (Obra #19): vendor is CLONED (APFS clonefile), never symlinked — an
            // isolated copy so `composer dump-autoload` inside the worktree can never
            // follow a link and rewrite the LIVE autoload (the wiper vector).
            if ($artifact === 'vendor') {
                if (AtlasCloneDir::copy($source, $target)) {
                    $artifacts['symlinks'][] = 'vendor:cloned';
                } else {
                    $artifacts['skipped'][] = 'vendor:clone_failed';
                }

                continue;
            }

            if (@symlink($source, $target)) {
                $artifacts['symlinks'][] = $artifact;
            } else {
                $artifacts['skipped'][] = $artifact.':symlink_failed';
            }
        }

        $writableDirs = [
            'bootstrap/cache',
            'storage/app',
            'storage/framework/cache/data',
            'storage/framework/sessions',
            'storage/framework/testing',
            'storage/framework/views',
            'storage/logs',
        ];
        foreach ($writableDirs as $dir) {
            $path = $worktreePath.DIRECTORY_SEPARATOR.$dir;
            if (is_dir($path)) {
                continue;
            }
            if (@mkdir($path, 0755, true)) {
                $artifacts['directories'][] = $dir;
            } else {
                $artifacts['skipped'][] = $dir.':mkdir_failed';
            }
        }

        return $artifacts;
    }
}
