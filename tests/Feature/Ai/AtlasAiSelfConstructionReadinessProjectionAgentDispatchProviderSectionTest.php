<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionAgentDispatchProviderSection;
use Tests\TestCase;

/**
 * Locks the contract of ReadinessProjectionAgentDispatchProviderSection —
 * the agentDispatch* (31) + agentProvider* (8) projection concern extracted
 * from the god-class AtlasSelfConstructionReadinessService.
 */
final class AtlasAiSelfConstructionReadinessProjectionAgentDispatchProviderSectionTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new ReadinessProjectionAgentDispatchProviderSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionAgentDispatchProviderSection::class, $section);
    }

    public function test_all_39_agent_dispatch_provider_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionAgentDispatchProviderSection(
            fn (array $payload): string => 'hash'
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => str_starts_with($m->getName(), 'agentDispatch')
                || str_starts_with($m->getName(), 'agentProvider')
        );
        $this->assertGreaterThanOrEqual(
            39,
            count($publicMethods),
            'Section must expose at least 39 agentDispatch* + agentProvider* public methods'
        );

        // Spot-check a few well-known methods from each family.
        foreach ([
            'agentDispatchPreflight',
            'agentDispatchReceiptTemplate',
            'agentProviderAdapterInvocationRuntimePolicy',
            'agentProviderProcessSupervisionPolicy',
        ] as $name) {
            $this->assertTrue(
                method_exists($section, $name),
                "ReadinessProjectionAgentDispatchProviderSection::{$name} must exist"
            );
        }
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AtlasSelfConstructionReadinessService::class);
        foreach ([
            'agentDispatchPreflight',
            'agentDispatchReceiptTemplate',
            'agentProviderAdapterInvocationRuntimePolicy',
            'agentProviderProcessSupervisionPolicy',
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
        $ref = new \ReflectionMethod($runtime, 'agentDispatchProviderSection');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(ReadinessProjectionAgentDispatchProviderSection::class, $section);
    }
}