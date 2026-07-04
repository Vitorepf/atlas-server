<?php

declare(strict_types=1);

namespace Tests\Unit\Ai\SelfConstruction\Maestro\Cost;

use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostAggregator;
use App\Services\Ai\SelfConstruction\Maestro\Cost\AtlasMaestroCostLedger;
use ReflectionClass;
use Tests\TestCase;

class AtlasMaestroCostAggregatorTest extends TestCase
{
    private string $ledgerPath = '';

    protected function setUp(): void
    {
        parent::setUp();
        $this->ledgerPath = sys_get_temp_dir().'/atlas-maestro-cost-agg-'.bin2hex(random_bytes(6)).'.jsonl';
    }

    protected function tearDown(): void
    {
        @unlink($this->ledgerPath);
        parent::tearDown();
    }

    private function fact(array $override = []): array
    {
        return array_replace([
            'task_packet_id' => 'pk-1',
            'task_class' => 'refactor',
            'provider' => 'atlas_native',
            'model' => 'atlas-native-1',
            'cycle_id' => 'cycle-A',
            'tokens_in' => 100,
            'tokens_out' => 50,
            'cost_cents' => 10,
            'recorded_at' => '2026-06-25T00:00:00Z',
        ], $override);
    }

    public function test_aggregate_by_provider_sums_match_hand_computed_six_row_fixture(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        // 2 providers × 3 records.
        foreach ([['atlas_native', 'm1', 100, 10], ['atlas_native', 'm1', 200, 20], ['atlas_native', 'm1', 300, 30]] as $r) {
            $ledger->append($this->fact(['provider' => $r[0], 'model' => $r[1], 'tokens_in' => $r[2], 'cost_cents' => $r[3], 'recorded_at' => '2026-06-25T00:00:0'.$r[3].'Z']));
        }
        foreach ([['codex', 'm2', 50, 5], ['codex', 'm2', 60, 6], ['codex', 'm2', 70, 7]] as $r) {
            $ledger->append($this->fact(['provider' => $r[0], 'model' => $r[1], 'tokens_in' => $r[2], 'cost_cents' => $r[3], 'recorded_at' => '2026-06-25T01:00:0'.$r[3].'Z']));
        }

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByProvider();

        self::assertSame(['atlas_native:m1', 'codex:m2'], array_keys($verdict));
        self::assertSame(60, $verdict['atlas_native:m1']['sum_cost_cents']);
        self::assertSame(600, $verdict['atlas_native:m1']['sum_tokens_in']);
        self::assertSame(3, $verdict['atlas_native:m1']['count_records']);
        self::assertSame(18, $verdict['codex:m2']['sum_cost_cents']);
        self::assertSame(180, $verdict['codex:m2']['sum_tokens_in']);
    }

