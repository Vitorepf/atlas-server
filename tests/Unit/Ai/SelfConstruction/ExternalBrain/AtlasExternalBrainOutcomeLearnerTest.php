<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainOutcomeLearner;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainOutcomeLearnerTest extends TestCase
{
    private AtlasExternalBrainOutcomeLearner $learner;

    protected function setUp(): void
    {
        $this->learner = new AtlasExternalBrainOutcomeLearner;
    }

    // ── empty input ───────────────────────────────────────────────────────────

    public function test_empty_outcomes_returns_empty_result(): void
    {
        $r = $this->learner->learn([]);
        $this->assertSame(AtlasExternalBrainOutcomeLearner::SCHEMA, $r['schema']);
        $this->assertSame([], $r['priority_adjustments']);
        $this->assertSame([], $r['promoted']);
        $this->assertSame([], $r['demoted']);
    }

    // ── delivered high-impact raises priority ─────────────────────────────────

    public function test_delivered_high_impact_promotes_pattern_family(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 'task-01',
            'pattern_family' => 'contract_mismatch_hunt',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
            'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
        ]]);

        $adj = $r['priority_adjustments'][0];
        $this->assertSame('contract_mismatch_hunt', $adj['pattern_family']);
        $this->assertGreaterThan(0, $adj['delta'], 'delivered high-impact must raise priority');
        $this->assertContains('contract_mismatch_hunt', $r['promoted']);
        $this->assertNotContains('contract_mismatch_hunt', $r['demoted']);
    }

    public function test_delivered_medium_impact_raises_priority_less_than_high(): void
    {
        $rHigh = $this->learner->learn([[
            'task_packet_id' => 'task-h',
            'pattern_family' => 'alpha',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
            'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
        ]]);
        $rMed = $this->learner->learn([[
            'task_packet_id' => 'task-m',
            'pattern_family' => 'alpha',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
            'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_MEDIUM,
        ]]);

        $deltaHigh = $rHigh['priority_adjustments'][0]['delta'];
        $deltaMed = $rMed['priority_adjustments'][0]['delta'];
        $this->assertGreaterThan($deltaMed, $deltaHigh, 'high-impact delta must exceed medium');
    }

    // ── give_back lowers priority ─────────────────────────────────────────────

    public function test_give_back_demotes_pattern_family(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 'task-gb-01',
            'pattern_family' => 'orphan_wiring_hunt',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK,
            'give_back_count' => 1,
        ]]);

        $adj = $r['priority_adjustments'][0];
        $this->assertLessThan(0, $adj['delta'], 'give_back must lower priority');
        $this->assertContains('orphan_wiring_hunt', $r['demoted']);
        $this->assertNotContains('orphan_wiring_hunt', $r['promoted']);
    }

    public function test_higher_give_back_count_yields_larger_negative_delta(): void
    {
        $r1 = $this->learner->learn([[
            'task_packet_id' => 'task-1',
            'pattern_family' => 'alpha',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK,
            'give_back_count' => 1,
        ]]);
        $r5 = $this->learner->learn([[
            'task_packet_id' => 'task-5',
            'pattern_family' => 'alpha',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK,
            'give_back_count' => 5,
        ]]);

        $this->assertLessThan($r1['priority_adjustments'][0]['delta'], $r5['priority_adjustments'][0]['delta']);
    }

    // ── proxy lowers priority more than give_back ─────────────────────────────

    public function test_proxy_demotes_more_than_single_give_back(): void
    {
        $rProxy = $this->learner->learn([[
            'task_packet_id' => 'task-proxy',
            'pattern_family' => 'alpha',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_PROXY,
        ]]);
        $rGb = $this->learner->learn([[
            'task_packet_id' => 'task-gb',
            'pattern_family' => 'alpha',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK,
            'give_back_count' => 1,
        ]]);

        $this->assertLessThan($rGb['priority_adjustments'][0]['delta'], $rProxy['priority_adjustments'][0]['delta']);
    }

    // ── delta is bounded ──────────────────────────────────────────────────────

    public function test_delta_is_clamped_to_minus_one(): void
    {
        // 10 proxy outcomes → would exceed -1.0 without clamping.
        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = [
                'task_packet_id' => "task-{$i}",
                'pattern_family' => 'bad_family',
                'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_PROXY,
            ];
        }
        $r = $this->learner->learn($outcomes);

        $delta = $r['priority_adjustments'][0]['delta'];
        $this->assertGreaterThanOrEqual(-1.0, $delta);
    }

    public function test_delta_is_clamped_to_plus_one(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = [
                'task_packet_id' => "task-{$i}",
                'pattern_family' => 'great_family',
                'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
                'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
            ];
        }
        $r = $this->learner->learn($outcomes);

        $delta = $r['priority_adjustments'][0]['delta'];
        $this->assertLessThanOrEqual(1.0, $delta);
    }

    // ── promoted and demoted are disjoint ────────────────────────────────────

    public function test_promoted_and_demoted_are_disjoint(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 'a', 'pattern_family' => 'alpha', 'outcome' => 'delivered', 'impact' => 'high'],
            ['task_packet_id' => 'b', 'pattern_family' => 'beta', 'outcome' => 'proxy'],
        ]);

        $overlap = array_intersect($r['promoted'], $r['demoted']);
        $this->assertSame([], array_values($overlap), 'promoted and demoted must be disjoint');
    }

    // ── next_wave_hints ───────────────────────────────────────────────────────

    public function test_promoted_family_generates_expand_hint(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 'task-01',
            'pattern_family' => 'contract_mismatch',
            'outcome' => 'delivered',
            'impact' => 'high',
        ]]);

        $focuses = array_column($r['next_wave_hints'], 'focus');
        $matched = array_filter($focuses, fn (string $f): bool => str_contains($f, 'contract_mismatch'));
        $this->assertNotEmpty($matched, 'promoted family must produce an expand hint');
    }

    public function test_demoted_family_generates_investigate_hint(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 'task-gb',
            'pattern_family' => 'orphan_wiring',
            'outcome' => 'give_back',
            'give_back_count' => 3,
        ]]);

        $focuses = array_column($r['next_wave_hints'], 'focus');
        $matched = array_filter($focuses, fn (string $f): bool => str_contains($f, 'orphan_wiring'));
        $this->assertNotEmpty($matched, 'demoted family must produce an investigate hint');
    }

    // ── provider-safe: no hidden prompts ──────────────────────────────────────

    public function test_output_contains_no_hidden_model_prompts(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 'task-01',
            'pattern_family' => 'alpha',
            'outcome' => 'delivered',
            'impact' => 'high',
        ]]);

        $json = (string) json_encode($r);

        $forbiddenPhrases = ['<|', '|>', 'SYSTEM:', 'USER:', 'ASSISTANT:', '###', 'prompt:'];
        foreach ($forbiddenPhrases as $phrase) {
            $this->assertStringNotContainsString($phrase, $json, "output must not contain hidden prompt marker: {$phrase}");
        }

        // Hints must carry source_category and focus — no free-form model instructions.
        foreach ($r['next_wave_hints'] as $hint) {
            $this->assertArrayHasKey('source_category', $hint);
            $this->assertArrayHasKey('focus', $hint);
            $this->assertArrayHasKey('priority', $hint);
            $this->assertIsFloat($hint['priority']);
            $this->assertGreaterThanOrEqual(0.0, $hint['priority']);
            $this->assertLessThanOrEqual(1.0, $hint['priority']);
        }
    }

    // ── accumulation across multiple outcomes for same family ─────────────────

    public function test_multiple_delivered_outcomes_accumulate_delta(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 'task-1', 'pattern_family' => 'alpha', 'outcome' => 'delivered', 'impact' => 'high'],
            ['task_packet_id' => 'task-2', 'pattern_family' => 'alpha', 'outcome' => 'delivered', 'impact' => 'medium'],
        ]);

        $adj = $r['priority_adjustments'][0];
        $this->assertSame('alpha', $adj['pattern_family']);
        $this->assertGreaterThan(0.30, $adj['delta'], 'two delivered outcomes must accumulate above single high-impact delta');
    }
}
