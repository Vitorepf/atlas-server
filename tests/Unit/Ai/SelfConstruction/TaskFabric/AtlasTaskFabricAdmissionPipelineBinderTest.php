<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\TaskFabric;

use App\Services\Ai\SelfConstruction\TaskFabric\AtlasTaskFabricAdmissionPipelineBinder;
use Tests\TestCase;

final class AtlasTaskFabricAdmissionPipelineBinderTest extends TestCase
{
    private function svc(): AtlasTaskFabricAdmissionPipelineBinder
    {
        return new AtlasTaskFabricAdmissionPipelineBinder;
    }

    /** Returns a candidate that satisfies all gate checks. */
    private function good(string $id, float $impact = 0.80, float $risk = 0.10): array
    {
        return [
            'task_packet_id' => $id,
            'target' => $id,
            'objective' => "Implement {$id} to provide a measurable capability improvement to the Atlas system.",
            'allowed_files' => [
                "app/Services/Ai/SelfConstruction/ExternalBrain/{$id}.php",
                "tests/Unit/Ai/SelfConstruction/ExternalBrain/{$id}Test.php",
            ],
            'acceptance_criteria' => ["{$id} test exits 0"],
            'required_evidence' => ['tests_or_gates_result', 'implementation_notes'],
            'compound_impact_score' => $impact,
            'give_back_risk_score' => $risk,
            'known_targets' => [],
            'is_template_farm' => false,
        ];
    }

    /** Returns a candidate that fails: compound_impact_low. */
    private function low(string $id): array
    {
        return array_merge($this->good($id), ['compound_impact_score' => 0.10]);
    }

    private function filter(array $candidates, array $shared = []): array
    {
        return $this->svc()->filter(['candidates' => $candidates, 'shared_facts' => $shared]);
    }

    // ── happy path ────────────────────────────────────────────────────────────

    public function test_good_candidate_is_admitted(): void
    {
        $r = $this->filter([$this->good('TaskA')]);

        $this->assertCount(1, $r['admitted']);
        $this->assertSame('TaskA', $r['admitted'][0]['task_packet_id']);
        $this->assertSame([], $r['rejected']);
    }

    public function test_admitted_spec_preserves_required_fields(): void
    {
        $c = $this->good('TaskA');
        $r = $this->filter([$c]);
        $admitted = $r['admitted'][0];

        $this->assertSame('TaskA', $admitted['task_packet_id']);
        $this->assertSame($c['allowed_files'], $admitted['allowed_files']);
        $this->assertSame($c['acceptance_criteria'], $admitted['acceptance_criteria']);
        $this->assertSame($c['required_evidence'], $admitted['required_evidence']);
    }

    public function test_admission_receipt_includes_worker_floor_and_replenish_soon_when_supplied(): void
    {
        $c = $this->good('TaskFloor');
        $r = $this->filter([$c], ['worker_floor' => true, 'replenish_soon' => true]);

        $this->assertCount(1, $r['admitted']);
        $this->assertTrue($r['admitted'][0]['worker_floor']);
        $this->assertTrue($r['admitted'][0]['replenish_soon']);
    }

    public function test_admission_receipt_omits_worker_floor_facts_when_absent(): void
    {
        $r = $this->filter([$this->good('TaskNoFloor')]);

        $this->assertArrayNotHasKey('worker_floor', $r['admitted'][0]);
        $this->assertArrayNotHasKey('replenish_soon', $r['admitted'][0]);
    }

    public function test_rejected_receipt_also_includes_worker_floor_facts_when_supplied(): void
    {
        $r = $this->filter([$this->low('TaskLowFloor')], ['worker_floor' => true]);

        $this->assertCount(1, $r['rejected']);
        $this->assertTrue($r['rejected'][0]['worker_floor']);
    }

    // ── rejection ─────────────────────────────────────────────────────────────

    public function test_low_impact_candidate_is_rejected(): void
    {
        $r = $this->filter([$this->low('TaskB')]);

        $this->assertSame([], $r['admitted']);
        $this->assertCount(1, $r['rejected']);
    }

    public function test_rejected_spec_includes_gate_reasons(): void
    {
        $r = $this->filter([$this->low('TaskB')]);

        $this->assertArrayHasKey('gate_reasons', $r['rejected'][0]);
        $this->assertContains('compound_impact_low', $r['rejected'][0]['gate_reasons']);
    }

    public function test_template_farm_candidate_is_rejected(): void
    {
        $c = array_merge($this->good('TaskC'), ['is_template_farm' => true]);
        $r = $this->filter([$c]);

        $this->assertContains('template_farm', $r['rejected'][0]['gate_reasons']);
    }

    public function test_semantic_duplicate_is_rejected(): void
    {
        $c = array_merge($this->good('TaskD'), ['known_targets' => ['TaskD']]);
        $r = $this->filter([$c]);

        $this->assertContains('semantic_duplicate', $r['rejected'][0]['gate_reasons']);
    }

    // ── mixed batch ───────────────────────────────────────────────────────────

    public function test_mixed_batch_splits_admitted_and_rejected(): void
    {
        $r = $this->filter([
            $this->good('Good1'),
            $this->low('Bad1'),
            $this->good('Good2'),
        ]);

        $this->assertCount(2, $r['admitted']);
        $this->assertCount(1, $r['rejected']);
    }

    // ── rejection_summary ─────────────────────────────────────────────────────

    public function test_rejection_summary_counts_reasons(): void
    {
        $r = $this->filter([
            $this->low('B1'),
            $this->low('B2'),
        ]);

        $this->assertSame(2, $r['rejection_summary']['compound_impact_low']);
    }

