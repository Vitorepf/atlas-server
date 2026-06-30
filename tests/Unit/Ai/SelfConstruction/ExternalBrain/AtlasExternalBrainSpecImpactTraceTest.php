<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainSpecImpactTrace;
use Tests\TestCase;

final class AtlasExternalBrainSpecImpactTraceTest extends TestCase
{
    private function tracer(): AtlasExternalBrainSpecImpactTrace
    {
        return new AtlasExternalBrainSpecImpactTrace;
    }

    private function fullInput(array $overrides = []): array
    {
        return array_merge([
            'task_id'              => 'task-abc-123',
            'evidence_refs'        => ['scan:orphan-scan-2026-06-30', 'doc:engineering-kb/gap-analysis.md'],
            'thesis'               => 'Wiring AtlasExternalBrainLeverageScorer into the pipeline unblocks 3 downstream organs.',
            'leverage_dimensions'  => ['capability_unlock' => 0.8, 'dependency_unblock' => 0.7, 'implementation_evidence' => 0.9],
            'capability_delta'     => 'LeverageScorer can be called by the SelfImprovementCycle to rank origination candidates.',
            'acceptance_proof'     => 'The runnable test AtlasExternalBrainLeverageScorerTest passes 14/14 green with no mocks.',
            'downstream_unlocks'   => ['SelfImprovementCycle', 'OriginationRanker'],
            'risk_reduction'       => 'Eliminates blind dispatch of unproven origination tasks.',
            'evidence_after_commit'=> 'AtlasExternalBrainSpecImpactTraceTest green with no mocks after commit.',
            'falsification_signal' => 'If LeverageScorer returns an empty ranked list post-ship, the hypothesis fails.',
        ], $overrides);
    }

    public function test_schema_constant(): void
    {
        $this->assertSame(
            'atlas.external_brain.spec_impact_trace.v1',
            AtlasExternalBrainSpecImpactTrace::SCHEMA,
        );
    }

    public function test_valid_trace_has_canonical_keys(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        foreach (['schema', 'task_id', 'verifiable', 'unverifiable_reasons', 'evidence_refs', 'thesis', 'leverage_dimensions', 'leverage_score', 'capability_delta', 'acceptance_proof'] as $key) {
            $this->assertArrayHasKey($key, $result);
        }
        $this->assertSame(AtlasExternalBrainSpecImpactTrace::SCHEMA, $result['schema']);
    }

