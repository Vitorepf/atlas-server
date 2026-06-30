<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\ExternalBrain;

use App\Services\Ai\SelfConstruction\ExternalBrain\AtlasExternalBrainMuscleOutcomeLearningMatrix;
use PHPUnit\Framework\TestCase;

final class AtlasExternalBrainMuscleOutcomeLearningMatrixTest extends TestCase
{
    private function matrix(): AtlasExternalBrainMuscleOutcomeLearningMatrix
    {
        return new AtlasExternalBrainMuscleOutcomeLearningMatrix;
    }

    private function row(array $overrides = []): array
    {
        return array_merge([
            'task_family' => 'refactor',
            'worker_id'   => 'w1',
            'model_tier'  => 'small_model',
            'outcome'     => 'success',
        ], $overrides);
    }

    // ── Output shape ──────────────────────────────────────────────────────────

    public function test_output_has_required_keys(): void
    {
        $r = $this->matrix()->analyze([]);
        $this->assertSame(AtlasExternalBrainMuscleOutcomeLearningMatrix::SCHEMA, $r['schema_version']);
        $this->assertArrayHasKey('family_matrix',   $r);
        $this->assertArrayHasKey('worker_matrix',   $r);
        $this->assertArrayHasKey('tier_matrix',     $r);
        $this->assertArrayHasKey('respec_families', $r);
        $this->assertArrayHasKey('supply_families', $r);
        $this->assertArrayHasKey('matrix_summary',  $r);
    }

    // ── AC2: success_rate / poison_rate / failure_rate ────────────────────────

