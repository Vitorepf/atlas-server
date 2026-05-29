<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AreaFocusLoop;

use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\LoopProviderRoutingService;
use App\Services\Ai\SoftwareCompanyStewardship\AreaFocusLoop\ProviderReliabilityLayerService;
use Tests\TestCase;

/**
 * Tests for LoopProviderRoutingService (AP-804 / LHL-17).
 *
 * Every test drives the service via input seams only — no network, no provider
 * calls, no merges, no filesystem mutations.
 */
final class LoopProviderRoutingServiceTest extends TestCase
{
    private function service(): LoopProviderRoutingService
    {
        return app(LoopProviderRoutingService::class);
    }

    // ---------------------------------------------------------------- helpers

    /**
     * Healthy base fixture: no faults, budget within ceiling, all providers healthy.
     *
     * @return array<string,mixed>
     */
    private function healthyInput(): array
    {
        return [
            'area' => 'agentic_engineering_os',
            'focus' => 'dev_forge',
            'budget' => [
                'cap_usd' => 100.0,
                'spent_usd' => 10.0,
                'remaining_usd' => 90.0,
            ],
        ];
    }

    // ---------------------------------------------------------------- per-lane routing

    /** T01: All canonical lanes are planned when no faults. */
    public function test_all_canonical_lanes_planned_on_healthy_input(): void
    {
        $plan = $this->service()->plan($this->healthyInput());

        $this->assertSame(LoopProviderRoutingService::STATUS_OK, $plan['status']);
        $lanes = $plan['lanes'];
        $this->assertCount(4, $lanes);

        $laneNames = array_column($lanes, 'lane');
        $this->assertContains(LoopProviderRoutingService::LANE_CONTEXT_SCOUT, $laneNames);
        $this->assertContains(LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE, $laneNames);
        $this->assertContains(LoopProviderRoutingService::LANE_REPAIR_AGENT, $laneNames);
        $this->assertContains(LoopProviderRoutingService::LANE_ARCHITECT_JUDGE, $laneNames);
    }

    /** T02: context_scout gets cheap_fast tier (gemini_cli preferred). */
    public function test_context_scout_uses_cheap_fast_tier(): void
    {
        $plan = $this->service()->plan($this->healthyInput());

        $scout = $this->findLane($plan, LoopProviderRoutingService::LANE_CONTEXT_SCOUT);
        $this->assertSame('cheap_fast', $scout['tier']);
        $this->assertSame('gemini_cli', $scout['preferred_provider']);
        $this->assertSame(LoopProviderRoutingService::INVOCATION_PLANNED, $scout['invocation_state']);
        $this->assertFalse($scout['provider_invoked']);
    }

    /** T03: implementer_simple gets builder tier (claude_cli preferred). */
    public function test_implementer_simple_uses_builder_tier(): void
    {
        $plan = $this->service()->plan($this->healthyInput());

        $impl = $this->findLane($plan, LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE);
        $this->assertSame('builder', $impl['tier']);
        $this->assertSame('claude_cli', $impl['preferred_provider']);
    }

    /** T04: architect_judge gets premium tier (claude_cli preferred). */
    public function test_architect_judge_uses_premium_tier(): void
    {
        $plan = $this->service()->plan($this->healthyInput());

        $judge = $this->findLane($plan, LoopProviderRoutingService::LANE_ARCHITECT_JUDGE);
        $this->assertSame('premium', $judge['tier']);
        $this->assertSame('claude_cli', $judge['preferred_provider']);
    }

    /** T05: Explicit lanes input routes only the requested lanes. */
    public function test_explicit_lanes_respected(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                ['lane' => LoopProviderRoutingService::LANE_REPAIR_AGENT],
                ['lane' => LoopProviderRoutingService::LANE_CONTEXT_SCOUT],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $this->assertCount(2, $plan['lanes']);
        $laneNames = array_column($plan['lanes'], 'lane');
        $this->assertContains(LoopProviderRoutingService::LANE_REPAIR_AGENT, $laneNames);
        $this->assertContains(LoopProviderRoutingService::LANE_CONTEXT_SCOUT, $laneNames);
    }

    // ---------------------------------------------------------------- circuit breaker

