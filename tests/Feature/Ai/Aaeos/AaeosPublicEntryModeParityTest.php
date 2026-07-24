<?php

declare(strict_types=1);

namespace Tests\Feature\Ai\Aaeos;

use App\Services\Ai\Aaeos\Control\AaeosExecutorMode;
use App\Services\Ai\AutonomousEvolution\AtlasLoopMasterSwitch;
use App\Services\Ai\SelfConstruction\AtlasTaskServingSwitch;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemon;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Tests\TestCase;

final class AaeosPublicEntryModeParityTest extends TestCase
{
    private string $switchesPath;

    private string $phpShimPath;

    protected function setUp(): void
    {
        parent::setUp();

        $suffix = bin2hex(random_bytes(4));
        $this->switchesPath = storage_path("framework/testing/p1a-public-switches-{$suffix}.env");
        $this->phpShimPath = storage_path("framework/testing/p1a-php-shim-{$suffix}");
        file_put_contents(
            $this->switchesPath,
            "ATLAS_TASK_SERVING_ENABLED=false\nATLAS_AUTONOMOS_MASTER_ENABLED=false\n",
        );
        file_put_contents($this->phpShimPath, <<<'SH'
#!/bin/sh
if [ "${1:-}" = "-r" ]; then
  exit 0
fi
printf '%s\n' "$@"
SH);
        chmod($this->phpShimPath, 0700);
        AtlasTaskServingSwitch::$envPathOverride = $this->switchesPath;
        AtlasLoopMasterSwitch::$envPathOverride = $this->switchesPath;
    }

    protected function tearDown(): void
    {
        AtlasTaskServingSwitch::$envPathOverride = null;
        AtlasLoopMasterSwitch::$envPathOverride = null;
        @unlink($this->switchesPath);
        @unlink($this->phpShimPath);

        parent::tearDown();
    }

    public function test_public_dev_forge_and_autonomos_aliases_resolve_to_native_surfaces(): void
    {
        $expectations = [
            'dev' => 'atlas:cli:dev',
            'forge' => 'atlas:cli:dev',
            'autonomos' => 'atlas:self-construction:runtime-daemon',
        ];

        foreach ($expectations as $alias => $signature) {
            $process = new Process([base_path('bin/atlas'), $alias, '--help'], base_path());
            $process->setTimeout(30);
            $process->run();

            self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
            self::assertStringContainsString($signature, $process->getOutput());
        }
    }

    public function test_public_aaeos_command_refuses_raw_dev_and_forge_intents_without_native_authority_objects(): void
    {
        foreach ([AaeosExecutorMode::DEV, AaeosExecutorMode::FORGE] as $mode) {
            $exit = Artisan::call('atlas:aaeos:run', [
                'intent' => 'public '.$mode.' native commissioning parity',
                '--mode' => $mode,
                '--live' => true,
                '--json' => true,
            ]);
            $receipt = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

            self::assertSame(1, $exit);
            self::assertSame('dispatch_failed', $receipt['status']);
            self::assertSame(0, $receipt['dispatch']['live']['provider_calls']);
            self::assertFalse($receipt['dispatch']['live']['mutation_performed']);
            self::assertSame(
                $mode === AaeosExecutorMode::DEV ? 'confirmed_dev_run_required' : 'forge_commissioning_required',
                $receipt['dispatch']['live']['effects'][0]['reason'],
            );
        }
    }

    public function test_forge_flag_overrides_the_efficient_default_and_reaches_the_forge_profile(): void
    {
        $process = new Process([
            base_path('bin/atlas'),
            'forge',
            'public forge profile parity',
            '--efficient',
            '--plan-only',
        ], base_path());
        $process->setEnv(['ATLAS_PHP_BIN' => $this->phpShimPath]);
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        self::assertSame([
            'artisan',
            'atlas:cli:dev',
            '--forge',
            'public forge profile parity',
            '--efficient',
            '--plan-only',
            '--workspace='.base_path(),
        ], preg_split('/\R/', trim($process->getOutput())) ?: []);

        $command = (string) file_get_contents(base_path('app/Console/Commands/AtlasCliDevCommand.php'));
        self::assertStringContainsString('$useEfficient = ! $forgeRequested', $command);
        self::assertStringContainsString('$programmingProfile = $forgeRequested ? \'forge\' : \'dev\';', $command);
    }

    public function test_deleted_aaeos_adapter_classes_do_not_resolve_in_a_cold_process(): void
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
$classes = [
    'App\\Services\\Ai\\Aaeos\\Control\\Adapters\\AaeosExecutorModeAdapter',
    'App\\Services\\Ai\\Aaeos\\Control\\Adapters\\AutonomosModeAdapter',
    'App\\Services\\Ai\\Aaeos\\Control\\Adapters\\DevModeAdapter',
    'App\\Services\\Ai\\Aaeos\\Control\\Adapters\\ForgeModeAdapter',
];
foreach ($classes as $class) {
    if (class_exists($class)) {
        exit(1);
    }
}
PHP;
        $process = new Process(['/opt/homebrew/bin/php', '-r', $script], base_path());
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
    }

    public function test_public_autonomos_alias_runs_daemon_claim_directly_and_reaches_safe_native_refusal(): void
    {
        $process = new Process([
            base_path('bin/atlas'),
            'autonomos',
            'public autonomos live parity',
        ], base_path());
        $process->setEnv(['ATLAS_PHP_BIN' => $this->phpShimPath]);
        $process->setTimeout(30);
        $process->run();

        self::assertSame(0, $process->getExitCode(), $process->getErrorOutput());
        $forwarded = preg_split('/\R/', trim($process->getOutput())) ?: [];
        self::assertSame([
            'artisan',
            'atlas:self-construction:runtime-daemon',
            'claim',
            '--json',
            'public autonomos live parity',
            '--workspace='.base_path(),
        ], $forwarded);

        $nativeWorker = new class implements AtlasNativeWorkerProductionRuntime
        {
            public int $claims = 0;

            public int $forbiddenEffects = 0;

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return [
                    'status' => 'disabled',
                    'reason' => 'task_serving_switch_off',
                    'task' => null,
                ];
            }

            public function report(string $clientId, array $outcome): array
            {
                $this->forbiddenEffects++;

                return [];
            }

            public function materialize(array $patchPlan): array
            {
                $this->forbiddenEffects++;

                return [];
            }
        };
        $this->app->instance(
            AtlasSelfConstructionRuntimeDaemon::class,
            new AtlasSelfConstructionRuntimeDaemon(nativeWorker: $nativeWorker),
        );
        $exit = Artisan::call('atlas:self-construction:runtime-daemon', [
            'action' => 'claim',
            'intent' => 'public autonomos live parity',
            '--workspace' => base_path(),
            '--json' => true,
        ]);
        $claim = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame(1, $exit);
        self::assertSame('claim', $claim['action']);
        self::assertSame('disabled', $claim['status']);
        self::assertSame('task_serving_switch_off', $claim['reason']);
        self::assertSame('public autonomos live parity', $claim['intent']);
        self::assertNull($claim['native_journey_ref']);
        self::assertSame([], $claim['native_cycle_refs']);
        self::assertSame([], $claim['native_task_refs']);
        self::assertSame([], $claim['native_lease_refs']);
        self::assertSame(0, $claim['provider_calls']);
        self::assertFalse($claim['task']['worker_executed']);
        self::assertFalse($claim['mutation_performed']);
        self::assertSame(1, $nativeWorker->claims);
        self::assertSame(0, $nativeWorker->forbiddenEffects);
        self::assertStringNotContainsString('atlas:aaeos:run', $process->getOutput());
    }
}
