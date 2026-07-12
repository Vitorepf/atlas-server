<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming\AtlasDev\Execution;

use App\Services\Ai\Programming\AtlasDev\Pipeline\PlanOnlyResult;
use Symfony\Component\Process\Process;

/**
 * Reads the workspace diff for read-only review projections.
 *
 * Surface adapters must not own workspace/process behavior. This component is
 * deliberately read-only and never applies patches or changes release state.
 */
final class AtlasDevWorkspaceDiffReader
{
    public function read(PlanOnlyResult $plan): string
    {
        $paths = [];
        foreach ($plan->miniSpec->expectedFiles as $file) {
            if (! is_string($file) || $file === '') {
                continue;
            }
            $workspace = rtrim($plan->envelope->workspace, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR;
            $real = realpath($file) ?: $file;
            $paths[] = str_starts_with($real, $workspace)
                ? substr($real, strlen($workspace))
                : $file;
        }

        $process = new Process(array_merge(['git', '-C', $plan->envelope->workspace, 'diff', '--'], $paths));
        $process->run();

        return $process->getOutput();
    }
}