    public function test_success_rate_computed_per_family(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.6667, $r['family_matrix']['refactor']['success_rate'], 0.001);
        $this->assertEqualsWithDelta(0.3333, $r['family_matrix']['refactor']['failure_rate'], 0.001);
        $this->assertSame(3, $r['family_matrix']['refactor']['total']);
    }

    public function test_poison_rate_computed_per_family(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'poison']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['family_matrix']['refactor']['poison_rate'], 0.001);
    }

    // ── AC3: family signals ───────────────────────────────────────────────────

    public function test_poison_prone_signal_when_poison_rate_above_threshold(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'    => [
                $this->row(['outcome' => 'poison']),
                $this->row(['outcome' => 'poison']),
                $this->row(['outcome' => 'success']),
            ],
            'poison_threshold' => 0.20,
        ]);
        $this->assertSame('poison_prone', $r['family_matrix']['refactor']['signal']);
        $this->assertContains('refactor', $r['respec_families']);
        $this->assertNotContains('refactor', $r['supply_families']);
    }

    public function test_high_success_signal_when_above_threshold(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'      => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
            'success_threshold' => 0.80,
        ]);
        $this->assertSame('high_success', $r['family_matrix']['refactor']['signal']);
        $this->assertContains('refactor', $r['supply_families']);
    }

    public function test_poison_prone_takes_priority_over_high_success(): void
    {
        // Both thresholds met: poison_prone wins.
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'poison']),
            ],
            'poison_threshold'  => 0.10, // 1/5=0.20 >= 0.10 → poison_prone
            'success_threshold' => 0.70, // 4/5=0.80 >= 0.70 → but second
        ]);
        $this->assertSame('poison_prone', $r['family_matrix']['refactor']['signal']);
    }

    public function test_normal_signal_when_nothing_triggered(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
            'poison_threshold'  => 0.20,
            'success_threshold' => 0.80,
        ]);
        $this->assertSame('normal', $r['family_matrix']['refactor']['signal']);
    }

    // ── Worker reliability ────────────────────────────────────────────────────

    public function test_worker_reliable_when_above_floor(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'    => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
            ],
            'worker_rely_floor' => 0.70,
        ]);
        $this->assertTrue($r['worker_matrix']['w1']['reliable']);
    }

    public function test_worker_unreliable_below_floor(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'    => [
                $this->row(['outcome' => 'failure']),
                $this->row(['outcome' => 'failure']),
            ],
            'worker_rely_floor' => 0.70,
        ]);
        $this->assertFalse($r['worker_matrix']['w1']['reliable']);
    }

    // ── Tier matrix ───────────────────────────────────────────────────────────

    public function test_tier_matrix_groups_by_model_tier(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['model_tier' => 'small',    'outcome' => 'success']),
                $this->row(['model_tier' => 'small',    'outcome' => 'failure']),
                $this->row(['model_tier' => 'frontier', 'outcome' => 'success']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['tier_matrix']['small']['success_rate'],    0.001);
        $this->assertEqualsWithDelta(1.00, $r['tier_matrix']['frontier']['success_rate'], 0.001);
    }

    // ── matrix_summary ────────────────────────────────────────────────────────

    public function test_matrix_summary_counts_correctly(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['task_family' => 'a', 'worker_id' => 'w1', 'model_tier' => 't1']),
                $this->row(['task_family' => 'b', 'worker_id' => 'w2', 'model_tier' => 't2']),
            ],
        ]);
        $s = $r['matrix_summary'];
        $this->assertSame(2, $s['total_rows']);
        $this->assertSame(2, $s['families']);
        $this->assertSame(2, $s['workers']);
        $this->assertSame(2, $s['tiers']);
    }

    // ── Determinism ───────────────────────────────────────────────────────────

    public function test_output_is_deterministic(): void
    {
        $facts = [
            'outcome_rows' => [
                $this->row(['task_family' => 'x', 'outcome' => 'poison']),
                $this->row(['task_family' => 'y', 'outcome' => 'success']),
            ],
        ];
        $a = $this->matrix()->analyze($facts);
        $b = $this->matrix()->analyze($facts);
        $this->assertSame(json_encode($a), json_encode($b));
    }

    // ── AC2: new output keys ──────────────────────────────────────────────────

    public function test_output_has_new_required_keys(): void
    {
        $r = $this->matrix()->analyze([]);
        $this->assertArrayHasKey('worker_reliability_signals', $r);
        $this->assertArrayHasKey('repeat_offenders',           $r);
    }

    // ── AC2: give_back rate computed ──────────────────────────────────────────

    public function test_give_back_rate_tracked_in_family_matrix(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'give_back']),
                $this->row(['outcome' => 'success']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['family_matrix']['refactor']['give_back_rate'], 0.001);
    }

    // ── AC2: quarantine rate ──────────────────────────────────────────────────

    public function test_quarantine_rate_tracked_in_family_matrix(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'quarantine']),
                $this->row(['outcome' => 'success']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['family_matrix']['refactor']['quarantine_rate'], 0.001);
    }

    // ── AC2: duplicate rate ───────────────────────────────────────────────────

    public function test_duplicate_rate_tracked_in_family_matrix(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'duplicate']),
                $this->row(['outcome' => 'duplicate']),
                $this->row(['outcome' => 'success']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.6667, $r['family_matrix']['refactor']['duplicate_rate'], 0.001);
    }

    // ── AC2: weak_green rate ──────────────────────────────────────────────────

    public function test_weak_green_rate_tracked_in_family_matrix(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'weak_green']),
                $this->row(['outcome' => 'success']),
            ],
        ]);
        $this->assertEqualsWithDelta(0.50, $r['family_matrix']['refactor']['weak_green_rate'], 0.001);
    }

    // ── AC3: quarantine_prone signal → respec ────────────────────────────────

    public function test_quarantine_prone_family_routed_to_respec(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'quarantine']),
                $this->row(['outcome' => 'quarantine']),
                $this->row(['outcome' => 'success']),
            ],
            'quarantine_threshold' => 0.30,
        ]);
        $this->assertSame('quarantine_prone', $r['family_matrix']['refactor']['signal']);
        $this->assertContains('refactor', $r['respec_families']);
    }

    // ── AC3: duplicate_prone signal → respec ─────────────────────────────────

    public function test_duplicate_prone_family_routed_to_respec(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'duplicate']),
                $this->row(['outcome' => 'duplicate']),
                $this->row(['outcome' => 'success']),
            ],
            'duplicate_threshold' => 0.30,
        ]);
        $this->assertSame('duplicate_prone', $r['family_matrix']['refactor']['signal']);
        $this->assertContains('refactor', $r['respec_families']);
    }

    // ── AC4: worker_reliability_signals for repeat give_back ─────────────────

    public function test_worker_with_repeat_give_back_flagged_in_reliability_signals(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'give_back']),
                $this->row(['outcome' => 'give_back']),
                $this->row(['outcome' => 'success']),
            ],
            'min_give_back_flag' => 2,
        ]);
        $this->assertNotEmpty($r['worker_reliability_signals']);
        $this->assertSame('w1',              $r['worker_reliability_signals'][0]['worker_id']);
        $this->assertSame(2,                 $r['worker_reliability_signals'][0]['give_back_count']);
        $this->assertSame('repeat_give_back', $r['worker_reliability_signals'][0]['signal']);
    }

    public function test_worker_below_give_back_threshold_not_flagged(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows'      => [$this->row(['outcome' => 'give_back'])],
            'min_give_back_flag' => 2,
        ]);
        $this->assertEmpty($r['worker_reliability_signals']);
    }

    // ── AC4: repeat_offenders for low-success workers ─────────────────────────

    public function test_low_success_worker_appears_in_repeat_offenders(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'failure']),
                $this->row(['outcome' => 'failure']),
                $this->row(['outcome' => 'failure']),
            ],
            'repeat_offender_floor'    => 0.30,
            'repeat_offender_min_rows' => 3,
        ]);
        $this->assertNotEmpty($r['repeat_offenders']);
        $this->assertSame('w1', $r['repeat_offenders'][0]['worker_id']);
        $this->assertSame('low_success_worker', $r['repeat_offenders'][0]['offender_type']);
    }

    public function test_worker_with_enough_success_not_repeat_offender(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'success']),
                $this->row(['outcome' => 'failure']),
            ],
            'repeat_offender_floor'    => 0.30,
            'repeat_offender_min_rows' => 3,
        ]);
        $this->assertEmpty($r['repeat_offenders']);
    }

    // ── Routing recommendations ───────────────────────────────────────────────

    public function test_output_has_routing_and_cross_matrix_keys(): void
    {
        $r = $this->matrix()->analyze([]);
        $this->assertArrayHasKey('routing_recommendations', $r);
        $this->assertArrayHasKey('family_worker_fit',       $r);
        $this->assertArrayHasKey('family_tier_fit',         $r);
    }

    public function test_high_success_worker_family_pair_appears_in_preferred_workers(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['worker_id' => 'w1', 'outcome' => 'success']),
                $this->row(['worker_id' => 'w1', 'outcome' => 'success']),
                $this->row(['worker_id' => 'w1', 'outcome' => 'success']),
            ],
            'routing_prefer_floor' => 0.70,
            'routing_min_rows'     => 2,
        ]);

        $pref = $r['routing_recommendations']['refactor']['preferred_workers'] ?? [];
        $ids  = array_column($pref, 'worker_id');
        $this->assertContains('w1', $ids, 'w1 with 100% success must appear in preferred_workers');
        $this->assertSame('prefer', $pref[array_search('w1', $ids)]['routing']);
    }

    public function test_low_success_worker_family_pair_appears_in_avoid_workers(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['worker_id' => 'w2', 'outcome' => 'failure']),
                $this->row(['worker_id' => 'w2', 'outcome' => 'failure']),
                $this->row(['worker_id' => 'w2', 'outcome' => 'failure']),
            ],
            'routing_avoid_ceiling' => 0.40,
            'routing_min_rows'      => 2,
        ]);

        $avoid = $r['routing_recommendations']['refactor']['avoid_workers'] ?? [];
        $this->assertContains('w2', array_column($avoid, 'worker_id'));
        $this->assertSame('avoid', $avoid[0]['routing']);
    }

    public function test_poison_prone_worker_family_pair_is_fail_closed(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['worker_id' => 'w3', 'outcome' => 'poison']),
                $this->row(['worker_id' => 'w3', 'outcome' => 'poison']),
                $this->row(['worker_id' => 'w3', 'outcome' => 'success']),
            ],
            'poison_threshold' => 0.20,
            'routing_min_rows' => 2,
        ]);

        $fc = $r['routing_recommendations']['refactor']['fail_closed_combinations'] ?? [];
        $this->assertNotEmpty($fc, 'poison-prone w3×refactor must be fail_closed');
        $this->assertSame('w3', $fc[0]['worker_id']);
        $this->assertSame('fail_closed', $fc[0]['routing']);
        $this->assertSame('poison_prone_combination', $fc[0]['reason']);
    }

    public function test_preferred_tier_is_highest_success_tier_for_family(): void
    {
        $r = $this->matrix()->analyze([
            'outcome_rows' => [
                $this->row(['model_tier' => 'small',    'outcome' => 'failure']),
                $this->row(['model_tier' => 'small',    'outcome' => 'failure']),
                $this->row(['model_tier' => 'frontier', 'outcome' => 'success']),
                $this->row(['model_tier' => 'frontier', 'outcome' => 'success']),
            ],
            'routing_min_rows' => 2,
        ]);

        $this->assertSame('frontier', $r['routing_recommendations']['refactor']['preferred_tier']);
    }

    public function test_three_family_routing_scenario_covers_all_routing_categories(): void
    {
        // add_feature: w1=3×success → prefer; w2=2×failure → avoid
        // bugfix: w3=3×success → prefer; model frontier has high success
        // refactor: w4=3×poison → fail_closed; w1=3×success → prefer
        $rows = [
            // add_feature
            ['task_family' => 'add_feature', 'worker_id' => 'w1', 'model_tier' => 'small', 'outcome' => 'success'],
            ['task_family' => 'add_feature', 'worker_id' => 'w1', 'model_tier' => 'small', 'outcome' => 'success'],
            ['task_family' => 'add_feature', 'worker_id' => 'w1', 'model_tier' => 'small', 'outcome' => 'success'],
            ['task_family' => 'add_feature', 'worker_id' => 'w2', 'model_tier' => 'small', 'outcome' => 'failure'],
            ['task_family' => 'add_feature', 'worker_id' => 'w2', 'model_tier' => 'small', 'outcome' => 'failure'],
            // bugfix
            ['task_family' => 'bugfix', 'worker_id' => 'w3', 'model_tier' => 'frontier', 'outcome' => 'success'],
            ['task_family' => 'bugfix', 'worker_id' => 'w3', 'model_tier' => 'frontier', 'outcome' => 'success'],
            ['task_family' => 'bugfix', 'worker_id' => 'w3', 'model_tier' => 'frontier', 'outcome' => 'success'],
            // refactor
            ['task_family' => 'refactor', 'worker_id' => 'w4', 'model_tier' => 'small', 'outcome' => 'poison'],
            ['task_family' => 'refactor', 'worker_id' => 'w4', 'model_tier' => 'small', 'outcome' => 'poison'],
            ['task_family' => 'refactor', 'worker_id' => 'w4', 'model_tier' => 'small', 'outcome' => 'success'],
            ['task_family' => 'refactor', 'worker_id' => 'w1', 'model_tier' => 'small', 'outcome' => 'success'],
            ['task_family' => 'refactor', 'worker_id' => 'w1', 'model_tier' => 'small', 'outcome' => 'success'],
            ['task_family' => 'refactor', 'worker_id' => 'w1', 'model_tier' => 'small', 'outcome' => 'success'],
        ];

        $r = $this->matrix()->analyze([
            'outcome_rows'    => $rows,
            'poison_threshold' => 0.20,
            'routing_prefer_floor'  => 0.70,
            'routing_avoid_ceiling' => 0.40,
            'routing_min_rows'      => 2,
        ]);

        // Three distinct families covered.
        $this->assertCount(3, $r['family_matrix']);

        // add_feature: w1 preferred, w2 avoided
        $addFeature = $r['routing_recommendations']['add_feature'];
        $this->assertContains('w1', array_column($addFeature['preferred_workers'], 'worker_id'));
        $this->assertContains('w2', array_column($addFeature['avoid_workers'],     'worker_id'));

        // bugfix: w3 preferred; frontier is preferred tier
        $bugfix = $r['routing_recommendations']['bugfix'];
        $this->assertContains('w3', array_column($bugfix['preferred_workers'], 'worker_id'));
        $this->assertSame('frontier', $bugfix['preferred_tier']);

        // refactor: w4 fail_closed, w1 preferred
        $refactor = $r['routing_recommendations']['refactor'];
        $this->assertContains('w4', array_column($refactor['fail_closed_combinations'], 'worker_id'));
        $this->assertContains('w1', array_column($refactor['preferred_workers'],        'worker_id'));

        // Evidence fields are present for Maestro/Task Fabric to explain routing.
        foreach (['add_feature', 'bugfix', 'refactor'] as $fam) {
            $rec = $r['routing_recommendations'][$fam];
            $this->assertArrayHasKey('routing_basis',            $rec);
            $this->assertArrayHasKey('fail_closed_combinations', $rec);
            $this->assertArrayHasKey('preferred_tier',           $rec);
        }
    }
}
