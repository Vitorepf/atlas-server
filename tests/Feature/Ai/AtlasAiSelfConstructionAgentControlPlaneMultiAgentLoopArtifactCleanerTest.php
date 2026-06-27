<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopArtifactCleaner;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use Tests\TestCase;

/**
 * Locks the contract of AgentControlPlaneMultiAgentLoopArtifactCleaner —
 * the run-scoped artifact cleanup + post-cleanup health probe concern
 * extracted from the god-class
 * AgentControlPlaneMultiAgentLoopCertificationService.
 */
final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopArtifactCleanerTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopArtifactCleaner(
            queue: null,
            leases: null,
        );
        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopArtifactCleaner::class, $section);
    }

    public function test_all_3_cleaner_methods_exist_on_section(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopArtifactCleaner(
            queue: null,
            leases: null,
        );

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => in_array($m->getName(), [
                'cleanupCertificationArtifacts',
                'postCleanupHealthDigestProbe',
                'flattenStrings',
            ], true)
        );
        $this->assertCount(
            3,
            $publicMethods,
            'Section must expose all 3 cleaner helpers as public methods'
        );
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        foreach ([
            'cleanupCertificationArtifacts',
            'postCleanupHealthDigestProbe',
            'flattenStrings',
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
        $ref = new \ReflectionMethod($runtime, 'artifactCleaner');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopArtifactCleaner::class, $section);
    }
}