    public function test_fully_populated_trace_is_verifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        $this->assertTrue($result['verifiable']);
        $this->assertSame([], $result['unverifiable_reasons']);
        $this->assertSame('task-abc-123', $result['task_id']);
    }

    public function test_evidence_refs_preserved_in_output(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        $this->assertContains('scan:orphan-scan-2026-06-30', $result['evidence_refs']);
        $this->assertContains('doc:engineering-kb/gap-analysis.md', $result['evidence_refs']);
    }

    public function test_leverage_score_is_average_of_dimensions_when_not_supplied(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        // (0.8 + 0.7 + 0.9) / 3 = 0.8
        $this->assertEqualsWithDelta(0.8, $result['leverage_score'], 0.001);
    }

    public function test_explicit_leverage_score_overrides_computed_average(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['leverage_score' => 0.55]));

        $this->assertEqualsWithDelta(0.55, $result['leverage_score'], 0.001);
    }

    public function test_no_evidence_refs_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['evidence_refs' => []]));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_evidence_origin', $result['unverifiable_reasons']);
    }

    public function test_blank_evidence_refs_treated_as_missing(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['evidence_refs' => ['', '  ']]));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_evidence_origin', $result['unverifiable_reasons']);
    }

    public function test_no_capability_delta_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['capability_delta' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_capability_delta', $result['unverifiable_reasons']);
    }

    public function test_empty_acceptance_proof_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['acceptance_proof' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_falsifiable_acceptance', $result['unverifiable_reasons']);
    }

    public function test_too_short_acceptance_proof_marks_unverifiable(): void
    {
        // Under 20 chars is considered too vague
        $result = $this->tracer()->trace($this->fullInput(['acceptance_proof' => 'tests pass']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_falsifiable_acceptance', $result['unverifiable_reasons']);
    }

    public function test_acceptance_proof_at_threshold_is_verifiable(): void
    {
        // Exactly 20 chars
        $proof  = str_repeat('a', 20);
        $result = $this->tracer()->trace($this->fullInput(['acceptance_proof' => $proof]));

        $this->assertTrue($result['verifiable']);
        $this->assertNotContains('no_falsifiable_acceptance', $result['unverifiable_reasons']);
    }

    public function test_all_three_pillars_missing_lists_all_reasons(): void
    {
        $result = $this->tracer()->trace([
            'task_id'          => 'task-bad',
            'thesis'           => 'something vague',
            'leverage_score'   => 0.5,
            'capability_delta' => '',
            'acceptance_proof' => '',
            'evidence_refs'    => [],
        ]);

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_evidence_origin',        $result['unverifiable_reasons']);
        $this->assertContains('no_capability_delta',       $result['unverifiable_reasons']);
        $this->assertContains('no_falsifiable_acceptance', $result['unverifiable_reasons']);
    }

    public function test_empty_dimensions_yields_zero_leverage_score(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['leverage_dimensions' => []]));

        $this->assertEqualsWithDelta(0.0, $result['leverage_score'], 0.001);
    }

    public function test_audit_partitions_batch_into_verifiable_and_unverifiable(): void
    {
        $specs = [
            $this->fullInput(['task_id' => 'good-1']),
            $this->fullInput(['task_id' => 'good-2']),
            $this->fullInput(['task_id' => 'bad-1', 'evidence_refs' => [], 'capability_delta' => '']),
        ];

        $result = $this->tracer()->audit($specs);

        $this->assertSame(3, $result['stats']['total']);
        $this->assertSame(2, $result['stats']['verifiable_count']);
        $this->assertSame(1, $result['stats']['unverifiable_count']);
        $this->assertCount(2, $result['verifiable']);
        $this->assertCount(1, $result['unverifiable']);

        $unverifiableTaskIds = array_column($result['unverifiable'], 'task_id');
        $this->assertContains('bad-1', $unverifiableTaskIds);
    }

    public function test_empty_batch_audit_returns_valid_structure(): void
    {
        $result = $this->tracer()->audit([]);

        $this->assertSame(0, $result['stats']['total']);
        $this->assertSame([], $result['verifiable']);
        $this->assertSame([], $result['unverifiable']);
    }

    // ── new impact-trace fields ───────────────────────────────────────────────

    public function test_trace_output_includes_all_four_new_impact_fields(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        foreach (['downstream_unlocks', 'risk_reduction', 'evidence_after_commit', 'falsification_signal'] as $key) {
            $this->assertArrayHasKey($key, $result, "trace output missing key: $key");
        }
    }

    public function test_downstream_unlocks_passed_through_in_output(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        $this->assertContains('SelfImprovementCycle', $result['downstream_unlocks']);
        $this->assertContains('OriginationRanker',    $result['downstream_unlocks']);
    }

    public function test_absent_downstream_unlocks_yields_empty_list(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['downstream_unlocks' => []]));

        $this->assertSame([], $result['downstream_unlocks']);
    }

    public function test_missing_evidence_after_commit_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['evidence_after_commit' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_evidence_after_commit', $result['unverifiable_reasons']);
    }

    public function test_cosmetic_only_true_marks_unverifiable_with_reason(): void
    {
        // No falsification_signal → override incomplete → proxy fires
        $result = $this->tracer()->trace($this->fullInput(['cosmetic_only' => true, 'falsification_signal' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('cosmetic_only_proxy', $result['unverifiable_reasons']);
    }

    public function test_cosmetic_only_verifiable_when_cap_delta_and_falsification_override_present(): void
    {
        // fullInput has both capability_delta and falsification_signal → proxy suppressed
        $result = $this->tracer()->trace($this->fullInput(['cosmetic_only' => true]));

        $this->assertNotContains('cosmetic_only_proxy', $result['unverifiable_reasons']);
    }

    public function test_metric_only_true_marks_unverifiable_with_reason(): void
    {
        // No falsification_signal → override incomplete → proxy fires
        $result = $this->tracer()->trace($this->fullInput(['metric_only' => true, 'falsification_signal' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('metric_only_proxy', $result['unverifiable_reasons']);
    }

    public function test_metric_only_verifiable_when_overrides_present(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['metric_only' => true]));

        $this->assertNotContains('metric_only_proxy', $result['unverifiable_reasons']);
    }

    public function test_falsification_signal_passed_through_in_output(): void
    {
        $result = $this->tracer()->trace($this->fullInput());

        $this->assertStringContainsString('LeverageScorer', $result['falsification_signal']);
    }

    // ── new pillar blockers ───────────────────────────────────────────────────

    public function test_missing_downstream_unlocks_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['downstream_unlocks' => []]));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_downstream_unlocks', $result['unverifiable_reasons']);
    }

    public function test_missing_risk_reduction_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['risk_reduction' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_risk_reduction', $result['unverifiable_reasons']);
    }

    public function test_missing_falsification_signal_marks_unverifiable(): void
    {
        $result = $this->tracer()->trace($this->fullInput(['falsification_signal' => '']));

        $this->assertFalse($result['verifiable']);
        $this->assertContains('no_falsification_signal', $result['unverifiable_reasons']);
    }

    // ── audit reason_counts ───────────────────────────────────────────────────

    public function test_audit_stats_include_reason_counts(): void
    {
        $specs = [
            $this->fullInput(['task_id' => 'ok']),
            $this->fullInput(['task_id' => 'bad-a', 'evidence_refs' => []]),
            $this->fullInput(['task_id' => 'bad-b', 'evidence_refs' => []]),
        ];

        $result = $this->tracer()->audit($specs);

        $this->assertArrayHasKey('reason_counts', $result['stats']);
        $this->assertSame(2, $result['stats']['reason_counts']['no_evidence_origin']);
    }
}
