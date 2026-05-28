<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SoftwareCompanyStewardship\AgentExecution;

use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\LaneProviderRoutingService;
use App\Services\Ai\SoftwareCompanyStewardship\AgentExecution\MultiAgentCycleCertificationService;
use Tests\TestCase;

/**
 * AP-804 · per-lane provider routing contract tests.
 *
 * The router is a pure deterministic planner: it never invokes a provider, never
 * falls back silently, never echoes a secret and never claims provider_invoked.
 * Atlas Decide topology is authoritative when present; otherwise the plan
 * degrades honestly to a deferred plan with an explicit blocker.
 */
class LaneProviderRoutingServiceTest extends TestCase
{
    private function service(): LaneProviderRoutingService
    {
        return app(LaneProviderRoutingService::class);
    }

    /**
     * A topology where every canonical provider is available with its tier
     * capabilities.
     *
     * @return array<string,mixed>
     */
    private function fullTopology(): array
    {
        return [
            'source' => LaneProviderRoutingService::SOURCE_ATLAS_DECIDE,
            'providers' => [
                'gemini_cli' => ['capabilities' => ['fast', 'low_cost', 'read_context', 'large_context'], 'models' => ['gemini-3.5-flash'], 'auth_mode' => 'account', 'available' => true],
                'claude_cli' => ['capabilities' => ['strong_reasoning', 'planning', 'spec_authoring', 'tool_use'], 'models' => ['claude-opus-4-7'], 'auth_mode' => 'session', 'available' => true],
                'cursor_cli' => ['capabilities' => ['tool_use', 'file_write', 'code_edit'], 'models' => ['composer-2.5-fast'], 'auth_mode' => 'account', 'available' => true],
                'codex_cli' => ['capabilities' => ['critical_review', 'deterministic_judgement', 'reasoning'], 'models' => ['gpt-5.5'], 'auth_mode' => 'api', 'available' => true],
            ],
        ];
    }

    public function test_architect_gets_strong_profile_when_available(): void
    {
        $e = $this->service()->routeLane('architect', 'lane_a', $this->fullTopology());

        $this->assertSame('strong_reasoning', $e['tier']);
        $this->assertSame('claude_cli', $e['selected_provider']);
        $this->assertSame('premium', $e['selected_profile']);
        $this->assertSame('available', $e['availability']);
        $this->assertNull($e['blocker']);
    }

    public function test_context_scout_gets_cheap_fast_profile_when_available(): void
    {
        $e = $this->service()->routeLane('context_scout', 'lane_s', $this->fullTopology());

        $this->assertSame('cheap_fast', $e['tier']);
        $this->assertSame('gemini_cli', $e['selected_provider']);
        $this->assertSame('fast', $e['selected_profile']);
        $this->assertContains('low_cost', $e['desired_capabilities']);
    }

    public function test_implementer_requires_write_or_tool_capability(): void
    {
        // Desired capabilities declare write/tool support.
        $e = $this->service()->routeLane('implementer', 'lane_i', $this->fullTopology());
        $this->assertContains('file_write', $e['desired_capabilities']);
        $this->assertContains('tool_use', $e['desired_capabilities']);
        $this->assertSame('cursor_cli', $e['selected_provider']);

        // A topology where the only available provider is read-only must NOT be
        // chosen for the implementer; it blocks instead.
        $readOnly = [
            'source' => LaneProviderRoutingService::SOURCE_INJECTED,
            'providers' => [
                'gemini_cli' => ['capabilities' => ['fast', 'read_context'], 'models' => ['gemini-3.5-flash'], 'auth_mode' => 'account', 'available' => true],
            ],
        ];
        $blocked = $this->service()->routeLane('implementer', 'lane_i', $readOnly);
        $this->assertNull($blocked['selected_provider']);
        $this->assertSame(LaneProviderRoutingService::BLOCK_PROVIDER_UNAVAILABLE, $blocked['blocker']);
    }

    public function test_unavailable_preferred_provider_falls_back_with_receipt(): void
    {
        $topo = $this->fullTopology();
        // Preferred builder_write head (cursor_cli) is unavailable; claude_cli has
        // write capability and is available -> explicit fallback.
        $topo['providers']['cursor_cli']['available'] = false;

        $e = $this->service()->routeLane('implementer', 'lane_i', $topo);

        $this->assertSame('cursor_cli', $e['preferred_provider']);
        $this->assertSame('claude_cli', $e['selected_provider']);
        $this->assertTrue($e['fallback_applied']);
        $this->assertStringContainsString('fell back', $e['reason']);
        $this->assertNull($e['blocker']);
    }

