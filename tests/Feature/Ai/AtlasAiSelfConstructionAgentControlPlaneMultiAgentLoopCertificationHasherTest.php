<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationHasher;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use Tests\TestCase;

/**
 * Locks the contract of AgentControlPlaneMultiAgentLoopCertificationHasher —
 * the JSON / serialization + canonical-hash helper concern extracted from
 * the god-class AgentControlPlaneMultiAgentLoopCertificationService.
 */
final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopCertificationHasherTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopCertificationHasher::class, $section);
    }

    public function test_all_4_hasher_methods_exist_on_section(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopCertificationHasher();

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => in_array($m->getName(), [
                'loadJson',
                'encodeJson',
                'normalizeForHash',
                'stableHash',
            ], true)
        );
        $this->assertCount(
            4,
            $publicMethods,
            'Section must expose all 4 hasher helpers as public methods'
        );
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        foreach ([
            'loadJson',
            'encodeJson',
            'normalizeForHash',
            'stableHash',
        ] as $method) {
            $this->assertTrue(
                method_exists($runtime, $method),
                "AgentControlPlaneMultiAgentLoopCertificationService::{$method} must exist as a delegator"
            );
        }
    }

    public function test_section_can_be_resolved_via_runtime_lazy_resolver(): void
    {
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        $ref = new \ReflectionMethod($runtime, 'hasher');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopCertificationHasher::class, $section);
    }

    public function test_stable_hash_is_byte_identical_across_two_runs(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopCertificationHasher();
        $payload = ['b' => 1, 'a' => 2, 'nested' => ['y' => 3, 'x' => 4]];

        $hash1 = $section->stableHash($payload);
        $hash2 = $section->stableHash($payload);

        $this->assertSame($hash1, $hash2, 'stableHash must be deterministic for identical input');
    }
}