<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSimplificationAntiFarmGate;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainSimplificationAntiFarmGateTest extends TestCase
{
    private function gate(): AtlasExternalBrainSimplificationAntiFarmGate
    {
        return new AtlasExternalBrainSimplificationAntiFarmGate;
    }

    private function candidate(string $id, array $overrides = []): array
    {
        return array_merge([
            'candidate_id' => $id,
            'pattern_family' => 'wrapper_consolidation',
            'target_pattern' => 'thin_service_wrapper',
            'approach' => 'merge_into_core',
            'evidence_strength' => 0.8,
            'evidence_refs' => ["ev-{$id}-1"],
        ], $overrides);
    }

    public function test_schema_and_decisions_present(): void
    {
        $result = $this->gate()->evaluate([]);

        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::SCHEMA, $result['schema']);
        $this->assertSame([], $result['decisions']);
    }

    // ── distinct_admit_case ──────────────────────────────────────────────────

    public function test_distinct_high_evidence_candidates_are_all_admitted(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a', ['pattern_family' => 'wrapper_consolidation', 'evidence_strength' => 0.8]),
            $this->candidate('b', ['pattern_family' => 'template_retirement', 'evidence_strength' => 0.9]),
            $this->candidate('c', ['pattern_family' => 'scaffold_merge', 'evidence_strength' => 0.75]),
        ]);

        foreach ($result['decisions'] as $decision) {
            $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_ADMIT, $decision['decision']);
        }
        // Distinct templates must not collide on the same cluster id.
        $clusterIds = array_column($result['decisions'], 'duplicate_cluster_id');
        $this->assertCount(3, array_unique($clusterIds));
    }

    public function test_distinct_low_evidence_candidate_is_rejected(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a', ['evidence_strength' => 0.2]),
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_REJECT, $result['decisions'][0]['decision']);
        $this->assertNotEmpty($result['decisions'][0]['required_new_evidence']);
    }

    // ── template_farm_rejection_case ─────────────────────────────────────────

    public function test_repeated_same_template_with_weak_new_evidence_is_rejected(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a', ['evidence_strength' => 0.8, 'evidence_refs' => ['ev-1']]),
            $this->candidate('b', ['evidence_strength' => 0.6, 'evidence_refs' => ['ev-1']]), // stale + weaker
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_ADMIT, $result['decisions'][0]['decision']);
        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_REJECT, $result['decisions'][1]['decision']);
        $this->assertSame($result['decisions'][0]['duplicate_cluster_id'], $result['decisions'][1]['duplicate_cluster_id']);
        $this->assertNotEmpty($result['decisions'][1]['required_new_evidence']);
    }

    public function test_repeated_same_template_reusing_identical_refs_is_rejected_even_with_higher_score(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a', ['evidence_strength' => 0.6, 'evidence_refs' => ['ev-1']]),
            $this->candidate('b', ['evidence_strength' => 0.95, 'evidence_refs' => ['ev-1']]), // same refs, no NEW evidence
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_REJECT, $result['decisions'][1]['decision']);
        $this->assertContains('evidence_refs_distinct_from_cluster_prior_refs', $result['decisions'][1]['required_new_evidence']);
    }

    public function test_repeated_same_template_with_genuine_new_evidence_is_admitted(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a', ['evidence_strength' => 0.6, 'evidence_refs' => ['ev-1']]),
            $this->candidate('b', ['evidence_strength' => 0.85, 'evidence_refs' => ['ev-2']]), // stronger + new ref
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_ADMIT, $result['decisions'][1]['decision']);
        $this->assertSame([], $result['decisions'][1]['required_new_evidence']);
    }

    public function test_third_repeat_compares_against_accumulated_cluster_max(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a', ['evidence_strength' => 0.6, 'evidence_refs' => ['ev-1']]),
            $this->candidate('b', ['evidence_strength' => 0.85, 'evidence_refs' => ['ev-2']]),
            $this->candidate('c', ['evidence_strength' => 0.80, 'evidence_refs' => ['ev-3']]), // new ref but weaker than 0.85 max
        ]);

        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_ADMIT, $result['decisions'][1]['decision']);
        $this->assertSame(AtlasExternalBrainSimplificationAntiFarmGate::DECISION_REJECT, $result['decisions'][2]['decision']);
    }

    // ── duplicate_cluster_id + required_new_evidence contract ───────────────

    public function test_all_decisions_include_duplicate_cluster_id_and_required_new_evidence_keys(): void
    {
        $result = $this->gate()->evaluate([
            $this->candidate('a'),
            $this->candidate('b', ['evidence_strength' => 0.1]),
        ]);

        foreach ($result['decisions'] as $decision) {
            $this->assertArrayHasKey('duplicate_cluster_id', $decision);
            $this->assertArrayHasKey('required_new_evidence', $decision);
            $this->assertNotSame('', $decision['duplicate_cluster_id']);
        }
    }

    public function test_result_is_deterministic_for_identical_input(): void
    {
        $gate = $this->gate();
        $candidates = [
            $this->candidate('a', ['evidence_strength' => 0.6, 'evidence_refs' => ['ev-1']]),
            $this->candidate('b', ['evidence_strength' => 0.85, 'evidence_refs' => ['ev-2']]),
        ];

        $this->assertSame($gate->evaluate($candidates), $gate->evaluate($candidates));
    }
}
