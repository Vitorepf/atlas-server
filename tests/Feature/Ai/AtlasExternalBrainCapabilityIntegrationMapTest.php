<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityIntegrationMap;
use Tests\TestCase;

/**
 * Feature-level gate for AtlasExternalBrainCapabilityIntegrationMap.
 *
 * Verifies the four integration statuses (contract_missing, dormant_implemented,
 * integration_debt, fully_integrated) classify in priority order, and that
 * integration_debt_items carry missing_connections + next_wiring_actions with
 * a full debt_summary.
 */
final class AtlasExternalBrainCapabilityIntegrationMapTest extends TestCase
{
    private function mapper(): AtlasExternalBrainCapabilityIntegrationMap
    {
        return new AtlasExternalBrainCapabilityIntegrationMap;
    }

    private function cap(array $overrides = []): array
    {
        return array_merge([
            'id'                 => 'cap1',
            'is_implemented'     => true,
            'is_wired'           => true,
            'integration_points' => ['orchestrator', 'task_fabric'],
            'connected_to'       => ['orchestrator', 'task_fabric'],
        ], $overrides);
    }

    // ── AC2: contract_missing before integration_debt ───────────────────────

    public function test_contract_missing_classified_before_integration_debt(): void
    {
        // Implemented, has_contract=false, AND not wired — must be contract_missing, not debt.
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'           => 'no_spec',
            'has_contract' => false,
            'is_wired'     => false,
        ])]]);

        $this->assertSame('contract_missing', $r['capability_map'][0]['integration_status']);
        $this->assertSame(1, $r['debt_summary']['contract_missing']);
    }

    // ── AC2: dormant_implemented ─────────────────────────────────────────────

    public function test_dormant_implemented_when_unwired_no_consumers_no_missing(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'                 => 'sleeper',
            'is_wired'           => false,
            'consumer_count'     => 0,
            'integration_points' => ['orchestrator', 'task_fabric'],
            'connected_to'       => ['orchestrator', 'task_fabric'],
        ])]]);

        $this->assertSame('dormant_implemented', $r['capability_map'][0]['integration_status']);
        $this->assertSame(1, $r['debt_summary']['dormant_implemented']);
        $this->assertEmpty($r['integration_debt_items']);
    }

    // ── AC4: integration_debt_items structure ────────────────────────────────

    public function test_debt_items_include_missing_connections_and_next_wiring_actions(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'           => 'gap',
            'is_wired'     => false,
            'connected_to' => [], // both points missing
        ])]]);

        $debt = $r['integration_debt_items'][0];
        $this->assertSame('gap', $debt['capability_id']);
        $this->assertContains('orchestrator', $debt['missing_connections']);
        $this->assertContains('task_fabric', $debt['missing_connections']);
        $this->assertNotEmpty($debt['next_wiring_actions']);
        $this->assertContains('connect_to:orchestrator', $debt['next_wiring_actions']);
        $this->assertContains('connect_to:task_fabric', $debt['next_wiring_actions']);
    }

    // ── AC4: debt_summary full counts ────────────────────────────────────────

    public function test_debt_summary_counts_all_five_categories(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'full']),
            $this->cap(['id' => 'debt', 'is_wired' => false]),
            $this->cap(['id' => 'dormant', 'is_wired' => false, 'consumer_count' => 0, 'connected_to' => ['orchestrator', 'task_fabric']]),
            $this->cap(['id' => 'nospec', 'has_contract' => false]),
            $this->cap(['id' => 'ghost', 'is_implemented' => false]),
        ]]);

        $s = $r['debt_summary'];
        $this->assertSame(5, $s['total']);
        $this->assertSame(4, $s['implemented']);
        $this->assertSame(1, $s['wired']);
        $this->assertSame(1, $s['integration_debt']);
        $this->assertSame(1, $s['dormant_implemented']);
        $this->assertSame(1, $s['contract_missing']);
        $this->assertSame(1, $s['not_implemented']);
    }

    // ── fully_integrated ─────────────────────────────────────────────────────

    public function test_fully_integrated_when_implemented_wired_and_connected(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap()]]);

        $this->assertSame('fully_integrated', $r['capability_map'][0]['integration_status']);
        $this->assertContains('cap1', $r['fulfilled_capabilities']);
    }
}
