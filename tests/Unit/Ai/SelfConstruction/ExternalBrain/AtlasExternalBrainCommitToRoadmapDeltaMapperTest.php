<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCommitToRoadmapDeltaMapper;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCommitToRoadmapDeltaMapperTest extends TestCase
{
    private function mapper(): AtlasExternalBrainCommitToRoadmapDeltaMapper
    {
        return new AtlasExternalBrainCommitToRoadmapDeltaMapper;
    }

    private function realCapabilityCommit(array $overrides = []): array
    {
        return array_merge([
            'capability_id'          => 'queue_drain_forecast',
            'touched_files'          => ['app/Services/Ai/AtlasFoo.php'],
            'test_files'             => ['tests/Unit/Ai/AtlasFooTest.php'],
            'objective'              => 'add queue drain forecast dossier',
            'impact_class'           => 'real_capability',
            'has_behavior_evidence'  => true,
            'before_maturity'        => 'partial',
            'after_maturity'         => 'integrated',
        ], $overrides);
    }

    // ── AC1: links commit evidence to roadmap gap, capability family, proof strength, residual blocker ──

    public function test_matured_delta_links_roadmap_gap_and_capability_family_with_strong_proof_and_no_blocker(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit([
                'roadmap_gap_id' => 'gap-forecast-1',
                'capability_family' => 'queueing',
            ]),
        ]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('gap-forecast-1', $delta['roadmap_gap_id']);
        $this->assertSame('queueing', $delta['capability_family']);
        $this->assertSame('strong', $delta['proof_strength']);
        $this->assertNull($delta['residual_blocker']);
    }

    public function test_roadmap_gap_id_defaults_to_capability_id_when_absent(): void
    {
        $r = $this->mapper()->map(['commits' => [$this->realCapabilityCommit()]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('queue_drain_forecast', $delta['roadmap_gap_id']);
        $this->assertSame('unclassified', $delta['capability_family']);
    }

    public function test_refused_delta_names_residual_blocker_as_primary_reason(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['impact_class' => 'scaffolding']),
        ]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('impact_class=scaffolding_not_real_capability', $delta['residual_blocker']);
    }

    public function test_proof_strength_is_none_without_behavior_evidence(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['has_behavior_evidence' => false]),
        ]]);

        $this->assertSame('none', $r['roadmap_delta'][0]['proof_strength']);
    }

    public function test_proof_strength_is_weak_for_non_real_capability_with_evidence(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['impact_class' => 'observability']),
        ]]);

        $this->assertSame('weak', $r['roadmap_delta'][0]['proof_strength']);
    }

    // ── AC2: distinguishes closed, reduced, unchanged and contradicted roadmap gaps ──

    public function test_matured_delta_reaching_mature_is_closed(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['before_maturity' => 'integrated', 'after_maturity' => 'mature']),
        ]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('closed', $delta['gap_status']);
        $this->assertContains('queue_drain_forecast', $r['closed_gaps']);
    }

    public function test_matured_delta_not_reaching_mature_is_reduced(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['before_maturity' => 'partial', 'after_maturity' => 'integrated']),
        ]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('reduced', $delta['gap_status']);
        $this->assertContains('queue_drain_forecast', $r['reduced_gaps']);
    }

    public function test_refused_delta_with_same_maturity_claim_is_unchanged(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['before_maturity' => 'mature', 'after_maturity' => 'mature']),
        ]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('unchanged', $delta['gap_status']);
        $this->assertSame([], $r['contradicted_gaps']);
    }

    public function test_refused_delta_claiming_a_lower_maturity_is_contradicted(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['impact_class' => 'scaffolding', 'before_maturity' => 'integrated', 'after_maturity' => 'partial']),
        ]]);

        $delta = $r['roadmap_delta'][0];
        $this->assertSame('contradicted', $delta['gap_status']);
        $this->assertContains('queue_drain_forecast', $r['contradicted_gaps']);
    }

    // ── AC3: refuses to count green commits as roadmap progress without capability evidence ──

    public function test_green_commit_without_behavior_evidence_never_closes_or_reduces_a_gap(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['has_behavior_evidence' => false]),
        ]]);

        $this->assertFalse($r['roadmap_delta'][0]['matured']);
        $this->assertSame([], $r['closed_gaps']);
        $this->assertSame([], $r['reduced_gaps']);
    }

    public function test_output_has_new_keys_alongside_legacy_keys(): void
    {
        $r = $this->mapper()->map(['commits' => []]);

        foreach ([
            'schema', 'roadmap_delta', 'matured_capabilities', 'unchanged_claims', 'evidence_gaps',
            'next_roadmap_gap_candidates', 'closed_gaps', 'reduced_gaps', 'contradicted_gaps',
        ] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
    }

    public function test_map_remains_deterministic_with_new_fields(): void
    {
        $input = ['commits' => [$this->realCapabilityCommit(['roadmap_gap_id' => 'gap-1', 'capability_family' => 'queueing'])]];

        $first = $this->mapper()->map($input);
        $second = $this->mapper()->map($input);

        $this->assertSame($first, $second);
    }
}
