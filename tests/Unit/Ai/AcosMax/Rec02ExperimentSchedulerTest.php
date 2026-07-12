<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\AcosMax;

use App\Services\Ai\Governance\Recursion\ExperimentValueOfInformationScheduler;
use App\Services\Ai\Governance\Recursion\HypothesisV1;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * REC-02 — VOI scheduler acceptance tests (frontier plan §3045).
 *
 *   - 2 hypotheses from INDEPENDENT families ⇒ scheduled in overlapping windows
 *     (parallelization proven — `parallel_with` non-empty for each entry).
 *   - 2 from the SAME family ⇒ NEVER overlap (attribution invariant); the
 *     second serialises with `starts_after_family_slot` set.
 *   - Hypothesis without a machinable falsifier ⇒ INELIGIBLE for the agenda
 *     (never in `ranked`, never in `agenda`).
 *   - Hypothesis with an unmeasurable component ⇒ tail of the queue with
 *     `basis=unmeasurable_component`, NEVER silently dropped.
 *   - Empty ranked ⇒ `status=insufficient_signal` (no fabricated ETA).
 */
final class Rec02ExperimentSchedulerTest extends TestCase
{
    #[Test]
    public function schema_version_is_pinned(): void
    {
        $this->assertSame(
            'atlas.acos.rec_scheduler.v1',
            ExperimentValueOfInformationScheduler::SCHEMA_VERSION,
        );
    }

    #[Test]
    public function independent_families_are_scheduled_in_parallel(): void
    {
        $scheduler = new ExperimentValueOfInformationScheduler;
        $report = $scheduler->schedule([
            $this->proposal('hyp-a', 'family-alpha', 1.0, 0.4, 7, 10.0),
            $this->proposal('hyp-b', 'family-beta', 0.5, 0.2, 5, 8.0),
        ]);

        $this->assertSame('ok', $report['status']);
        $this->assertCount(2, $report['agenda']);
        foreach ($report['agenda'] as $item) {
            $this->assertNotSame([], $item['parallel_with'], 'each independent family must have a parallel peer');
            $this->assertSame(0, $item['family_slot']);
            $this->assertNull($item['starts_after_family_slot']);
        }
    }

    #[Test]
    public function same_family_hypotheses_serialise_never_overlap(): void
    {
        $scheduler = new ExperimentValueOfInformationScheduler;
        $report = $scheduler->schedule([
            $this->proposal('hyp-a1', 'family-alpha', 2.0, 0.6, 5, 4.0),
            $this->proposal('hyp-a2', 'family-alpha', 1.0, 0.3, 5, 4.0),
        ]);

        $this->assertSame('ok', $report['status']);
        $slots = array_column($report['agenda'], 'family_slot');
        $this->assertSame([0, 1], $slots, 'second same-family hypothesis must serialise after the first');
        $this->assertSame(0, $report['agenda'][1]['starts_after_family_slot']);
        foreach ($report['agenda'] as $item) {
            $this->assertSame([], $item['parallel_with'], 'same-family entries must never be marked parallel');
        }
    }

    #[Test]
    public function hypothesis_without_falsifier_is_ineligible_for_the_agenda(): void
    {
        $scheduler = new ExperimentValueOfInformationScheduler;
        $broken = $this->validHypothesis();
        unset($broken['falsifier']);

        $report = $scheduler->schedule([
            [
                'hypothesis_id' => 'no-falsifier',
                'family' => 'family-broken',
                'hypothesis' => $broken,
                'interval_width_current' => 1.0,
                'expected_gain' => 0.5,
                'window_days' => 7,
                'cost_units' => 4.0,
            ],
        ]);

        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame([], $report['ranked']);
        $this->assertCount(1, $report['ineligible']);
        $this->assertSame(
            ExperimentValueOfInformationScheduler::BASIS_INELIGIBLE_NO_FALSIFIER,
            $report['ineligible'][0]['basis'],
        );
    }

