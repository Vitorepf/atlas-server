<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopCertificationService;
use App\Services\Ai\SelfConstruction\AgentControlPlaneMultiAgentLoopDigestPredicates;
use Tests\TestCase;

/**
 * Locks the contract of AgentControlPlaneMultiAgentLoopDigestPredicates —
 * the terminal-loop digest-presence predicate concern extracted from the
 * god-class AgentControlPlaneMultiAgentLoopCertificationService.
 */
final class AtlasAiSelfConstructionAgentControlPlaneMultiAgentLoopDigestPredicatesTest extends TestCase
{
    public function test_section_class_is_resolvable(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopDigestPredicates();
        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopDigestPredicates::class, $section);
    }

    public function test_all_8_digest_predicate_methods_exist_on_section(): void
    {
        $section = new AgentControlPlaneMultiAgentLoopDigestPredicates();

        $ref = new \ReflectionClass($section);
        $publicMethods = array_filter(
            $ref->getMethods(\ReflectionMethod::IS_PUBLIC),
            fn (\ReflectionMethod $m): bool => in_array($m->getName(), [
                'terminalLoopFleetLaunchPlanPresent',
                'terminalLoopFleetReplenishmentPlanPresent',
                'terminalLoopFleetResumeRollupPresent',
                'terminalLoopFleetEvidenceRollupPresent',
                'terminalLoopFleetOperatorHandoffPresent',
                'terminalLoopFleetLaneIsolationPresent',
                'terminalLoopCycleSupervisorPresent',
                'terminalLoopFleetLaunchRunbookPresent',
            ], true)
        );
        $this->assertCount(
            8,
            $publicMethods,
            'Section must expose all 8 digest-presence predicates as public methods'
        );
    }

    public function test_runtime_service_delegates_to_section(): void
    {
        $runtime = app(AgentControlPlaneMultiAgentLoopCertificationService::class);
        foreach ([
            'terminalLoopFleetLaunchPlanPresent',
            'terminalLoopFleetReplenishmentPlanPresent',
            'terminalLoopFleetResumeRollupPresent',
            'terminalLoopFleetEvidenceRollupPresent',
            'terminalLoopFleetOperatorHandoffPresent',
            'terminalLoopFleetLaneIsolationPresent',
            'terminalLoopCycleSupervisorPresent',
            'terminalLoopFleetLaunchRunbookPresent',
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
        $ref = new \ReflectionMethod($runtime, 'digestPredicates');
        $ref->setAccessible(true);
        $section = $ref->invoke($runtime);

        $this->assertInstanceOf(AgentControlPlaneMultiAgentLoopDigestPredicates::class, $section);
    }
}