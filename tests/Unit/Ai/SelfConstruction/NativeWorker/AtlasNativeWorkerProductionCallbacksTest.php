<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionCallbacks;
use Tests\TestCase;

final class AtlasNativeWorkerProductionCallbacksTest extends TestCase
{
    public function test_production_callbacks_expose_claim_report_and_patch_callables(): void
    {
        $callbacks = app(AtlasNativeWorkerProductionCallbacks::class)->forClient('atlas-native-worker-test');

        $this->assertIsCallable($callbacks['claim_callback']);
        $this->assertIsCallable($callbacks['report_callback']);
        $this->assertIsCallable($callbacks['patch_materializer']);
    }

    public function test_runtime_daemon_resolve_callbacks_defaults_to_production_bindings(): void
    {
        if (app()->bound('atlas.self_construction.runtime_daemon.action_callbacks')) {
            app()->offsetUnset('atlas.self_construction.runtime_daemon.action_callbacks');
        }

        $command = app(\App\Console\Commands\AtlasSelfConstructionRuntimeDaemonCommand::class);
        $method = new \ReflectionMethod($command, 'resolveCallbacks');
        $method->setAccessible(true);
        /** @var array<string,callable> $callbacks */
        $callbacks = $method->invoke($command);

        $this->assertArrayHasKey('claim_callback', $callbacks);
        $this->assertArrayHasKey('report_callback', $callbacks);
        $this->assertArrayHasKey('patch_materializer', $callbacks);
        $this->assertIsCallable($callbacks['claim_callback']);
    }
}