    public function test_rejection_summary_is_empty_when_all_admitted(): void
    {
        $r = $this->filter([$this->good('A1'), $this->good('A2')]);

        $this->assertSame([], $r['rejection_summary']);
    }

    // ── batch_value_score ─────────────────────────────────────────────────────

    public function test_batch_value_score_is_avg_of_admitted(): void
    {
        // value_score = impact * (1 - risk) = 0.80 * 0.90 = 0.72
        $r = $this->filter([$this->good('A1', impact: 0.80, risk: 0.10)]);

        $this->assertNotNull($r['batch_value_score']);
        $this->assertEqualsWithDelta(0.72, $r['batch_value_score'], 0.001);
    }

    public function test_batch_value_score_null_when_no_admitted(): void
    {
        $r = $this->filter([$this->low('B1')]);

        $this->assertNull($r['batch_value_score']);
    }

    // ── shared_facts merge ────────────────────────────────────────────────────

    public function test_shared_known_targets_applied_to_all_candidates(): void
    {
        // Both candidates pass on their own, but shared_facts marks target as known
        $r = $this->filter(
            [$this->good('SharedTarget')],
            ['known_targets' => ['SharedTarget']],
        );

        $this->assertCount(0, $r['admitted']);
        $this->assertContains('semantic_duplicate', $r['rejected'][0]['gate_reasons']);
    }

    // ── empty + schema ────────────────────────────────────────────────────────

    public function test_empty_candidates_returns_empty_output(): void
    {
        $r = $this->filter([]);

        $this->assertSame([], $r['admitted']);
        $this->assertSame([], $r['rejected']);
        $this->assertSame([], $r['rejection_summary']);
        $this->assertNull($r['batch_value_score']);
    }

    public function test_schema_version_present(): void
    {
        $r = $this->svc()->filter([]);
        $this->assertSame(AtlasTaskFabricAdmissionPipelineBinder::SCHEMA, $r['schema_version']);
    }

    // ── AC3: admitted candidates preserve target ────────────────────────────────

    public function test_admitted_spec_preserves_target(): void
    {
        $r = $this->filter([$this->good('TaskA')]);

        $this->assertSame('TaskA', $r['admitted'][0]['target']);
    }

    public function test_rejected_spec_also_preserves_target(): void
    {
        $r = $this->filter([$this->low('TaskB')]);

        $this->assertSame('TaskB', $r['rejected'][0]['target']);
    }

    // ── AC2: respec_action_plan maps each gate_reason to a minimal repair ──────

    public function test_rejected_spec_includes_respec_action_plan_for_low_impact(): void
    {
        $r = $this->filter([$this->low('TaskB')]);

        $this->assertArrayHasKey('respec_action_plan', $r['rejected'][0]);
        $this->assertArrayHasKey('compound_impact_low', $r['rejected'][0]['respec_action_plan']);
        $this->assertSame(
            'add_at_least_one_measurable_compound_impact_signal_before_resubmitting',
            $r['rejected'][0]['respec_action_plan']['compound_impact_low'],
        );
    }

    public function test_respec_action_plan_covers_every_gate_reason_when_multiple_fire_at_once(): void
    {
        // low impact AND template farm at once -> both reasons must appear in gate_reasons
        // AND both must have their own respec_action_plan entry.
        $c = array_merge($this->low('TaskMulti'), ['is_template_farm' => true]);
        $r = $this->filter([$c]);

        $this->assertContains('compound_impact_low', $r['rejected'][0]['gate_reasons']);
        $this->assertContains('template_farm', $r['rejected'][0]['gate_reasons']);
        $this->assertArrayHasKey('compound_impact_low', $r['rejected'][0]['respec_action_plan']);
        $this->assertArrayHasKey('template_farm', $r['rejected'][0]['respec_action_plan']);
    }

    public function test_respec_action_plan_is_deterministic_per_reason(): void
    {
        $r1 = $this->filter([$this->low('TaskB')]);
        $r2 = $this->filter([$this->low('TaskB')]);

        $this->assertSame($r1['rejected'][0]['respec_action_plan'], $r2['rejected'][0]['respec_action_plan']);
    }

    // ── AC4: next_originator_actions — deduplicated, sorted union across the batch ──

    public function test_next_originator_actions_deduplicates_repeated_repair_across_the_batch(): void
    {
        $r = $this->filter([$this->low('B1'), $this->low('B2')]);

        $this->assertCount(1, $r['next_originator_actions'], 'both B1 and B2 fail the same reason -> one deduplicated action');
        $this->assertContains(
            'add_at_least_one_measurable_compound_impact_signal_before_resubmitting',
            $r['next_originator_actions'],
        );
    }

    public function test_next_originator_actions_is_sorted_deterministically(): void
    {
        $c = array_merge($this->low('TaskMulti'), ['is_template_farm' => true]);
        $r1 = $this->filter([$c]);
        $r2 = $this->filter([$c]);

        $this->assertSame($r1['next_originator_actions'], $r2['next_originator_actions']);
        $sorted = $r1['next_originator_actions'];
        $expected = $sorted;
        sort($expected, SORT_STRING);
        $this->assertSame($expected, $sorted);
    }

    public function test_next_originator_actions_empty_when_all_admitted(): void
    {
        $r = $this->filter([$this->good('A1'), $this->good('A2')]);

        $this->assertSame([], $r['next_originator_actions']);
    }
}
