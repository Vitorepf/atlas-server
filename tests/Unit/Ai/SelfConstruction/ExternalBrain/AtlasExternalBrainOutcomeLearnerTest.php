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

    // ── new fields present in output ──────────────────────────────────────────

    public function test_output_includes_new_family_fields(): void
    {
        $r = $this->learner->learn([]);

        foreach (['family_performance', 'give_back_risk', 'poison_family_hints', 'worker_fit_hints', 'next_wave_adjustments'] as $key) {
            $this->assertArrayHasKey($key, $r, "Missing key '{$key}' in learn() output");
            $this->assertIsArray($r[$key]);
        }
    }

    // ── family_success_rate computed per task_family ──────────────────────────

    public function test_family_performance_tracks_delivered_and_give_back(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture'],
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'architecture'],
        ]);

        $perf = $this->findByKey($r['family_performance'], 'task_family', 'architecture');
        $this->assertSame(3, $perf['total']);
        $this->assertSame(2, $perf['delivered']);
        $this->assertSame(1, $perf['give_back']);
        $this->assertEqualsWithDelta(0.667, $perf['success_rate'], 0.001);
    }

    public function test_family_performance_success_rate_zero_when_all_give_back(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bug_fix'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bug_fix'],
        ]);

        $perf = $this->findByKey($r['family_performance'], 'task_family', 'bug_fix');
        $this->assertSame(0.0, $perf['success_rate']);
    }

    // ── give_back_risk per task_family ────────────────────────────────────────

    public function test_give_back_risk_high_when_rate_exceeds_60pct(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'template'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'template'],
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'template'],
        ]);

        $risk = $this->findByKey($r['give_back_risk'], 'task_family', 'template');
        $this->assertSame('high', $risk['risk_level']);
    }

    public function test_give_back_risk_low_when_all_delivered(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
        ]);

        $risk = $this->findByKey($r['give_back_risk'], 'task_family', 'evolution');
        $this->assertSame('low', $risk['risk_level']);
    }

    // ── poison_family_hints emitted for repeated give_backs ───────────────────

    public function test_poison_family_hints_emitted_when_give_back_count_reaches_threshold(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = ['task_packet_id' => "t{$i}", 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'proxy_heavy'];
        }

        $r = $this->learner->learn($outcomes);

        $poisonFamilies = array_column($r['poison_family_hints'], 'task_family');
        $this->assertContains('proxy_heavy', $poisonFamilies, 'family with 3+ give_backs must produce a poison hint');
    }

    public function test_poison_hint_does_not_affect_high_performing_family(): void
    {
        $outcomes = [
            // poison family: 3 give_backs
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bad_family'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bad_family'],
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bad_family'],
            // high-performing family: all delivered
            ['task_packet_id' => 't4', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'good_family'],
            ['task_packet_id' => 't5', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'good_family'],
        ];

        $r = $this->learner->learn($outcomes);

        $poisonFamilies = array_column($r['poison_family_hints'], 'task_family');
        $this->assertContains('bad_family', $poisonFamilies, 'bad_family should be poisoned');
        $this->assertNotContains('good_family', $poisonFamilies, 'good_family must NOT be poisoned');

        // good_family must still have a positive next_wave adjustment
        $goodAdj = $this->findByKey($r['next_wave_adjustments'], 'task_family', 'good_family');
        $this->assertGreaterThan(0.0, $goodAdj['priority_delta']);
    }

    // ── worker_fit_hints per worker/client ────────────────────────────────────

    public function test_worker_fit_strong_when_success_rate_high(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture', 'worker_id' => 'claude-muscle-3'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture', 'worker_id' => 'claude-muscle-3'],
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture', 'worker_id' => 'claude-muscle-3'],
        ]);

        $fit = $this->findWorkerFit($r['worker_fit_hints'], 'claude-muscle-3', 'architecture');
        $this->assertSame('strong', $fit['fit']);
        $this->assertSame(1.0, $fit['success_rate']);
    }

    public function test_worker_fit_weak_when_success_rate_low(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'multi_file', 'worker_id' => 'codex-worker-1'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'multi_file', 'worker_id' => 'codex-worker-1'],
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'multi_file', 'worker_id' => 'codex-worker-1'],
            ['task_packet_id' => 't4', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'multi_file', 'worker_id' => 'codex-worker-1'],
        ]);

        $fit = $this->findWorkerFit($r['worker_fit_hints'], 'codex-worker-1', 'multi_file');
        $this->assertSame('weak', $fit['fit']);
    }

    public function test_worker_fit_accepts_client_id_as_alias_for_worker_id(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution', 'client_id' => 'claude-muscle-3'],
        ]);

        $fits = array_column($r['worker_fit_hints'], 'worker_id');
        $this->assertContains('claude-muscle-3', $fits);
    }

    // ── next_wave_adjustments reflects family_success_rate ────────────────────

    public function test_next_wave_adjustment_positive_for_high_success_family(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
        ]);

        $adj = $this->findByKey($r['next_wave_adjustments'], 'task_family', 'evolution');
        $this->assertGreaterThan(0.0, $adj['priority_delta']);
    }

    public function test_next_wave_adjustment_negative_for_low_success_family(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'template'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'template'],
        ]);

        $adj = $this->findByKey($r['next_wave_adjustments'], 'task_family', 'template');
        $this->assertLessThan(0.0, $adj['priority_delta']);
    }

    // ── existing priority_adjustments still present ───────────────────────────

    public function test_existing_priority_adjustments_preserved_alongside_new_fields(): void
    {
        $r = $this->learner->learn([
            [
                'task_packet_id' => 't1',
                'pattern_family' => 'contract_hunt',
                'outcome'        => 'delivered',
                'impact'         => 'high',
                'task_family'    => 'architecture',
                'worker_id'      => 'claude-muscle-3',
            ],
        ]);

        // Existing fields intact
        $this->assertNotEmpty($r['priority_adjustments']);
        $this->assertSame('contract_hunt', $r['priority_adjustments'][0]['pattern_family']);
        $this->assertContains('contract_hunt', $r['promoted']);

        // New fields also present
        $this->assertNotEmpty($r['family_performance']);
        $this->assertNotEmpty($r['worker_fit_hints']);
    }

    // ── helpers ───────────────────────────────────────────────────────────────

    private function findByKey(array $list, string $key, string $value): array
    {
        foreach ($list as $item) {
            if (($item[$key] ?? null) === $value) {
                return $item;
            }
        }
        $this->fail("No item with {$key}='{$value}' found in list");
    }

    private function findWorkerFit(array $hints, string $workerId, string $taskFamily): array
    {
        foreach ($hints as $hint) {
            if ($hint['worker_id'] === $workerId && $hint['task_family'] === $taskFamily) {
                return $hint;
            }
        }
        $this->fail("No worker_fit_hint for worker='{$workerId}', task_family='{$taskFamily}'");
    }
}
