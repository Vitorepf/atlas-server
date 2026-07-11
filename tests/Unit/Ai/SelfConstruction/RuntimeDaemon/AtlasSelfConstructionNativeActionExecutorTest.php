<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionNativeActionExecutor;
use PHPUnit\Framework\TestCase;

final class AtlasSelfConstructionNativeActionExecutorTest extends TestCase
{
    public function test_missing_provider_or_model_route_fails_closed_before_invocation(): void
    {
        $calls = 0;
        $provider = new class($calls) implements ProviderPort
        {
            public function __construct(private int &$calls) {}

            public function invoke(array $request): array
            {
                $this->calls++;

                return ['status' => 'ok'];
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $this->runtime(), provider: $provider))
            ->execute(['kind' => 'native_tick'], []);

        self::assertSame('provider_route_missing', $result['reason']);
        self::assertSame(0, $calls);
    }

    private function runtime(): AtlasNativeWorkerProductionRuntime
    {
        return new class implements AtlasNativeWorkerProductionRuntime
        {
            public function claim(string $clientId): ?array
            {
                return ['task_packet_id' => 'task-x', 'lease_id' => 'lease-x', 'allowed_files' => ['app/X.php']];
            }

            public function report(string $clientId, array $outcome): array
            {
                return [];
            }

            public function materialize(array $patchPlan): array
            {
                return [];
            }
        };
    }

    public function test_provider_success_applies_in_sandbox_and_keeps_lease_open_without_report(): void
    {
        $provider = new class implements ProviderPort
        {
            public function invoke(array $request): array
            {
                return [
                    'status' => 'ok',
                    'patch_plan' => ['allowed_files' => ['app/Generated.php'], 'patches' => [[
                        'path' => 'app/Generated.php', 'mode' => 'create', 'next' => '<?php return true;',
                    ]]],
                    'command_plan' => [[
                        'name' => 'sandbox-gate', 'argv' => [PHP_BINARY, '-r', 'exit(0);'], 'timeout_seconds' => 5,
                    ]],
                ];
            }
        };
        $runtime = new class implements AtlasNativeWorkerProductionRuntime
        {
            public int $reports = 0;

            public function claim(string $clientId): ?array
            {
                return ['task_packet_id' => 'task-1', 'lease_id' => 'lease-1', 'allowed_files' => ['app/Generated.php']];
            }

            public function report(string $clientId, array $outcome): array
            {
                $this->reports++;

                return [];
            }

            public function materialize(array $patchPlan): array
            {
                return [];
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $runtime, provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'test-model'], []);

        self::assertSame('held', $result['status']);
        self::assertSame('governed_release_and_canary_pending', $result['reason']);
        self::assertSame(0, $runtime->reports);
        self::assertTrue($result['sandbox_applied']);
    }

    public function test_provider_timeout_is_held_retryable(): void
    {
        $provider = new class implements ProviderPort
        {
            public function invoke(array $request): array
            {
                throw new \RuntimeException('provider timeout');
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $this->runtime(), provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'test-model'], ['state_hash' => 'state']);

        self::assertSame('held', $result['status']);
        self::assertSame('provider_timeout', $result['reason']);
        self::assertTrue($result['retryable']);
    }

    public function test_provider_down_is_held_retryable(): void
    {
        $provider = new class implements ProviderPort
        {
            public function invoke(array $request): array
            {
                throw new \RuntimeException('connection refused');
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $this->runtime(), provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'test-model'], []);

        self::assertSame('provider_down', $result['reason']);
        self::assertTrue($result['retryable']);
    }

    public function test_provider_fallback_exhaustion_is_held_without_native_apply(): void
    {
        $provider = new class implements ProviderPort
        {
            public function invoke(array $request): array
            {
                return ['status' => 'unavailable', 'exhausted' => true];
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $this->runtime(), provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'test-model'], []);

        self::assertSame('provider_fallback_exhausted', $result['reason']);
        self::assertTrue($result['retryable']);
        self::assertArrayNotHasKey('native_worker_cycle_hash', $result);
    }
}
