<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityIntegrationMap;
use PHPUnit\Framework\TestCase;

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

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->mapper()->map([]);
        $this->assertSame(AtlasExternalBrainCapabilityIntegrationMap::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('capability_map',         $r);
        $this->assertArrayHasKey('integration_debt_items', $r);
        $this->assertArrayHasKey('fulfilled_capabilities', $r);
        $this->assertArrayHasKey('debt_summary',           $r);
    }

    // ── fully_integrated ──────────────────────────────────────────────────────

    public function test_implemented_wired_fully_connected_is_fulfilled(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap()]]);
        $this->assertContains('cap1', $r['fulfilled_capabilities']);
        $this->assertSame('fully_integrated', $r['capability_map'][0]['integration_status']);
        $this->assertSame(1.0, $r['capability_map'][0]['coverage_score']);
    }

    // ── AC2: integration_debt ─────────────────────────────────────────────────

    public function test_implemented_but_not_wired_is_integration_debt(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'       => 'unwired',
            'is_wired' => false,
        ])]]);
        $this->assertSame('integration_debt', $r['capability_map'][0]['integration_status']);
        $this->assertNotContains('unwired', $r['fulfilled_capabilities']);
        $this->assertCount(1, $r['integration_debt_items']);
    }

    public function test_implemented_wired_but_missing_connections_is_debt(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'           => 'partial',
            'connected_to' => ['orchestrator'], // task_fabric missing
        ])]]);
        $entry = $r['capability_map'][0];
        $this->assertSame('integration_debt', $entry['integration_status']);
        $this->assertContains('task_fabric', $entry['missing_connections']);
        $this->assertNotContains('partial', $r['fulfilled_capabilities']);
    }

    public function test_integration_debt_items_list_missing_connections(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'           => 'gap',
            'connected_to' => [],
        ])]]);
        $debt = $r['integration_debt_items'][0];
        $this->assertSame('gap', $debt['capability_id']);
        $this->assertContains('orchestrator', $debt['missing_connections']);
        $this->assertContains('task_fabric',  $debt['missing_connections']);
    }

    // ── not_implemented ───────────────────────────────────────────────────────

    public function test_not_implemented_capability_classified_correctly(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'             => 'ghost',
            'is_implemented' => false,
            'is_wired'       => true, // wiring claims ignored if not implemented
        ])]]);
        $this->assertSame('not_implemented', $r['capability_map'][0]['integration_status']);
        $this->assertNotContains('ghost', $r['fulfilled_capabilities']);
        $this->assertEmpty($r['integration_debt_items']); // not_implemented is NOT debt
    }

    // ── Coverage score ────────────────────────────────────────────────────────

    public function test_partial_coverage_score_computed(): void
    {
        // 1 of 2 points connected → 0.5
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'connected_to' => ['orchestrator'],
        ])]]);
        $this->assertSame(0.5, $r['capability_map'][0]['coverage_score']);
    }

    public function test_no_integration_points_fulfilled_is_1(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'integration_points' => [],
            'connected_to'       => [],
        ])]]);
        $this->assertSame(1.0, $r['capability_map'][0]['coverage_score']);
        $this->assertSame('fully_integrated', $r['capability_map'][0]['integration_status']);
    }

    public function test_no_integration_points_unwired_is_0(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'is_wired'           => false,
            'integration_points' => [],
            'connected_to'       => [],
        ])]]);
        $this->assertSame(0.0, $r['capability_map'][0]['coverage_score']);
    }

    // ── debt_summary counts ───────────────────────────────────────────────────

    public function test_debt_summary_counts_correctly(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'full']),
            $this->cap(['id' => 'debt', 'is_wired' => false]),
            $this->cap(['id' => 'miss', 'is_implemented' => false]),
        ]]);
        $s = $r['debt_summary'];
        $this->assertSame(3, $s['total']);
        $this->assertSame(2, $s['implemented']); // full + debt
        $this->assertSame(1, $s['wired']);        // only full
        $this->assertSame(1, $s['integration_debt']);
        $this->assertSame(1, $s['not_implemented']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = ['capabilities' => [
            $this->cap(['id' => 'a']),
            $this->cap(['id' => 'b', 'is_wired' => false]),
        ]];
        $a = $this->mapper()->map($facts);
        $b = $this->mapper()->map($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC2: dormant_implemented ──────────────────────────────────────────────

    public function test_dormant_implemented_when_no_consumers_unwired_no_missing(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'                 => 'sleeper',
            'is_wired'           => false,
            'consumer_count'     => 0,
            'integration_points' => ['orchestrator', 'task_fabric'],
            'connected_to'       => ['orchestrator', 'task_fabric'],
        ])]]);

        $this->assertSame('dormant_implemented', $r['capability_map'][0]['integration_status']);
        $this->assertNotContains('sleeper', $r['fulfilled_capabilities']);
        $this->assertEmpty($r['integration_debt_items']);
        $this->assertSame(1, $r['debt_summary']['dormant_implemented']);
    }

    public function test_unwired_without_consumer_count_is_still_integration_debt(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'       => 'debt',
            'is_wired' => false,
        ])]]);

        $this->assertSame('integration_debt', $r['capability_map'][0]['integration_status']);
    }

    // ── AC2: contract_missing ─────────────────────────────────────────────────

    public function test_contract_missing_when_has_contract_false(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'           => 'no_spec',
            'has_contract' => false,
        ])]]);

        $this->assertSame('contract_missing', $r['capability_map'][0]['integration_status']);
        $this->assertNotContains('no_spec', $r['fulfilled_capabilities']);
        $this->assertSame(1, $r['debt_summary']['contract_missing']);
    }

    public function test_has_contract_true_does_not_change_classification(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap(['has_contract' => true])]]);

        $this->assertSame('fully_integrated', $r['capability_map'][0]['integration_status']);
    }

    // ── AC3: wiring_coverage ─────────────────────────────────────────────────

    public function test_wiring_coverage_present_in_capability_map(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap()]]);

        $this->assertArrayHasKey('wiring_coverage', $r['capability_map'][0]);
        $this->assertIsFloat($r['capability_map'][0]['wiring_coverage']);
    }

    public function test_wiring_coverage_with_consumer_and_control_plane(): void
    {
        // coverage_score=1.0, consumer_count=5 (ceiling), control_plane=true
        // wiring_coverage = 1.0*0.6 + 1.0*0.2 + 1.0*0.2 = 1.0
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'consumer_count'       => 5,
            'control_plane_exists' => true,
        ])]]);

        $this->assertSame(1.0, $r['capability_map'][0]['wiring_coverage']);
    }

    public function test_wiring_coverage_no_new_fields_equals_60_pct_of_coverage(): void
    {
        // Fully connected, no consumer_count/control_plane → wiring_coverage = 1.0*0.6 = 0.6
        $r = $this->mapper()->map(['capabilities' => [$this->cap()]]);

        $this->assertSame(round(1.0 * 0.6, 4), $r['capability_map'][0]['wiring_coverage']);
    }

    // ── AC4: next_wiring_actions in debt items ────────────────────────────────

    public function test_debt_item_has_next_wiring_actions(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'       => 'unwired',
            'is_wired' => false,
        ])]]);

        $debt = $r['integration_debt_items'][0];
        $this->assertArrayHasKey('next_wiring_actions', $debt);
        $this->assertIsArray($debt['next_wiring_actions']);
        $this->assertNotEmpty($debt['next_wiring_actions']);
    }

    public function test_next_wiring_actions_lists_connect_to_for_missing_points(): void
    {
        $r = $this->mapper()->map(['capabilities' => [$this->cap([
            'id'           => 'partial',
            'connected_to' => ['orchestrator'], // task_fabric missing
        ])]]);

        $actions = $r['integration_debt_items'][0]['next_wiring_actions'];
        $this->assertContains('connect_to:task_fabric', $actions);
    }

    // ── AC4: debt_summary extended counts ────────────────────────────────────

    public function test_debt_summary_has_dormant_and_contract_missing_keys(): void
    {
        $r = $this->mapper()->map([]);

        $this->assertArrayHasKey('dormant_implemented', $r['debt_summary']);
        $this->assertArrayHasKey('contract_missing',    $r['debt_summary']);
    }

    // ── AC: circuits, isolated_organs, circuit_recommendations in output ──────

    public function test_output_has_circuit_and_recommendation_keys(): void
    {
        $r = $this->mapper()->map([]);

        $this->assertArrayHasKey('circuits',                $r);
        $this->assertArrayHasKey('isolated_organs',         $r);
        $this->assertArrayHasKey('circuit_recommendations', $r);
    }

    public function test_organs_sharing_integration_point_grouped_in_same_circuit(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'a', 'integration_points' => ['orchestrator'], 'connected_to' => ['orchestrator']]),
            $this->cap(['id' => 'b', 'integration_points' => ['orchestrator'], 'connected_to' => ['orchestrator']]),
        ]]);

        $this->assertCount(1, $r['circuits']);
        $this->assertSame('orchestrator', $r['circuits'][0]['circuit_id']);
        $this->assertContains('a', $r['circuits'][0]['member_organ_ids']);
        $this->assertContains('b', $r['circuits'][0]['member_organ_ids']);
    }

    public function test_circuit_accumulates_missing_edges_from_all_members(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'a', 'connected_to' => ['orchestrator']]),            // task_fabric missing
            $this->cap(['id' => 'b', 'integration_points' => ['orchestrator', 'task_fabric'], 'connected_to' => ['orchestrator']]),
        ]]);

        $circuit = $r['circuits'][0];
        $this->assertContains('task_fabric', $circuit['missing_edges']);
    }

    public function test_explicit_circuit_field_overrides_inferred_circuit(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'a', 'circuit' => 'my_circuit', 'integration_points' => ['orchestrator']]),
            $this->cap(['id' => 'b', 'circuit' => 'my_circuit', 'integration_points' => ['task_fabric']]),
        ]]);

        $circuitIds = array_column($r['circuits'], 'circuit_id');
        $this->assertContains('my_circuit', $circuitIds);
        $idx = array_search('my_circuit', $circuitIds, true);
        $this->assertContains('a', $r['circuits'][$idx]['member_organ_ids']);
        $this->assertContains('b', $r['circuits'][$idx]['member_organ_ids']);
    }

    public function test_organ_with_no_connections_or_integration_points_is_isolated(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'floating',
                'integration_points' => [],
                'connected_to'       => [],
                'consumer_count'     => 0,
            ]),
        ]]);

        $this->assertContains('floating', $r['isolated_organs']);
        $this->assertEmpty($r['circuits']);
    }

    public function test_retire_recommendation_for_isolated_organ_has_high_leverage_false(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'dead',
                'integration_points' => [],
                'connected_to'       => [],
                'consumer_count'     => 0,
            ]),
        ]]);

        $retireRecs = array_filter($r['circuit_recommendations'], fn(array $rec): bool => $rec['action'] === 'retire');
        $this->assertNotEmpty($retireRecs);
        foreach ($retireRecs as $rec) {
            $this->assertFalse($rec['high_leverage'], 'retire must not be high_leverage');
        }
    }

    public function test_connect_recommendation_for_missing_edges_is_high_leverage(): void
    {
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap([
                'id'           => 'partial',
                'connected_to' => ['orchestrator'],   // task_fabric still missing
            ]),
        ]]);

        $connectRecs = array_filter($r['circuit_recommendations'], fn(array $rec): bool => $rec['action'] === 'connect');
        $this->assertNotEmpty($connectRecs, 'expected at least one connect recommendation');
        foreach ($connectRecs as $rec) {
            $this->assertTrue($rec['high_leverage'], 'connect with named targets must be high_leverage');
        }
    }

    public function test_merge_recommendation_for_redundant_circuit_is_high_leverage(): void
    {
        // Two fully-connected organs on same circuit but zero consumers → merge
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap(['id' => 'a', 'integration_points' => ['orchestrator'], 'connected_to' => ['orchestrator'], 'consumer_count' => 0]),
            $this->cap(['id' => 'b', 'integration_points' => ['orchestrator'], 'connected_to' => ['orchestrator'], 'consumer_count' => 0]),
        ]]);

        $mergeRecs = array_filter($r['circuit_recommendations'], fn(array $rec): bool => $rec['action'] === 'merge');
        $this->assertNotEmpty($mergeRecs);
        foreach ($mergeRecs as $rec) {
            $this->assertTrue($rec['high_leverage']);
        }
    }

    public function test_wrapper_only_debt_organ_does_not_produce_high_leverage_connect(): void
    {
        // Organ is integration_debt (is_wired=false) but all integration_points are already connected_to
        // → missing_connections=[] → no connect recommendation → AC4 satisfied
        $r = $this->mapper()->map(['capabilities' => [
            $this->cap([
                'id'                 => 'unwired_but_connected',
                'is_wired'           => false,
                'integration_points' => ['orchestrator', 'task_fabric'],
                'connected_to'       => ['orchestrator', 'task_fabric'],
                'consumer_count'     => 0,
            ]),
        ]]);

        $highLeverageRecs = array_filter(
            $r['circuit_recommendations'],
            fn(array $rec): bool => ($rec['high_leverage'] ?? false) === true,
        );
        $this->assertEmpty($highLeverageRecs, 'wrapper-only debt must not generate high_leverage recommendations');
    }
}
