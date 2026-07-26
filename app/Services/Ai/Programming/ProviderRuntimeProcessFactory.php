<?php

declare(strict_types=1);

namespace App\Services\Ai\Programming;

use App\Services\Ai\Governance\ProviderGovernanceConsult;
use Symfony\Component\Process\Process;

final class ProviderRuntimeProcessFactory
{
    /**
     * @param  array<int,string>  $argv
     * @param  array<string,string>  $env
     */
    public static function make(array $argv, ?string $cwd, array $env, int $timeout, ?callable $factory = null): Process
    {
        // L3 governança — choke único da lane SDK (cursor/minimax/antigravity spawnam por
        // aqui sem passar pelo AiProviderManager). Advisory + fail-open: grava CONSULTED no
        // coverage ledger e nunca altera o Process construído.
        try {
            if (function_exists('app') && $argv !== []) {
                app(ProviderGovernanceConsult::class)->consultBeforeSpawn([
                    'provider' => basename((string) $argv[0]),
                    'surface' => 'provider_runtime_process_factory',
                    'kind' => 'atlas_programming',
                ]);
            }
        } catch (\Throwable) {
            // fail-open: governança indisponível nunca impede a construção do processo
        }

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
