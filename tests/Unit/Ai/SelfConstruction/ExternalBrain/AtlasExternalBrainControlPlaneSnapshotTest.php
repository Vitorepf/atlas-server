<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainControlPlaneSnapshot;
use Tests\TestCase;

final class AtlasExternalBrainControlPlaneSnapshotTest extends TestCase
{
    private function snap(): AtlasExternalBrainControlPlaneSnapshot
    {
        return new AtlasExternalBrainControlPlaneSnapshot;
    }

    private function healthyInputs(): array
    {
        return [
            'rubric_scores'  => ['capability_unlock' => 0.8, 'dependency_unblock' => 0.7, 'implementation_evidence' => 0.9],
            'queue_health'   => ['status' => 'healthy', 'give_back_rate' => 0.05],
            'audit_result'   => ['verdict' => 'pass', 'findings' => []],
            'ledger_summary' => ['total' => 20, 'success_rate' => 0.75],
            'stalled_yield'  => false,
            'known_capabilities' => [],
        ];
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.control_plane_snapshot.v1',
            AtlasExternalBrainControlPlaneSnapshot::SCHEMA,
        );
    }

    public function test_output_has_canonical_keys(): void
    {
        $result = $this->snap()->snapshot($this->healthyInputs());

        foreach (['schema', 'maturity_band', 'maturity_score', 'blockers', 'next_actions', 'evidence_gaps', 'next_missing_capabilities', 'dimensions'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainControlPlaneSnapshot::SCHEMA, $result['schema']);
    }

    public function test_healthy_inputs_yield_high_maturity_no_blockers(): void
    {
        $result = $this->snap()->snapshot($this->healthyInputs());

        $this->assertContains($result['maturity_band'], [
            AtlasExternalBrainControlPlaneSnapshot::BAND_ADVANCED,
            AtlasExternalBrainControlPlaneSnapshot::BAND_AUTONOMOUS,
        ]);
        $this->assertSame([], $result['blockers']);
        $this->assertSame([], $result['evidence_gaps']);
        $this->assertGreaterThanOrEqual(0.7, $result['maturity_score']);
    }

    public function test_missing_ledger_lowers_maturity_to_emerging_and_names_blocker(): void
    {
        $inputs = array_merge($this->healthyInputs(), ['ledger_summary' => null]);

        $result = $this->snap()->snapshot($inputs);

        $dimensions = ['bootstrapping', 'emerging'];
        $this->assertContains($result['maturity_band'], $dimensions);

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('ledger', $blockerDimensions);
        $this->assertContains('learning_ledger', $result['evidence_gaps']);
    }

    public function test_stalled_queue_caps_maturity_at_emerging(): void
    {
        $inputs = array_merge($this->healthyInputs(), ['queue_health' => ['status' => 'stalled']]);

        $result = $this->snap()->snapshot($inputs);

        $this->assertContains($result['maturity_band'], [
            AtlasExternalBrainControlPlaneSnapshot::BAND_BOOTSTRAPPING,
            AtlasExternalBrainControlPlaneSnapshot::BAND_EMERGING,
        ]);

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('queue_health', $blockerDimensions);

        $queueBlocker = array_values(array_filter($result['blockers'], static fn (array $b): bool => $b['dimension'] === 'queue_health'))[0];
        $this->assertSame('stalled', $queueBlocker['status']);
    }

    public function test_degraded_queue_caps_maturity_at_functional(): void
    {
        $inputs = array_merge($this->healthyInputs(), ['queue_health' => ['status' => 'degraded']]);

        $result = $this->snap()->snapshot($inputs);

        $allowedBands = [
            AtlasExternalBrainControlPlaneSnapshot::BAND_BOOTSTRAPPING,
            AtlasExternalBrainControlPlaneSnapshot::BAND_EMERGING,
            AtlasExternalBrainControlPlaneSnapshot::BAND_FUNCTIONAL,
        ];
        $this->assertContains($result['maturity_band'], $allowedBands);

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('queue_health', $blockerDimensions);
    }

    public function test_audit_reject_caps_maturity_and_adds_evidence_gap(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'audit_result' => ['verdict' => 'reject', 'findings' => [['finding' => 'template_farm']]],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $allowedBands = [
            AtlasExternalBrainControlPlaneSnapshot::BAND_BOOTSTRAPPING,
            AtlasExternalBrainControlPlaneSnapshot::BAND_EMERGING,
            AtlasExternalBrainControlPlaneSnapshot::BAND_FUNCTIONAL,
        ];
        $this->assertContains($result['maturity_band'], $allowedBands);

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('batch_quality_audit', $blockerDimensions);
        $this->assertContains('batch_quality_proof', $result['evidence_gaps']);
    }

    public function test_stalled_yield_lowers_maturity_by_one_band(): void
    {
        // High rubric → advanced; stalled_yield should lower by 1 → functional.
        $inputs = array_merge($this->healthyInputs(), ['stalled_yield' => true]);

        $result = $this->snap()->snapshot($inputs);

        $blockerDimensions = array_column($result['blockers'], 'dimension');
        $this->assertContains('yield', $blockerDimensions);
        $this->assertContains('yield_recovery_evidence', $result['evidence_gaps']);
        // Band must be lower than advanced.
        $this->assertNotSame(AtlasExternalBrainControlPlaneSnapshot::BAND_AUTONOMOUS, $result['maturity_band']);
    }

    public function test_next_actions_populated_for_every_blocker(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'ledger_summary' => null,
            'queue_health'   => ['status' => 'stalled'],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $this->assertNotEmpty($result['next_actions']);
        $this->assertGreaterThanOrEqual(2, count($result['next_actions']));
        foreach ($result['next_actions'] as $action) {
            $this->assertIsString($action);
            $this->assertNotEmpty($action);
        }
    }

    public function test_next_missing_capabilities_lists_low_scoring_dimensions(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'rubric_scores' => [
                'capability_unlock'       => 0.9,
                'dependency_unblock'      => 0.3,   // below 0.5 → missing
                'implementation_evidence' => 0.2,   // below 0.5 → missing
            ],
            'known_capabilities' => ['dependency_unblock'],  // already proven → excluded
        ]);

        $result = $this->snap()->snapshot($inputs);

        $missingCaps = array_column($result['next_missing_capabilities'], 'capability');
        $this->assertContains('implementation_evidence', $missingCaps);
        $this->assertNotContains('capability_unlock',   $missingCaps);  // above threshold
        $this->assertNotContains('dependency_unblock',  $missingCaps);  // in known_capabilities
    }

    public function test_next_missing_capabilities_ordered_by_score_ascending(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'rubric_scores' => ['a' => 0.1, 'b' => 0.4, 'c' => 0.2],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $scores = array_column($result['next_missing_capabilities'], 'current_score');
        $sorted  = $scores;
        sort($sorted);
        $this->assertSame($sorted, $scores);
    }

    public function test_empty_inputs_returns_bootstrapping(): void
    {
        $result = $this->snap()->snapshot([]);

        $this->assertSame(AtlasExternalBrainControlPlaneSnapshot::BAND_BOOTSTRAPPING, $result['maturity_band']);
        $this->assertNotEmpty($result['blockers']);
        $this->assertNotEmpty($result['evidence_gaps']);
    }

    public function test_dimensions_block_reflects_inputs(): void
    {
        $result = $this->snap()->snapshot($this->healthyInputs());

        $dims = $result['dimensions'];
        $this->assertTrue($dims['ledger_present']);
        $this->assertSame('healthy', $dims['queue_status']);
        $this->assertSame('pass', $dims['audit_verdict']);
        $this->assertFalse($dims['stalled_yield']);
    }

    // --- domain_map tests ---

    public function test_snapshot_includes_domain_map_key(): void
    {
        $result = $this->snap()->snapshot($this->healthyInputs());

        $this->assertArrayHasKey('domain_map', $result);
        $this->assertIsArray($result['domain_map']);
    }

    public function test_no_domain_facts_produces_empty_domain_map(): void
    {
        $result = $this->snap()->snapshot($this->healthyInputs());

        $this->assertSame([], $result['domain_map']);
    }

    public function test_fully_populated_domain_fact_preserved_verbatim(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'domain_facts' => [[
                'area'          => 'ACOS',
                'maturity'      => 'emerging',
                'risk'          => 'medium',
                'owner_signal'  => 'degraded',
                'current_gap'   => 'failing tests in ACOS pipeline',
                'next_lever'    => 'wire LeverageScorer into SelfImprovementCycle',
                'evidence_refs' => ['doc:engineering-kb/acos.md', 'scan:orphan-2026-06-30'],
            ]],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $this->assertCount(1, $result['domain_map']);
        $area = $result['domain_map'][0];
        $this->assertSame('ACOS',             $area['area']);
        $this->assertSame('emerging',         $area['maturity']);
        $this->assertSame('medium',           $area['risk']);
        $this->assertSame('degraded',         $area['owner_signal']);
        $this->assertSame('failing tests in ACOS pipeline',          $area['current_gap']);
        $this->assertSame('wire LeverageScorer into SelfImprovementCycle', $area['next_lever']);
        $this->assertContains('doc:engineering-kb/acos.md', $area['evidence_refs']);
    }

    public function test_missing_optional_fields_produce_explicit_sentinels(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'domain_facts' => [[
                'area' => 'EvolutionLoop',
                // maturity, risk, owner_signal, current_gap, next_lever, evidence_refs all absent
            ]],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $area = $result['domain_map'][0];
        $this->assertSame('unknown', $area['maturity'],     'missing maturity must be unknown');
        $this->assertSame('unknown', $area['risk'],         'missing risk must be unknown');
        $this->assertSame('missing', $area['owner_signal'], 'missing owner_signal must be missing');
        $this->assertSame('missing', $area['current_gap'],  'missing current_gap must be missing');
        $this->assertSame('missing', $area['next_lever'],   'missing next_lever must be missing');
        $this->assertSame([],        $area['evidence_refs'], 'missing evidence_refs must be []');
    }

    public function test_blank_evidence_refs_are_filtered_out(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'domain_facts' => [[
                'area'          => 'AAEOS',
                'evidence_refs' => ['valid-ref', '', '  ', 'another-ref'],
            ]],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $this->assertSame(['valid-ref', 'another-ref'], $result['domain_map'][0]['evidence_refs']);
    }

    public function test_entry_with_missing_area_is_skipped(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'domain_facts' => [
                ['maturity' => 'emerging'],  // no area key → skip
                ['area' => '', 'maturity' => 'advanced'],  // empty area → skip
                ['area' => 'Forge', 'maturity' => 'functional'],  // valid
            ],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $this->assertCount(1, $result['domain_map']);
        $this->assertSame('Forge', $result['domain_map'][0]['area']);
    }

    public function test_multiple_domain_areas_all_present(): void
    {
        $inputs = array_merge($this->healthyInputs(), [
            'domain_facts' => [
                ['area' => 'ACOS',          'maturity' => 'emerging',   'risk' => 'high'],
                ['area' => 'EvolutionLoop', 'maturity' => 'functional', 'risk' => 'medium'],
                ['area' => 'Forge',         'maturity' => 'advanced',   'risk' => 'low'],
            ],
        ]);

        $result = $this->snap()->snapshot($inputs);

        $this->assertCount(3, $result['domain_map']);
        $areas = array_column($result['domain_map'], 'area');
        $this->assertContains('ACOS',          $areas);
        $this->assertContains('EvolutionLoop', $areas);
        $this->assertContains('Forge',         $areas);
    }
}
