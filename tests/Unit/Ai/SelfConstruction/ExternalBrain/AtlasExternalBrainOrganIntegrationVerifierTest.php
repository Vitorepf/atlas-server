<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOrganIntegrationVerifier;
use Tests\TestCase;

final class AtlasExternalBrainOrganIntegrationVerifierTest extends TestCase
{
    private function svc(): AtlasExternalBrainOrganIntegrationVerifier
    {
        return new AtlasExternalBrainOrganIntegrationVerifier;
    }

    private function organ(string $id, bool $tests = true, bool $impl = true): array
    {
        return ['organ_id' => $id, 'has_tests' => $tests, 'has_implementation' => $impl];
    }

    // ── integrated ────────────────────────────────────────────────────────────

    public function test_organ_with_consumer_in_flow_usage_is_integrated(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('A')],
            'flow_usage' => ['A' => ['decision_loop']],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertContains('A', $r['integrated_ids']);
        $this->assertSame(['decision_loop'], $r['results'][0]['consumers']);
    }

    public function test_organ_with_control_plane_exposure_is_integrated(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('B')],
            'control_plane_exposure' => ['B' => true],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTEGRATED, $r['results'][0]['status']);
        $this->assertTrue($r['results'][0]['control_plane_exposed']);
    }

    // ── intentionally standalone ──────────────────────────────────────────────

    public function test_organ_with_standalone_justification_is_intentionally_standalone(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('C')],
            'standalone_justifications' => ['C' => 'pure utility, consumed dynamically'],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_INTENTIONALLY_STANDALONE, $r['results'][0]['status']);
        $this->assertContains('C', $r['intentionally_standalone_ids']);
        $this->assertSame('pure utility, consumed dynamically', $r['results'][0]['standalone_reason']);
    }

    // ── orphaned ──────────────────────────────────────────────────────────────

    public function test_organ_with_tests_and_impl_but_no_wiring_is_orphaned(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('D', true, true)],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_ORPHANED, $r['results'][0]['status']);
        $this->assertContains('D', $r['orphaned_ids']);
        $this->assertTrue($r['results'][0]['capability_island'], 'has_tests+impl but no wiring → capability_island=true');
    }

    public function test_orphaned_organ_sets_has_orphans_true(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('E')],
        ]);

        $this->assertTrue($r['has_orphans']);
    }

    public function test_capability_island_false_when_organ_lacks_impl(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('F', true, false)],
        ]);

        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::STATUS_ORPHANED, $r['results'][0]['status']);
        $this->assertFalse($r['results'][0]['capability_island']);
    }

    // ── multi-organ classification ────────────────────────────────────────────

    public function test_multiple_organs_classified_independently(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [
                $this->organ('integrated'),
                $this->organ('standalone'),
                $this->organ('orphan'),
            ],
            'flow_usage' => ['integrated' => ['loop']],
            'standalone_justifications' => ['standalone' => 'utility only'],
        ]);

        $this->assertSame(3, $r['total_organs']);
        $this->assertContains('integrated', $r['integrated_ids']);
        $this->assertContains('standalone', $r['intentionally_standalone_ids']);
        $this->assertContains('orphan', $r['orphaned_ids']);
        $this->assertTrue($r['has_orphans']);
    }

    public function test_no_orphans_when_all_organs_wired(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('X'), $this->organ('Y')],
            'flow_usage' => ['X' => ['a'], 'Y' => ['b']],
        ]);

        $this->assertFalse($r['has_orphans']);
        $this->assertSame([], $r['orphaned_ids']);
    }

    // ── edge cases ────────────────────────────────────────────────────────────

    public function test_empty_inventory_returns_no_results(): void
    {
        $r = $this->svc()->verify([]);

        $this->assertSame(0, $r['total_organs']);
        $this->assertSame([], $r['results']);
        $this->assertFalse($r['has_orphans']);
        $this->assertSame(AtlasExternalBrainOrganIntegrationVerifier::SCHEMA, $r['schema_version']);
    }

    public function test_standalone_reason_null_when_not_standalone(): void
    {
        $r = $this->svc()->verify([
            'organ_inventory' => [$this->organ('Z')],
            'flow_usage' => ['Z' => ['consumer_a']],
        ]);

        $this->assertNull($r['results'][0]['standalone_reason']);
    }
}