    #[Test]
    public function unmeasurable_component_goes_to_the_tail_never_dropped(): void
    {
        $scheduler = new ExperimentValueOfInformationScheduler;
        $report = $scheduler->schedule([
            $this->proposal('measured', 'fam-x', 1.0, 0.4, 5, 4.0),
            [
                'hypothesis_id' => 'no-cost',
                'family' => 'fam-y',
                'hypothesis' => $this->validHypothesis(),
                'interval_width_current' => 1.0,
                'expected_gain' => 0.4,
                'window_days' => 5,
                'cost_units' => null,
            ],
        ]);

        $this->assertSame('ok', $report['status']);
        $this->assertCount(1, $report['ranked']);
        $this->assertCount(1, $report['unmeasurable']);
        $this->assertSame(
            ExperimentValueOfInformationScheduler::BASIS_UNMEASURABLE,
            $report['unmeasurable'][0]['basis'],
        );
    }

    #[Test]
    public function voi_orders_ranked_descending_and_critical_path_follows_highest(): void
    {
        $scheduler = new ExperimentValueOfInformationScheduler;
        $report = $scheduler->schedule([
            $this->proposal('low', 'fam-l', 0.1, 0.1, 30, 20.0),
            $this->proposal('high', 'fam-h', 2.0, 1.0, 3, 2.0),
        ]);

        $this->assertSame(['high', 'low'], array_column($report['ranked'], 'hypothesis_id'));
        $this->assertGreaterThan(
            $report['ranked'][1]['voi'],
            $report['ranked'][0]['voi'],
        );
        $this->assertNotEmpty($report['critical_path']);
        $this->assertSame('high', $report['critical_path'][0]);
    }

    #[Test]
    public function empty_input_returns_insufficient_signal_never_fabricated_eta(): void
    {
        $scheduler = new ExperimentValueOfInformationScheduler;
        $report = $scheduler->schedule([]);

        $this->assertSame('insufficient_signal', $report['status']);
        $this->assertSame([], $report['ranked']);
        $this->assertSame([], $report['agenda']);
        $this->assertSame([], $report['critical_path']);
    }

    #[Test]
    public function scheduler_source_declares_read_only_and_proposes_only(): void
    {
        $report = (new ExperimentValueOfInformationScheduler)->schedule([]);
        $this->assertTrue($report['source']['read_only']);
        $this->assertFalse($report['source']['starts_windows']);
        $this->assertTrue($report['source']['proposes_only']);
    }

    /**
     * @return array<string,mixed>
     */
    private function proposal(
        string $id,
        string $family,
        float $intervalWidth,
        float $expectedGain,
        int $windowDays,
        float $costUnits,
    ): array {
        return [
            'hypothesis_id' => $id,
            'family' => $family,
            'hypothesis' => $this->validHypothesis(),
            'interval_width_current' => $intervalWidth,
            'expected_gain' => $expectedGain,
            'window_days' => $windowDays,
            'cost_units' => $costUnits,
        ];
    }

    /**
     * @return array<string,mixed>
     */
    private function validHypothesis(): array
    {
        return [
            'proposed_change' => 'raise recall floor on golden v2',
            'causal_mechanism' => 'better recall follows tighter memory ranking',
            'frozen_metric_ref' => 'atlas.memory.recall_golden_v2#3525f397',
            'expected_result' => '+0.05 recall@5 over baseline',
            'falsifier' => [
                'metric' => 'memory_recall_golden_v2.recall_at_5',
                'condition' => 'recall_at_5 < baseline_recall_at_5',
            ],
            'treatment_control' => 'A: current ranker · B: ranker with new prior',
            'budget' => ['tokens' => 1000, 'wallclock_seconds' => 60],
            'rollback_pre_declared' => 'revert flag atlas.aobg.new_prior_enabled',
            'architectural_cost' => 'zero new organs; pure prior tweak',
            'alternatives_compared' => [
                'do_nothing' => 'baseline drift acceptable',
                'simplify_existing' => 'remove one ranking factor first',
                'remove_a_layer' => 'strip late fusion entirely',
            ],
        ];
    }

    #[Test]
    public function guard_valid_hypothesis_passes_rec01_schema(): void
    {
        $errors = HypothesisV1::validate($this->validHypothesis());
        $this->assertSame([], $errors, 'test fixture must satisfy REC-01 or the whole suite is meaningless');
    }
}
