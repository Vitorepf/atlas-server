<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\NativeWorker;

use App\Console\Commands\AtlasSelfConstructionRuntimeDaemonCommand;
use App\Services\Ai\SelfConstruction\NativeWorker\AtlasNativeWorkerProductionCallbacks;
use App\Services\Ai\SelfConstruction\RuntimeDaemon\AtlasSelfConstructionRuntimeDaemonCycle;
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

    public function test_runtime_daemon_builds_typed_productive_cycle_without_callback_resolution(): void
    {
        if (app()->bound('atlas.self_construction.runtime_daemon.action_callbacks')) {
            app()->offsetUnset('atlas.self_construction.runtime_daemon.action_callbacks');
        }

        $command = app(AtlasSelfConstructionRuntimeDaemonCommand::class);
        $this->assertFalse(method_exists($command, 'resolveCallbacks'));
        $method = new \ReflectionMethod($command, 'productiveCycle');
        $method->setAccessible(true);
        $cycle = $method->invoke($command);

        $this->assertInstanceOf(
            AtlasSelfConstructionRuntimeDaemonCycle::class,
            $cycle,
        );
    }
}
