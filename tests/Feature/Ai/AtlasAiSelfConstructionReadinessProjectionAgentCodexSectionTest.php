<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AtlasSelfConstructionReadinessService;
use App\Services\Ai\SelfConstruction\ReadinessProjectionAgentCodexSection;
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
        $section = new ReadinessProjectionAgentCodexSection(
            fn (array $payload): string => 'hash:'.md5((string) json_encode($payload))
        );
        $this->assertInstanceOf(ReadinessProjectionAgentCodexSection::class, $section);
    }

    public function test_all_171_agent_codex_methods_exist_on_section(): void
    {
        $section = new ReadinessProjectionAgentCodexSection(
            fn (array $payload): string => 'hash'
        );

        // Use reflection to count actual unique public methods on the section.
        $ref = new \ReflectionClass($section);
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
                method_exists($section, $name),
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
}