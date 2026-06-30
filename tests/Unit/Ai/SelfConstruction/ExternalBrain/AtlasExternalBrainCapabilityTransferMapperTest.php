<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityTransferMapper;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityTransferMapperTest extends TestCase
{
    private function mapper(): AtlasExternalBrainCapabilityTransferMapper
    {
        return new AtlasExternalBrainCapabilityTransferMapper;
    }

    private function src(string $id, string $name, string $area, array $refs = ['phpunit:t1']): array
    {
        return ['id' => $id, 'name' => $name, 'area' => $area, 'evidence_refs' => $refs];
    }

    private function dst(string $id, string $name, string $area): array
    {
        return ['id' => $id, 'name' => $name, 'area' => $area];
    }

    // ── AC1: recommendation shape ─────────────────────────────────────────────

    public function test_valid_transfer_emits_recommendation_with_required_fields(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Queue health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Queue health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $this->assertCount(1, $r['transfer_recommendations']);
        $rec = $r['transfer_recommendations'][0];
        $this->assertSame('cap-1', $rec['source_id']);
        $this->assertSame('gap-1', $rec['destination_id']);
        $this->assertArrayHasKey('priority_score', $rec);
        $this->assertArrayHasKey('required_adaptations', $rec);
        $this->assertArrayHasKey('proof_requirements', $rec);
    }

    public function test_high_evidence_includes_direct_transfer_test_in_proof_requirements(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor service', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health check integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.9],
        ]);

        $proofReqs = $r['transfer_recommendations'][0]['proof_requirements'];
        $this->assertContains('tests_or_gates_result', $proofReqs);
        $this->assertContains('behavior_observable_in_destination', $proofReqs);
        $this->assertContains('direct_transfer_test', $proofReqs);
        $this->assertNotContains('adaptation_proof', $proofReqs);
    }

    public function test_low_evidence_includes_adaptation_proof_in_proof_requirements(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration wrapper', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.5],
        ]);

        $proofReqs = $r['transfer_recommendations'][0]['proof_requirements'];
        $this->assertContains('adaptation_proof', $proofReqs);
        $this->assertNotContains('direct_transfer_test', $proofReqs);
    }

    public function test_adaptation_risks_included_and_reduce_priority_score(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration wrapper', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
            'adaptation_risks'    => [['source_id' => 'cap-1', 'destination_id' => 'gap-1', 'risk' => 'api_surface_mismatch']],
        ]);

        $rec = $r['transfer_recommendations'][0];
        $this->assertContains('api_surface_mismatch', $rec['required_adaptations']);
        $this->assertLessThan(0.8, $rec['priority_score']); // penalty applied
    }

    public function test_recommendations_sorted_by_priority_score_descending(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [
                $this->src('cap-high', 'Alpha capability', 'loop'),
                $this->src('cap-low', 'Beta capability feature', 'loop'),
            ],
            'destination_gaps' => [$this->dst('gap-1', 'Alpha integration', 'maestro')],
            'evidence_strength' => ['cap-high' => 0.9, 'cap-low' => 0.4],
        ]);

        $scores = array_column($r['transfer_recommendations'], 'priority_score');
        $sorted = $scores;
        rsort($sorted);
        $this->assertSame($sorted, $scores, 'recommendations must be sorted by priority_score descending');
    }

    // ── AC2: rejections ───────────────────────────────────────────────────────

    public function test_same_area_rejected_as_circular_dependency(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'maestro')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.9],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertCount(1, $r['rejected_transfers']);
        $this->assertSame('circular_dependency', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_missing_evidence_rejected_as_lacks_source_evidence(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo bar service', 'loop', [])],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo bar integration', 'maestro')],
            // no evidence_strength → 0.0
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('lacks_source_evidence', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_high_word_overlap_with_low_strength_rejected_as_name_only_similarity(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Queue health check monitor', 'loop', ['weak'])],
            'destination_gaps'    => [$this->dst('gap-1', 'Queue health check system', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.1],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('name_only_similarity', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_circular_dependency_takes_precedence_over_lacks_evidence(): void
    {
        $r = $this->mapper()->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo bar', 'same-area', [])],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo bar', 'same-area')],
        ]);

        $this->assertSame('circular_dependency', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_output_always_has_schema_version_and_both_lists(): void
    {
        $r = $this->mapper()->map([]);
        $this->assertSame(AtlasExternalBrainCapabilityTransferMapper::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('transfer_recommendations', $r);
        $this->assertArrayHasKey('rejected_transfers', $r);
    }

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.75],
        ];
        $a = $this->mapper()->map($facts);
        $b = $this->mapper()->map($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }
}
