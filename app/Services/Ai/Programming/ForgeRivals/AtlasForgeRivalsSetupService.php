<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\ForgeRivals;

use App\Services\Ai\Programming\WorkspaceHygieneService;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Atlas Forge Rivals · Setup.
 *
 * Provisions two isolated worktrees per run via `git worktree add`:
 *   /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/atlas
 *   /Users/vitorepf/develop/Atlas-rivals/runs/<run_id>/rival
 *
 * Does NOT depend on the source workspace being clean. The worktrees are
 * checkouts of a specific git ref (defaults to current HEAD commit); the
 * dirty index of the source repo is irrelevant to them.
 *
 * Refuses to touch any path outside the canonical runs root.
 */
final class AtlasForgeRivalsSetupService
{
    public const DEFAULT_SOURCE_REF_HINT = 'HEAD';

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly WorkspaceHygieneService $hygiene,
    ) {}

    /**
     * @param  array{run_id?:string,source_ref?:string,repo_root?:string,workspace?:string}  $input
     * @return array<string,mixed>
     */
    public function provision(array $input): array
    {
        $repoRoot = (string) ($input['repo_root'] ?? $input['workspace'] ?? (function_exists('base_path') ? base_path() : getcwd()));
        $repoRoot = rtrim($repoRoot, '/');
        $blockers = [];

        if (! is_dir($repoRoot.'/.git')) {
            return [
                'status' => 'blocked',
                'blockers' => ['source_repo_not_git:'.$repoRoot],
                'next_command' => 'cd into a git repository and re-run',
            ];
        }

        // Tracked .pyc is a hard blocker for the FLOW (per canon).
        $bytecode = $this->hygiene->trackedPythonBytecode($repoRoot);
        if (($bytecode['tracked_count'] ?? 0) > 0) {
            return [
                'status' => 'blocked',
                'blockers' => ['tracked_python_bytecode_present:'.$bytecode['tracked_count']],
                'resolution_command' => $bytecode['resolution_command'] ?? '',
                'tracked_sample' => $bytecode['tracked_sample'] ?? [],
                'next_command' => 'untrack bytecode and re-run: php artisan atlas:forge:rivals setup --source-ref=HEAD --json',
            ];
        }

        $sourceRef = trim((string) ($input['source_ref'] ?? self::DEFAULT_SOURCE_REF_HINT));
        if ($sourceRef === '') {
            $sourceRef = self::DEFAULT_SOURCE_REF_HINT;
        }
        $resolvedSha = $this->resolveRefSha($repoRoot, $sourceRef);
        if ($resolvedSha === null) {
            return [
                'status' => 'blocked',
                'blockers' => ['source_ref_resolve_failed:'.$sourceRef],
                'next_command' => 'pass a valid --source-ref=<sha|branch|HEAD>',
            ];
        }

        $runId = (string) ($input['run_id'] ?? '');
        if ($runId === '') {
            $runId = $this->generateRunId();
        }

        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);

        $worktrees = [];
        foreach (['atlas', 'rival'] as $arm) {
            $target = $paths[$arm];
            // If already present, treat as idempotent (operator may re-run setup)
            if (is_dir($target.'/.git') || is_file($target.'/.git')) {
                $runtime = $this->provisionRuntime($repoRoot, $target);
                foreach ($runtime['blockers'] as $blocker) {
                    $blockers[] = 'runtime_provision_failed:'.$arm.':'.$blocker;
                }

                $worktrees[$arm] = [
                    'path' => $target,
                    'ref' => $sourceRef,
                    'resolved_sha' => $resolvedSha,
                    'created' => false,
                    'reused' => true,
                    'runtime' => $runtime,
                ];

                continue;
            }
            $branch = sprintf('forge-rivals/%s/%s', $runId, $arm);
            $proc = new Process(
                ['git', '-C', $repoRoot, 'worktree', 'add', '-B', $branch, '--force', $target, $resolvedSha]
            );
            $proc->setTimeout(120);
            $proc->run();
            if (! $proc->isSuccessful()) {
                $blockers[] = 'git_worktree_add_failed:'.$arm.':'.trim((string) $proc->getErrorOutput());

                continue;
            }
            $runtime = $this->provisionRuntime($repoRoot, $target);
            foreach ($runtime['blockers'] as $blocker) {
                $blockers[] = 'runtime_provision_failed:'.$arm.':'.$blocker;
            }
            $worktrees[$arm] = [
                'path' => $target,
                'ref' => $sourceRef,
                'resolved_sha' => $resolvedSha,
                'branch' => $branch,
                'created' => true,
                'reused' => false,
                'runtime' => $runtime,
            ];
        }

        if ($blockers !== []) {
            return [
                'status' => 'blocked',
                'blockers' => $blockers,
                'run_id' => $paths['run_id'],
                'worktrees' => $worktrees,
                'next_command' => 'php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=setup_failed --json',
            ];
        }

        return [
            'status' => 'ok',
            'run_id' => $paths['run_id'],
            'source_ref' => $sourceRef,
            'resolved_sha' => $resolvedSha,
            'paths' => $paths,
            'worktrees' => $worktrees,
            'next_command' => 'php artisan atlas:forge:rivals preflight --mode=local_fake --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json',
        ];
    }

    private function resolveRefSha(string $repoRoot, string $ref): ?string
    {
        $proc = new Process(['git', '-C', $repoRoot, 'rev-parse', '--verify', $ref]);
        $proc->setTimeout(15);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return null;
        }

        return trim((string) $proc->getOutput()) ?: null;
    }

    private function generateRunId(): string
    {
        return 'fr2-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
    }

    /**
     * Worktrees are intentionally isolated, but Laravel cannot boot from a
     * plain checkout without the local runtime files that are gitignored in the
     * source repo. We link/copy only deterministic operator-local runtime
     * dependencies; source files stay isolated per arm.
     *
     * @return array{
     *   vendor_ready:bool,
     *   env_ready:bool,
     *   actions:list<string>,
     *   blockers:list<string>,
     *   vendor_source:string,
     *   vendor_target:string
     * }
     */
    private function provisionRuntime(string $repoRoot, string $target): array
    {
        $actions = [];
        $blockers = [];

        $vendorSource = $repoRoot.'/vendor';
        $vendorTarget = $target.'/vendor';
        if (! is_file($vendorSource.'/autoload.php')) {
            $blockers[] = 'source_vendor_autoload_missing';
        } else {
            if (! file_exists($vendorTarget) && ! is_link($vendorTarget)) {
                if (@symlink($vendorSource, $vendorTarget)) {
                    $actions[] = 'vendor_symlinked';
                } else {
                    $blockers[] = 'vendor_symlink_failed';
                }
            }
            if (! is_file($vendorTarget.'/autoload.php')) {
                $blockers[] = 'vendor_autoload_missing_after_provision';
            }
        }

        $envReady = false;
        foreach (['.env', '.env.testing'] as $envFile) {
            $source = $repoRoot.'/'.$envFile;
            $dest = $target.'/'.$envFile;
            if (! is_file($source)) {
                continue;
            }
            if (! file_exists($dest)) {
                if (@copy($source, $dest)) {
                    $actions[] = $envFile.'_copied';
                } else {
                    $blockers[] = $envFile.'_copy_failed';
                }
            }
            if (is_file($dest)) {
                $envReady = true;
            }
        }

        return [
            'vendor_ready' => is_file($vendorTarget.'/autoload.php'),
            'env_ready' => $envReady,
            'actions' => $actions,
            'blockers' => $blockers,
            'vendor_source' => $vendorSource,
            'vendor_target' => $vendorTarget,
        ];
    }
}
