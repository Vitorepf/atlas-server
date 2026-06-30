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

    // ── classify() ────────────────────────────────────────────────────────────

    private function predicates(): AgentControlPlaneMultiAgentLoopDigestPredicates
    {
        return new AgentControlPlaneMultiAgentLoopDigestPredicates;
    }

    public function test_proof_commands_alone_yield_delivered_value(): void
    {
        $result = $this->predicates()->classify(['proof_commands' => ['php artisan test']]);

        $this->assertTrue($result['delivered_value']);
        $this->assertSame([], $result['missing_evidence']);
        $this->assertContains('delivered_value', $result['digest_flags']);
    }

    public function test_outcome_events_alone_yield_delivered_value(): void
    {
        $result = $this->predicates()->classify(['outcome_events' => ['task_resolved']]);

        $this->assertTrue($result['delivered_value']);
    }

    public function test_commits_alone_without_proof_or_outcome_is_not_delivered_value(): void
    {
        $result = $this->predicates()->classify(['commits_count' => 3]);

        $this->assertFalse($result['delivered_value']);
        $this->assertContains('proof_commands_or_outcome_events', $result['missing_evidence']);
    }

    public function test_commits_without_proof_or_active_lease_is_vanity_activity(): void
    {
        $result = $this->predicates()->classify(['commits_count' => 2]);

        $this->assertTrue($result['vanity_activity']);
        $this->assertContains('vanity_activity', $result['digest_flags']);
    }

    public function test_active_lease_without_proof_is_productive_active_not_vanity(): void
    {
        $result = $this->predicates()->classify(['active_leases' => 1, 'commits_count' => 1]);

        $this->assertTrue($result['productive_active']);
        $this->assertFalse($result['vanity_activity']);
        $this->assertFalse($result['delivered_value']);
    }

    public function test_blocked_count_marks_blocked(): void
    {
        $result = $this->predicates()->classify(['blocked_count' => 2]);

        $this->assertTrue($result['blocked']);
        $this->assertContains('blocked', $result['digest_flags']);
    }

    public function test_no_active_leases_and_old_activity_is_stale(): void
    {
        $result = $this->predicates()->classify(['active_leases' => 0, 'last_activity_age_minutes' => 120]);

        $this->assertTrue($result['stale']);
    }

    public function test_active_leases_present_prevents_stale_even_with_old_timestamp(): void
    {
        $result = $this->predicates()->classify(['active_leases' => 1, 'last_activity_age_minutes' => 120]);

        $this->assertFalse($result['stale']);
    }

    public function test_recent_activity_with_no_lease_is_not_stale(): void
    {
        $result = $this->predicates()->classify(['active_leases' => 0, 'last_activity_age_minutes' => 5]);

        $this->assertFalse($result['stale']);
    }

    public function test_custom_stale_age_minutes_is_respected(): void
    {
        $result = $this->predicates()->classify(['active_leases' => 0, 'last_activity_age_minutes' => 20, 'stale_age_minutes' => 10]);

        $this->assertTrue($result['stale']);
    }

    public function test_clean_idle_record_has_no_flags_and_names_missing_evidence(): void
    {
        $result = $this->predicates()->classify([]);

        $this->assertSame([], $result['digest_flags']);
        $this->assertContains('proof_commands_or_outcome_events', $result['missing_evidence']);
    }

    public function test_proof_commands_present_blocks_vanity_activity_classification(): void
    {
        $result = $this->predicates()->classify(['proof_commands' => ['php artisan test'], 'commits_count' => 5]);

        $this->assertFalse($result['vanity_activity']);
        $this->assertTrue($result['delivered_value']);
    }

    public function test_record_can_carry_both_blocked_and_stale_flags(): void
    {
        $result = $this->predicates()->classify(['blocked_count' => 1, 'active_leases' => 0, 'last_activity_age_minutes' => 90]);

        $this->assertContains('blocked', $result['digest_flags']);
        $this->assertContains('stale', $result['digest_flags']);
    }
}