    /** T06: Open circuit on preferred provider triggers fallback to next in chain. */
    public function test_open_circuit_on_preferred_triggers_fallback(): void
    {
        // claude_cli is circuit-open → implementer_simple should fall back to codex_cli.
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                ['lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE],
            ],
            'provider_reliability' => [
                'providers' => [
                    [
                        'id' => 'claude_cli',
                        'lane' => 'dev',
                        'permanent_failures' => 5,       // triggers circuit open (threshold=3)
                        'timeout_rate' => 0.0,
                        'model_quality_by_lane' => 1.0,
                        'rate_limited' => false,
                        'fallback_availability' => true,
                    ],
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $impl = $this->findLane($plan, LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE);
        $this->assertNotSame('claude_cli', $impl['selected_provider'], 'Should have fallen back from circuit-open claude_cli');
        $this->assertTrue($impl['fallback_applied']);
        $this->assertSame(LoopProviderRoutingService::INVOCATION_PLANNED, $impl['invocation_state']);
    }

    /** T07: All providers in chain circuit-open → lane is blocked (honest). */
    public function test_all_providers_circuit_open_blocks_lane(): void
    {
        // All builder-tier providers including minimax_m27_cli must be circuit-open.
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                ['lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE],
            ],
            'provider_reliability' => [
                'providers' => [
                    ['id' => 'claude_cli', 'permanent_failures' => 5],
                    ['id' => 'codex_cli', 'permanent_failures' => 5],
                    ['id' => 'gemini_cli', 'permanent_failures' => 5],
                    ['id' => 'minimax_m27_cli', 'permanent_failures' => 5],
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        // Status should degrade to blocked (lane_blocked blocker present).
        $this->assertSame(LoopProviderRoutingService::STATUS_BLOCKED, $plan['status']);

        $impl = $this->findLane($plan, LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE);
        $this->assertSame(LoopProviderRoutingService::INVOCATION_BLOCKED, $impl['invocation_state']);
        $this->assertNull($impl['selected_provider']);
        $this->assertFalse($impl['provider_invoked']);
    }

    /** T08: Circuit open status never dressed as ok in plan. */
    public function test_blocked_lane_never_dressed_as_ok(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                ['lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE],
            ],
            'provider_reliability' => [
                'providers' => [
                    ['id' => 'claude_cli', 'permanent_failures' => 5],
                    ['id' => 'codex_cli', 'permanent_failures' => 5],
                    ['id' => 'gemini_cli', 'permanent_failures' => 5],
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $this->assertNotSame(LoopProviderRoutingService::STATUS_OK, $plan['status']);
        $this->assertTrue($plan['claim_policy']['blocked_never_dressed_as_ready']);
    }

    // ---------------------------------------------------------------- timeout fallback

    /** T09: timeout_count >= 2 skips head of chain and falls back to next provider. */
    public function test_timeout_count_triggers_fallback_provider(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                [
                    'lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE,
                    'timeout_count' => 2,
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $impl = $this->findLane($plan, LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE);
        // claude_cli is head; with timeout >= 2 it should be skipped to codex_cli.
        $this->assertNotSame('claude_cli', $impl['selected_provider']);
        $this->assertStringContainsString('timeout_fallback_triggered', $impl['reason']);
    }

    /** T10: timeout_count < 2 does NOT trigger fallback. */
    public function test_timeout_count_below_threshold_no_fallback(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                [
                    'lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE,
                    'timeout_count' => 1,
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $impl = $this->findLane($plan, LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE);
        $this->assertSame('claude_cli', $impl['selected_provider']);
        $this->assertFalse($impl['fallback_applied']);
    }

    // ---------------------------------------------------------------- error escalation

    /** T11: repair_agent escalates tier to builder_plus when same_error_count >= 2. */
    public function test_repair_agent_escalates_on_repeated_errors(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                [
                    'lane' => LoopProviderRoutingService::LANE_REPAIR_AGENT,
                    'same_error_count' => 2,
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $repair = $this->findLane($plan, LoopProviderRoutingService::LANE_REPAIR_AGENT);
        $this->assertSame('builder_plus', $repair['tier']);
        $this->assertStringContainsString('tier_escalated', $repair['reason']);
    }

    /** T12: repair_agent stays at builder when same_error_count < 2. */
    public function test_repair_agent_no_escalation_below_threshold(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                [
                    'lane' => LoopProviderRoutingService::LANE_REPAIR_AGENT,
                    'same_error_count' => 1,
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $repair = $this->findLane($plan, LoopProviderRoutingService::LANE_REPAIR_AGENT);
        $this->assertSame('builder', $repair['tier']);
    }

    // ---------------------------------------------------------------- rate limit

    /** T13: Rate limit hit marks backoff_required=true on the lane. */
    public function test_rate_limit_hit_marks_backoff_required(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                [
                    'lane' => LoopProviderRoutingService::LANE_CONTEXT_SCOUT,
                    'rate_limit_hit' => true,
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $scout = $this->findLane($plan, LoopProviderRoutingService::LANE_CONTEXT_SCOUT);
        $this->assertTrue($scout['backoff_required']);
        $this->assertStringContainsString('rate_limit_hit', $scout['reason']);

        // Backoff lane should appear in summary.
        $this->assertContains(
            LoopProviderRoutingService::LANE_CONTEXT_SCOUT,
            $plan['summary']['backoff_required_lanes'],
        );
    }

    // ---------------------------------------------------------------- budget breach

    /** T14: Budget breach returns status=budget_paused with no lanes planned. */
    public function test_budget_breach_pauses_plan(): void
    {
        $input = array_merge($this->healthyInput(), [
            'budget' => [
                'cap_usd' => 100.0,
                'spent_usd' => 110.0,
                'remaining_usd' => -10.0,
            ],
        ]);

        $plan = $this->service()->plan($input);

        $this->assertSame(LoopProviderRoutingService::STATUS_BUDGET_PAUSED, $plan['status']);
        $this->assertSame([], $plan['lanes']);
        $this->assertContains('provider_budget_breach_pauses_loop', $plan['blockers']);
    }

    /** T15: Explicit budget_breach=true also triggers budget_paused. */
    public function test_explicit_budget_breach_flag_pauses_plan(): void
    {
        $input = array_merge($this->healthyInput(), ['budget_breach' => true]);

        $plan = $this->service()->plan($input);

        $this->assertSame(LoopProviderRoutingService::STATUS_BUDGET_PAUSED, $plan['status']);
    }

    // ---------------------------------------------------------------- schema & claims

    /** T16: Plan schema and claim_policy are present and correct. */
    public function test_plan_schema_and_claim_policy(): void
    {
        $plan = $this->service()->plan($this->healthyInput());

        $this->assertSame(LoopProviderRoutingService::PLAN_SCHEMA, $plan['schema_version']);
        $this->assertSame(LoopProviderRoutingService::AP_CONTRACT, $plan['ap_contract']);
        $this->assertSame(LoopProviderRoutingService::SLICE_ID, $plan['slice_id']);

        $policy = $plan['claim_policy'];
        $this->assertTrue($policy['read_only']);
        $this->assertFalse($policy['provider_invoked']);
        $this->assertTrue($policy['no_silent_fallback']);
        $this->assertTrue($policy['budget_paused_honest']);
    }

    /** T17: provider_invoked is always false on every lane entry. */
    public function test_provider_invoked_is_always_false(): void
    {
        $plan = $this->service()->plan($this->healthyInput());

        foreach ($plan['lanes'] as $entry) {
            $this->assertFalse($entry['provider_invoked'], "Lane {$entry['lane']} must never set provider_invoked=true");
        }
    }

    /** T18: Plan is deterministic — same input produces same plan_hash. */
    public function test_plan_is_deterministic(): void
    {
        $input = $this->healthyInput();

        $plan1 = $this->service()->plan($input);
        $plan2 = $this->service()->plan($input);

        // routing_id must be identical (hash of stable fields).
        $this->assertSame($plan1['routing_id'], $plan2['routing_id']);
    }

    /** T19: Topology provider availability is respected. */
    public function test_topology_unavailable_provider_skipped(): void
    {
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                ['lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE],
            ],
            'provider_topology' => [
                'providers' => [
                    'claude_cli' => ['available' => false],
                    'codex_cli' => ['available' => true],
                    'gemini_cli' => ['available' => true],
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $impl = $this->findLane($plan, LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE);
        // claude_cli is unavailable in topology — should fall through to codex_cli.
        $this->assertNotSame('claude_cli', $impl['selected_provider']);
        $this->assertTrue($impl['fallback_applied']);
    }

    /** T20: Summary correctly lists blocked and fallback-applied lanes. */
    public function test_summary_blocked_and_fallback_lanes(): void
    {
        // Block ALL builder-tier providers (including minimax_m27_cli) to force implementer_simple blocked.
        $input = array_merge($this->healthyInput(), [
            'lanes' => [
                ['lane' => LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE],
                ['lane' => LoopProviderRoutingService::LANE_CONTEXT_SCOUT],
            ],
            'provider_reliability' => [
                'providers' => [
                    ['id' => 'claude_cli', 'permanent_failures' => 5],
                    ['id' => 'codex_cli', 'permanent_failures' => 5],
                    ['id' => 'gemini_cli', 'permanent_failures' => 5],
                    ['id' => 'minimax_m27_cli', 'permanent_failures' => 5],
                ],
            ],
        ]);

        $plan = $this->service()->plan($input);

        $this->assertContains(
            LoopProviderRoutingService::LANE_IMPLEMENTER_SIMPLE,
            $plan['summary']['blocked_lanes'],
        );
    }

    // ---------------------------------------------------------------- helpers

    /**
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed>
     */
    private function findLane(array $plan, string $laneName): array
    {
        foreach ($plan['lanes'] as $entry) {
            if ($entry['lane'] === $laneName) {
                return $entry;
            }
        }
        $this->fail("Lane '{$laneName}' not found in plan");
    }
}
