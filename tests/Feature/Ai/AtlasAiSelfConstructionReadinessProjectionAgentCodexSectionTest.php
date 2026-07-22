<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\Readiness\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\Readiness\ReadinessProjectionAgentCodexSection;
use App\Services\Ai\SelfConstruction\Support\ReadinessHash;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionAgentCodexSection — the
 * agentCodex* projection concern extracted from the god-class
 * AtlasSelfConstructionReadinessService.
 *
 * The runtime service delegates 171 methods to this collaborator; this test
 * pins the wiring so a future refactor cannot silently break the delegation
 * contract.
 */
final class AtlasAiSelfConstructionReadinessProjectionAgentCodexSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $this->assertTrue(class_exists(ReadinessProjectionAgentCodexSection::class));

        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $this->assertTrue(method_exists($runtime, 'agentCodexProviderExecutionContractTemplate'));
    }

    public function test_hashes_use_readiness_hash_stable_convention(): void
    {
        $payload = ['key' => 'value', 'z' => 1, 'a' => 2];
        $expected = ReadinessHash::stable($payload);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $expected);
        $this->assertSame($expected, ReadinessHash::stable($payload), 'ReadinessHash::stable must be deterministic');
    }

    public function test_all_171_agent_codex_methods_exist_on_section(): void
    {
        // Use reflection to count actual unique public methods on the section.
        $ref = new \ReflectionClass(ReadinessProjectionAgentCodexSection::class);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'agentCodex')
        );
        $this->assertGreaterThanOrEqual(
            171,
            count($publicMethods),
            'Section must expose at least 171 agentCodex* public methods'
        );

        // Verify at least the first few well-known methods exist on the class.
        foreach ([
            'agentCodexProviderExecutionContractTemplate',
            'agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket',
        ] as $name) {
            $this->assertTrue(
                method_exists(ReadinessProjectionAgentCodexSection::class, $name),
                "ReadinessProjectionAgentCodexSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        // Spot-check a few key delegators exist on the runtime.
        foreach ([
            'agentCodexProviderExecutionContractTemplate',
            'agentCodexRealInvokerPostStartOperatorStartHandoffBuilderImplementationPacket',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AtlasSelfConstructionReadinessService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $ref = new \ReflectionMethod($runtime, 'agentCodexSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionAgentCodexSection::class, $section);
    }

    public function test_liveness_monitor_preflight_reaches_read_only_storage_readiness(): void
    {
        $payload = app(AtlasSelfConstructionReadinessService::class)
            ->agentCodexRealInvokerPostStartLivenessMonitorPreflight();

        $this->assertContains($payload['status'], [
            'codex_real_invoker_post_start_liveness_monitor_ready',
            'blocked',
        ]);
        $this->assertSame(
            'read_only_agent_codex_real_invoker_post_start_liveness_monitor_preflight',
            $payload['mode']
        );
        $this->assertArrayHasKey(
            'codex_real_invoker_post_start_liveness_monitor_preflight',
            $payload
        );
        $this->assertArrayHasKey(
            'codex_real_invoker_post_start_liveness_monitor_preflight_hash',
            $payload
        );

        $preflight = $payload['codex_real_invoker_post_start_liveness_monitor_preflight'];
        $this->assertIsArray($preflight);
        $this->assertArrayHasKey('storage', $preflight);
        $this->assertArrayHasKey('agent_runs_table_ready', $preflight['storage']);
        $this->assertArrayHasKey('ledger_table_ready', $preflight['storage']);
    }

    public function test_provider_execution_contract_template_reaches_provider_adapter_preflights(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        $registryPreflight = $runtime->agentProviderAdapterRegistryPreflight();
        $executionGuardPreflight = $runtime->agentProviderAdapterExecutionGuardPreflight();
        $payload = $runtime
            ->agentCodexProviderExecutionContractTemplate();

        $this->assertSame(
            'atlas.self_construction_agent_codex_provider_execution_contract_template.v1',
            $payload['schema_version']
        );
        $this->assertSame('read_only_agent_codex_provider_execution_contract_template', $payload['mode']);
        $this->assertSame('codex_provider_execution_contract_template_ready', $payload['status']);
        $this->assertFalse($payload['execution_allowed']);

        $template = $payload['codex_provider_execution_contract_template'];
        $this->assertIsArray($template);
        $this->assertSame(
            $registryPreflight['provider_adapter_registry_preflight_hash'],
            $template['source_provider_adapter_registry_preflight_hash']
        );
        $this->assertSame(
            $executionGuardPreflight['provider_adapter_execution_guard_preflight_hash'],
            $template['source_provider_adapter_execution_guard_preflight_hash']
        );
    }
}
