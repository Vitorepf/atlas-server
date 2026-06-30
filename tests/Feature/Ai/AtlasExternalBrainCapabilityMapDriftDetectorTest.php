<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityMapDriftDetector;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityMapDriftDetectorTest extends TestCase
{
    private AtlasExternalBrainCapabilityMapDriftDetector $detector;

    protected function setUp(): void
    {
        $this->detector = new AtlasExternalBrainCapabilityMapDriftDetector;
    }

    private function detect(array $input): array
    {
        return $this->detector->detect($input);
    }

    private function entry(array $overrides = []): array
    {
        return array_merge([
            'area_id'                => 'domain-a',
            'state'                  => 'functional',
            'last_updated_age_days'  => 5,
            'has_completion_evidence' => true,
            'owner'                  => 'team-x',
            'maturity_band'          => 'functional',
            'next_leverage'          => 'improve_reliability',
            'owner_evidence_age_days' => 5,
        ], $overrides);
    }

    // ── AC1: evidence contradicts stored maturity → drift finding ─────────────

    public function test_integrated_state_without_evidence_is_contradictory_drift(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'state'                   => 'integrated',
            'has_completion_evidence' => false,
        ])]]);

        $this->assertTrue($result['has_drift']);
        $types = array_column($result['findings'], 'drift_type');
        $this->assertContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY, $types);
    }

    public function test_contradictory_drift_has_high_impact(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'state'                   => 'integrated',
            'has_completion_evidence' => false,
        ])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY
        ));
        $this->assertSame('high', $finding['impact_level']);
    }

    public function test_contradictory_finding_includes_evidence_needed(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'state'                   => 'integrated',
            'has_completion_evidence' => false,
        ])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY
        ));
        $this->assertNotEmpty($finding['evidence_needed']);
    }

    // ── AC2: missing next_leverage is a medium-severity finding ──────────────

    public function test_missing_next_leverage_is_a_drift_finding(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry(['next_leverage' => ''])]]);

        $this->assertTrue($result['has_drift']);
        $types = array_column($result['findings'], 'drift_type');
        $this->assertContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE, $types);
    }

    public function test_missing_next_leverage_has_medium_impact(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry(['next_leverage' => ''])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE
        ));
        $this->assertSame('medium', $finding['impact_level']);
    }

    public function test_missing_next_leverage_includes_actionable_evidence_needed(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry(['next_leverage' => ''])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE
        ));
        $this->assertContains('leverage_assessment', $finding['evidence_needed']);
    }

    public function test_present_next_leverage_does_not_trigger_drift(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry(['next_leverage' => 'improve_test_coverage'])]]);

        $types = array_column($result['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE, $types);
    }

    // ── AC3: stale evidence timestamps reduce confidence ──────────────────────

    public function test_fresh_evidence_yields_high_confidence(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'last_updated_age_days' => 5,
            'next_leverage'         => '',  // force a finding to inspect
        ])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE
        ));
        $this->assertSame('high', $finding['confidence']);
    }

    public function test_stale_evidence_reduces_confidence_to_medium(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'last_updated_age_days' => 35,
            'next_leverage'         => '',
        ])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE
        ));
        $this->assertSame('medium', $finding['confidence']);
    }

    public function test_very_stale_evidence_reduces_confidence_to_low(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'last_updated_age_days' => 65,
            'next_leverage'         => '',
        ])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE
        ));
        $this->assertSame('low', $finding['confidence']);
    }

    public function test_stale_contradictory_finding_has_reduced_confidence(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry([
            'state'                   => 'integrated',
            'has_completion_evidence' => false,
            'last_updated_age_days'   => 40,
        ])]]);

        $finding = current(array_filter($result['findings'],
            fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY
        ));
        $this->assertSame('medium', $finding['confidence']);
        $this->assertNotSame('high', $finding['confidence'],
            'Stale evidence must not be treated as current truth');
    }

    // ── AC4: pure, deterministic, accepts supplied facts ─────────────────────

    public function test_output_is_deterministic(): void
    {
        $input = ['map_entries' => [$this->entry(['next_leverage' => ''])], 'queued_areas' => ['domain-a']];

        $this->assertSame(json_encode($this->detect($input)), json_encode($this->detect($input)));
    }

    public function test_findings_include_confidence_field(): void
    {
        $result = $this->detect(['map_entries' => [$this->entry(['next_leverage' => ''])]]);

        foreach ($result['findings'] as $finding) {
            $this->assertArrayHasKey('confidence', $finding, 'Every finding must include a confidence field');
            $this->assertContains($finding['confidence'], ['high', 'medium', 'low']);
        }
    }
}
