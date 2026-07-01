<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainGapInventory;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainGapInventoryTest extends TestCase
{
    private function inventory(): AtlasExternalBrainGapInventory
    {
        return new AtlasExternalBrainGapInventory;
    }

    private const AREAS = [
        'external_brain', 'task_fabric', 'maestro', 'queue',
        'outcome_learning', 'simplification', 'control_plane', 'prompt_contract',
    ];

    /** @return array<string, array<string,mixed>> */
    private function connectedAreas(array $overrides = []): array
    {
        $areas = [];
        foreach (self::AREAS as $area) {
            $areas[$area] = ['connected_consumers' => ["{$area}_consumer"]];
        }

        return array_merge($areas, $overrides);
    }

    // ── AC: normalized gap rows carry all required fields ─────────────────────

    public function test_gap_rows_carry_area_missing_link_severity_evidence_and_proposed_closure_task(): void
    {
        $areas = $this->connectedAreas();
        unset($areas['queue']);

        $r = $this->inventory()->inventory(['areas' => $areas]);

        $this->assertNotEmpty($r['gaps']);
        foreach (['area', 'missing_link', 'severity', 'evidence', 'proposed_closure_task'] as $field) {
            $this->assertArrayHasKey($field, $r['gaps'][0], "Missing field: {$field}");
        }
        $this->assertSame('queue', $r['gaps'][0]['area']);
    }

    // ── AC: no_gap=true only when every required area has a connected consumer ──

    public function test_no_gap_true_when_every_area_has_a_connected_consumer(): void
    {
        $r = $this->inventory()->inventory(['areas' => $this->connectedAreas()]);

        $this->assertTrue($r['no_gap']);
        $this->assertSame([], $r['gaps']);
        $this->assertSame([], $r['detached_areas']);
    }

    public function test_single_disconnected_area_makes_no_gap_false(): void
    {
        $areas = $this->connectedAreas(['maestro' => ['connected_consumers' => []]]);

        $r = $this->inventory()->inventory(['areas' => $areas]);

        $this->assertFalse($r['no_gap']);
        $this->assertContains('maestro', $r['detached_areas']);
    }

    // ── AC: empty or report-only inputs → no_gap=false with detached_area findings ──

    public function test_empty_input_produces_no_gap_false_with_all_areas_detached(): void
    {
        $r = $this->inventory()->inventory([]);

        $this->assertFalse($r['no_gap']);
        $this->assertCount(8, $r['detached_areas']);
        $this->assertSame(self::AREAS, $r['detached_areas']);
    }

    public function test_report_only_areas_produce_no_gap_false_with_detached_findings(): void
    {
        $areas = [];
        foreach (self::AREAS as $area) {
            $areas[$area] = ['connected_consumers' => [], 'report_only' => true];
        }

        $r = $this->inventory()->inventory(['areas' => $areas]);

        $this->assertFalse($r['no_gap']);
        $this->assertCount(8, $r['detached_areas']);
        $this->assertSame('medium', $r['gaps'][0]['severity']);
        $this->assertStringContainsString('report_only', $r['gaps'][0]['missing_link']);
    }

    public function test_report_only_area_with_consumers_is_still_detached(): void
    {
        // Report-only means nothing downstream actually consumes it, even if consumers are named.
        $areas = $this->connectedAreas(['control_plane' => ['connected_consumers' => ['x'], 'report_only' => true]]);

        $r = $this->inventory()->inventory(['areas' => $areas]);

        $this->assertFalse($r['no_gap']);
        $this->assertContains('control_plane', $r['detached_areas']);
    }

    public function test_area_with_only_blank_consumer_strings_is_detached(): void
    {
        $areas = $this->connectedAreas(['simplification' => ['connected_consumers' => ['', '   ']]]);

        $r = $this->inventory()->inventory(['areas' => $areas]);

        $this->assertContains('simplification', $r['detached_areas']);
    }

    // ── Determinism ────────────────────────────────────────────────────────────

    public function test_inventory_is_deterministic(): void
    {
        $facts = ['areas' => $this->connectedAreas(['queue' => ['connected_consumers' => []]])];
        $a = $this->inventory()->inventory($facts);
        $b = $this->inventory()->inventory($facts);

        $this->assertSame(json_encode($a), json_encode($b));
    }

    public function test_schema_version_present(): void
    {
        $r = $this->inventory()->inventory([]);
        $this->assertSame(AtlasExternalBrainGapInventory::SCHEMA, $r['schema']);
    }

    public function test_areas_scanned_is_always_eight(): void
    {
        $r = $this->inventory()->inventory(['areas' => $this->connectedAreas()]);
        $this->assertSame(8, $r['areas_scanned']);
    }
}
