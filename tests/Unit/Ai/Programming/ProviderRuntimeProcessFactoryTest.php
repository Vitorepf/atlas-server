<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\Programming;

use App\Services\Ai\Programming\ProviderRuntimeProcessFactory;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class ProviderRuntimeProcessFactoryTest extends TestCase
{
    public function test_factory_hook_can_return_process(): void
    {
        $seenEnv = null;
        $process = ProviderRuntimeProcessFactory::make(
            [PHP_BINARY, '-r', 'echo "ok";'],
            null,
            ['ATLAS_TEST_ENV' => 'yes'],
            5,
            function (array $argv, ?string $cwd, array $env, int $timeout) use (&$seenEnv): Process {
                $seenEnv = $env;

                return new Process($argv, $cwd, $env, null, $timeout);
            },
        );

        $process->run();

        $this->assertSame(['ATLAS_TEST_ENV' => 'yes'], $seenEnv);
        $this->assertSame('ok', $process->getOutput());
    }

    public function test_default_process_sets_timeout(): void
    {
        $process = ProviderRuntimeProcessFactory::make([PHP_BINARY, '-r', 'echo "ok";'], null, [], 7);

        $this->assertSame(7.0, $process->getTimeout());
    }
}
