<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Symfony\Component\Process\Process;

/**
 * Atlas Rivals Test Worktree Provisioner v1.
 *
 * Provisions and resets two isolated git worktrees (Atlas + Baseline) under a
 * controlled root (default: storage/app/rivals-worktrees) so a Rivals battery
 * can run on stable, deterministic checkouts without polluting the operator's
 * main workspace. Refuses to operate while the source repo is dirty, while
 * the operator is sitting inside one of the target worktrees, or when a path
 * collision exists with a non-worktree directory.
 *
 * Hard safety rules:
 *  - Never deletes the source repo. `realpath` of every removal target is
 *    compared against the source repo before any destructive call.
 *  - Every git invocation is wrapped in a Symfony Process with a 30s timeout
 *    and stderr is captured for the structured failure payload.
 *  - sha256(source_repo) is the stable namespace for the default worktree
 *    paths so two distinct repos never collide on the same default root.
 *
 * Schema namespace: atlas.programming.rivals_test_worktree_provisioner.v1
 */
class AtlasRivalsTestWorktreeProvisioner
{
    public const SCHEMA_VERSION = 'atlas.programming.rivals_test_worktree_provisioner.v1';

    /** Timeout (seconds) for every git subprocess. */
    public const GIT_TIMEOUT_SECONDS = 30;

    public function __construct(
        private readonly WorkspaceHygieneService $workspaceHygiene,
    ) {}

