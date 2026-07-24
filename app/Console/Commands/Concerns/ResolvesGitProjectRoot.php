<?php

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Support\AtlasSecurity;
use Symfony\Component\Process\Process;

/**
 * Shared git toplevel resolver for atlas CLI workspace commands.
 *
 * Full-pass reuse: byte-identical projectRootFor() copies across AtlasCli* commands.
 */
trait ResolvesGitProjectRoot
{
    private function projectRootFor(string $workspace): ?string
    {
        try {
            $process = new Process(['git', 'rev-parse', '--show-toplevel'], $workspace, AtlasSecurity::processEnv(profile: 'tool'));
            $process->setTimeout(3);
            $process->run();
        } catch (\Throwable) {
            return null;
        }

        if (! $process->isSuccessful()) {
            return null;
        }

        $root = trim(AtlasSecurity::redactString($process->getOutput()));

        return $root !== '' && is_dir($root) ? $root : null;
    }
}
