<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use Symfony\Component\Process\Process;

final class ProviderRuntimeProcessFactory
{
    /**
     * @param  array<int,string>  $argv
     * @param  array<string,string>  $env
     */
    public static function make(array $argv, ?string $cwd, array $env, int $timeout, ?callable $factory = null): Process
    {
        if ($factory !== null) {
            $product = call_user_func($factory, $argv, $cwd, $env, $timeout);
            if ($product instanceof Process) {
                return $product;
            }
        }

        $process = new Process($argv, $cwd, $env, null, (float) $timeout);
        $process->setTimeout((float) $timeout);

        return $process;
    }
}
