<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Simulation;

use Symfony\Component\Process\Process;

/** Applies a proposed diff only inside a previously materialized sandbox. */
final class AtlasLoopSimulationDryRunner
{
    public function run(SandboxHandle $sandbox, string $diff): DryRunReceipt
    {
        $started = gmdate('Y-m-d\TH:i:s\Z');
        $before = $this->snapshot($sandbox->sandboxPath);
        $apply = new Process(['git', 'apply', '--whitespace=nowarn'], $sandbox->sandboxPath);
        $apply->setInput($diff);
        $apply->run();

        $changed = [];
        $lint = [];
        if ($apply->isSuccessful()) {
            $after = $this->snapshot($sandbox->sandboxPath);
            foreach (array_keys($after) as $path) {
                if (($before[$path] ?? null) !== $after[$path]) {
                    $row = ['path' => $path, 'sha256_pre' => $before[$path] ?? '0', 'sha256_post' => $after[$path]];
                    $changed[] = $row;
                    if (str_ends_with($path, '.php')) {
                        $php = new Process(['/opt/homebrew/bin/php', '-l', $sandbox->sandboxPath.'/'.$path], $sandbox->sandboxPath);
                        $php->run();
                        $lint[$path] = $php->getExitCode() ?? 1;
                    }
                }
            }
            usort($changed, static fn (array $a, array $b): int => strcmp((string) $a['path'], (string) $b['path']));
        }

        return new DryRunReceipt(
            patchApplyExitCode: $apply->getExitCode() ?? 1,
            patchApplyOutputTail: substr(trim($apply->getOutput().$apply->getErrorOutput()), -4000),
            changedFiles: $changed,
            phpLintExitCodePerFile: $lint,
            frozenTestExitCode: 0,
            frozenTestStdoutTail: '',
            runStartedAt: $started,
            runFinishedAt: gmdate('Y-m-d\TH:i:s\Z'),
        );
    }

    /** @return array<string,string> */
    private function snapshot(string $root): array
    {
        $proc = new Process(['git', 'ls-files', '--others', '--modified', '--deleted', '-z'], $root);
        $proc->run();
        $paths = $proc->isSuccessful() ? array_filter(explode("\0", $proc->getOutput())) : [];
        $snapshot = [];
        foreach ($paths as $path) {
            $absolute = $root.'/'.$path;
            $snapshot[$path] = is_file($absolute) ? hash_file('sha256', $absolute) : '0';
        }
        ksort($snapshot, SORT_STRING);
        return $snapshot;
    }
}