    public function test_aggregate_by_cycle_filters_when_cycle_id_supplied(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['cycle_id' => 'cycle-A', 'cost_cents' => 5]));
        $ledger->append($this->fact(['cycle_id' => 'cycle-B', 'cost_cents' => 7]));

        $byCycleA = (new AtlasMaestroCostAggregator($ledger))->aggregateByCycle('cycle-A');
        self::assertArrayHasKey('cycle-A', $byCycleA);
        self::assertArrayNotHasKey('cycle-B', $byCycleA);

        $byCycleAll = (new AtlasMaestroCostAggregator($ledger))->aggregateByCycle(null);
        self::assertArrayHasKey('cycle-A', $byCycleAll);
        self::assertArrayHasKey('cycle-B', $byCycleAll);
    }

    public function test_aggregate_by_task_class_groups_by_task_class(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_class' => 'refactor', 'cost_cents' => 10]));
        $ledger->append($this->fact(['task_class' => 'refactor', 'cost_cents' => 5]));
        $ledger->append($this->fact(['task_class' => 'docs', 'cost_cents' => 3]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByTaskClass();
        self::assertSame(['docs', 'refactor'], array_keys($verdict));
        self::assertSame(15, $verdict['refactor']['sum_cost_cents']);
        self::assertSame(3, $verdict['docs']['sum_cost_cents']);
    }

    public function test_empty_ledger_returns_empty_array_for_all_three_views(): void
    {
        $aggregator = new AtlasMaestroCostAggregator(new AtlasMaestroCostLedger($this->ledgerPath));

        self::assertSame([], $aggregator->aggregateByTaskClass());
        self::assertSame([], $aggregator->aggregateByProvider());
        self::assertSame([], $aggregator->aggregateByCycle());
    }

    public function test_malformed_row_does_not_throw_and_is_not_summed(): void
    {
        // Build the input rows directly via the pure primitive.
        $rows = [
            ['task_class' => 'refactor', 'tokens_in' => 100, 'tokens_out' => 50, 'cost_cents' => 10, 'recorded_at' => '2026-06-25T00:00:00Z'],
            ['task_class' => 'refactor', 'tokens_in' => 'BAD', 'tokens_out' => 50, 'cost_cents' => 10, 'recorded_at' => '2026-06-25T00:00:01Z'],
        ];
        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup($rows, 'task_class');

        self::assertSame(1, $verdict['refactor']['count_records'], 'malformed row must be skipped from count_records');
        self::assertSame(10, $verdict['refactor']['sum_cost_cents']);
    }

    public function test_public_methods_return_array_shape_only_no_quality_field(): void
    {
        $reflection = new ReflectionClass(AtlasMaestroCostAggregator::class);
        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isConstructor()) {
                continue;
            }
            self::assertSame('array', (string) $method->getReturnType(), "method {$method->getName()} must return array");
        }

        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup([
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:00Z'],
        ], 'task_class');
        foreach ($verdict as $group) {
            foreach (array_keys($group) as $key) {
                self::assertDoesNotMatchRegularExpression('/(quality|score|rating)/i', (string) $key);
            }
        }
    }

    public function test_first_and_last_recorded_at_track_extents_within_group(): void
    {
        $rows = [
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:02Z'],
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:01Z'],
            ['task_class' => 'x', 'tokens_in' => 1, 'tokens_out' => 1, 'cost_cents' => 1, 'recorded_at' => '2026-06-25T00:00:03Z'],
        ];
        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup($rows, 'task_class');
        self::assertSame('2026-06-25T00:00:01Z', $verdict['x']['first_recorded_at']);
        self::assertSame('2026-06-25T00:00:03Z', $verdict['x']['last_recorded_at']);
    }

    public function test_malformed_row_increments_rejected_count(): void
    {
        $rows = [
            ['task_class' => 'x', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_cents' => 3, 'recorded_at' => '2026-06-25T00:00:00Z'],
            ['task_class' => 'x', 'tokens_in' => 'BAD', 'tokens_out' => 5, 'cost_cents' => 3, 'recorded_at' => '2026-06-25T00:00:01Z'],
            ['task_class' => 'x', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_cents' => null, 'recorded_at' => '2026-06-25T00:00:02Z'],
        ];
        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup($rows, 'task_class');

        self::assertSame(1, $verdict['x']['count_records'], 'only valid row counted');
        self::assertSame(2, $verdict['x']['rejected_count'], 'two malformed rows must be counted as rejected');
    }

    public function test_malformed_row_increments_rejected_reason_counts_with_distinct_keys(): void
    {
        $rows = [
            ['task_class' => 'x', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_cents' => 3, 'recorded_at' => '2026-06-25T00:00:00Z'],
            ['task_class' => 'x', 'tokens_in' => 'BAD', 'tokens_out' => 5, 'cost_cents' => 3, 'recorded_at' => '2026-06-25T00:00:01Z'],
            ['task_class' => 'x', 'tokens_in' => 10, 'tokens_out' => 5, 'cost_cents' => null, 'recorded_at' => '2026-06-25T00:00:02Z'],
            ['task_class' => 'x', 'tokens_in' => 10, 'tokens_out' => 'BAD', 'cost_cents' => 3, 'recorded_at' => '2026-06-25T00:00:03Z'],
        ];
        $verdict = AtlasMaestroCostAggregator::fromRowsByGroup($rows, 'task_class');

        $this->assertSame(1, $verdict['x']['count_records'], 'only the fully-valid row counted');
        $this->assertSame(3, $verdict['x']['rejected_count']);
        $this->assertSame(1, $verdict['x']['rejected_reason_counts']['non_numeric_tokens_in']);
        $this->assertSame(1, $verdict['x']['rejected_reason_counts']['non_numeric_cost_cents']);
        $this->assertSame(1, $verdict['x']['rejected_reason_counts']['non_numeric_tokens_out']);
        $this->assertSame(3, $verdict['x']['sum_cost_cents'], 'malformed rows must not be summed');
    }

    public function test_atlas_native_bucket_is_flagged_as_zero_cost_provider(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['provider' => 'atlas_native', 'model' => 'n1', 'cost_cents' => 0]));
        $ledger->append($this->fact(['provider' => 'codex', 'model' => 'm1', 'cost_cents' => 50]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByProvider();

        self::assertTrue($verdict['atlas_native:n1']['is_zero_cost_provider'], 'atlas_native must be flagged as zero-cost');
        self::assertFalse($verdict['codex:m1']['is_zero_cost_provider'], 'paid provider must not be flagged as zero-cost');
    }

    public function test_aggregate_by_window_separates_different_days(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['recorded_at' => '2026-06-24T23:59:59Z', 'cost_cents' => 5]));
        $ledger->append($this->fact(['recorded_at' => '2026-06-25T00:00:00Z', 'cost_cents' => 7]));
        $ledger->append($this->fact(['recorded_at' => '2026-06-25T12:00:00Z', 'cost_cents' => 3]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByWindow('day');

        self::assertArrayHasKey('2026-06-24', $verdict);
        self::assertArrayHasKey('2026-06-25', $verdict);
        self::assertSame(5, $verdict['2026-06-24']['sum_cost_cents'], 'day 24 must not mix with day 25');
        self::assertSame(10, $verdict['2026-06-25']['sum_cost_cents'], 'day 25 aggregates both its records');
        self::assertSame(2, $verdict['2026-06-25']['count_records']);
    }

    // ── AC2: quality_outcome buckets ───────────────────────────────────────────

    public function test_aggregate_by_quality_outcome_groups_success_weak_green_and_give_back(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-success-1', 'cost_cents' => 10]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-success-2', 'cost_cents' => 5]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-weak-1', 'cost_cents' => 8]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-giveback-1', 'cost_cents' => 3]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByQualityOutcome([
            'pk-success-1' => 'success',
            'pk-success-2' => 'success',
            'pk-weak-1' => 'weak_green',
            'pk-giveback-1' => 'give_back',
        ]);

        self::assertSame(['give_back', 'success', 'weak_green'], array_keys($verdict));
        self::assertSame(15, $verdict['success']['sum_cost_cents']);
        self::assertSame(2, $verdict['success']['count_records']);
        self::assertSame(8, $verdict['weak_green']['sum_cost_cents']);
        self::assertSame(3, $verdict['give_back']['sum_cost_cents']);
    }

    public function test_aggregate_by_quality_outcome_buckets_unmapped_task_packet_id_as_unknown(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-orphan', 'cost_cents' => 4]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByQualityOutcome([]);

        self::assertArrayHasKey('', $verdict);
        self::assertSame(4, $verdict['']['sum_cost_cents']);
    }

    // ── AC3: cost_quality_hotspots surface expensive low-quality task classes ──

    public function test_expensive_low_quality_task_class_is_a_cost_quality_hotspot(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        // 'flaky_refactor' is expensive AND mostly give_back/weak_green (hotspot).
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'task_class' => 'flaky_refactor', 'cost_cents' => 50]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-2', 'task_class' => 'flaky_refactor', 'cost_cents' => 40]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-3', 'task_class' => 'flaky_refactor', 'cost_cents' => 10]));
        // 'stable_docs' is cheap and mostly success (not a hotspot).
        $ledger->append($this->fact(['task_packet_id' => 'pk-4', 'task_class' => 'stable_docs', 'cost_cents' => 2]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-5', 'task_class' => 'stable_docs', 'cost_cents' => 1]));

        $outcomes = [
            'pk-1' => 'give_back',
            'pk-2' => 'weak_green',
            'pk-3' => 'success',
            'pk-4' => 'success',
            'pk-5' => 'success',
        ];

        $hotspots = (new AtlasMaestroCostAggregator($ledger))->costQualityHotspots($outcomes);

        self::assertArrayHasKey('flaky_refactor', $hotspots);
        self::assertArrayNotHasKey('stable_docs', $hotspots);
        self::assertSame(100, $hotspots['flaky_refactor']['sum_cost_cents']);
        self::assertEqualsWithDelta(0.6667, $hotspots['flaky_refactor']['low_quality_outcome_rate'], 0.001);
    }

    public function test_low_quality_but_zero_cost_task_class_is_not_a_hotspot(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'task_class' => 'free_experiment', 'cost_cents' => 0]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-2', 'task_class' => 'free_experiment', 'cost_cents' => 0]));

        $hotspots = (new AtlasMaestroCostAggregator($ledger))->costQualityHotspots([
            'pk-1' => 'give_back',
            'pk-2' => 'give_back',
        ]);

        self::assertArrayNotHasKey('free_experiment', $hotspots);
    }

    public function test_expensive_high_quality_task_class_is_not_a_hotspot(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['task_packet_id' => 'pk-1', 'task_class' => 'expensive_but_good', 'cost_cents' => 500]));
        $ledger->append($this->fact(['task_packet_id' => 'pk-2', 'task_class' => 'expensive_but_good', 'cost_cents' => 500]));

        $hotspots = (new AtlasMaestroCostAggregator($ledger))->costQualityHotspots([
            'pk-1' => 'success',
            'pk-2' => 'success',
        ]);

        self::assertArrayNotHasKey('expensive_but_good', $hotspots);
    }

    // ── AC4: provider/client class summaries omit raw provider-sensitive metadata ──

    public function test_aggregate_by_provider_class_never_exposes_raw_provider_or_model_name(): void
    {
        $ledger = new AtlasMaestroCostLedger($this->ledgerPath);
        $ledger->append($this->fact(['provider' => 'atlas_native', 'model' => 'atlas-native-secret-1', 'cost_cents' => 0]));
        $ledger->append($this->fact(['provider' => 'some_frontier_provider_internal_name', 'model' => 'gpt-5.5-secret-id', 'cost_cents' => 20]));

        $verdict = (new AtlasMaestroCostAggregator($ledger))->aggregateByProviderClass();

        self::assertSame(['paid_provider', 'zero_cost'], array_keys($verdict));
        self::assertSame(20, $verdict['paid_provider']['sum_cost_cents']);
        self::assertSame(0, $verdict['zero_cost']['sum_cost_cents']);

        $encoded = (string) json_encode($verdict);
        self::assertStringNotContainsString('some_frontier_provider_internal_name', $encoded);
        self::assertStringNotContainsString('gpt-5.5-secret-id', $encoded);
        self::assertStringNotContainsString('atlas-native-secret-1', $encoded);
    }
}
