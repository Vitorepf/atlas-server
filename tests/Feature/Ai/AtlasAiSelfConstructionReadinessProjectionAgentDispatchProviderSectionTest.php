<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentDispatchProviderSection;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Tests\TestCase;

final class AtlasAiSelfConstructionReadinessProjectionAgentDispatchProviderSectionTest extends TestCase
{
    public function test_dispatch_provider_policy_resolves_typed_capability_owners_and_remains_fail_closed(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $section = (new ReadinessProjectionAgentDispatchProviderSection(
            static fn (array $payload): string => ReadinessHash::stable($payload),
        ))->setMother($runtime);

        $direct = $section->agentProviderAdapterInvocationRuntimePolicy();
        $viaFacade = $runtime->agentProviderAdapterInvocationRuntimePolicy();
        $policy = (array) data_get($direct, 'agent_provider_adapter_invocation_runtime_policy', []);
        $releaseAuthorizationStatus = $section->agentDispatchExecutorReleaseAuthorizationPersistenceStatus();

        $capabilityPreflights = [
            'provider_adapter_registry_missing' => data_get(
                $section->agentProviderAdapterRegistryPreflight(),
                'provider_adapter_registry_preflight.blocking_reasons',
                [],
            ),
            'receipt_use_writer_missing' => data_get(
                $section->agentDispatchExecutorReceiptUseWriterPreflight(),
                'dispatch_executor_receipt_use_writer_preflight.blocking_reasons',
                [],
            ),
            'sandbox_binding_writer_missing' => data_get(
                $section->agentDispatchExecutorSandboxBindingPreflight(),
                'dispatch_executor_sandbox_binding_preflight.blocking_reasons',
                [],
            ),
            'provider_start_driver_missing' => data_get(
                $section->agentDispatchExecutorProviderStartDriverPreflight(),
                'dispatch_executor_provider_start_driver_preflight.blocking_reasons',
                [],
            ),
            'adapter_invocation_boundary_missing' => data_get(
                $section->agentDispatchExecutorAdapterInvocationBoundaryPreflight(),
                'dispatch_executor_adapter_invocation_boundary_preflight.blocking_reasons',
                [],
            ),
            'provider_adapter_execution_guard_missing' => data_get(
                $section->agentProviderAdapterExecutionGuardPreflight(),
                'provider_adapter_execution_guard_preflight.blocking_reasons',
                [],
            ),
        ];

        $this->assertSame($direct, $viaFacade);
        $this->assertSame('blocked', $policy['status']);
        $this->assertTrue($policy['component_readiness']['provider_adapter_registry']);
        $this->assertNotContains('provider_adapter_registry_not_ready', $policy['blocking_reasons']);
        foreach ($capabilityPreflights as $missingCapability => $blockingReasons) {
            $this->assertNotContains($missingCapability, $blockingReasons);
        }
        $this->assertTrue(data_get(
            $releaseAuthorizationStatus,
            'dispatch_executor_release_authorization_persistence_status.storage.persistence_writer_ready',
        ));
        $this->assertFalse($direct['execution_allowed']);
        $this->assertFalse($direct['dispatch_allowed']);
    }
}
