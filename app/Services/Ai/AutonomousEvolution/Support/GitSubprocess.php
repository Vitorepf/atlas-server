<?php

declare(strict_types=1);

namespace App\Services\Ai\AutonomousEvolution\Support;

use Symfony\Component\Process\Process;

final class GitSubprocess
{
    /** @param list<string> $argv */
    public static function run(?string $cwd, array $argv, float $timeout = 60.0): Process
    {
        $process = new Process(array_merge(['git'], array_values($argv)), $cwd, null, null, $timeout);
        $process->run();

        return $process;
    }
}
