<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainWasteReductionSimulator;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainWasteReductionSimulatorTest extends TestCase
{
    private AtlasExternalBrainWasteReductionSimulator $simulator;

    protected function setUp(): void
    {
        $this->simulator = new AtlasExternalBrainWasteReductionSimulator;
    }

    private function input(array $overrides = []): array
    {
        return array_merge([
            'baseline' => [
                'served'                 => 100,
                'give_back'              => 20,
                'quarantine'             => 10,
                'malformed'              => 5,
                'successful_high_impact' => 30,
            ],
            'proposed' => [
                'served'                 => 100,
                'give_back'              => 10,
                'quarantine'             => 5,
                'malformed'              => 2,
                'successful_high_impact' => 30,
            ],
            'tokens_per_task' => 100.0,
        ], $overrides);
    }

    // ── Schema / required keys ────────────────────────────────────────────────

    public function test_result_has_required_keys(): void
    {
        $result = $this->simulator->simulate($this->input());

        foreach ([
            'schema', 'avoided_give_backs', 'avoided_quarantines', 'avoided_malformed_serves',
            'estimated_tokens_saved', 'confidence_band', 'penalty_applied', 'penalty_reason', 'net_value_score',
        ] as $k) {
            $this->assertArrayHasKey($k, $result);
        }
        $this->assertSame(AtlasExternalBrainWasteReductionSimulator::SCHEMA, $result['schema']);
    }

    // ── AC2: avoided counts are deltas ────────────────────────────────────────

    public function test_avoided_counts_are_correct(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertSame(10, $result['avoided_give_backs']);      // 20 - 10
        $this->assertSame(5,  $result['avoided_quarantines']);     // 10 - 5
        $this->assertSame(3,  $result['avoided_malformed_serves']); // 5 - 2
    }

    // ── AC2: estimated_tokens_saved ───────────────────────────────────────────

    public function test_estimated_tokens_saved_uses_total_avoided(): void
    {
        $result = $this->simulator->simulate($this->input());

        // (10 + 5 + 3) * 100 = 1800
        $this->assertEqualsWithDelta(1800.0, $result['estimated_tokens_saved'], 0.001);
    }

    // ── AC2: confidence_band — high ───────────────────────────────────────────

    public function test_high_confidence_when_enough_data(): void
    {
        $result = $this->simulator->simulate($this->input());

        // served=100 >= 50, total_avoided=18 >= 5
        $this->assertSame('high', $result['confidence_band']);
    }

    // ── AC2: confidence_band — medium ────────────────────────────────────────

    public function test_medium_confidence_on_smaller_dataset(): void
    {
        $result = $this->simulator->simulate($this->input([
            'baseline' => ['served' => 25, 'give_back' => 4, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10],
            'proposed' => ['served' => 25, 'give_back' => 2, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10],
        ]));

        // served=25 >= 20 → medium
        $this->assertSame('medium', $result['confidence_band']);
    }

    // ── AC2: confidence_band — low ────────────────────────────────────────────

    public function test_low_confidence_with_tiny_dataset(): void
    {
        $result = $this->simulator->simulate($this->input([
            'baseline' => ['served' => 5, 'give_back' => 1, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 2],
            'proposed' => ['served' => 5, 'give_back' => 0, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 2],
        ]));

        $this->assertSame('low', $result['confidence_band']);
    }

    // ── AC3: penalty when high-impact tasks are lost ──────────────────────────

    public function test_penalty_applied_when_high_impact_tasks_lost(): void
    {
        $result = $this->simulator->simulate($this->input([
            'proposed' => [
                'serve'                  => 100,
                'give_back'              => 5,
                'quarantine'             => 2,
                'malformed'              => 1,
                'successful_high_impact' => 20, // lost 10 high-impact tasks
            ],
        ]));

        $this->assertTrue($result['penalty_applied']);
        $this->assertNotNull($result['penalty_reason']);
        $this->assertStringContainsString('high_impact', $result['penalty_reason']);
    }

    // ── AC3: penalty reduces net_value_score below tokens_saved ──────────────

    public function test_penalty_reduces_net_value_score(): void
    {
        $result = $this->simulator->simulate($this->input([
            'proposed' => [
                'give_back'              => 10,
                'quarantine'             => 5,
                'malformed'              => 2,
                'successful_high_impact' => 20, // lost 10
            ],
            'tokens_per_task' => 100.0,
        ]));

        // penalty = 10 * 100 * 3 = 3000; tokens_saved = 1800; net = -1200
        $this->assertLessThan($result['estimated_tokens_saved'], $result['net_value_score']);
    }

    // ── AC3: no penalty when high-impact count unchanged ─────────────────────

    public function test_no_penalty_when_high_impact_preserved(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertFalse($result['penalty_applied']);
        $this->assertNull($result['penalty_reason']);
    }

    // ── Avoided counts never go negative ─────────────────────────────────────

    public function test_avoided_counts_are_non_negative_when_proposed_is_worse(): void
    {
        $result = $this->simulator->simulate($this->input([
            'proposed' => [
                'give_back'              => 30, // worse than baseline
                'quarantine'             => 15,
                'malformed'              => 8,
                'successful_high_impact' => 30,
            ],
        ]));

        $this->assertSame(0, $result['avoided_give_backs']);
        $this->assertSame(0, $result['avoided_quarantines']);
        $this->assertSame(0, $result['avoided_malformed_serves']);
        $this->assertEqualsWithDelta(0.0, $result['estimated_tokens_saved'], 0.001);
    }

    // ── net_value_score == tokens_saved when no penalty ──────────────────────

    public function test_net_value_score_equals_tokens_saved_without_penalty(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertEqualsWithDelta($result['estimated_tokens_saved'], $result['net_value_score'], 0.001);
    }

    // ── New output fields present ─────────────────────────────────────────────

    public function test_new_output_fields_present(): void
    {
        $result = $this->simulator->simulate($this->input());

        foreach (['avoided_poison_count', 'avoided_give_back_count', 'lost_high_value_count', 'false_negative_penalty', 'net_policy_value'] as $k) {
            $this->assertArrayHasKey($k, $result, "Missing key: {$k}");
        }
    }

    // ── avoided_poison_count ──────────────────────────────────────────────────

    public function test_avoided_poison_count_is_delta(): void
    {
        $result = $this->simulator->simulate($this->input([
            'baseline' => array_merge($this->input()['baseline'], ['poison' => 8]),
            'proposed' => array_merge($this->input()['proposed'], ['poison' => 3]),
        ]));

        $this->assertSame(5, $result['avoided_poison_count']);
    }

    public function test_avoided_poison_count_defaults_to_zero(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertSame(0, $result['avoided_poison_count']);
    }

    // ── avoided_give_back_count ───────────────────────────────────────────────

    public function test_avoided_give_back_count_mirrors_avoided_give_backs(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertSame($result['avoided_give_backs'], $result['avoided_give_back_count']);
    }

    // ── lost_high_value_count ─────────────────────────────────────────────────

    public function test_lost_high_value_count_reflects_impact_loss(): void
    {
        $result = $this->simulator->simulate($this->input([
            'proposed' => array_merge($this->input()['proposed'], ['successful_high_impact' => 20]),
        ]));

        $this->assertSame(10, $result['lost_high_value_count']); // 30 - 20
    }

    public function test_lost_high_value_count_zero_when_no_loss(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertSame(0, $result['lost_high_value_count']);
    }

    // ── false_negative_penalty ────────────────────────────────────────────────

    public function test_false_negative_penalty_is_loss_times_multiplier(): void
    {
        // lost 10, tokens=100, multiplier=3 → penalty=3000
        $result = $this->simulator->simulate($this->input([
            'proposed' => array_merge($this->input()['proposed'], ['successful_high_impact' => 20]),
        ]));

        $this->assertEqualsWithDelta(3000.0, $result['false_negative_penalty'], 0.001);
    }

    public function test_false_negative_penalty_zero_when_no_loss(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertEqualsWithDelta(0.0, $result['false_negative_penalty'], 0.001);
    }

    // ── net_policy_value (AC2) ────────────────────────────────────────────────

    public function test_high_savings_with_false_negatives_scores_below_low_savings_clean(): void
    {
        // Policy A: saves many tokens but rejects 10 high-value tasks
        $policyA = $this->simulator->simulate([
            'baseline'       => ['served' => 100, 'give_back' => 30, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10, 'poison' => 0],
            'proposed'       => ['served' => 100, 'give_back' => 0,  'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 0,  'poison' => 0],
            'tokens_per_task' => 100.0,
        ]);

        // Policy B: saves fewer tokens, but preserves all high-value tasks
        $policyB = $this->simulator->simulate([
            'baseline'       => ['served' => 100, 'give_back' => 10, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10, 'poison' => 0],
            'proposed'       => ['served' => 100, 'give_back' => 5,  'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10, 'poison' => 0],
            'tokens_per_task' => 100.0,
        ]);

        // A saves 3000 but penalty=3000 → net_policy_value=0
        // B saves 500 and penalty=0 → net_policy_value=500
        $this->assertLessThan($policyB['net_policy_value'], $policyA['net_policy_value']);
    }

    public function test_net_policy_value_includes_poison_savings(): void
    {
        $result = $this->simulator->simulate([
            'baseline'       => ['served' => 100, 'give_back' => 5, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10, 'poison' => 4],
            'proposed'       => ['served' => 100, 'give_back' => 5, 'quarantine' => 0, 'malformed' => 0, 'successful_high_impact' => 10, 'poison' => 0],
            'tokens_per_task' => 100.0,
        ]);

        // tokens_saved=0, poison_savings=4*100=400, false_neg_penalty=0
        $this->assertEqualsWithDelta(400.0, $result['net_policy_value'], 0.001);
    }

    // ── AC5: waste simulated for duplicate specs, poison, false-wait, low-value backlog ──

    public function test_high_waste_scenario_produces_actionable_prevention_rules(): void
    {
        $result = $this->simulator->simulate([
            'baseline' => [
                'served' => 100, 'give_back' => 0, 'quarantine' => 0, 'malformed' => 0,
                'successful_high_impact' => 10,
                'duplicate_spec' => 6, 'poison' => 8, 'false_wait' => 5, 'low_value_backlog' => 10,
            ],
            'proposed' => [
                'served' => 100, 'give_back' => 0, 'quarantine' => 0, 'malformed' => 0,
                'successful_high_impact' => 10,
                'duplicate_spec' => 0, 'poison' => 0, 'false_wait' => 0, 'low_value_backlog' => 0,
            ],
            'tokens_per_task' => 100.0,
        ]);

        $this->assertSame(6, $result['avoided_duplicate_spec_count']);
        $this->assertSame(5, $result['avoided_false_wait_count']);
        $this->assertSame(10, $result['avoided_low_value_backlog_count']);
        $this->assertCount(4, $result['prevention_rules']);
        $this->assertStringContainsString('duplicate-spec', $result['prevention_rules'][0]);
        $this->assertGreaterThan(0.0, $result['expected_savings']);
    }

    public function test_low_waste_high_leverage_batch_has_no_prevention_rules(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertSame([], $result['prevention_rules']);
        $this->assertGreaterThanOrEqual(0.0, $result['expected_savings']);
        $this->assertFalse($result['penalty_applied']);
    }

    public function test_expected_savings_key_present_alongside_scalar_scores(): void
    {
        $result = $this->simulator->simulate($this->input());

        $this->assertArrayHasKey('expected_savings', $result);
        $this->assertArrayHasKey('prevention_rules', $result);
        $this->assertIsArray($result['prevention_rules']);
    }
}
