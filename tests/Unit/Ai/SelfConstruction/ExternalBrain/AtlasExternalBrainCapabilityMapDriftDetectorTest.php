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

    private function entry(
        string $id,
        string $state = 'known',
        int $ageDays = 5,
        bool $evidence = true,
        string $owner = 'default-owner',
        string $maturityBand = 'functional',
    ): array {
        return [
            'area_id'                 => $id,
            'state'                   => $state,
            'last_updated_age_days'   => $ageDays,
            'has_completion_evidence' => $evidence,
            'owner'                   => $owner,
            'maturity_band'           => $maturityBand,
            'next_leverage'           => 'default-next-leverage',
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

    // ── missing_owner ─────────────────────────────────────────────────────────

    public function test_missing_owner_flagged_with_evidence_needed(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [array_merge($this->entry('auth'), ['owner' => ''])],
            'queued_areas' => [],
        ]);

        $f = $this->findingFor($r, 'auth');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_OWNER, $f['drift_type']);
        $this->assertContains('owner_assignment', $f['evidence_needed']);
        $this->assertContains('domain_mapping', $f['evidence_needed']);
    }

    public function test_entry_with_owner_not_flagged_as_missing_owner(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('auth')], // owner='default-owner'
            'queued_areas' => [],
        ]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_OWNER, $driftTypes);
    }

    // ── missing_maturity_band ─────────────────────────────────────────────────

    public function test_missing_maturity_band_flagged_with_evidence_needed(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [array_merge($this->entry('ledger'), ['maturity_band' => ''])],
            'queued_areas' => [],
        ]);

        $f = $this->findingFor($r, 'ledger');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_MATURITY_BAND, $f['drift_type']);
        $this->assertContains('maturity_assessment', $f['evidence_needed']);
        $this->assertContains('capability_evaluation', $f['evidence_needed']);
    }

    public function test_entry_with_maturity_band_not_flagged(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('ledger')], // maturity_band='functional'
            'queued_areas' => [],
        ]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_MATURITY_BAND, $driftTypes);
    }

    // ── stale_owner_evidence ──────────────────────────────────────────────────

    public function test_stale_owner_evidence_flagged(): void
    {
        $threshold = AtlasExternalBrainCapabilityMapDriftDetector::STALE_AGE_THRESHOLD_DAYS;
        $entry = array_merge($this->entry('billing'), ['owner_evidence_age_days' => $threshold + 1]);

        $r = $this->svc()->detect(['map_entries' => [$entry], 'queued_areas' => []]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_STALE_OWNER_EVIDENCE, $driftTypes);

        $f = $this->findingFor($r, 'billing');
        $this->assertContains('owner_revalidation', $f['evidence_needed']);
    }

    public function test_fresh_owner_evidence_not_flagged(): void
    {
        $entry = array_merge($this->entry('billing'), ['owner_evidence_age_days' => 5]);

        $r = $this->svc()->detect(['map_entries' => [$entry], 'queued_areas' => []]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_STALE_OWNER_EVIDENCE, $driftTypes);
    }

    // ── maturity_regression ───────────────────────────────────────────────────

    public function test_maturity_regression_without_follow_up_flagged(): void
    {
        $entry = array_merge($this->entry('core', 'known', 5, true, 'team-a', 'emerging'), [
            'previous_maturity_band' => 'advanced',
            'has_follow_up_task'     => false,
        ]);

        $r = $this->svc()->detect(['map_entries' => [$entry], 'queued_areas' => []]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MATURITY_REGRESSION, $driftTypes);

        $f = array_values(array_filter($r['findings'], fn ($f) => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MATURITY_REGRESSION))[0];
        $this->assertContains('regression_root_cause', $f['evidence_needed']);
        $this->assertContains('follow_up_task_ref', $f['evidence_needed']);
    }

    public function test_maturity_regression_with_follow_up_not_flagged(): void
    {
        $entry = array_merge($this->entry('core', 'known', 5, true, 'team-a', 'emerging'), [
            'previous_maturity_band' => 'advanced',
            'has_follow_up_task'     => true,
        ]);

        $r = $this->svc()->detect(['map_entries' => [$entry], 'queued_areas' => []]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MATURITY_REGRESSION, $driftTypes);
    }

    public function test_no_regression_when_current_band_is_same_or_higher(): void
    {
        $entry = array_merge($this->entry('core', 'known', 5, true, 'team-a', 'advanced'), [
            'previous_maturity_band' => 'emerging',
            'has_follow_up_task'     => false,
        ]);

        $r = $this->svc()->detect(['map_entries' => [$entry], 'queued_areas' => []]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MATURITY_REGRESSION, $driftTypes);
    }

    // ── retired_blocked_queue_conflict ────────────────────────────────────────

    public function test_queued_area_targeting_retired_map_entry_is_high_impact(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('old-module', 'retired')],
            'queued_areas' => ['old-module'],
        ]);

        $f = $this->findingFor($r, 'old-module');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_RETIRED_BLOCKED_QUEUE, $f['drift_type']);
        $this->assertSame('high', $f['impact_level']);
        $this->assertContains('queue_redirect', $f['evidence_needed']);
    }

    public function test_queued_area_targeting_blocked_map_entry_is_high_impact(): void
    {
        $r = $this->svc()->detect([
            'map_entries' => [$this->entry('gated-module', 'blocked')],
            'queued_areas' => ['gated-module'],
        ]);

        $f = $this->findingFor($r, 'gated-module');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_RETIRED_BLOCKED_QUEUE, $f['drift_type']);
        $this->assertSame('high', $f['impact_level']);
    }

    public function test_retired_blocked_queue_conflict_ranks_above_stale_entries(): void
    {
        $threshold = AtlasExternalBrainCapabilityMapDriftDetector::STALE_AGE_THRESHOLD_DAYS;
        $r = $this->svc()->detect([
            'map_entries' => [
                $this->entry('stale-area', 'known', $threshold + 5),
                $this->entry('retired-area', 'retired', 5),
            ],
            'queued_areas' => ['retired-area'],
        ]);

        $impacts = array_column($r['findings'], 'impact_level');
        $highIdx = array_search('high', $impacts, true);
        $medIdx  = array_search('medium', $impacts, true);
        $this->assertLessThan($medIdx, $highIdx);
    }

    public function test_queued_area_in_active_map_state_not_flagged_as_conflict(): void
    {
        foreach (['integrated', 'known', 'in_progress'] as $state) {
            $r = $this->svc()->detect([
                'map_entries' => [$this->entry('area', $state)],
                'queued_areas' => ['area'],
            ]);

            $driftTypes = array_column($r['findings'], 'drift_type');
            $this->assertNotContains(
                AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_RETIRED_BLOCKED_QUEUE,
                $driftTypes,
                "state={$state} should not trigger retired_blocked_queue_conflict",
            );
        }
    }

    // ── AC1: missing_next_leverage ──────────────────────────────────────────────

    public function test_missing_next_leverage_on_active_area_flagged_with_evidence_needed(): void
    {
        $entry = array_merge($this->entry('discovery'), ['next_leverage' => '']);

        $r = $this->svc()->detect(['map_entries' => [$entry], 'queued_areas' => []]);

        $f = $this->findingFor($r, 'discovery');
        $this->assertNotNull($f);
        $this->assertSame(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE, $f['drift_type']);
        $this->assertContains('leverage_assessment', $f['evidence_needed']);
        $this->assertContains('next_opportunity_scan', $f['evidence_needed']);
    }

    public function test_present_next_leverage_not_flagged(): void
    {
        $r = $this->svc()->detect(['map_entries' => [$this->entry('discovery')], 'queued_areas' => []]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertNotContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MISSING_NEXT_LEVERAGE, $driftTypes);
    }

    // ── AC2: recent bad outcomes contradict claimed advanced/integrated maturity ──

    public function test_failed_outcomes_flag_contradictory_even_when_state_is_integrated(): void
    {
        $entry = $this->entry('payments', 'integrated', 5, true);

        $r = $this->svc()->detect([
            'map_entries' => [$entry],
            'queued_areas' => [],
            'outcomes' => [
                ['area_id' => 'payments', 'result' => 'failure'],
                ['area_id' => 'payments', 'result' => 'give_back'],
            ],
        ]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_CONTRADICTORY, $driftTypes);
    }

    public function test_failed_outcomes_flag_maturity_regression_when_band_is_advanced_but_state_not_integrated(): void
    {
        $entry = $this->entry('billing', 'known', 5, true, 'team-a', 'advanced');

        $r = $this->svc()->detect([
            'map_entries' => [$entry],
            'queued_areas' => [],
            'outcomes' => [
                ['area_id' => 'billing', 'result' => 'poison'],
                ['area_id' => 'billing', 'result' => 'failure'],
            ],
        ]);

        $driftTypes = array_column($r['findings'], 'drift_type');
        $this->assertContains(AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_MATURITY_REGRESSION, $driftTypes);
    }

    public function test_mostly_successful_outcomes_do_not_contradict_advanced_maturity(): void
    {
        $entry = $this->entry('reporting', 'integrated', 5, true);

        $r = $this->svc()->detect([
            'map_entries' => [$entry],
            'queued_areas' => [],
            'outcomes' => [
                ['area_id' => 'reporting', 'result' => 'success'],
                ['area_id' => 'reporting', 'result' => 'success'],
                ['area_id' => 'reporting', 'result' => 'failure'],
            ],
        ]);

        $this->assertNull($this->findingFor($r, 'reporting'));
    }

    // ── AC3: successful evidence improves completion-drift confidence, owner staleness unaffected ──

    public function test_successful_outcomes_boost_stale_finding_confidence(): void
    {
        $threshold = AtlasExternalBrainCapabilityMapDriftDetector::STALE_AGE_THRESHOLD_DAYS;
        $entryNoOutcome = $this->entry('search', 'known', $threshold + 1, true);
        $entryWithSuccess = $this->entry('search-2', 'known', $threshold + 1, true);

        $rNoOutcome = $this->svc()->detect(['map_entries' => [$entryNoOutcome], 'queued_areas' => []]);
        $rWithSuccess = $this->svc()->detect([
            'map_entries' => [$entryWithSuccess],
            'queued_areas' => [],
            'outcomes' => [['area_id' => 'search-2', 'result' => 'success']],
        ]);

        $baseConfidence = $this->findingFor($rNoOutcome, 'search')['confidence'];
        $boostedConfidence = $this->findingFor($rWithSuccess, 'search-2')['confidence'];

        $this->assertSame('medium', $baseConfidence);
        $this->assertSame('high', $boostedConfidence);
    }

    public function test_stale_owner_evidence_confidence_is_not_boosted_by_successful_outcomes(): void
    {
        $threshold = AtlasExternalBrainCapabilityMapDriftDetector::STALE_AGE_THRESHOLD_DAYS;
        $entry = array_merge(
            $this->entry('billing', 'known', $threshold + 1, true),
            ['owner_evidence_age_days' => $threshold + 1],
        );

        $r = $this->svc()->detect([
            'map_entries' => [$entry],
            'queued_areas' => [],
            'outcomes' => [['area_id' => 'billing', 'result' => 'success']],
        ]);

        $ownerFinding = array_values(array_filter(
            $r['findings'],
            static fn (array $f): bool => $f['drift_type'] === AtlasExternalBrainCapabilityMapDriftDetector::DRIFT_STALE_OWNER_EVIDENCE,
        ))[0];

        // Owner staleness is still reported, and its confidence reflects the raw age, NOT
        // boosted by the unrelated successful outcome — stale ownership is never hidden.
        $this->assertSame('medium', $ownerFinding['confidence']);
    }
}
