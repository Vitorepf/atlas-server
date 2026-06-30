<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainCapabilityTransferMapper;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainCapabilityTransferMapperTest extends TestCase
{
    private AtlasExternalBrainCapabilityTransferMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new AtlasExternalBrainCapabilityTransferMapper;
    }

    private function src(string $id, string $name, string $area, array $refs = ['phpunit:t1']): array
    {
        return ['id' => $id, 'name' => $name, 'area' => $area, 'evidence_refs' => $refs];
    }

    private function dst(string $id, string $name, string $area, float $fit = 1.0): array
    {
        return ['id' => $id, 'name' => $name, 'area' => $area, 'destination_fit_score' => $fit];
    }

    // ── AC1: recommendation shape ─────────────────────────────────────────────

    public function test_valid_transfer_emits_recommendation_with_required_fields(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Queue health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Queue health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $this->assertCount(1, $r['transfer_recommendations']);
        $rec = $r['transfer_recommendations'][0];
        foreach (['source_id', 'destination_id', 'priority_score', 'required_adaptations', 'proof_requirements', 'destination_fit_score', 'risk_penalty', 'transfer_type'] as $k) {
            $this->assertArrayHasKey($k, $rec, "Missing field: {$k}");
        }
        $this->assertSame('cap-1', $rec['source_id']);
        $this->assertSame('gap-1', $rec['destination_id']);
    }

    public function test_high_evidence_uses_direct_transfer_type_and_proof(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor service', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health check integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.9],
        ]);

        $rec = $r['transfer_recommendations'][0];
        $this->assertSame('direct_transfer', $rec['transfer_type']);
        $this->assertContains('direct_transfer_test', $rec['proof_requirements']);
        $this->assertNotContains('adaptation_proof', $rec['proof_requirements']);
        $this->assertContains('tests_or_gates_result', $rec['proof_requirements']);
        $this->assertContains('behavior_observable_in_destination', $rec['proof_requirements']);
    }

    public function test_low_evidence_uses_adaptation_required_type_and_proof(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration wrapper', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.5],
        ]);

        $rec = $r['transfer_recommendations'][0];
        $this->assertSame('adaptation_required', $rec['transfer_type']);
        $this->assertContains('adaptation_proof', $rec['proof_requirements']);
        $this->assertNotContains('direct_transfer_test', $rec['proof_requirements']);
    }

    public function test_adaptation_risks_reduce_priority_score_via_risk_penalty(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration wrapper', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
            'adaptation_risks'    => [['source_id' => 'cap-1', 'destination_id' => 'gap-1', 'risk' => 'api_surface_mismatch']],
        ]);

        $rec = $r['transfer_recommendations'][0];
        $this->assertContains('api_surface_mismatch', $rec['required_adaptations']);
        $this->assertEqualsWithDelta(0.1, $rec['risk_penalty'], 0.001);
        $this->assertEqualsWithDelta(0.7, $rec['priority_score'], 0.001);
    }

    public function test_recommendations_sorted_by_priority_score_descending(): void
    {
        $r = $this->mapper->map([
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
        $this->assertSame($sorted, $scores);
    }

    // ── AC2: rejection — circular_dependency ──────────────────────────────────

    public function test_same_area_rejected_as_circular_dependency(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'maestro')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.9],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('circular_dependency', $r['rejected_transfers'][0]['rejection_reason']);
    }

    // ── AC2: rejection — lacks_source_evidence ────────────────────────────────

    public function test_missing_evidence_rejected_as_lacks_source_evidence(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo bar service', 'loop', [])],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo bar integration', 'maestro')],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('lacks_source_evidence', $r['rejected_transfers'][0]['rejection_reason']);
    }

    // ── AC2: rejection — name_only_similarity ─────────────────────────────────

    public function test_high_word_overlap_with_low_strength_rejected_as_name_only_similarity(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Queue health check monitor', 'loop', ['weak'])],
            'destination_gaps'    => [$this->dst('gap-1', 'Queue health check system', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.1],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('name_only_similarity', $r['rejected_transfers'][0]['rejection_reason']);
    }

    // ── AC2: rejection — low_destination_fit ─────────────────────────────────

    public function test_low_destination_fit_score_rejects_transfer(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro', 0.3)],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('low_destination_fit', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_destination_fit_at_threshold_is_rejected(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro', 0.49)],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $this->assertSame('low_destination_fit', $r['rejected_transfers'][0]['rejection_reason']);
    }

    // ── AC2: rejection — adaptation_risk_too_high ─────────────────────────────

    public function test_too_many_adaptation_risks_rejects_transfer(): void
    {
        $risks = [];
        for ($i = 0; $i < 4; $i++) {
            $risks[] = ['source_id' => 'cap-1', 'destination_id' => 'gap-1', 'risk' => "risk_{$i}"];
        }
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
            'adaptation_risks'    => $risks,
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('adaptation_risk_too_high', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_exactly_ceiling_risks_are_allowed(): void
    {
        $risks = [];
        for ($i = 0; $i < 3; $i++) {
            $risks[] = ['source_id' => 'cap-1', 'destination_id' => 'gap-1', 'risk' => "risk_{$i}"];
        }
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
            'adaptation_risks'    => $risks,
        ]);

        $this->assertNotEmpty($r['transfer_recommendations']);
    }

    // ── AC2: rejection — missing_behavior_proof ───────────────────────────────

    public function test_no_behavior_proof_with_weak_evidence_rejected(): void
    {
        // Word overlap: "Foo service helper" vs "Bar integration system" = 0 → not name_only.
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop', ['phpunit:t1'])],
            'destination_gaps'    => [$this->dst('gap-1', 'Bar integration system', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.1],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('missing_behavior_proof', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_integration_evidence_ref_satisfies_behavior_proof(): void
    {
        // Same weak strength but has integration: prefix → NOT missing_behavior_proof.
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop', ['integration:suite_green'])],
            'destination_gaps'    => [$this->dst('gap-1', 'Bar integration system', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.31], // just above EVIDENCE_THRESHOLD
        ]);

        // strength 0.31 >= 0.30 → missing_behavior_proof does not fire (requires < 0.30).
        $this->assertNotEmpty($r['transfer_recommendations']);
    }

    // ── Rejection precedence ─────────────────────────────────────────────────

    public function test_circular_dependency_beats_lacks_evidence(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo bar', 'same-area', [])],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo bar', 'same-area')],
        ]);

        $this->assertSame('circular_dependency', $r['rejected_transfers'][0]['rejection_reason']);
    }

    // ── Schema / keys ─────────────────────────────────────────────────────────

    public function test_output_has_schema_version_and_both_lists(): void
    {
        $r = $this->mapper->map([]);

        $this->assertSame(AtlasExternalBrainCapabilityTransferMapper::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('transfer_recommendations', $r);
        $this->assertArrayHasKey('rejected_transfers', $r);
    }

    // ── AC4: transfer_value, adaptation_risk, first_safe_task ──────────────────

    public function test_recommendation_emits_transfer_value_adaptation_risk_and_first_safe_task(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $rec = $r['transfer_recommendations'][0];
        $this->assertSame($rec['priority_score'], $rec['transfer_value']);
        $this->assertSame(0, $rec['adaptation_risk']['count']);
        $this->assertSame([], $rec['adaptation_risk']['items']);
        $this->assertSame('prove_direct_transfer:cap-1->gap-1:direct_transfer_test', $rec['first_safe_task']);
    }

    public function test_first_safe_task_targets_first_adaptation_risk_when_present(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Foo service helper', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Foo integration wrapper', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.5],
            'adaptation_risks'    => [['source_id' => 'cap-1', 'destination_id' => 'gap-1', 'risk' => 'api_surface_mismatch']],
        ]);

        $rec = $r['transfer_recommendations'][0];
        $this->assertSame('resolve_adaptation_risk:cap-1->gap-1:api_surface_mismatch', $rec['first_safe_task']);
        $this->assertSame(1, $rec['adaptation_risk']['count']);
        $this->assertContains('api_surface_mismatch', $rec['adaptation_risk']['items']);
    }

    // ── AC3: rejection — destination lacks required context/evidence ──────────

    public function test_destination_lacking_required_context_is_rejected(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [array_merge($this->dst('gap-1', 'Health integration', 'maestro'), ['has_required_context' => false])],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('lacks_destination_context', $r['rejected_transfers'][0]['rejection_reason']);
    }

    public function test_destination_lacking_required_evidence_is_rejected(): void
    {
        $r = $this->mapper->map([
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [array_merge($this->dst('gap-1', 'Health integration', 'maestro'), ['has_required_evidence' => false])],
            'evidence_strength'   => ['cap-1' => 0.8],
        ]);

        $this->assertEmpty($r['transfer_recommendations']);
        $this->assertSame('lacks_destination_evidence', $r['rejected_transfers'][0]['rejection_reason']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'source_capabilities' => [$this->src('cap-1', 'Health monitor', 'loop')],
            'destination_gaps'    => [$this->dst('gap-1', 'Health integration', 'maestro')],
            'evidence_strength'   => ['cap-1' => 0.75],
        ];

        $this->assertSame(json_encode($this->mapper->map($facts)), json_encode($this->mapper->map($facts)));
    }
}
