<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\RuntimeDaemon;

use App\Services\Ai\EngineeringKernel\ProviderPort;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionRuntime;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerRecoverableProductionRuntime;
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

    public function test_quality_foundry_native_tick_requires_execution_order_before_provider_invocation(): void
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
            ->execute([
                'kind' => 'native_tick', 'provider' => 'test', 'model' => 'model',
                'quality_foundry_required' => true,
            ], []);

        self::assertSame('quality_foundry_execution_order_missing', $result['reason']);
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
        $calls = 0;
        $provider = new class($calls) implements ProviderPort
        {
            public function __construct(private int &$calls) {}

            public function invoke(array $request): array
            {
                $this->calls++;

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
        $runtime = new class(bin2hex(random_bytes(4))) implements AtlasNativeWorkerProductionRuntime
        {
            public int $reports = 0;

            public function __construct(private readonly string $suffix) {}

            public function claim(string $clientId): ?array
            {
                return ['task_packet_id' => 'task-'.$this->suffix, 'lease_id' => 'lease-'.$this->suffix, 'allowed_files' => ['app/Generated.php']];
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
        $replayed = (new AtlasSelfConstructionNativeActionExecutor(production: $runtime, provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'test-model'], []);

        self::assertSame('held', $result['status']);
        self::assertSame('governed_release_and_canary_pending', $result['reason']);
        self::assertSame(0, $runtime->reports);
        self::assertTrue($result['sandbox_applied']);
        self::assertSame(1, $calls);
        self::assertTrue($replayed['replayed']);
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

    public function test_restart_resumes_and_renews_existing_daemon_lease_before_claiming_new_work(): void
    {
        $runtime = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $claims = 0;

            public int $renewals = 0;

            public function resume(string $clientId): ?array
            {
                return ['task_packet_id' => 'task-resume', 'lease_id' => 'lease-resume', 'allowed_files' => ['app/X.php']];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                $this->renewals++;

                return $clientId === 'atlas-self-construction-runtime-daemon' && $taskPacketId === 'task-resume' && $leaseId === 'lease-resume';
            }

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return null;
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
        $provider = new class implements ProviderPort
        {
            public function invoke(array $request): array
            {
                return ['status' => 'ok', 'patch_plan' => ['allowed_files' => ['app/X.php'], 'patches' => [[
                    'path' => 'app/X.php', 'mode' => 'create', 'next' => 'resumed',
                ]]]];
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $runtime, provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'model'], []);

        self::assertSame('governed_release_and_canary_pending', $result['reason']);
        self::assertSame(0, $runtime->claims);
        self::assertSame(1, $runtime->renewals);
    }

    public function test_restart_does_not_execute_or_claim_when_existing_lease_cannot_be_renewed(): void
    {
        $providerCalls = 0;
        $runtime = new class implements AtlasNativeWorkerRecoverableProductionRuntime
        {
            public int $claims = 0;

            public function resume(string $clientId): ?array
            {
                return ['task_packet_id' => 'task-old', 'lease_id' => 'lease-old'];
            }

            public function renew(string $clientId, string $taskPacketId, string $leaseId): bool
            {
                return false;
            }

            public function claim(string $clientId): ?array
            {
                $this->claims++;

                return null;
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
        $provider = new class($providerCalls) implements ProviderPort
        {
            public function __construct(private int &$calls) {}

            public function invoke(array $request): array
            {
                $this->calls++;

                return ['status' => 'ok'];
            }
        };

        $result = (new AtlasSelfConstructionNativeActionExecutor(production: $runtime, provider: $provider))
            ->execute(['kind' => 'native_tick', 'provider' => 'test', 'model' => 'model'], []);

        self::assertSame('lease_recovery_failed', $result['reason']);
        self::assertSame(0, $runtime->claims);
        self::assertSame(0, $providerCalls);
    }
}