    /**
     * Provision the Atlas + Baseline worktrees. Idempotent: if a worktree
     * already exists at the resolved path, validates that it is a real git
     * worktree of the source repo and reuses it instead of failing.
     *
     * @param  array{root?:string|null, atlas_path?:string|null, baseline_path?:string|null, source_repo?:string|null}  $input
     * @return array<string,mixed>
     */
    public function provision(array $input): array
    {
        $sourceRepo = $this->resolveSourceRepo($input['source_repo'] ?? null);
        $root = $this->resolveRoot($input['root'] ?? null);

        $namespace = $this->stableNamespace($sourceRepo);
        $atlasPath = $this->resolveTargetPath(
            $input['atlas_path'] ?? null,
            $root.DIRECTORY_SEPARATOR.'atlas-'.$namespace,
        );
        $baselinePath = $this->resolveTargetPath(
            $input['baseline_path'] ?? null,
            $root.DIRECTORY_SEPARATOR.'baseline-'.$namespace,
        );

        $rootEnsure = $this->ensureDirectory($root);
        if ($rootEnsure !== null) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_root_unavailable',
                'root' => $root,
                'error' => $rootEnsure,
            ];
        }

        $sourceCheck = $this->checkSourceRepoUsable($sourceRepo, $atlasPath, $baselinePath);
        if ($sourceCheck !== null) {
            return $sourceCheck;
        }

        $atlasAdd = $this->ensureWorktree($sourceRepo, $atlasPath);
        if ($atlasAdd['status'] !== 'ok') {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => $atlasAdd['blocked_status'],
                'worktree_role' => 'atlas',
                'path' => $atlasPath,
                'source_repo' => $sourceRepo,
                'hint' => $atlasAdd['hint'],
                'stderr' => $atlasAdd['stderr'],
            ];
        }

        $baselineAdd = $this->ensureWorktree($sourceRepo, $baselinePath);
        if ($baselineAdd['status'] !== 'ok') {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => $baselineAdd['blocked_status'],
                'worktree_role' => 'baseline',
                'path' => $baselinePath,
                'source_repo' => $sourceRepo,
                'hint' => $baselineAdd['hint'],
                'stderr' => $baselineAdd['stderr'],
            ];
        }

        $atlasHash = $this->revParseHead($atlasPath);
        $baselineHash = $this->revParseHead($baselinePath);

        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => 'worktrees_ready',
            'atlas_path' => $atlasPath,
            'baseline_path' => $baselinePath,
            'atlas_hash' => $atlasHash,
            'baseline_hash' => $baselineHash,
            'source_repo' => $sourceRepo,
            'root' => $root,
            'created' => $atlasAdd['created'] || $baselineAdd['created'],
            'atlas_created' => $atlasAdd['created'],
            'baseline_created' => $baselineAdd['created'],
        ];
    }

    /**
     * Remove both worktrees and re-provision from scratch. Requires a human
     * `reason` string so operator intent is captured in the Rivals evidence
     * trail. Idempotent: missing worktrees do not raise; they just trigger a
     * clean provision.
     *
     * @param  array{root?:string|null, atlas_path?:string|null, baseline_path?:string|null, source_repo?:string|null, reason?:string|null}  $input
     * @return array<string,mixed>
     */
    public function reset(array $input): array
    {
        $reason = is_string($input['reason'] ?? null) ? trim((string) $input['reason']) : '';
        if ($reason === '') {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_missing_reason',
                'hint' => 'reset() requires a non-empty reason describing why the worktrees are being recreated.',
            ];
        }

        $sourceRepo = $this->resolveSourceRepo($input['source_repo'] ?? null);
        $root = $this->resolveRoot($input['root'] ?? null);
        $namespace = $this->stableNamespace($sourceRepo);
        $atlasPath = $this->resolveTargetPath(
            $input['atlas_path'] ?? null,
            $root.DIRECTORY_SEPARATOR.'atlas-'.$namespace,
        );
        $baselinePath = $this->resolveTargetPath(
            $input['baseline_path'] ?? null,
            $root.DIRECTORY_SEPARATOR.'baseline-'.$namespace,
        );

        $sourceCheck = $this->checkSourceRepoUsable($sourceRepo, $atlasPath, $baselinePath);
        if ($sourceCheck !== null) {
            return $sourceCheck;
        }

        $atlasRemove = $this->removeWorktree($sourceRepo, $atlasPath);
        if ($atlasRemove['status'] === 'refused_would_delete_source') {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_refuses_to_delete_source',
                'worktree_role' => 'atlas',
                'path' => $atlasPath,
                'source_repo' => $sourceRepo,
            ];
        }

        $baselineRemove = $this->removeWorktree($sourceRepo, $baselinePath);
        if ($baselineRemove['status'] === 'refused_would_delete_source') {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_refuses_to_delete_source',
                'worktree_role' => 'baseline',
                'path' => $baselinePath,
                'source_repo' => $sourceRepo,
            ];
        }

        $provisioned = $this->provision([
            'root' => $root,
            'atlas_path' => $atlasPath,
            'baseline_path' => $baselinePath,
            'source_repo' => $sourceRepo,
        ]);

        if (($provisioned['status'] ?? null) !== 'worktrees_ready') {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_reset_reprovision_failed',
                'reason' => $reason,
                'reprovision' => $provisioned,
            ];
        }

        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => 'worktrees_reset_and_ready',
            'reason' => $reason,
            'reset_at' => gmdate('Y-m-d\TH:i:s\Z'),
            'atlas_path' => $atlasPath,
            'baseline_path' => $baselinePath,
            'atlas_hash' => $provisioned['atlas_hash'] ?? null,
            'baseline_hash' => $provisioned['baseline_hash'] ?? null,
            'source_repo' => $sourceRepo,
            'root' => $root,
            'atlas_remove_status' => $atlasRemove['status'],
            'baseline_remove_status' => $baselineRemove['status'],
        ];
    }

    /**
     * Read-only inspection: do the worktrees exist, are they clean, and what
     * HEAD do they point at? Used by callers that need a snapshot of the
     * Rivals harness state without mutating anything.
     *
     * @param  array{root?:string|null, atlas_path?:string|null, baseline_path?:string|null, source_repo?:string|null}  $input
     * @return array<string,mixed>
     */
    public function inspect(array $input): array
    {
        $sourceRepo = $this->resolveSourceRepo($input['source_repo'] ?? null);
        $root = $this->resolveRoot($input['root'] ?? null);
        $namespace = $this->stableNamespace($sourceRepo);
        $atlasPath = $this->resolveTargetPath(
            $input['atlas_path'] ?? null,
            $root.DIRECTORY_SEPARATOR.'atlas-'.$namespace,
        );
        $baselinePath = $this->resolveTargetPath(
            $input['baseline_path'] ?? null,
            $root.DIRECTORY_SEPARATOR.'baseline-'.$namespace,
        );

        $atlas = $this->inspectOne($atlasPath);
        $baseline = $this->inspectOne($baselinePath);

        return [
            'schema' => self::SCHEMA_VERSION,
            'status' => 'inspected',
            'source_repo' => $sourceRepo,
            'root' => $root,
            'atlas_path' => $atlasPath,
            'atlas_exists' => $atlas['exists'],
            'atlas_clean' => $atlas['clean'],
            'atlas_hash' => $atlas['hash'],
            'atlas_branch' => $atlas['branch'],
            'baseline_path' => $baselinePath,
            'baseline_exists' => $baseline['exists'],
            'baseline_clean' => $baseline['clean'],
            'baseline_hash' => $baseline['hash'],
            'baseline_branch' => $baseline['branch'],
        ];
    }

    // ------------------------------------------------------------------
    // Resolvers
    // ------------------------------------------------------------------

    private function resolveSourceRepo(mixed $candidate): string
    {
        if (is_string($candidate) && trim($candidate) !== '') {
            $real = realpath($candidate);

            return is_string($real) && $real !== '' ? $real : $candidate;
        }

        $real = realpath(base_path());

        return is_string($real) && $real !== '' ? $real : base_path();
    }

    private function resolveRoot(mixed $candidate): string
    {
        if (is_string($candidate) && trim($candidate) !== '') {
            return rtrim($candidate, DIRECTORY_SEPARATOR);
        }

        return rtrim(storage_path('app/rivals-worktrees'), DIRECTORY_SEPARATOR);
    }

    private function resolveTargetPath(mixed $candidate, string $fallback): string
    {
        if (is_string($candidate) && trim($candidate) !== '') {
            return rtrim($candidate, DIRECTORY_SEPARATOR);
        }

        return rtrim($fallback, DIRECTORY_SEPARATOR);
    }

    private function stableNamespace(string $sourceRepo): string
    {
        return substr(hash('sha256', $sourceRepo), 0, 8);
    }

    private function ensureDirectory(string $path): ?string
    {
        if (is_dir($path)) {
            return null;
        }

        if (! @mkdir($path, 0775, true) && ! is_dir($path)) {
            return sprintf('failed_to_create_directory: %s', $path);
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Safety gates
    // ------------------------------------------------------------------

    /**
     * @return array<string,mixed>|null
     */
    private function checkSourceRepoUsable(string $sourceRepo, string $atlasPath, string $baselinePath): ?array
    {
        if (! is_dir($sourceRepo)) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_source_repo_missing',
                'source_repo' => $sourceRepo,
            ];
        }

        $realSource = realpath($sourceRepo) ?: $sourceRepo;
        $realAtlas = realpath($atlasPath);
        $realBaseline = realpath($baselinePath);

        // Operator is sitting inside one of the target worktrees — bail.
        if (
            ($realAtlas !== false && $realSource === $realAtlas)
            || ($realBaseline !== false && $realSource === $realBaseline)
        ) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_operator_in_target_path',
                'source_repo' => $realSource,
                'atlas_path' => $atlasPath,
                'baseline_path' => $baselinePath,
                'hint' => 'cd back to your main repo checkout before provisioning rivals worktrees.',
            ];
        }

        $snapshot = $this->workspaceHygiene->snapshot($realSource);
        if (! ($snapshot['is_git'] ?? false)) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_source_not_git',
                'source_repo' => $realSource,
            ];
        }

        if (! ($snapshot['clean'] ?? false)) {
            return [
                'schema' => self::SCHEMA_VERSION,
                'status' => 'blocked_source_dirty',
                'source_repo' => $realSource,
                'dirty_count' => $snapshot['dirty_count'] ?? 0,
                'dirty_files_sample' => $snapshot['dirty_files_sample'] ?? [],
                'dirty_files_truncated' => $snapshot['dirty_files_truncated'] ?? false,
                'hint' => 'commit or stash the source repo before provisioning rivals worktrees.',
            ];
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Git worktree primitives
    // ------------------------------------------------------------------

    /**
     * @return array{status:string, created:bool, blocked_status?:string, hint?:string, stderr?:string}
     */
    private function ensureWorktree(string $sourceRepo, string $path): array
    {
        // If the path already looks like a git worktree of the source repo,
        // accept it as-is (idempotent provisioning).
        if (is_dir($path)) {
            $inside = $this->runGit($path, ['git', 'rev-parse', '--is-inside-work-tree']);
            if ($inside['ok'] && trim($inside['stdout']) === 'true') {
                $commonDir = $this->runGit($path, ['git', 'rev-parse', '--git-common-dir']);
                $sourceCommonDir = $this->runGit($sourceRepo, ['git', 'rev-parse', '--git-common-dir']);
                $a = $commonDir['ok'] ? realpath(trim($commonDir['stdout'])) : false;
                $b = $sourceCommonDir['ok'] ? realpath(trim($sourceCommonDir['stdout'])) : false;
                if ($a !== false && $b !== false && $a === $b) {
                    return ['status' => 'ok', 'created' => false];
                }

                return [
                    'status' => 'blocked',
                    'created' => false,
                    'blocked_status' => 'blocked_path_collision',
                    'hint' => 'directory exists and is a git repo, but not a worktree of the configured source_repo.',
                    'stderr' => '',
                ];
            }

            // Directory exists but is not a git worktree — refuse to overwrite.
            if ($this->directoryHasEntries($path)) {
                return [
                    'status' => 'blocked',
                    'created' => false,
                    'blocked_status' => 'blocked_path_collision',
                    'hint' => 'target path exists and is not a git worktree; move or remove it before provisioning.',
                    'stderr' => '',
                ];
            }
        }

        $add = $this->runGit($sourceRepo, ['git', 'worktree', 'add', $path]);
        if (! $add['ok']) {
            return [
                'status' => 'blocked',
                'created' => false,
                'blocked_status' => 'blocked_path_collision',
                'hint' => 'git worktree add failed; inspect stderr for the underlying cause.',
                'stderr' => $add['stderr'],
            ];
        }

        return ['status' => 'ok', 'created' => true];
    }

    /**
     * @return array{status:string, stderr?:string}
     */
    private function removeWorktree(string $sourceRepo, string $path): array
    {
        if (! is_dir($path)) {
            return ['status' => 'absent'];
        }

        $realPath = realpath($path);
        $realSource = realpath($sourceRepo);
        if ($realPath === false || $realSource === false) {
            return ['status' => 'absent'];
        }

        if ($realPath === $realSource) {
            return ['status' => 'refused_would_delete_source'];
        }

        $remove = $this->runGit($sourceRepo, ['git', 'worktree', 'remove', '--force', $path]);
        if ($remove['ok']) {
            return ['status' => 'removed'];
        }

        // git refused — but the path may have been a stale, untracked dir.
        // We still won't touch it on disk: surface the error to the caller.
        return ['status' => 'remove_failed', 'stderr' => $remove['stderr']];
    }

    private function revParseHead(string $path): ?string
    {
        $result = $this->runGit($path, ['git', 'rev-parse', 'HEAD']);
        if (! $result['ok']) {
            return null;
        }

        $sha = trim($result['stdout']);

        return $sha === '' ? null : $sha;
    }

    /**
     * @return array{exists:bool, clean:bool, hash:?string, branch:?string}
     */
    private function inspectOne(string $path): array
    {
        if (! is_dir($path)) {
            return ['exists' => false, 'clean' => false, 'hash' => null, 'branch' => null];
        }

        $snapshot = $this->workspaceHygiene->snapshot($path);
        $branch = null;
        $branchResult = $this->runGit($path, ['git', 'rev-parse', '--abbrev-ref', 'HEAD']);
        if ($branchResult['ok']) {
            $branch = trim($branchResult['stdout']);
            if ($branch === '') {
                $branch = null;
            }
        }

        return [
            'exists' => (bool) ($snapshot['is_git'] ?? false),
            'clean' => (bool) ($snapshot['clean'] ?? false),
            'hash' => $snapshot['head_sha'] ?? null,
            'branch' => $branch,
        ];
    }

    // ------------------------------------------------------------------
    // Filesystem helpers
    // ------------------------------------------------------------------

    private function directoryHasEntries(string $path): bool
    {
        $entries = @scandir($path);
        if ($entries === false) {
            return false;
        }

        foreach ($entries as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $cmd
     * @return array{ok:bool, stdout:string, stderr:string, exit_code:int}
     */
    private function runGit(string $cwd, array $cmd): array
    {
        $process = new Process($cmd, $cwd);
        $process->setTimeout((float) self::GIT_TIMEOUT_SECONDS);
        $process->run();

        return [
            'ok' => $process->isSuccessful(),
            'stdout' => (string) $process->getOutput(),
            'stderr' => (string) $process->getErrorOutput(),
            'exit_code' => (int) $process->getExitCode(),
        ];
    }
}
