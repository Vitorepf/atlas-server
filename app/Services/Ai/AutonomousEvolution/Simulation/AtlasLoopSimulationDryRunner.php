<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Simulation;

use Symfony\Component\Process\Process;

// Force autoload of the file that also declares SandboxHandle (PSR-4 maps one class per file).
\class_exists(AtlasLoopSimulationSandboxBuilder::class);

/**
 * Applies a proposed change INSIDE a previously built sandbox (never the live source tree)
 * and captures FACT outcomes only: patch-apply exit code, changed files w/ pre/post sha256,
 * php -l per touched PHP file, frozen-test exit code + stdout tail, started/finished UTC.
 *
 * NEVER commits, pushes, or writes outside SandboxHandle::sandboxPath. NEVER scores or judges.
 */
final class AtlasLoopSimulationDryRunner
{
    public const STDOUT_TAIL_BYTES = 4096;

    /**
     * @param  list<string>  $frozenTestPaths  paths relative to sandboxPath
     */
    public function run(SandboxHandle $handle, string $unifiedDiff, array $frozenTestPaths = []): DryRunReceipt
    {
        $startedAt = gmdate('Y-m-d\TH:i:s\Z');

        $preFingerprints = $this->fingerprintTouchedFiles($handle->sandboxPath, $unifiedDiff);

        [$applyExit, $applyOutput] = $this->applyPatch($handle->sandboxPath, $unifiedDiff);

        $postFingerprints = $this->fingerprintTouchedFiles($handle->sandboxPath, $unifiedDiff);

        $changed = [];
        foreach (array_keys($preFingerprints + $postFingerprints) as $rel) {
            $rel = (string) $rel;
            $pre = $preFingerprints[$rel] ?? null;
            $post = $postFingerprints[$rel] ?? null;
            $changed[] = [
                'path' => $rel,
                'sha256_pre' => $pre,
                'sha256_post' => $post,
            ];
        }
        usort($changed, static fn (array $a, array $b): int => strcmp($a['path'], $b['path']));

        $lintExitCodes = [];
        foreach ($changed as $entry) {
            if (! str_ends_with($entry['path'], '.php')) {
                continue;
            }
            $lintExitCodes[$entry['path']] = $this->phpLint($handle->sandboxPath.'/'.$entry['path']);
        }
        ksort($lintExitCodes, SORT_STRING);

        [$testExit, $testTail] = $this->runFrozenTests($handle->sandboxPath, $frozenTestPaths);

        return new DryRunReceipt(
            patchApplyExitCode: $applyExit,
            patchApplyOutputTail: $this->tail($applyOutput),
            changedFiles: $changed,
            phpLintExitCodePerFile: $lintExitCodes,
            frozenTestExitCode: $testExit,
            frozenTestStdoutTail: $testTail,
            runStartedAt: $startedAt,
            runFinishedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /**
     * @return array<string,string>
     */
    private function fingerprintTouchedFiles(string $sandboxPath, string $unifiedDiff): array
    {
        $map = [];
        foreach ($this->touchedPathsFromDiff($unifiedDiff) as $rel) {
            $abs = $sandboxPath.'/'.$rel;
            $map[$rel] = is_file($abs) ? hash_file('sha256', $abs) : '';
        }

        return $map;
    }

    /**
     * @return list<string>
     */
    private function touchedPathsFromDiff(string $diff): array
    {
        $paths = [];
        foreach (preg_split('/\r?\n/', $diff) ?: [] as $line) {
            if (preg_match('/^\+\+\+\s+(?:b\/)?(.+?)\s*$/', $line, $m)) {
                $rel = trim($m[1]);
                if ($rel !== '/dev/null') {
                    $paths[$rel] = true;
                }
            } elseif (preg_match('/^---\s+(?:a\/)?(.+?)\s*$/', $line, $m)) {
                $rel = trim($m[1]);
                if ($rel !== '/dev/null') {
                    $paths[$rel] = true;
                }
            }
        }

        return array_keys($paths);
    }

    /**
     * @return array{0:int,1:string}
     */
    private function applyPatch(string $sandboxPath, string $unifiedDiff): array
    {
        $patchFile = tempnam(sys_get_temp_dir(), 'atlas-dryrun-patch-');
        file_put_contents((string) $patchFile, $unifiedDiff);

        $proc = new Process(['git', 'apply', '--whitespace=nowarn', (string) $patchFile], $sandboxPath);
        $proc->run();
        @unlink((string) $patchFile);

        return [$proc->getExitCode() ?? 1, $proc->getOutput().$proc->getErrorOutput()];
    }

    private function phpLint(string $absolutePath): int
    {
        if (! is_file($absolutePath)) {
            return -1;
        }
        $proc = new Process(['php', '-l', $absolutePath]);
        $proc->run();

        return $proc->getExitCode() ?? 1;
    }

    /**
     * @param  list<string>  $frozenTestPaths
     * @return array{0:int,1:string}
     */
    private function runFrozenTests(string $sandboxPath, array $frozenTestPaths): array
    {
        if ($frozenTestPaths === []) {
            return [0, ''];
        }
        $cmd = array_merge(['php', 'vendor/bin/phpunit'], $frozenTestPaths);
        $proc = new Process($cmd, $sandboxPath);
        $proc->run();

        return [$proc->getExitCode() ?? 1, $this->tail($proc->getOutput().$proc->getErrorOutput())];
    }

    private function tail(string $s): string
    {
        if (strlen($s) <= self::STDOUT_TAIL_BYTES) {
            return $s;
        }

        return substr($s, -self::STDOUT_TAIL_BYTES);
    }
}

/**
 * FACT-only receipt of one dry-run; no scoring, no verdict, no provider response.
 */
final class DryRunReceipt
{
    /**
     * @param  list<array{path:string,sha256_pre:?string,sha256_post:?string}>  $changedFiles
     * @param  array<string,int>  $phpLintExitCodePerFile
     */
    public function __construct(
        public readonly int $patchApplyExitCode,
        public readonly string $patchApplyOutputTail,
        public readonly array $changedFiles,
        public readonly array $phpLintExitCodePerFile,
        public readonly int $frozenTestExitCode,
        public readonly string $frozenTestStdoutTail,
        public readonly string $runStartedAt,
        public readonly string $runFinishedAt,
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'patch_apply_exit_code' => $this->patchApplyExitCode,
            'patch_apply_output_tail' => $this->patchApplyOutputTail,
            'changed_files' => $this->changedFiles,
            'php_lint_exit_code_per_file' => $this->phpLintExitCodePerFile,
            'frozen_test_exit_code' => $this->frozenTestExitCode,
            'frozen_test_stdout_tail' => $this->frozenTestStdoutTail,
            'run_started_at' => $this->runStartedAt,
            'run_finished_at' => $this->runFinishedAt,
        ];
    }
}
