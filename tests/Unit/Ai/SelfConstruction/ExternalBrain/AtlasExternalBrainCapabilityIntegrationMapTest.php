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
}
