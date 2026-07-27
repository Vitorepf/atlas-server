<?php

declare(strict_types=1);

namespace App\Services\Ai\SelfConstruction;

use App\Support\AtlasPhpBinary;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Throwable;

/**
 * EVI-03 — isolated artisan boot smoke via subprocess (`php artisan list --raw`).
 * The current PHP process already booted and cannot see a newly broken Command class.
 */
class AtlasArtisanBootSmokeGate
{
    public function __construct(private readonly float $timeout = 60.0) {}

    /**
     * Absolute smoke against the live working tree (operator pregate path).
     *
     * @return array{ok:bool, exit_code:int, stderr_tail:string, skipped?:string}
     */
    public function smokeLiveTree(string $repoRoot): array
    {
        return $this->smoke($repoRoot);
    }

    /**
     * Differential smoke for scoped Autônomos landings: HEAD baseline vs HEAD + allowed_files overlay.
     *
     * @param  list<string>  $allowedFiles
     * @return array{
     *   baseline:array{ok:bool,exit_code:int,stderr_tail:string,skipped?:string},
     *   snapshot:array{ok:bool,exit_code:int,stderr_tail:string,skipped?:string},
     *   introduced_failure:bool,
     *   baseline_already_broken:bool,
     *   warning:?string
     * }
     */
    public function differentialForScopedCommit(string $repoRoot, array $allowedFiles): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $worktree = sys_get_temp_dir().'/atlas-boot-wt-'.bin2hex(random_bytes(6));

        try {
            if (! $this->git($repoRoot, ['worktree', 'add', '--detach', $worktree, 'HEAD'])) {
                return $this->degradedDifferential('worktree_add_failed');
            }

            // A fresh worktree carries no vendor/ — it is gitignored — so `php artisan
            // list --raw` fatals on the missing autoloader and the BASELINE always
            // failed. That made introduced_failure = $baselineOk && ! $snapshotOk
            // permanently false: this gate could never catch a landing that breaks
            // boot, which is the only thing it exists to catch. Measured on this repo:
            // exit 255 without vendor, exit 0 with it linked.
            //
            // A symlink, not a copy: the smoke only reads the tree, and copying ~500MB
            // per landing would cost more than the check is worth.
            $vendor = $repoRoot.'/vendor';
            if (is_dir($vendor) && ! file_exists($worktree.'/vendor')) {
                @symlink($vendor, $worktree.'/vendor');
            }

            $baseline = $this->smoke($worktree);

            foreach ($this->normalizeFiles($allowedFiles) as $rel) {
                $src = $repoRoot.'/'.$rel;
                $dst = $worktree.'/'.$rel;
                if (is_file($src)) {
                    @mkdir(dirname($dst), 0775, true);
                    copy($src, $dst);
                } elseif (is_file($dst)) {
                    @unlink($dst);
                }
            }

            $snapshot = $this->smoke($worktree);
            $baselineOk = (bool) ($baseline['ok'] ?? false);
            $snapshotOk = (bool) ($snapshot['ok'] ?? false);

            return [
                'baseline' => $baseline,
                'snapshot' => $snapshot,
                'introduced_failure' => $baselineOk && ! $snapshotOk,
                'baseline_already_broken' => ! $baselineOk,
                'warning' => ! $baselineOk ? 'baseline_boot_already_broken' : null,
            ];
        } finally {
            $this->git($repoRoot, ['worktree', 'remove', '--force', $worktree]);
            if (is_dir($worktree)) {
                File::deleteDirectory($worktree);
            }
        }
    }

    /**
     * @return array{ok:bool, exit_code:int, stderr_tail:string, skipped?:string}
     */
    public function smoke(string $repoRoot): array
    {
        $repoRoot = rtrim($repoRoot, '/');
        $artisan = $repoRoot.'/artisan';
        if (! is_file($artisan)) {
            return ['ok' => true, 'exit_code' => 0, 'stderr_tail' => '', 'skipped' => 'no_artisan'];
        }

        try {
            $process = new Process(
                [AtlasPhpBinary::path(), $artisan, 'list', '--raw'],
                $repoRoot,
                ['CI' => '1', 'APP_ENV' => 'testing'],
                null,
                $this->timeout,
            );
            $process->run();
            $stderr = trim($process->getErrorOutput());
            $stdout = trim($process->getOutput());

            return [
                'ok' => $process->isSuccessful(),
                'exit_code' => (int) $process->getExitCode(),
                'stderr_tail' => mb_substr($stderr !== '' ? $stderr : $stdout, -400),
            ];
        } catch (Throwable $e) {
            return [
                'ok' => false,
                'exit_code' => 1,
                'stderr_tail' => mb_substr($e->getMessage(), 0, 400),
            ];
        }
    }

    /**
     * @return array{
     *   baseline:array{ok:bool,exit_code:int,stderr_tail:string},
     *   snapshot:array{ok:bool,exit_code:int,stderr_tail:string},
     *   introduced_failure:bool,
     *   baseline_already_broken:bool,
     *   warning:?string
     * }
     */
    private function degradedDifferential(string $reason): array
    {
        $fail = ['ok' => false, 'exit_code' => 1, 'stderr_tail' => $reason];

        return [
            'baseline' => $fail,
            'snapshot' => $fail,
            'introduced_failure' => false,
            'baseline_already_broken' => false,
            'warning' => 'boot_smoke_degraded:'.$reason,
        ];
    }

    /**
     * @param  list<string>  $args
     */
    private function git(string $repoRoot, array $args): bool
    {
        try {
            $process = new Process(array_merge(['git'], $args), $repoRoot);
            $process->setTimeout(30.0);
            $process->run();

            return $process->isSuccessful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param  list<string>  $files
     * @return list<string>
     */
    private function normalizeFiles(array $files): array
    {
        $out = [];
        foreach ($files as $file) {
            $path = ltrim(trim(str_replace('\\', '/', (string) $file)), '/');
            if ($path !== '' && ! str_contains($path, '..')) {
                $out[$path] = true;
            }
        }

        return array_keys($out);
    }
}
