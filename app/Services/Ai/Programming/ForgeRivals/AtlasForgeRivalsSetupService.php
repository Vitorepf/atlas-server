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
 *   /Users/vitorepf/develop/Atlas-rivals/arms/<run_id>-atlas/workspace
 *   /Users/vitorepf/develop/Atlas-rivals/arms/<run_id>-rival/workspace
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

    private const DEFAULT_MIN_FREE_BYTES_BEFORE_WORKTREE_ADD = 1073741824;

    private const DEFAULT_MIN_FREE_BYTES_BEFORE_MINIMAL_WORKTREE_ADD = 33554432;

    public function __construct(
        private readonly AtlasForgeRivalsRunPathResolver $paths,
        private readonly WorkspaceHygieneService $hygiene,
    ) {}

    /**
     * @param  array{run_id?:string,source_ref?:string,repo_root?:string,workspace?:string,checkout_strategy?:string}  $input
     * @return array<string,mixed>
     */
    public function provision(array $input): array
    {
        $repoRoot = (string) ($input['repo_root'] ?? $input['workspace'] ?? (function_exists('base_path') ? base_path() : getcwd()));
        $repoRoot = rtrim($repoRoot, '/');
        $checkoutStrategy = trim((string) ($input['checkout_strategy'] ?? 'full'));
        $minimalCheckout = $checkoutStrategy === 'minimal_no_checkout';
        $blockers = [];

        if (! is_dir($repoRoot.'/.git')) {
            return $this->withAdvisoryInvariants([
                'status' => 'blocked',
                'blockers' => ['source_repo_not_git:'.$repoRoot],
                'next_command' => 'cd into a git repository and re-run',
            ]);
        }

        // Tracked .pyc is a hard blocker for the FLOW (per canon).
        $bytecode = $this->hygiene->trackedPythonBytecode($repoRoot);
        if (($bytecode['tracked_count'] ?? 0) > 0) {
            return $this->withAdvisoryInvariants([
                'status' => 'blocked',
                'blockers' => ['tracked_python_bytecode_present:'.$bytecode['tracked_count']],
                'resolution_command' => $bytecode['resolution_command'] ?? '',
                'tracked_sample' => $bytecode['tracked_sample'] ?? [],
                'next_command' => 'untrack bytecode and re-run: php artisan atlas:forge:rivals setup --source-ref=HEAD --json',
            ]);
        }

        $sourceRef = trim((string) ($input['source_ref'] ?? self::DEFAULT_SOURCE_REF_HINT));
        if ($sourceRef === '') {
            $sourceRef = self::DEFAULT_SOURCE_REF_HINT;
        }
        $resolvedSha = $this->resolveRefSha($repoRoot, $sourceRef);
        if ($resolvedSha === null) {
            return $this->withAdvisoryInvariants([
                'status' => 'blocked',
                'blockers' => ['source_ref_resolve_failed:'.$sourceRef],
                'next_command' => 'pass a valid --source-ref=<sha|branch|HEAD>',
            ]);
        }

        $runId = (string) ($input['run_id'] ?? '');
        if ($runId === '') {
            $runId = $this->generateRunId();
        }

        $paths = $this->paths->paths($runId);
        @mkdir($paths['base'], 0o755, true);
        @mkdir($paths['evidence'], 0o755, true);
        @mkdir($paths['arms_root'], 0o755, true);

        $diskBlockers = $this->worktreeDiskBlockers($paths, $minimalCheckout);
        if ($diskBlockers !== []) {
            return $this->withAdvisoryInvariants([
                'status' => 'blocked',
                'blockers' => $diskBlockers,
                'run_id' => $paths['run_id'],
                'worktrees' => [],
                'next_command' => 'free disk space before running Provider Arena setup',
            ]);
        }

        $worktrees = [];
        foreach (['atlas', 'rival'] as $arm) {
            $target = $paths[$arm];
            @mkdir(dirname($target), 0o755, true);
            // If already present, treat as idempotent (operator may re-run setup)
            if (is_dir($target.'/.git') || is_file($target.'/.git')) {
                $minimalRuntime = $minimalCheckout
                    ? $this->checkoutMinimalRuntimeFiles($target, $resolvedSha)
                    : ['actions' => [], 'blockers' => []];
                foreach ($minimalRuntime['blockers'] as $blocker) {
                    $blockers[] = 'minimal_runtime_checkout_failed:'.$arm.':'.$blocker;
                }

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
                    'checkout_strategy' => $minimalCheckout ? 'minimal_no_checkout' : 'full',
                    'minimal_runtime' => $minimalRuntime,
                    'runtime' => $runtime,
                ];

                continue;
            }
            $branch = sprintf('forge-rivals/%s/%s', $runId, $arm);
            $command = $minimalCheckout
                ? ['git', '-C', $repoRoot, 'worktree', 'add', '--no-checkout', '-B', $branch, '--force', $target, $resolvedSha]
                : ['git', '-C', $repoRoot, 'worktree', 'add', '-B', $branch, '--force', $target, $resolvedSha];
            $proc = new Process($command);
            $proc->setTimeout(120);
            $proc->run();
            if (! $proc->isSuccessful()) {
                $blockers[] = 'git_worktree_add_failed:'.$arm.':'.trim((string) $proc->getErrorOutput());

                continue;
            }
            $minimalRuntime = $minimalCheckout
                ? $this->checkoutMinimalRuntimeFiles($target, $resolvedSha)
                : ['actions' => [], 'blockers' => []];
            foreach ($minimalRuntime['blockers'] as $blocker) {
                $blockers[] = 'minimal_runtime_checkout_failed:'.$arm.':'.$blocker;
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
                'checkout_strategy' => $minimalCheckout ? 'minimal_no_checkout' : 'full',
                'minimal_runtime' => $minimalRuntime,
                'runtime' => $runtime,
            ];
        }

        if ($blockers !== []) {
            return $this->withAdvisoryInvariants([
                'status' => 'blocked',
                'blockers' => $blockers,
                'run_id' => $paths['run_id'],
                'worktrees' => $worktrees,
                'next_command' => 'php artisan atlas:forge:rivals reset --run-id='.$paths['run_id'].' --reason=setup_failed --json',
            ]);
        }

        return $this->withAdvisoryInvariants([
            'status' => 'ok',
            'run_id' => $paths['run_id'],
            'source_ref' => $sourceRef,
            'resolved_sha' => $resolvedSha,
            'paths' => $paths,
            'worktrees' => $worktrees,
            'next_command' => 'php artisan atlas:forge:rivals preflight --mode=local_fake --atlas-model=claude_sonnet --rival=claude_sonnet --preset=smoke --json',
        ]);
    }

    /**
     * @param  array<string,mixed>  $payload
     * @return array<string,mixed>
     */
    private function withAdvisoryInvariants(array $payload): array
    {
        return $payload + [
            'external_provider_call' => false,
            'provider_tokens_spent' => false,
            'separated_from_external_rivals_certification' => true,
            'advisory_only' => true,
            'should_update_provider_topology' => false,
            'never_changes_atlas_decide_topology' => true,
            'owner_of_model_routing' => 'atlas_decide',
            'routing_effect' => 'none',
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

    /**
     * @param  array<string,string>  $paths
     * @return list<string>
     */
    private function worktreeDiskBlockers(array $paths, bool $minimalCheckout = false): array
    {
        $missingWorktrees = 0;
        foreach (['atlas', 'rival'] as $arm) {
            $target = (string) ($paths[$arm] ?? '');
            if ($target === '') {
                continue;
            }
            if (! is_dir($target.'/.git') && ! is_file($target.'/.git')) {
                $missingWorktrees++;
            }
        }
        if ($missingWorktrees === 0) {
            return [];
        }

        $root = (string) ($paths['arms_root'] ?? $paths['base'] ?? getcwd());
        $probePath = is_dir($root) ? $root : dirname($root);
        $free = @disk_free_space($probePath);
        if ($free === false) {
            return ['worktree_disk_space_probe_failed:'.$probePath];
        }

        $minimumKey = $minimalCheckout
            ? 'atlas_rivals.min_free_bytes_before_minimal_worktree_add'
            : 'atlas_rivals.min_free_bytes_before_worktree_add';
        $minimumEnv = $minimalCheckout
            ? 'ATLAS_FORGE_RIVALS_MIN_FREE_BYTES_BEFORE_MINIMAL_WORKTREE_ADD'
            : 'ATLAS_FORGE_RIVALS_MIN_FREE_BYTES_BEFORE_WORKTREE_ADD';
        $minimumDefault = $minimalCheckout
            ? self::DEFAULT_MIN_FREE_BYTES_BEFORE_MINIMAL_WORKTREE_ADD
            : self::DEFAULT_MIN_FREE_BYTES_BEFORE_WORKTREE_ADD;
        $minimum = (int) config($minimumKey, env($minimumEnv, $minimumDefault));
        $required = max(0, $minimum);
        if ($required === 0 || $free >= $required) {
            return [];
        }

        return [
            sprintf(
                'worktree_disk_space_insufficient:free_bytes=%d:required_bytes=%d:missing_worktrees=%d:path=%s',
                (int) $free,
                $required,
                $missingWorktrees,
                $probePath,
            ),
        ];
    }

    private function generateRunId(): string
    {
        return 'fr2-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6));
    }

    /**
     * Minimal industrial batteries do not need a full repository checkout, but
     * real-provider runtime isolation must be able to regenerate Composer
     * autoload files inside each arm. Materialize only the small root contract
     * files Composer requires.
     *
     * @return array{actions:list<string>,blockers:list<string>}
     */
    private function checkoutMinimalRuntimeFiles(string $target, string $resolvedSha): array
    {
        $files = ['composer.json', 'composer.lock'];
        $proc = new Process(['git', '-C', $target, 'checkout', $resolvedSha, '--', ...$files]);
        $proc->setTimeout(30);
        $proc->run();
        if (! $proc->isSuccessful()) {
            return [
                'actions' => ['minimal_runtime_checkout_attempted'],
                'blockers' => ['git_checkout_runtime_files_failed:'.trim((string) $proc->getErrorOutput())],
            ];
        }

        $blockers = [];
        foreach ($files as $file) {
            if (! is_file($target.'/'.$file)) {
                $blockers[] = 'minimal_runtime_file_missing:'.$file;
            }
        }

        return [
            'actions' => ['minimal_runtime_files_checked_out'],
            'blockers' => $blockers,
        ];
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
        $this->ensureRuntimeExcludes($target);
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

    private function ensureRuntimeExcludes(string $target): void
    {
        $excludePath = $target.'/.git/info/exclude';
        if (! is_file($excludePath) && (is_file($target.'/.git') || is_dir($target.'/.git'))) {
            try {
                $proc = new Process(['git', '-C', $target, 'rev-parse', '--git-path', 'info/exclude']);
                $proc->setTimeout(5);
                $proc->run();
                if ($proc->isSuccessful()) {
                    $resolved = trim((string) $proc->getOutput());
                    if ($resolved !== '') {
                        $excludePath = str_starts_with($resolved, '/') ? $resolved : $target.'/'.$resolved;
                    }
                }
            } catch (\Throwable) {
            }
        }
        if (! is_file($excludePath)) {
            return;
        }

        $existing = (string) @file_get_contents($excludePath);
        $entries = [
            'vendor/',
            '.env',
            '.env.testing',
            'storage/framework/',
        ];
        $append = [];
        foreach ($entries as $entry) {
            if (! str_contains($existing, $entry)) {
                $append[] = $entry;
            }
        }
        if ($append !== []) {
            @file_put_contents($excludePath, rtrim($existing, "\n")."\n".implode("\n", $append)."\n");
        }
    }
}
