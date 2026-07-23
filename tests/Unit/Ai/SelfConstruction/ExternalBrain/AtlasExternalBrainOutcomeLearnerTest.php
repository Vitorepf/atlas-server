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
        // A single high-impact delivery only reaches promotable confidence when backed by
        // explicit value_proof — see test_single_delivered_high_impact_without_value_proof_does_not_promote_alone.
        $r = $this->learner->learn([[
            'task_packet_id' => 'task-01',
            'pattern_family' => 'contract_mismatch_hunt',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
            'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
            'value_proof' => true,
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
            'value_proof' => true,
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
                'value_proof'    => true,
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

    // ── next_batch_budget (AC1 + AC2) ────────────────────────────────────────

    public function test_next_batch_budget_present_in_output(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture'],
        ]);

        $this->assertArrayHasKey('next_batch_budget', $r);
        $this->assertIsArray($r['next_batch_budget']);
        $this->assertNotEmpty($r['next_batch_budget']);
    }

    public function test_next_batch_budget_has_required_fields(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'architecture'],
        ]);

        $entry = $r['next_batch_budget'][0];
        $this->assertArrayHasKey('task_family', $entry);
        $this->assertArrayHasKey('max_count', $entry);
        $this->assertArrayHasKey('min_evidence_floor', $entry);
        $this->assertArrayHasKey('risk_cap', $entry);
    }

    public function test_next_batch_budget_empty_when_no_task_family(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered'],
        ]);

        $this->assertSame([], $r['next_batch_budget']);
    }

    public function test_next_batch_budget_max_count_at_least_one_for_good_family(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
        ]);

        $entry = $this->findByKey($r['next_batch_budget'], 'task_family', 'evolution');
        $this->assertGreaterThanOrEqual(1, $entry['max_count']);
    }

    /** AC2: any proxy task in the family → max_count capped at 1. */
    public function test_next_batch_budget_max_count_capped_at_one_for_proxy_heavy_family(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'evolution'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'proxy',     'task_family' => 'evolution'],
        ]);

        $entry = $this->findByKey($r['next_batch_budget'], 'task_family', 'evolution');
        $this->assertSame(1, $entry['max_count'], 'proxy-heavy family must be capped at max_count=1');
    }

    /** AC2: many completions never increase budget for a proxy-heavy family. */
    public function test_next_batch_budget_many_deliveries_do_not_increase_proxy_heavy_max_count(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 10; $i++) {
            $outcomes[] = ['task_packet_id' => "t{$i}", 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'bulk'];
        }
        $outcomes[] = ['task_packet_id' => 't10', 'pattern_family' => 'pf', 'outcome' => 'proxy', 'task_family' => 'bulk'];

        $r = $this->learner->learn($outcomes);

        $entry = $this->findByKey($r['next_batch_budget'], 'task_family', 'bulk');
        $this->assertSame(1, $entry['max_count'], '10 deliveries + 1 proxy must still yield max_count=1');
    }

    public function test_next_batch_budget_min_evidence_floor_higher_for_risky_family(): void
    {
        $rGood = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'good'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'good'],
        ]);
        $rRisky = $this->learner->learn([
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'risky'],
            ['task_packet_id' => 't4', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'risky'],
        ]);

        $floorGood  = $this->findByKey($rGood['next_batch_budget'],  'task_family', 'good')['min_evidence_floor'];
        $floorRisky = $this->findByKey($rRisky['next_batch_budget'], 'task_family', 'risky')['min_evidence_floor'];
        $this->assertGreaterThan($floorGood, $floorRisky, 'risky family must have a higher min_evidence_floor');
    }

    public function test_next_batch_budget_risk_cap_lower_for_risky_family(): void
    {
        $rGood = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'good'],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'delivered', 'task_family' => 'good'],
        ]);
        $rRisky = $this->learner->learn([
            ['task_packet_id' => 't3', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'risky'],
            ['task_packet_id' => 't4', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'risky'],
        ]);

        $capGood  = $this->findByKey($rGood['next_batch_budget'],  'task_family', 'good')['risk_cap'];
        $capRisky = $this->findByKey($rRisky['next_batch_budget'], 'task_family', 'risky')['risk_cap'];
        $this->assertGreaterThan($capRisky, $capGood, 'risky family must have a lower risk_cap');
    }

    // ── confidence floor (single noisy outcome must not promote) ─────────────

    public function test_single_medium_delivered_outcome_does_not_promote_without_confidence(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'noisy', 'outcome' => 'delivered', 'impact' => 'medium'],
        ]);

        $this->assertNotContains('noisy', $r['promoted']);
        $this->assertSame('low', $r['priority_adjustments'][0]['confidence']);
    }

    public function test_single_low_delivered_outcome_does_not_promote_without_confidence(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'noisy_low', 'outcome' => 'delivered', 'impact' => 'low'],
        ]);

        $this->assertNotContains('noisy_low', $r['promoted']);
        $this->assertSame('low', $r['priority_adjustments'][0]['confidence']);
    }

    public function test_repeated_high_impact_delivered_with_value_proof_yields_high_confidence_and_promotion(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'proven', 'outcome' => 'delivered', 'impact' => 'high', 'value_proof' => true],
            ['task_packet_id' => 't2', 'pattern_family' => 'proven', 'outcome' => 'delivered', 'impact' => 'high', 'value_proof' => true],
        ]);

        $this->assertSame('high', $r['priority_adjustments'][0]['confidence']);
        $this->assertContains('proven', $r['promoted']);
    }

    public function test_poison_and_quarantine_still_demote_immediately_with_repair_and_self_heal(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'harmful', 'outcome' => 'poison', 'task_family' => 'harmful_family'],
            ['task_packet_id' => 't2', 'pattern_family' => 'isolate', 'outcome' => 'quarantine', 'task_family' => 'isolate_family'],
        ]);

        $this->assertContains('harmful', $r['demoted']);
        $this->assertContains('isolate', $r['demoted']);

        $repair = $this->findByKey($r['recommendations'], 'task_family', 'harmful_family');
        $this->assertSame('repair', $repair['action']);

        $selfHeal = $this->findByKey($r['recommendations'], 'task_family', 'isolate_family');
        $this->assertSame('self_heal', $selfHeal['action']);
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

    // ── new AC1: delivered high-impact WITHOUT value_proof does not promote by itself ──

    public function test_single_delivered_high_impact_without_value_proof_does_not_promote_alone(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'fam-x', 'outcome' => 'delivered', 'impact' => 'high'],
        ]);

        $this->assertNotContains('fam-x', $result['promoted']);
    }

    // ── new AC2: proxy outcomes produce an avoid recommendation ──────────────

    public function test_proxy_outcome_produces_avoid_recommendation(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'fam-p', 'task_family' => 'fam-p', 'outcome' => 'proxy'],
        ]);

        $rec = $this->findByKey($result['recommendations'], 'task_family', 'fam-p');
        $this->assertSame('avoid', $rec['action']);
    }

    public function test_poison_outcome_produces_repair_recommendation(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'fam-poison', 'task_family' => 'fam-poison', 'outcome' => 'poison'],
        ]);

        $rec = $this->findByKey($result['recommendations'], 'task_family', 'fam-poison');
        $this->assertSame('repair', $rec['action']);
    }

    public function test_quarantine_outcome_produces_self_heal_recommendation(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'fam-q', 'task_family' => 'fam-q', 'outcome' => 'quarantine'],
        ]);

        $rec = $this->findByKey($result['recommendations'], 'task_family', 'fam-q');
        $this->assertSame('self_heal', $rec['action']);
    }

    public function test_repeated_give_back_produces_avoid_recommendation(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'fam-gb', 'task_family' => 'fam-gb', 'outcome' => 'give_back'],
            ['task_packet_id' => 't2', 'pattern_family' => 'fam-gb', 'task_family' => 'fam-gb', 'outcome' => 'give_back'],
        ]);

        $rec = $this->findByKey($result['recommendations'], 'task_family', 'fam-gb');
        $this->assertSame('avoid', $rec['action']);
    }

    // ── new AC3: worker fit hints stay scoped and never suppress unrelated families ──

    public function test_worker_fit_hint_for_one_family_does_not_suppress_unrelated_high_performing_family(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'weak-fam', 'task_family' => 'weak-fam', 'worker_id' => 'w1', 'outcome' => 'give_back'],
            ['task_packet_id' => 't2', 'pattern_family' => 'weak-fam', 'task_family' => 'weak-fam', 'worker_id' => 'w1', 'outcome' => 'give_back'],
            ['task_packet_id' => 't3', 'pattern_family' => 'strong-fam', 'task_family' => 'strong-fam', 'worker_id' => 'w2', 'outcome' => 'delivered', 'impact' => 'high'],
            ['task_packet_id' => 't4', 'pattern_family' => 'strong-fam', 'task_family' => 'strong-fam', 'worker_id' => 'w2', 'outcome' => 'delivered', 'impact' => 'high'],
        ]);

        $weakFit = $this->findWorkerFit($result['worker_fit_hints'], 'w1', 'weak-fam');
        $strongFit = $this->findWorkerFit($result['worker_fit_hints'], 'w2', 'strong-fam');

        $this->assertSame('weak', $weakFit['fit']);
        $this->assertSame('strong', $strongFit['fit']);

        $strongRec = $this->findByKey($result['recommendations'], 'task_family', 'strong-fam');
        $this->assertSame('promote', $strongRec['action']);
    }

    // ── new AC4: promoted and demoted stay disjoint under mixed real-world outcomes ──

    public function test_promoted_and_demoted_remain_disjoint_with_mixed_outcomes(): void
    {
        $result = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'good', 'outcome' => 'delivered', 'impact' => 'high'],
            ['task_packet_id' => 't2', 'pattern_family' => 'good', 'outcome' => 'delivered', 'impact' => 'high'],
            ['task_packet_id' => 't3', 'pattern_family' => 'bad', 'outcome' => 'proxy'],
            ['task_packet_id' => 't4', 'pattern_family' => 'bad', 'outcome' => 'give_back'],
        ]);

        $this->assertSame([], array_intersect($result['promoted'], $result['demoted']));
    }

    // ── AC1: next_batch_policy_delta on promoted recommendation ────────────────

    public function test_delivered_high_impact_family_recommendation_has_next_batch_policy_delta(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = ['task_packet_id' => "t{$i}", 'task_family' => 'promo-fam', 'outcome' => 'delivered', 'impact' => 'high'];
        }
        $r = $this->learner->learn($outcomes);

        $rec = $this->findByKey($r['recommendations'], 'task_family', 'promo-fam');
        $this->assertSame('promote', $rec['action']);
        $this->assertArrayHasKey('confidence', $rec);
        $this->assertArrayHasKey('next_batch_policy_delta', $rec);
        $this->assertGreaterThan(0, $rec['next_batch_policy_delta']);
    }

    // ── AC2: demoted/self_heal recommendations carry decay + revalidation metadata ──

    public function test_repeated_give_back_recommendation_has_decay_and_revalidation_metadata(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = ['task_packet_id' => "t{$i}", 'task_family' => 'bad-fam', 'worker_id' => 'w1', 'outcome' => 'give_back'];
        }
        $r = $this->learner->learn($outcomes);

        $rec = $this->findByKey($r['recommendations'], 'task_family', 'bad-fam');
        $this->assertSame('avoid', $rec['action']);
        $this->assertArrayHasKey('decay', $rec);
        $this->assertArrayHasKey('revalidate_after_cycles', $rec);
    }

    public function test_quarantine_recommendation_has_decay_and_revalidation_metadata(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'task_family' => 'quarantined-fam', 'outcome' => 'quarantine'],
        ]);

        $rec = $this->findByKey($r['recommendations'], 'task_family', 'quarantined-fam');
        $this->assertSame('self_heal', $rec['action']);
        $this->assertArrayHasKey('decay', $rec);
        $this->assertArrayHasKey('revalidate_after_cycles', $rec);
    }

    // ── AC3: poison attribution distinguishes worker-specific from family-systemic ──

    public function test_poison_hint_attributed_to_single_worker_when_give_backs_come_from_one_worker(): void
    {
        $outcomes = [];
        for ($i = 0; $i < 3; $i++) {
            $outcomes[] = ['task_packet_id' => "t{$i}", 'task_family' => 'one-bad-worker-fam', 'worker_id' => 'bad-worker', 'outcome' => 'give_back'];
        }
        $r = $this->learner->learn($outcomes);

        $hint = $this->findByKey($r['poison_family_hints'], 'task_family', 'one-bad-worker-fam');
        $this->assertSame('worker_specific', $hint['attribution']);
    }

    public function test_poison_hint_attributed_to_family_when_give_backs_spread_across_workers(): void
    {
        $outcomes = [
            ['task_packet_id' => 't1', 'task_family' => 'systemic-fam', 'worker_id' => 'worker-a', 'outcome' => 'give_back'],
            ['task_packet_id' => 't2', 'task_family' => 'systemic-fam', 'worker_id' => 'worker-b', 'outcome' => 'give_back'],
            ['task_packet_id' => 't3', 'task_family' => 'systemic-fam', 'worker_id' => 'worker-c', 'outcome' => 'give_back'],
        ];
        $r = $this->learner->learn($outcomes);

        $hint = $this->findByKey($r['poison_family_hints'], 'task_family', 'systemic-fam');
        $this->assertNotNull($hint);
        $this->assertSame('family_systemic', $hint['attribution']);
    }

    // ── AC: recency weighting ─────────────────────────────────────────────────

    public function test_fresh_outcome_has_stronger_priority_adjustment_than_stale(): void
    {
        $freshDelta = $this->learner->learn([[
            'pattern_family' => 'recency_fam',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
            'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
            'recency' => 'fresh',
        ]])['priority_adjustments'][0]['delta'];

        $staleDelta = $this->learner->learn([[
            'pattern_family' => 'recency_fam',
            'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
            'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
            'recency' => 'stale',
        ]])['priority_adjustments'][0]['delta'];

        $this->assertGreaterThan($staleDelta, $freshDelta);
        $this->assertGreaterThan(0, $staleDelta, 'stale delivered must still be positive, just weaker');
    }

    public function test_stale_poison_demotes_but_does_not_suppress_unrelated_fresh_high_impact_family(): void
    {
        $r = $this->learner->learn([
            [
                'pattern_family' => 'stale_poison_fam',
                'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_POISON,
                'recency' => 'stale',
            ],
            [
                'pattern_family' => 'fresh_delivered_fam',
                'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED,
                'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
                'value_proof' => true,
                'recency' => 'fresh',
            ],
        ]);

        $this->assertContains('stale_poison_fam', $r['demoted']);
        $this->assertContains('fresh_delivered_fam', $r['promoted']);
        $this->assertNotContains('fresh_delivered_fam', $r['demoted']);
    }

    public function test_promoted_and_demoted_remain_disjoint_with_recency_weighting(): void
    {
        $r = $this->learner->learn([
            ['pattern_family' => 'a', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH, 'value_proof' => true, 'recency' => 'fresh'],
            ['pattern_family' => 'b', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_POISON, 'recency' => 'stale'],
        ]);

        $this->assertEmpty(array_intersect($r['promoted'], $r['demoted']));
    }

    // ── AC: poison and proxy outcomes reduce pattern score more than low-impact delivered outcomes increase it ──

    public function test_poison_reduces_score_more_than_low_impact_delivered_increases(): void
    {
        $rPoison = $this->learner->learn([[
            'task_packet_id' => 't1', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_POISON,
        ]]);
        $rLowDelivered = $this->learner->learn([[
            'task_packet_id' => 't2', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_LOW,
        ]]);

        $poisonDelta = $rPoison['priority_adjustments'][0]['delta'];
        $lowDelta = $rLowDelivered['priority_adjustments'][0]['delta'];

        $this->assertLessThan($lowDelta, $poisonDelta, 'poison must reduce score more than low-impact delivered increases it');
    }

    public function test_proxy_reduces_score_more_than_low_impact_delivered_increases(): void
    {
        $rProxy = $this->learner->learn([[
            'task_packet_id' => 't1', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_PROXY,
        ]]);
        $rLowDelivered = $this->learner->learn([[
            'task_packet_id' => 't2', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_LOW,
        ]]);

        $proxyDelta = $rProxy['priority_adjustments'][0]['delta'];
        $lowDelta = $rLowDelivered['priority_adjustments'][0]['delta'];

        $this->assertLessThan($lowDelta, $proxyDelta, 'proxy must reduce score more than low-impact delivered increases it');
    }

    // ── AC: repeated give_back on the same family compounds negative learning and emits avoid_family guidance ──

    public function test_repeated_give_back_compounds_negative_learning(): void
    {
        $r1 = $this->learner->learn([[
            'task_packet_id' => 't1', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'give_back_count' => 1,
        ]]);
        $r3 = $this->learner->learn([[
            'task_packet_id' => 't1', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_GIVE_BACK, 'give_back_count' => 3,
        ]]);

        $delta1 = $r1['priority_adjustments'][0]['delta'];
        $delta3 = $r3['priority_adjustments'][0]['delta'];

        $this->assertLessThan($delta1, $delta3, 'repeated give_back must compound negative learning');
    }

    public function test_repeated_give_back_emits_avoid_family_guidance(): void
    {
        $r = $this->learner->learn([
            ['task_packet_id' => 't1', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bad_family', 'give_back_count' => 3],
            ['task_packet_id' => 't2', 'pattern_family' => 'pf', 'outcome' => 'give_back', 'task_family' => 'bad_family', 'give_back_count' => 3],
        ]);

        $rec = $this->findByKey($r['recommendations'], 'task_family', 'bad_family');
        $this->assertSame('avoid', $rec['action']);
    }

    // ── AC: high-impact delivered outcomes still produce positive learning only when evidence is concrete ──

    public function test_high_impact_delivered_with_value_proof_produces_positive_learning(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 't1', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH, 'value_proof' => true,
        ]]);

        $this->assertGreaterThan(0, $r['priority_adjustments'][0]['delta']);
        $this->assertContains('fam', $r['promoted']);
    }

    public function test_high_impact_delivered_without_value_proof_does_not_promote_alone(): void
    {
        $r = $this->learner->learn([[
            'task_packet_id' => 't1', 'pattern_family' => 'fam', 'outcome' => AtlasExternalBrainOutcomeLearner::OUTCOME_DELIVERED, 'impact' => AtlasExternalBrainOutcomeLearner::IMPACT_HIGH,
        ]]);

        // Delta is still positive but confidence is low → not promoted
        $this->assertGreaterThan(0, $r['priority_adjustments'][0]['delta']);
        $this->assertNotContains('fam', $r['promoted']);
    }
}
