<?php

declare(strict_types=1);

namespace Tests\Feature\Ai;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCommitToRoadmapDeltaMapper;
use Tests\TestCase;

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

    public function test_output_has_required_keys(): void
    {
        $r = $this->mapper()->map(['commits' => []]);

        foreach (['schema', 'roadmap_delta', 'matured_capabilities', 'unchanged_claims', 'evidence_gaps', 'next_roadmap_gap_candidates'] as $key) {
            $this->assertArrayHasKey($key, $r);
        }
        $this->assertSame(AtlasExternalBrainCommitToRoadmapDeltaMapper::SCHEMA, $r['schema']);
    }

    public function test_real_capability_with_evidence_and_maturity_progress_matures_capability(): void
    {
        $r = $this->mapper()->map(['commits' => [$this->realCapabilityCommit()]]);

        $this->assertContains('queue_drain_forecast', $r['matured_capabilities']);
        $this->assertSame([], $r['unchanged_claims']);
        $this->assertSame([], $r['evidence_gaps']);
        $delta = $r['roadmap_delta'][0];
        $this->assertTrue($delta['matured']);
        $this->assertSame('integrated', $delta['after_maturity']);
    }

    public function test_scaffolding_impact_class_refuses_maturity_claim(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['impact_class' => 'scaffolding']),
        ]]);

        $this->assertContains('queue_drain_forecast', $r['unchanged_claims']);
        $this->assertNotContains('queue_drain_forecast', $r['matured_capabilities']);
        $delta = $r['roadmap_delta'][0];
        $this->assertFalse($delta['matured']);
        $this->assertSame('partial', $delta['after_maturity']); // claim refused, stays at before_maturity
        $this->assertNotEmpty($r['evidence_gaps']);
    }

    public function test_test_only_commit_refuses_maturity_claim(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit([
                'touched_files' => ['tests/Unit/Ai/AtlasFooTest.php'],
                'test_files' => ['tests/Unit/Ai/AtlasFooTest.php'],
            ]),
        ]]);

        $this->assertContains('queue_drain_forecast', $r['unchanged_claims']);
        $this->assertStringContainsString('test_only_commit', implode(',', $r['evidence_gaps']));
    }

    public function test_unverified_output_without_behavior_evidence_refuses_maturity_claim(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['has_behavior_evidence' => false]),
        ]]);

        $this->assertContains('queue_drain_forecast', $r['unchanged_claims']);
        $this->assertStringContainsString('no_behavior_evidence', implode(',', $r['evidence_gaps']));
    }

    public function test_after_maturity_not_exceeding_before_maturity_refuses_claim(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['before_maturity' => 'mature', 'after_maturity' => 'mature']),
        ]]);

        $this->assertContains('queue_drain_forecast', $r['unchanged_claims']);
    }

    public function test_refused_capability_becomes_next_roadmap_gap_candidate(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['impact_class' => 'observability']),
        ]]);

        $this->assertContains('queue_drain_forecast', $r['next_roadmap_gap_candidates']);
    }

    public function test_mixed_batch_separates_matured_and_unchanged(): void
    {
        $r = $this->mapper()->map(['commits' => [
            $this->realCapabilityCommit(['capability_id' => 'good_one']),
            $this->realCapabilityCommit(['capability_id' => 'bad_one', 'impact_class' => 'scaffolding']),
        ]]);

        $this->assertContains('good_one', $r['matured_capabilities']);
        $this->assertContains('bad_one', $r['unchanged_claims']);
        $this->assertCount(2, $r['roadmap_delta']);
    }

    public function test_empty_commits_returns_safe_empty_result(): void
    {
        $r = $this->mapper()->map(['commits' => []]);

        $this->assertSame([], $r['roadmap_delta']);
        $this->assertSame([], $r['matured_capabilities']);
    }

    public function test_map_is_deterministic(): void
    {
        $input = ['commits' => [$this->realCapabilityCommit()]];

        $first = $this->mapper()->map($input);
        $second = $this->mapper()->map($input);

        $this->assertSame($first, $second);
    }
}