    public function test_no_available_provider_blocks(): void
    {
        $topo = $this->fullTopology();
        foreach (['cursor_cli', 'claude_cli', 'codex_cli'] as $p) {
            $topo['providers'][$p]['available'] = false;
        }

        $e = $this->service()->routeLane('implementer', 'lane_i', $topo);

        $this->assertNull($e['selected_provider']);
        $this->assertSame('unavailable', $e['availability']);
        $this->assertSame(LaneProviderRoutingService::BLOCK_PROVIDER_UNAVAILABLE, $e['blocker']);
    }

    public function test_degraded_without_atlas_decide_is_honest_deferred(): void
    {
        // No topology -> degraded, deferred plan with an explicit blocker, but a
        // preferred provider + fallback chain still exist (substitutable).
        $e = $this->service()->routeLane('architect', 'lane_a', []);

        $this->assertSame(LaneProviderRoutingService::SOURCE_DEGRADED, $e['routing_source']);
        $this->assertSame('deferred', $e['invocation_state']);
        $this->assertSame('unknown', $e['availability']);
        $this->assertSame(LaneProviderRoutingService::BLOCK_ATLAS_DECIDE_UNAVAILABLE, $e['blocker']);
        $this->assertNotEmpty($e['preferred_provider']);
        $this->assertNotEmpty($e['fallback_chain']);
    }

    public function test_provider_invoked_is_false_when_only_planned(): void
    {
        foreach ([[], $this->fullTopology()] as $topo) {
            foreach (['context_scout', 'architect', 'implementer', 'reviewer', 'judge', 'repair_agent'] as $role) {
                $e = $this->service()->routeLane($role, 'lane_'.$role, $topo);
                $this->assertFalse($e['provider_invoked'], "{$role} must not claim provider_invoked");
                $this->assertContains($e['invocation_state'], ['planned', 'deferred']);
            }
        }
    }

    public function test_secrets_are_redacted_and_auth_mode_is_a_label(): void
    {
        $topo = $this->fullTopology();
        $topo['providers']['codex_cli']['auth_mode'] = 'api';
        $topo['providers']['codex_cli']['api_key'] = 'sk-SUPERSECRET-DO-NOT-LEAK-123';
        $topo['providers']['codex_cli']['reason'] = 'configured with sk-SUPERSECRET-DO-NOT-LEAK-123';

        $e = $this->service()->routeLane('judge', 'lane_j', $topo);

        $this->assertSame('codex_cli', $e['selected_provider']);
        $this->assertSame('api', $e['auth_mode']);
        $this->assertStringNotContainsString('sk-SUPERSECRET', json_encode($e));
    }

    public function test_lane_provider_plan_is_deterministic(): void
    {
        $input = [
            'lanes' => [
                ['lane_id' => 'l1', 'role' => 'context_scout'],
                ['lane_id' => 'l2', 'role' => 'implementer'],
            ],
            'provider_topology' => $this->fullTopology(),
        ];
        $a = $this->service()->route($input);
        $b = $this->service()->route($input);

        $this->assertSame(LaneProviderRoutingService::PLAN_SCHEMA, $a['schema_version']);
        $this->assertSame('AP-804', $a['ap_contract']);
        $this->assertSame($a['plan_hash'], $b['plan_hash']);
        $this->assertSame($a['lanes'][0]['entry_hash'], $b['lanes'][0]['entry_hash']);
        $this->assertTrue($a['claim_policy']['no_provider_call']);
        $this->assertTrue($a['claim_policy']['no_silent_fallback']);
    }

    public function test_certification_blocks_a_lane_without_a_provider_plan(): void
    {
        $cert = app(MultiAgentCycleCertificationService::class);

        $lanesNoPlan = [
            'context_scout' => ['status' => 'completed'],
            'architect' => ['status' => 'completed'],
            'implementer' => ['status' => 'completed'],
            'reviewer' => ['status' => 'completed'],
            'judge' => ['status' => 'completed'],
        ];

        $blockedReport = $cert->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => [
                'multi_agent' => true,
                'provider_routing_required' => true,
                'lanes' => $lanesNoPlan,
            ],
        ]);
        $this->assertContains('lane_missing_provider_plan', $blockedReport['blockers']);
        $this->assertSame('blocked', $blockedReport['status']);

        // The same cycle with a provider_plan on every lane is NOT blocked on that.
        $lanesWithPlan = [];
        foreach ($lanesNoPlan as $role => $entry) {
            $entry['provider_plan'] = $this->service()->routeLane($role, 'lane_'.$role, $this->fullTopology());
            $lanesWithPlan[$role] = $entry;
        }
        $okReport = $cert->certify([
            'use_real_services' => true,
            'real_recorded_cycle' => [
                'multi_agent' => true,
                'provider_routing_required' => true,
                'lanes' => $lanesWithPlan,
            ],
        ]);
        $this->assertNotContains('lane_missing_provider_plan', $okReport['blockers']);
    }
}
