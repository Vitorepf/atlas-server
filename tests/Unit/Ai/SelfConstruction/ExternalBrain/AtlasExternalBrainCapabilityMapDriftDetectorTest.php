<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityMapDriftDetector;
use Tests\TestCase;

final class AtlasExternalBrainCapabilityMapDriftDetectorTest extends TestCase
{
    private function svc(): AtlasExternalBrainCapabilityMapDriftDetector
    {
        return new AtlasExternalBrainCapabilityMapDriftDetector;
    }

    private function entry(string $id, string $state = 'known', int $ageDays = 5, bool $evidence = true): array
    {
        return [
            'area_id' => $id,
            'state' => $state,
            'last_updated_age_days' => $ageDays,
            'has_completion_evidence' => $evidence,
        ];
    }

    private function findingFor(array $result, string $areaId): ?array
    {
        foreach ($result['findings'] as $f) {
            if ($f['area_id'] === $areaId) {
                return $f;
            }
        }

        return null;
    }

    // ── no drift ──────────────────────────────────────────────────────────────

    public function test_no_drift_gives_empty_findings(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gate-impl', 'integrated', 5, true)],
            'queued_areas' => ['gate-impl'],
        ]);

        $this->assertFalse($r['has_drift']);
        $this->assertSame([], $r['findings']);
        $this->assertSame(0, $r['total_findings']);
    }

    // ── stale ─────────────────────────────────────────────────────────────────

    public function test_stale_entry_detected(): void
    {
        $threshold = AtlasExternalBrainCapabilityMapDriftDetector::STALE_AGE_THRESHOLD_DAYS;
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('research', 'known', $threshold + 1, true)],
            'queued_areas' => [],
        ]);

        $f = $this->findingFor($r, 'research');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_STALE, $f['drift_type']);
        $this->assertSame('medium', $f['impact_level']);
        $this->assertContains('fresh_evidence_scan', $f['evidence_needed']);
    }

    public function test_fresh_entry_not_flagged_as_stale(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('research', 'known', 10, true)],
            'queued_areas' => [],
        ]);

        $this->assertNull($this->findingFor($r, 'research'));
    }

    // ── contradictory ─────────────────────────────────────────────────────────

    public function test_contradictory_entry_detected(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gate-impl', 'integrated', 5, false)],
            'queued_areas' => [],
        ]);

        $f = $this->findingFor($r, 'gate-impl');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY, $f['drift_type']);
        $this->assertSame('high', $f['impact_level']);
        $this->assertContains('completion_proof', $f['evidence_needed']);
        $this->assertContains('integration_test_result', $f['evidence_needed']);
    }

    public function test_integrated_with_evidence_not_flagged(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gate-impl', 'integrated', 5, true)],
            'queued_areas' => [],
        ]);

        $this->assertNull($this->findingFor($r, 'gate-impl'));
    }

    // ── missing ───────────────────────────────────────────────────────────────

    public function test_missing_queued_area_detected(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gate-impl')],
            'queued_areas' => ['discovery'],
        ]);

        $f = $this->findingFor($r, 'discovery');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING, $f['drift_type']);
        $this->assertSame('high', $f['impact_level']);
        $this->assertContains('area_discovery_scan', $f['evidence_needed']);
        $this->assertContains('capability_mapping', $f['evidence_needed']);
    }

    public function test_queued_area_in_map_not_flagged_as_missing(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gate-impl')],
            'queued_areas' => ['gate-impl'],
        ]);

        $this->assertNull($this->findingFor($r, 'gate-impl'));
    }

    // ── ranking ───────────────────────────────────────────────────────────────

    public function test_findings_ranked_high_before_medium(): void
    {
        $threshold = AtlasExternalBrainCapabilityMapDriftDetector::STALE_AGE_THRESHOLD_DAYS;
        $r = $this->svc()->detect([
            'map_entries' => [
                $this->entry('stale-area', 'known', $threshold + 5, true),
                $this->entry('contra-area', 'integrated', 5, false),
            ],
            'queued_areas' => [],
        ]);

        $impacts = array_column($r['findings'], 'impact_level');
        // high should come before medium
        $highIdx = array_search('high', $impacts, true);
        $medIdx = array_search('medium', $impacts, true);
        $this->assertLessThan($medIdx, $highIdx);
    }

    public function test_has_drift_true_when_findings_present(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gate-impl', 'integrated', 5, false)],
            'queued_areas' => [],
        ]);

        $this->assertTrue($r['has_drift']);
        $this->assertSame(1, $r['total_findings']);
    }

    // ── schema ────────────────────────────────────────────────────────────────

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->detect([]);

        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::SCHEMA, $r['schema_version']);
    }
}
